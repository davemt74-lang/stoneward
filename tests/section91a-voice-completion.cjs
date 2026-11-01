'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const read=name=>fs.readFileSync(path.join(__dirname,'..',name),'utf8');
const publicApp=read('assets/js/app.js'),adminApp=read('admin/assets/admin.js');
function extract(source,name){
  const offset=source.search(new RegExp('(?:async )?function '+name+'\\('));
  assert.ok(offset>=0,'Missing '+name);
  const open=source.indexOf('{',offset);let level=0;
  for(let i=open;i<source.length;i++){
    if(source[i]==='{')level++;
    if(source[i]==='}'&&!--level)return source.slice(offset,i+1);
  }
  throw Error('Unbalanced '+name);
}
const flush=async()=>{await new Promise(resolve=>setImmediate(resolve))};

async function onlyIndependentVoiceCanProbe(){
  let now=100000,timerId=0,frameId=0,amplitude=0.004;
  const timers=new Map(),frames=new Map(),recognizers=[],events=[];
  const browser={
    SpeechRecognition:class{
      constructor(){recognizers.push(this)}
      start(){}
      abort(){}
      emit(phrase,final=true){this.onresult?.({results:[{0:{transcript:phrase},isFinal:final}]})}
      end(){this.onend?.()}
    },
    AudioContext:class{
      constructor(){this.state='running'}
      createMediaStreamSource(){return {connect(){}}}
      createBiquadFilter(){return {frequency:{value:0},connect(){}}}
      createAnalyser(){return {getFloatTimeDomainData:buffer=>buffer.fill(amplitude)}}
      close(){return Promise.resolve()}
    },
    navigator:{mediaDevices:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}},
    setTimeout:(callback,ms)=>{const id=++timerId;timers.set(id,{callback,ms});return id},
    clearTimeout:id=>timers.delete(id),
    requestAnimationFrame:callback=>{const id=++frameId;frames.set(id,callback);return id},
    cancelAnimationFrame:id=>frames.delete(id)
  };
  vm.runInNewContext(read('assets/js/conversation-barge-in.js'),{window:browser,AbortController,Float32Array,Date:{now:()=>now}});
  const pump=(level,framesCount)=>{amplitude=level;for(let i=0;i<framesCount;i++){
    const entry=frames.entries().next().value;assert.ok(entry,'sampling frame');frames.delete(entry[0]);entry[1]();
  }};
  const tick=ms=>{const entry=[...timers].find(([,timer])=>timer.ms===ms);assert.ok(entry,'timer '+ms);timers.delete(entry[0]);now+=ms;entry[1].callback()};
  const monitor=browser.StonefellowBargeIn.create(
    phrase=>events.push(['interrupt',phrase]),browser,
    signal=>browser.StonefellowBargeIn.verifyAgentSpeech('Our record has ten songs.',browser,signal),
    stage=>{if(stage!=='paused')events.push([stage]);return true},
    null,{requireTranscript:true}
  );
  assert.equal(await monitor.start(),true);
  await flush();
  pump(0.20,120);
  assert.equal(events.length,0,'Agent audio energy cannot even pause the reply without independent speech');
  recognizers[0].emit('Our record has ten songs.');recognizers[0].end();await flush();
  pump(0.26,120);
  assert.equal(events.length,0,'ASR transcription of Agent text is not a participant');
  tick(250);await flush();
  recognizers[1].emit('stop');await flush();
  now+=2401;pump(0.30,10);
  assert.equal(events.length,0,'old independent ASR fragments cannot interrupt a later speech segment');
  tick(250);await flush();
  recognizers[2].emit('Please stop');await flush();
  pump(0.30,4);
  assert.deepEqual(events,[['probe']],'fresh independently recognized words permit acoustic confirmation');
  tick(180);pump(0.30,3);
  assert.deepEqual(events,[['probe'],['confirm'],['interrupt','Please stop']],'confirmed participant interrupts exactly once');
  assert.equal(monitor.active,false);
}

function voiceSynth(){
  const utterances=[];
  const synth={paused:false, speaking:true,cancelCount:0,
    getVoices:()=>[{localService:true,lang:'en-US'}],
    speak:utterance=>{utterances.push(utterance);utterance.onstart?.()},
    cancel(){this.cancelCount++},resume(){this.paused=false}
  };
  class Utterance{constructor(text){this.text=text}}
  return {synth,utterances,Utterance};
}

