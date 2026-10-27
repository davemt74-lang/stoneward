'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');
function extract(source,name){
  const start=source.search(new RegExp('(?:async )?function '+name+'\\('));
  assert.ok(start>=0,name+' must exist');
  const opening=source.indexOf('{',start);let depth=0;
  for(let i=opening;i<source.length;i++){
    if(source[i]==='{')depth++;
    if(source[i]==='}'&&!--depth)return source.slice(start,i+1);
  }
  throw Error('Unbalanced '+name);
}

function createFixture(){
  const recognizers=[],timers=new Map(),frames=new Map();
  let timerId=0,frameId=0,amplitude=0.004,released=0,closed=0,requests=0,now=100000;
  const stream={getTracks:()=>[{stop:()=>released++}]};
  const analyser={getFloatTimeDomainData:samples=>samples.fill(amplitude)};
  class Recognition {
    constructor(){this.aborted=false;recognizers.push(this)}
    start(){this.started=true;this.onstart?.()}
    abort(){this.aborted=true}
    emit(value,isFinal=true){this.onresult?.({results:[{0:{transcript:value},isFinal}]})}
    end(){this.onend?.()}
  }
  class AudioContext {
    constructor(){this.state='running'}
    createMediaStreamSource(){return {connect:()=>{}}}
    createBiquadFilter(){return {type:'',frequency:{value:0},connect:()=>{}}}
    createAnalyser(){return analyser}
    close(){closed++;return Promise.resolve()}
  }
  const browser={SpeechRecognition:Recognition,AudioContext,
    navigator:{mediaDevices:{getUserMedia:async options=>{
      requests++;assert.equal(options.audio.echoCancellation,true);
      assert.equal(options.audio.noiseSuppression,true);return stream;
    }}},
    setTimeout:(callback,delay)=>{const id=++timerId;timers.set(id,{callback,delay});return id},
    clearTimeout:id=>timers.delete(id),
    requestAnimationFrame:callback=>{const id=++frameId;frames.set(id,callback);return id},
    cancelAnimationFrame:id=>frames.delete(id)};
  vm.runInNewContext(read('assets/js/conversation-barge-in.js'),{window:browser,AbortController,Float32Array,Date:{now:()=>now}});
  const flush=async()=>{await new Promise(resolve=>setImmediate(resolve))};
  const pump=(energy,count)=>{amplitude=energy;for(let i=0;i<count;i++){
    const entry=frames.entries().next().value;
    assert.ok(entry,'audio activity sampling must remain active');
    frames.delete(entry[0]);entry[1]();
  }};
  const advance=(delay)=>{for(const [id,timer] of [...timers])if(timer.delay===delay){timers.delete(id);now+=delay;timer.callback();return true}return false};
  return {browser,recognizers,timers,frames,flush,pump,advance,elapse:ms=>{now+=ms},
    get released(){return released},get closed(){return closed},get requests(){return requests}};
}

async function voiceSilencesAgentAfterEchoProbe(){
  const f=createFixture(),events=[];
  const barge=f.browser.StonefellowBargeIn.create(
    phrase=>events.push(['turn',phrase]),f.browser,
    signal=>f.browser.StonefellowBargeIn.verifyAgentSpeech('The album has a new song.',f.browser,signal),
    stage=>{if(stage!=='paused')events.push([stage]);return true});
  assert.equal(await barge.prepare(),true,'mic tap is acquired before voice output');
  assert.equal(await barge.start(),true);
  await f.flush();
  assert.equal(f.requests,1,'one shared microphone tap across replies');
  assert.equal(f.recognizers.length,1);
  f.pump(0.004,10);assert.equal(events.length,0,'quiet background must not interrupt');
  f.pump(0.16,4);
  assert.deepEqual(events,[['probe']],'mic activity first requests a reversible pause');
  assert.equal(f.advance(180),true,'wait for speaker echo to decay');
  f.pump(0.16,3);
  assert.deepEqual(events,[['probe'],['confirm']],'independent speech must continue after audio pauses');
  f.recognizers[0].emit('Can you play the previous track?',false);
  assert.equal(events.length,2,'interim words preserve the full request');
  f.recognizers[0].emit('Can you play the previous track?',true);
  await f.flush();
  assert.deepEqual(events,[['probe'],['confirm'],['turn','Can you play the previous track?']]);
  assert.equal(barge.active,false,'one speech turn must finish exactly once');
  assert.equal(f.advance(5000),false,'successful recognition clears fallback timer');
  barge.close();assert.equal(f.released,1,'conversation off must release mic track');
  assert.equal(f.closed,1,'conversation off must release audio context');
}

