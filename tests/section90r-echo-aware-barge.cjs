'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');

function extract(source,name){
  const start=source.search(new RegExp('(?:async )?function '+name+'\\('));
  assert.ok(start>=0,'missing '+name);
  const opening=source.indexOf('{',start);let depth=0;
  for(let i=opening;i<source.length;i++){
    if(source[i]==='{')depth++;
    if(source[i]==='}'&&!--depth)return source.slice(start,i+1);
  }
  throw Error('unbalanced '+name);
}

function publicPlaybackProbe(){
  const source=read('assets/js/app.js'),events=[];
  const agentAudio={paused:false,
    pause(){this.paused=true;events.push('tts-pause')},
    play(){this.paused=false;events.push('tts-resume');return Promise.resolve()}};
  const song={paused:false,volume:0.16};
  const context={
    st:{conversationOn:true,voiceAudio:agentAudio},voicePlaybackPending:true,
    voicePlaybackSequence:7,voiceProbeAudio:null,voiceProbeSynth:false,voiceProbeMusicVolume:null,
    audio:song,voiceAudioCancel:()=>events.push('cancel-tts'),speechFallbackResolve:null,
    window:{speechSynthesis:{speaking:false}},agent:()=>{},showTranscript:()=>{},syncConversationMusic:()=>{}
  };
  vm.createContext(context);
  for(const name of ['muteMusicDuringVoiceProbe','restoreVoiceProbeMusic','holdVoicePlaybackForBarge','handleVoiceActivityProbe']){
    vm.runInContext(extract(source,name),context);
  }
  assert.equal(context.handleVoiceActivityProbe('probe'),true);
  assert.equal(context.handleVoiceActivityProbe('paused'),true,'public playback pause is acknowledged before probing speech');
  assert.equal(agentAudio.paused,true);
  assert.equal(song.volume,0,'music must also be muted during the echo test');
  assert.equal(context.voicePlaybackSequence,7,'a provisional pause must not invalidate TTS');
  assert.equal(context.handleVoiceActivityProbe('resume'),true);
  assert.equal(context.handleVoiceActivityProbe('paused'),false,'resumed playback must not be mistaken for muted audio');
  assert.equal(agentAudio.paused,false,'echo-only test resumes the same audio');
  assert.equal(song.volume,0.16,'music volume is restored');
  assert.deepEqual(events,['tts-pause','tts-resume']);
  assert.equal(context.handleVoiceActivityProbe('probe'),true);
  assert.equal(context.handleVoiceActivityProbe('confirm'),true);
  assert.equal(context.voicePlaybackSequence,8,'confirmed independent speech cancels prior playback');
  assert.equal(song.volume,0.16,'confirmation must not leave music muted');
  assert.ok(events.includes('cancel-tts'),'independent speech cancels TTS');
}

function adminSpeechProbe(){
  const source=read('admin/assets/admin.js'),events=[];
  const synth={speaking:true,paused:false,
    pause(){this.paused=true;events.push('pause')},
    resume(){this.paused=false;events.push('resume')},
    cancel(){events.push('cancel')}};
  const context={window:{speechSynthesis:synth},adminConversationEnabled:true,
    adminSpeechPending:true,adminSpeechSequence:3,adminSpeechProbePaused:false,
    adminSpeechResolve:()=>{},adminSpeechActive:true,$:()=>({textContent:''}),agent:()=>{}};
  vm.createContext(context);
  for(const name of ['cancelAdminSpeechOutput','holdAdminSpeechForBarge','handleAdminVoiceActivityProbe'])vm.runInContext(extract(source,name),context);
  assert.equal(context.handleAdminVoiceActivityProbe('probe'),true);
  assert.equal(context.handleAdminVoiceActivityProbe('paused'),true,'Admin playback pause is confirmed by the browser');
  assert.equal(synth.paused,true);
  assert.equal(context.adminSpeechSequence,3);
  context.handleAdminVoiceActivityProbe('resume');
  assert.equal(context.handleAdminVoiceActivityProbe('paused'),false);
  assert.deepEqual(events,['pause','resume'],'Agent echo must not cancel Admin output');
  context.handleAdminVoiceActivityProbe('probe');
  context.handleAdminVoiceActivityProbe('confirm');
  assert.deepEqual(events,['pause','resume','pause','cancel','resume'],'cancel must release the paused synthesis queue');
  assert.equal(context.adminSpeechSequence,4,'real interruption cancels Admin playback');
}

async function audioPauseMustNotResolveRemoteSpeech(){
  const source=read('assets/js/app.js'),created=[];
  class Audio{
    constructor(){this.paused=true;created.push(this)}
    play(){this.paused=false;return Promise.resolve()}
    pause(){this.paused=true;this.onpause?.()}
  }
  const context={
    st:{auth:{authenticated:true,csrf:'token'},voiceAudio:null},cfg:{apiBase:'api'},
    voiceRepliesEnabled:true,voicePlaybackSequence:2,voiceAudioCancel:null,voiceProbeAudio:null,
    globalThis:{StonefellowSpeechSegments:{split:()=>['Spoken sentence.']}},
    fetch:async()=>({ok:true,blob:async()=>({})}),
    URL:{createObjectURL:()=> 'blob:sample',revokeObjectURL:()=>{}},
    Audio,agent:()=>{},setVoiceSpeechAudible:()=>{},loadAuth:async()=>{},encodeURIComponent
  };
  vm.createContext(context);
  vm.runInContext(extract(source,'elevenLabsSpeak'),context);
  let outcome='pending';
  const work=context.elevenLabsSpeak('Spoken sentence.',false,2).then(x=>{outcome=x});
  for(let i=0;i<8&&!created.length;i++)await Promise.resolve();
  assert.equal(created.length,1);
  const speech=created[0];context.voiceProbeAudio=speech;
  speech.pause();
  await Promise.resolve();
  assert.equal(outcome,'pending','probe pause is not a cancelled or truncated reply');
  context.voiceProbeAudio=null;
  await speech.play();speech.onended?.();await work;
  assert.equal(outcome,true,'same ElevenLabs segment completes after resume');
}

async function echoCannotImpersonateStop(){
  const script=read('assets/js/conversation-barge-in.js');
  class Recognition{
    start(){}abort(){}
    emit(text){this.onresult?.({results:[{0:{transcript:text},isFinal:true}]})}
  }
  const browser={SpeechRecognition:Recognition};
  vm.runInNewContext(script,{window:browser,AbortController,Float32Array});
  // A matching Agent word cannot be mistaken for a participant.
  // The monitor's acoustic test is responsible for identifying overlapping speech.
  // Capture the created recognizer using the browser's constructor override.
  let recognizer;
  class Capture extends Recognition{constructor(){super();recognizer=this}}
  browser.SpeechRecognition=Capture;
  const checking=browser.StonefellowBargeIn.verifyAgentSpeech('I can stop playback anytime.',browser);
  recognizer.emit('stop');recognizer.onend?.();
  assert.equal(await checking,null,'Agent saying stop cannot self-interrupt');
  const allowed=browser.StonefellowBargeIn.verifyAgentSpeech('This is the latest track.',browser);
  recognizer.emit('stop');
  assert.equal(await allowed,'stop','a genuine stop remains available when it is not an echo');
}

Promise.resolve().then(publicPlaybackProbe).then(adminSpeechProbe)
  .then(audioPauseMustNotResolveRemoteSpeech).then(echoCannotImpersonateStop)
  .then(()=>console.log('Section 90R echo-aware barge: public/Admin pause, real interrupt, ElevenLabs continuity, and self-stop guard PASS'))
  .catch(error=>{console.error(error);process.exitCode=1});
