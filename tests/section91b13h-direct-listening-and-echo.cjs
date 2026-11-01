'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const app=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
let checks=0;
function check(label,fn){fn();checks++;console.log('91B13H PASS '+label);}

const directStart=app.indexOf('  function directSongListeningCommand(');
const liveStart=app.indexOf('  async function liveAgent(',directStart);
const liveEnd=app.indexOf('  function membershipBenefitList(',liveStart);
assert.ok(directStart>0&&liveStart>directStart&&liveEnd>liveStart);
let active=false,loadAuthCalls=0;
const actions=[],notices=[],modes=[],spoken=[];
const scope={
  songListeningSkill:{status:()=>({active})},
  currentSongDraft:()=>active?null:{track_id:'sf-earth',text:'A corrected verse'},
  songLyricReport:null,
  executeSongCaptureAction:async action=>{
    actions.push(action);
    if(action.type==='song_capture_live_start'){active=true;return 'Listening to Earth Girl';}
    if(action.type==='song_capture_stop'){active=false;return 'Lyric draft ready for review';}
    if(action.type==='song_capture_analyze'){
      scope.songLyricReport={report:'The lyric suggests change and renewal.'};
      return scope.songLyricReport.report;
    }
    return 'Unexpected action';
  },
  loadAuth:async()=>{loadAuthCalls++;throw Error('Remote Agent endpoint unavailable');},
  popupEntry:(role,text)=>notices.push({role,text}),
  agent:(mode,text)=>modes.push({mode,text}),
  say:async text=>spoken.push(text),
  st:{playing:true},
  window:{StonefellowSongExperience:{active:()=>active}}
};
vm.createContext(scope);
vm.runInContext(app.slice(directStart,liveEnd)+'\nthis.resolve=directSongListeningCommand;this.send=liveAgent;',scope);
check('Exact Listen and Interpret is a direct, explicitly consented player command',()=>{
  const a=scope.resolve('listen and interpret');
  assert.equal(a.type,'song_capture_live_start');
  assert.equal(a.interpret_on_stop,true);
  assert.equal(scope.resolve('Stonefellow, listen and interpret the lyrics').interpret_on_stop,true);
  assert.equal(scope.resolve('please listen to this song and analyze the lyrics').interpret_on_stop,true);
  assert.equal(scope.resolve('start live transcription').interpret_on_stop,false);
});
check('Ambiguous player/lyric requests cannot bypass the ordinary Agent router',()=>{
  assert.equal(scope.resolve('play Earth Girl'),null);
  assert.equal(scope.resolve('listen to Earth Girl'),null);
  assert.equal(scope.resolve('save my lyric note'),null);
  assert.equal(scope.resolve('stop listening'),null,'Stop cannot hijack microphone controls without an active song session');
});
async function run(){
  await scope.send('listen and interpret');
  check('Unavailable Agent service cannot block local player-track listening',()=>{
    assert.equal(loadAuthCalls,0);
    assert.equal(actions.length,1);
    assert.equal(actions[0].type,'song_capture_live_start');
    assert.equal(active,true);
    assert.equal(spoken.length,0,'Do not speak capture metadata into the microphone');
    assert.match(notices[0].text,/Listening to Earth Girl/);
    assert.equal(modes.at(-1).mode,'listening');
  });
  await scope.send('stop listening');
  check('Stopping returns draft to review, with no premature interpretation',()=>{
    assert.equal(actions.at(-1).type,'song_capture_stop');
    assert.equal(active,false);
    assert.equal(spoken.length,0);
    assert.match(notices.at(-1).text,/ready for review/);
  });
  await scope.send('interpret lyrics');
  check('Only the completed AI lyric reading is spoken',()=>{
    assert.equal(actions.at(-1).type,'song_capture_analyze');
    assert.equal(spoken.at(-1),'The lyric suggests change and renewal.');
    assert.equal(loadAuthCalls,0);
  });
  const wake=app.indexOf('  const musicWakePhrase=');
  const after=app.indexOf('  function updateConversationButton()',wake);
  const guardScope={
    st:{conversationOn:true,playing:true},audio:{paused:false},
    voiceTurnBusy:false,voicePlaybackPending:false,
    voiceSpeechGuard:{blocked:false}
  };
  vm.createContext(guardScope);
  vm.runInContext(app.slice(wake,after)+'\nthis.duck=shouldDuckConversationInput;',guardScope);
  check('Song lyrics and Agent echo do not make the microphone pump music volume',()=>{
    assert.equal(guardScope.duck(),false);
    assert.equal(guardScope.duck('I heard the words from the song'),false);
    assert.equal(guardScope.duck('Stonefellow listen and interpret'),true);
    guardScope.voiceSpeechGuard.blocked=true;
    assert.equal(guardScope.duck('Stonefellow listen and interpret'),false);
    guardScope.voiceSpeechGuard.blocked=false;
    guardScope.audio.paused=true;
    assert.equal(guardScope.duck(),true);
  });
  check('Public recognition waits for a recognized wake phrase while music plays',()=>{
    assert.match(app,/recognition\.onspeechstart=\(\)=>\{if\(shouldDuckConversationInput\(\)\)setVoiceInputActive\(true\)/);
    assert.match(app,/if\(spoken&&!finalReceived\)setVoiceInputActive\(shouldDuckConversationInput\(spoken\)\)/);
    assert.match(app,/if\(st\.playing&&!audio\.paused&&!woke\)/);
    assert.match(app,/say “Stonefellow” to talk over music/);
    assert.match(app,/\{requireTranscript:true,echoTailMs:320,echoDeadlineMs:930\}/);\n    assert.doesNotMatch(app,/conversationBargeIn\?\.start\(\)/,'The Agent must not start an acoustic monitor while its own voice is audible');\n    assert.match(app,/conversationBargeIn\?\.stop\(\)/);
    assert.match(app,/songExperience\.listening_mode==='recognition'\?'listening':'recording'/);
    assert.match(app,/if\(directSongListeningCommand\(text\)\)\{await liveAgent\(text\);return\}await loadAuth\(\)/);
  });
  check('Small speech gaps do not repeatedly restore and re-duck music',()=>{
    assert.match(app,/voiceSpeechReleaseTimer=setTimeout/);
    assert.match(app,/speechPilotReleaseTimer=setTimeout/);
    assert.match(app,/setSpeechPilotSpeaking\(mode==='speaking'\)/);
    new vm.Script(app);
  });
  console.log('Section 91B13H: '+checks+' local listening and acoustic isolation assertions passed');
}
run().catch(error=>{console.error(error);process.exitCode=1});
