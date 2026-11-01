'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
let passed=0;
function check(label,fn){fn();passed++;console.log('91B13B PASS '+label);}
const source=read('assets/js/agent-song-listening-skill.js');
const events=[];
class Recognition {
  constructor(){this.started=[];this.stops=0;this.aborts=0;Recognition.last=this;}
  start(track){this.started.push(track);}
  stop(){this.stops++;this.onend?.();}
  abort(){this.aborts++;}
}
const audioTrack={kind:'audio',readyState:'live',stopped:false,stop(){this.stopped=true;}};
const audio={paused:false,currentTime:12,src:'/published/song.mp3',currentSrc:'/published/song.mp3',
  captureStream(){events.push('captureStream');return {getAudioTracks:()=>[audioTrack],getTracks:()=>[audioTrack]};}};
const browser={navigator:{userAgent:'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36'},
  SpeechRecognition:Recognition};
const scope={window:browser,document:{documentElement:{lang:'en-US'}},
  setTimeout:()=>123,clearTimeout:()=>{}};
vm.runInNewContext(source,scope,{filename:'agent-song-listening-skill.js'});
const api=browser.StonefellowSongListening;
check('Player-track recognition is enabled for a documented desktop browser',()=>{
  assert.equal(api.supportsPlayerTrack(browser),true);
  assert.equal(api.supportsPlayerTrack({...browser,navigator:{userAgent:'Mozilla/5.0 Chrome/134.0.0.0 Safari/537.36'}}),false);
  assert.equal(api.supportsPlayerTrack({...browser,navigator:{userAgent:'Mozilla/5.0 (iPhone) CriOS/150.0'}}),false);
  assert.equal(api.supportsPlayerTrack({...browser,navigator:{userAgent:'Mozilla/5.0 Version/27 Safari/605'}}),false);
});
const updates=[],finishes=[];
const skill=api.create({audio,getTrack:()=>({id:'published-1',title:'Earth Girl',audio:'/published/song.mp3'}),
  now:()=>new Date('2026-11-01T12:00:00.000Z'),onUpdate:v=>updates.push(v),
  onFinish:v=>{finishes.push(v);return 'Interpretation requested';}});
check('Start listens to the player track, never a microphone or recorder',()=>{
  const started=skill.begin();assert.equal(started.ok,true);
  assert.equal(Recognition.last.started[0],audioTrack);
  assert.equal(events.includes('captureStream'),true);
  assert.match(started.text,/no clip saved/i);
  assert.equal(skill.status().active,true);
  assert.doesNotMatch(source,/new\s+MediaRecorder|new\s+Blob|\.getUserMedia\s*\(/);
  assert.match(source,/recognizer\.start\(audioTrack\)/);
  assert.doesNotMatch(source,/recognizer\.start\(\)/);
});
check('Live recognition previews unverified words in memory',()=>{
  Recognition.last.onresult({resultIndex:0,results:[
    {isFinal:true,0:{transcript:'The stars are in the trees'}},
    {isFinal:false,0:{transcript:'tonight'}}]});
  assert.match(updates.at(-1).text,/stars are in the trees/);
  assert.equal(updates.at(-1).interim,'tonight');
  Recognition.last.onresult({resultIndex:0,results:[
    {isFinal:true,0:{transcript:'The stars are in the trees'}}]});
  assert.equal(updates.at(-1).text,'The stars are in the trees');
});
async function main(){
  audio.currentTime=22;
  const finished=await skill.stop();
  check('Stop returns transcript metadata without any audio blob',()=>{
    assert.equal(finished.ok,true);
    assert.equal(finished.receipt.start_ms,12000);
    assert.equal(finished.receipt.end_ms,22000);
    assert.equal(finished.receipt.track_id,'published-1');
    assert.equal(finished.receipt.review_status,'unverified');
    assert.equal(finished.receipt.provider,'browser-speech-recognition');
    assert.equal(finished.receipt.text,'The stars are in the trees');
    assert.equal(Object.hasOwn(finished.receipt,'blob'),false);
    assert.equal(finishes.length,1);
    assert.equal(skill.status().active,false);
    assert.equal(audioTrack.stopped,true);
  });
  check('OpenAI-only transcription remains optional for the separate Record Clip',()=>{
    const app=read('assets/js/app.js');
    const liveRoute=app.slice(app.indexOf("if(action.type==='song_capture_live_start'){"),
      app.indexOf("if(action.type==='song_capture_stop'&&songListeningSkill"));
    assert.match(liveRoute,/songListeningSkill\.begin\(\)/);
    assert.doesNotMatch(liveRoute,/songCaptureSkill\.begin\(\)|enableLive\(|agent-song-transcribe\.php/);
    assert.match(app,/if\(action\.type==='song_capture_start'\)\{/);
    assert.match(app,/songCaptureSkill\.begin\(\)\.text/);
    assert.match(app,/currentSongSource\(\)/);
    assert.match(app,/songListeningSkill\.stop\('seek'\)/);
    assert.match(app,/songListeningSkill\.stop\('switch'\)/);
    assert.match(app,/await analyzeLastSongDraft/);
    assert.doesNotMatch(app,/uploadSongLiveSegment|enqueueSongLiveSegment/);
  });
  check('Listener script boots before app, while Create Record remains a separate page',()=>{
    const shell=read('stonefellow-v120.php');
    assert.ok(shell.indexOf('agent-song-listening-skill.js')<shell.indexOf('assets/js/app.js'));
    assert.match(read('assets/js/app.js'),/if\(action==='record'\)\{navigate\('builder'\);return\}/);
    new vm.Script(read('assets/js/app.js'));
    new vm.Script(read('assets/js/agent-song-experience-ui.js'));
  });
  console.log('Section 91B13B: '+passed+' listener / privacy / routing assertions passed');
}
main().catch(error=>{console.error(error);process.exitCode=1});