async function failedRecognitionNeverResumesAgent(){
  const f=createFixture(),events=[];
  const barge=f.browser.StonefellowBargeIn.create(
    phrase=>events.push(['turn',phrase]),f.browser,
    signal=>f.browser.StonefellowBargeIn.verifyAgentSpeech('Let me explain this album.',f.browser,signal),
    stage=>{if(stage!=='paused')events.push([stage]);return true});
  await barge.start();await f.flush();
  f.pump(0.15,4);assert.deepEqual(events,[['probe']]);
  assert.equal(f.advance(180),true);
  f.pump(0.15,3);assert.deepEqual(events,[['probe'],['confirm']]);
  f.recognizers[0].onerror?.({error:'no-speech'});await f.flush();
  assert.equal(f.advance(250),true,'failed recognition must rearm while playback stays stopped');
  await f.flush();assert.equal(f.recognizers.length,2);
  assert.equal(f.advance(5000),true,'failure after voice activity exits through listening fallback');
  await f.flush();
  assert.deepEqual(events,[['probe'],['confirm'],['turn','']],'confirmed interruption must never replay old audio');
  assert.equal(barge.active,false);
  f.recognizers[1].emit('Too late');await f.flush();
  assert.equal(events.length,3,'late ASR events cannot create duplicates');
  barge.close();
}

async function agentEchoCannotCancelItsOwnReply(){
  const f=createFixture(),events=[];
  const barge=f.browser.StonefellowBargeIn.create(
    phrase=>events.push(['turn',phrase]),f.browser,
    signal=>f.browser.StonefellowBargeIn.verifyAgentSpeech('The album has a new song.',f.browser,signal),
    stage=>{if(stage!=='paused')events.push([stage]);return true});
  await barge.start();await f.flush();
  f.pump(0.16,4);
  assert.deepEqual(events,[['probe']]);
  f.recognizers[0].emit('The album has a new song.',true);
  assert.equal(f.advance(180),true);
  f.pump(0.065,8);
  f.pump(0.004,12);
  assert.equal(f.advance(640),true);
  assert.deepEqual(events,[['probe'],['resume']],'speaker-only echo must resume the same reply');
  f.pump(0.16,12);
  assert.deepEqual(events,[['probe'],['resume']],'calibrated speaker energy cannot repeatedly barge in');
  f.elapse(1000);f.pump(0.32,4);
  assert.deepEqual(events,[['probe'],['resume'],['probe']],'louder participant can still trigger a later acoustic probe');
  assert.equal(f.advance(180),true);
  f.pump(0.32,3);
  assert.deepEqual(events,[['probe'],['resume'],['probe'],['confirm']],'independent voice still wins after echo calibration');
  assert.equal(barge.active,true,'recognition remains primed');
  assert.equal(f.advance(5000),true,'real interruption gets a missing-transcript fallback');
  assert.deepEqual(events.at(-1),['turn','']);
  barge.close();
}

async function stopDuringProbeIsNotReplayed(){
  const f=createFixture(),events=[];
  const barge=f.browser.StonefellowBargeIn.create(
    phrase=>events.push(['turn',phrase]),f.browser,
    signal=>f.browser.StonefellowBargeIn.verifyAgentSpeech('I will explain the recording.',f.browser,signal),
    stage=>{if(stage!=='paused')events.push([stage]);return true});
  await barge.start();await f.flush();f.pump(0.2,4);
  f.recognizers[0].emit('stop');await f.flush();
  assert.deepEqual(events,[['probe']],'even an ASR stop must wait for physical voice confirmation');
  assert.equal(f.advance(180),true);
  f.pump(0.2,3);
  assert.deepEqual(events,[['probe'],['confirm'],['turn','stop']],'confirmed participant stop is accepted after speaker is muted');
  assert.equal(f.advance(640),false,'no echo-probe resume may restart an interrupted reply');
  barge.close();
}