async function publicSpeechCompletesEveryChunk(){
  const {synth,utterances,Utterance}=voiceSynth(),timers=[];
  const context={
    window:{speechSynthesis:synth,SpeechSynthesisUtterance:Utterance},
    voiceRepliesEnabled:true,voicePlaybackSequence:5,voiceProbeSynth:false,speechFallbackResolve:null,
    voiceSpeechGuard:{audible:()=>true},
    globalThis:{StonefellowSpeechSegments:{split:()=>['First segment.','Second segment.','Last segment.']}},
    setTimeout:callback=>{timers.push(callback);return timers.length},agent:()=>{},
    setVoiceSpeechAudible:()=>{},cancelLocalVoiceOutput:()=>synth.cancel(),
  };
  vm.createContext(context);
  vm.runInContext(extract(publicApp,'speakWithDeviceVoice'),context);
  const work=context.speakWithDeviceVoice('The complete response.',5);
  await flush();
  assert.equal(utterances.length,1);
  utterances[0].onerror({error:'interrupted'});
  while(timers.length)timers.shift()();
  assert.equal(utterances.length,2,'transient engine interruption retries current segment');
  assert.equal(utterances[1].text,'First segment.','retry never skips the current words');
  utterances[0].onend?.();
  assert.equal(utterances.length,2,'stale end cannot start the next segment');
  utterances[1].onend();
  assert.equal(utterances[2].text,'Second segment.');
  context.voiceProbeSynth=true;
  utterances[2].onend();
  assert.equal(utterances.length,3,'end while temporarily paused cannot advance past words');
  context.voiceProbeSynth=false;
  while(timers.length)timers.shift()();
  assert.equal(utterances[3].text,'Second segment.','probe-truncated segment is replayed');
  utterances[3].onend();
  assert.equal(utterances[4].text,'Last segment.');
  utterances[4].onend();
  assert.equal(await work,true,'browser speech completes only after the final onend');
  assert.equal(context.speechFallbackResolve,null,'completion releases the cancellation callback');
}

async function adminSpeechCompletesEveryChunk(){
  const {synth,utterances,Utterance}=voiceSynth(),timers=[],states=[],restarted=[];
  const composer={textContent:''};
  const context={
    window:{speechSynthesis:synth,SpeechSynthesisUtterance:Utterance},
    adminSpeechEnabled:true,adminSpeechPending:false,adminSpeechSequence:0,
    adminSpeechActive:false,adminSpeechResolve:null,adminSpeechText:'',adminSpeechProbePaused:false,
    adminConversationEnabled:true,
    adminConversationMonitor:{start:()=>Promise.resolve(true),stop:()=>states.push('monitor-stop')},
    adminConversationResume:()=>restarted.push('listen'),
    adminSpeechGuard:{begin:()=>1,audible:()=>true,finish:()=>true},syncAdminMusicDucking:()=>{},
    globalThis:{StonefellowSpeechSegments:{split:()=>['First instruction.','Then second instruction.']}},
    $:()=>composer,agent:state=>states.push(state),
    syncAdminSpeechStop:()=>{},
    setTimeout:callback=>{timers.push(callback);return timers.length},clearTimeout:()=>{},
    stopAdminSpeech(resume=true){assert.equal(resume,false,'new output never briefly opens normal recognition');context.adminSpeechSequence++;context.adminSpeechPending=false},
    cancelAdminSpeechOutput:()=>synth.cancel()
  };
  vm.createContext(context);
  vm.runInContext(extract(adminApp,'speakAdminReply'),context);
  const work=context.speakAdminReply('Full instructions');
  await flush();
  assert.equal(utterances.length,1);
  assert.equal(restarted.length,0,'recognition remains suspended during Admin response');
  utterances[0].onerror({error:'interrupted'});
  while(timers.length)timers.shift()();
  assert.equal(utterances[1].text,'First instruction.');
  utterances[1].onend();
  assert.equal(utterances[2].text,'Then second instruction.');
  utterances[2].onend();
  assert.equal(await work,true);
  assert.equal(restarted.length,1,'normal Admin listening resumes only after the last instruction');
  assert.equal(context.adminSpeechPending,false);
  assert.equal(composer.textContent,'');
}

async function remotePauseEventCannotCompleteSpeech(){
  assert.doesNotMatch(extract(publicApp,'elevenLabsSpeak'),/a\.onpause\s*=/,
    'asynchronous Audio.pause events must never settle ElevenLabs segments');
  assert.match(publicApp,/requireTranscript:true/,'public uses transcript-and-acoustics barge gate');
  assert.match(adminApp,/requireTranscript:true/,'Admin uses the same barge gate');
  assert.match(read('admin/index.php'),/id="adminAgentSpeechStop"[^>]+hidden/,'Admin provides an accessible manual stop');
  assert.match(adminApp,/stopSpeech\.onclick=\(\)=>\{stopAdminSpeech\(\)/,'manual stop cancels playback');
  assert.match(read('admin/assets/agent-canvas.css'),/:has\(#adminAgentSpeechStop:not\(\[hidden\]\)\)/,'responsive composer reserves stop-button width only when shown');
  assert.match(read('stonefellow-v120.php'),/id="agentInterrupt"/,'public keeps manual interruption');
  assert.match(publicApp,/Speech recognition is unavailable\. Use Stop speaking/,'ASR outage guidance is accurate');
}

Promise.resolve().then(onlyIndependentVoiceCanProbe)
  .then(publicSpeechCompletesEveryChunk)
  .then(adminSpeechCompletesEveryChunk)
  .then(remotePauseEventCannotCompleteSpeech)
  .then(()=>console.log('Section 91A: echo gating, true barge-in, complete public/Admin playback, and pause safety PASS'))
  .catch(error=>{console.error(error);process.exitCode=1});