async function misheardAgentSpeechCannotEndPlayback(){
  const f=createFixture(),events=[];
  const barge=f.browser.StonefellowBargeIn.create(
    phrase=>events.push(['turn',phrase]),f.browser,
    signal=>f.browser.StonefellowBargeIn.verifyAgentSpeech('Here is the whole story of the album.',f.browser,signal),
    stage=>{if(stage!=='paused')events.push([stage]);return true});
  await barge.start();await f.flush();
  f.recognizers[0].emit('stop',true);await f.flush();
  assert.deepEqual(events,[],'a plausible ASR word cannot stop an ongoing reply without a speaker probe');
  assert.equal(barge.active,true);
  assert.equal(f.advance(250),true,'recognition can rearm after ignoring unverified words');
  await f.flush();
  f.recognizers[1].emit('play my favorite record',true);await f.flush();
  f.pump(0.004,12);
  assert.deepEqual(events,[],'even a fully novel echoed command cannot terminate speech alone');
  assert.equal(barge.active,true);
  barge.close();
}

async function misheardEchoDuringProbeIsDiscarded(){
  const f=createFixture(),events=[];
  const barge=f.browser.StonefellowBargeIn.create(
    phrase=>events.push(['turn',phrase]),f.browser,
    signal=>f.browser.StonefellowBargeIn.verifyAgentSpeech('The song will keep playing.',f.browser,signal),
    stage=>{if(stage!=='paused')events.push([stage]);return true});
  await barge.start();await f.flush();f.pump(0.17,4);
  f.recognizers[0].emit('skip to the next song',true);await f.flush();
  assert.deepEqual(events,[['probe']]);
  assert.equal(f.advance(180),true);
  f.pump(0.004,12);
  assert.equal(f.advance(640),true);
  assert.deepEqual(events,[['probe'],['resume']],'echo-detected words are discarded on speaker resume');
  f.elapse(1000);f.pump(0.3,4);
  assert.equal(f.advance(180),true);f.pump(0.3,3);
  assert.deepEqual(events,[['probe'],['resume'],['probe'],['confirm']]);
  assert.equal(f.advance(5000),true);
  assert.deepEqual(events.at(-1),['turn',''],'the discarded echo must not be submitted as the later visitor');
  barge.close();
}

async function pauseNotAcknowledgedIsNotAnInterrupt(){
  const f=createFixture(),events=[];
  const barge=f.browser.StonefellowBargeIn.create(
    phrase=>events.push(['turn',phrase]),f.browser,
    signal=>f.browser.StonefellowBargeIn.verifyAgentSpeech('This reply is still speaking.',f.browser,signal),
    stage=>{if(stage!=='paused')events.push([stage]);return stage!=='paused'});
  await barge.start();await f.flush();f.pump(0.2,4);
  assert.equal(f.advance(180),true);
  f.pump(0.2,10);
  assert.deepEqual(events,[['probe'],['resume']],'an ineffective pause cannot turn agent audio into a visitor');
  assert.equal(barge.active,true);
  barge.close();
}

async function unavailableMicFailsClosed(){
  const f=createFixture(),events=[];
  f.browser.navigator.mediaDevices.getUserMedia=async()=>{throw Error('denied')};
  const barge=f.browser.StonefellowBargeIn.create(
    phrase=>events.push(phrase),f.browser,()=>Promise.resolve('stop'),()=>true);
  assert.equal(await barge.start(),false,'unavailable acoustic validation cannot arm unverified barge-in');
  assert.equal(f.recognizers.length,0,'never start an unguarded ASR monitor');
  assert.deepEqual(events,[]);
  barge.close();
}

async function prepareAfterCloseCannotReopenMic(){
  const f=createFixture();let resolveMic;
  f.browser.navigator.mediaDevices.getUserMedia=()=>new Promise(resolve=>{resolveMic=resolve});
  let stopped=0;
  const monitor=f.browser.StonefellowBargeIn.create(()=>{},f.browser,()=>Promise.resolve(null),()=>{});
  const pending=monitor.prepare();monitor.close();
  resolveMic({getTracks:()=>[{stop:()=>stopped++}]});
  assert.equal(await pending,false,'late permission response cannot resurrect ended conversation');
  assert.equal(stopped,1,'late permission stream is released');
}

async function playbackHoldsAreWiredWithoutKillingRecognition(){
  const app=read('assets/js/app.js'),admin=read('admin/assets/admin.js');
  assert.match(app,/conversationBargeIn\?\.prepare\(\)/,'public microphone gesture must prepare the detector');
  assert.match(admin,/adminConversationMonitor\?\.prepare\(\)/,'Admin microphone gesture must prepare the detector');
  assert.match(app,/conversationBargeIn\?\.close\(\)/,'public conversation shutdown releases mic');
  assert.match(admin,/adminConversationMonitor\?\.close\(\)/,'Admin conversation shutdown releases mic');
  let cancelAudio=0,cancelSpeech=0,monitorStopped=0,shown='';
  const publicContext={
    st:{conversationOn:true,voiceAudio:{paused:false,pause(){cancelAudio++}}},
    voicePlaybackPending:true,voicePlaybackSequence:6,
    voiceAudioCancel:()=>{cancelAudio++},speechFallbackResolve:()=>{},
    window:{speechSynthesis:{cancel:()=>cancelSpeech++}},
    conversationBargeIn:{stop:()=>monitorStopped++},
    restoreVoiceProbeMusic:()=>{},
    cancelLocalVoiceOutput:()=>cancelSpeech++,
    agent:()=>{},showTranscript:text=>{shown=text}
  };
  vm.createContext(publicContext);
  vm.runInContext(extract(app,'holdVoicePlaybackForBarge'),publicContext);
  publicContext.holdVoicePlaybackForBarge();
  assert.equal(publicContext.voicePlaybackSequence,7,'old audio must become stale immediately');
  assert.ok(cancelAudio>=1&&cancelSpeech===1,'output is silenced immediately');
  assert.equal(monitorStopped,0,'barge recognizer must remain active after voice onset');
  assert.equal(publicContext.voicePlaybackPending,true,'capture window stays open for the complete request');
  assert.match(shown,/Listening to the interruption/);
  let adminCancelled=0,adminStopped=0;
  const adminContext={
    adminConversationEnabled:true,adminSpeechPending:true,adminSpeechSequence:8,
    adminSpeechActive:true,adminSpeechResolve:()=>{},
    window:{speechSynthesis:{cancel:()=>adminCancelled++}},
    cancelAdminSpeechOutput:()=>adminCancelled++,
    adminConversationMonitor:{stop:()=>adminStopped++},
    $:()=>({textContent:''}),agent:()=>{}
  };
  vm.createContext(adminContext);
  vm.runInContext(extract(admin,'holdAdminSpeechForBarge'),adminContext);
  adminContext.holdAdminSpeechForBarge();
  assert.equal(adminContext.adminSpeechSequence,9);
  assert.equal(adminContext.adminSpeechPending,true,'Admin keeps collecting the interruption');
  assert.equal(adminCancelled,1);
  assert.equal(adminStopped,0,'Admin speech hold must not abort ASR');
}

Promise.resolve().then(voiceSilencesAgentAfterEchoProbe)
  .then(failedRecognitionNeverResumesAgent)
  .then(agentEchoCannotCancelItsOwnReply)
  .then(stopDuringProbeIsNotReplayed)
  .then(misheardAgentSpeechCannotEndPlayback)
  .then(misheardEchoDuringProbeIsDiscarded)
  .then(pauseNotAcknowledgedIsNotAnInterrupt)
  .then(unavailableMicFailsClosed)
  .then(prepareAfterCloseCannotReopenMic)
  .then(playbackHoldsAreWiredWithoutKillingRecognition)
  .then(()=>console.log('Section 90Q live voice: public/Admin pause, misheard echo, real interruption, and mic lifecycle PASS'))
  .catch(error=>{console.error(error);process.exitCode=1});
