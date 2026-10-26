'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');
const publicApp=read('assets/js/app.js'),adminApp=read('admin/assets/admin.js');
function extract(source,name){
  const start=source.search(new RegExp('(?:async )?function '+name+'\\('));
  assert.ok(start>=0,`${name} missing`);
  const opening=source.indexOf('{',start);let depth=0;
  for(let i=opening;i<source.length;i++){
    if(source[i]==='{')depth++;
    if(source[i]==='}'&&!--depth)return source.slice(start,i+1);
  }
  throw new Error('Unbalanced function '+name);
}
assert.match(read('stonefellow-v120.php'),/conversation-barge-in\.js/);
assert.match(read('admin/index.php'),/conversation-barge-in\.js/);
assert.match(read('service-worker.js'),/conversation-barge-in\.js/);
assert.match(publicApp,/sequence!==voicePlaybackSequence/);
assert.match(publicApp,/if\(!st\.conversationOn\|\|voiceTurnBusy\|\|voicePlaybackPending\)return/);
assert.match(publicApp,/conversationBargeIn\?\.stop\(\)/);
assert.match(adminApp,/recognition\.onend=\(\)=>\{recognitionActive=false;if\(conversationOn/);
assert.match(adminApp,/if\(!conversationOn\|\|pending\|\|adminSpeechPending\|\|voiceSubmitted\)return/);

async function monitorRejectsEchoAndDetectsForeground(){
  let now=1000,amplitude=0.006,frame=null,interrupts=0,stops=0,closed=0,heard='';
  const confirmations=[null,'Please stop now'];
  const destination={connect:()=>{}};
  const analyser={fftSize:0,smoothingTimeConstant:0,getFloatTimeDomainData:array=>array.fill(amplitude)};
  const browser={
    navigator:{mediaDevices:{getUserMedia:async constraints=>{
      assert.equal(constraints.audio.echoCancellation,true);
      return {getTracks:()=>[{stop:()=>stops++}]};
    }}},
    AudioContext:class{createMediaStreamSource(){return destination}createBiquadFilter(){return {type:'',frequency:{value:0},connect:()=>{}}}createAnalyser(){return analyser}close(){closed++;return Promise.resolve()}},
    requestAnimationFrame:cb=>{frame=cb;return 1},cancelAnimationFrame:()=>{frame=null}
  };
  const sandbox={window:browser,Date:{now:()=>now},Float32Array,Math,AbortController};
  vm.runInNewContext(read('assets/js/conversation-barge-in.js'),sandbox);
  const monitor=browser.StonefellowBargeIn.create(phrase=>{interrupts++;heard=phrase},browser,async()=>confirmations.shift());
  assert.equal(await monitor.start(),true);
  function tick(energy,count){amplitude=energy;for(let i=0;i<count;i++){now+=40;const callback=frame;assert.ok(callback);callback()}}
  tick(0.006,20);
  tick(0.12,1);tick(0.006,12);
  assert.equal(interrupts,0,'one noise transient must not interrupt playback');
  tick(0.16,9);
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(interrupts,0,'loud playback echo without confirmed speech must never cancel the Agent');
  assert.equal(monitor.active,true,'monitor rearms after unconfirmed acoustic activity');
  now+=4000;
  tick(0.16,9);
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(interrupts,1,'verified independent user speech interrupts playback');
  assert.equal(heard,'Please stop now');
  assert.equal(monitor.active,false);
  assert.equal(stops,2,'microphone must be released while verifying each candidate');
  assert.equal(closed,2,'audio context must be closed while verifying each candidate');
  monitor.stop();assert.equal(interrupts,1,'stopped detector must not interrupt again');
}

async function adminConversationRestartsAndStops(){
  const listeners={},activeClasses=new Set(),attrs={},timers=new Map();let timerId=0,sendCount=0,speechCount=0,starts=0,aborts=0,recognizer;
  const button={disabled:false,classList:{toggle:(name,state)=>state?activeClasses.add(name):activeClasses.delete(name)},setAttribute:(key,value)=>{attrs[key]=value}};
  const input={value:'',style:{},scrollHeight:44,readOnly:false,addEventListener:()=>{}};
  const form={querySelector:()=>({disabled:false}),addEventListener:(name,handler)=>{listeners[name]=handler},setAttribute:()=>{},removeAttribute:()=>{}};
  const status={textContent:''};
  const elements={'#adminChatForm':form,'#adminChatInput':input,'#adminVoiceButton':button,'#adminAgentComposerStatus':status};
  const FakeRecognition=class{
    constructor(){recognizer=this}
    start(){starts++;this.onstart?.()}
    abort(){aborts++;this.onend?.()}
  };
  const sandbox={
    $:selector=>elements[selector],window:{SpeechRecognition:FakeRecognition,StonefellowBargeIn:{create:()=>({start:async()=>true,stop:()=>{}})},SFAdminAgentCanvas:{send:async()=>{sendCount++;return {text:'Private response'}}}},
    app:{view:'dashboard'},stopAdminSpeech:()=>{},speakAdminReply:()=>{speechCount++;sandbox.adminSpeechPending=true;},agent:()=>{},say:()=>{},
    adminSpeechActive:false,adminSpeechPending:false,adminConversationEnabled:false,adminConversationMonitor:null,adminConversationResume:null,
    Math,setTimeout:(callback)=>{const id=++timerId;timers.set(id,callback);return id},clearTimeout:id=>timers.delete(id)
  };
  const flush=()=>{for(const [id,cb] of [...timers]){timers.delete(id);cb()}};
  vm.createContext(sandbox);
  vm.runInContext(extract(adminApp,'bindAgent')+';bindAgent();',sandbox);
  button.onclick();
  assert.equal(starts,1);assert.equal(attrs['aria-pressed'],'true');
  assert.ok(activeClasses.has('active'));
  recognizer.onresult({results:[{0:{transcript:'Show my catalog'},isFinal:true}]});
  recognizer.onresult({results:[{0:{transcript:'Show my catalog'},isFinal:true}]});
  await Promise.resolve();await Promise.resolve();await Promise.resolve();
  assert.equal(sendCount,1,'one final recognition result must produce one Agent turn');
  assert.equal(speechCount,1);
  assert.equal(starts,1,'Agent speech must not be recognized as a new user turn');
  assert.ok(activeClasses.has('active'),'conversation button must stay active during response');
  sandbox.adminSpeechPending=false;
  sandbox.adminConversationResume();flush();
  assert.equal(starts,2,'recognition restarts without a second microphone click');
  button.onclick();flush();
  assert.equal(attrs['aria-pressed'],'false');
  assert.ok(!activeClasses.has('active'));
  assert.equal(starts,2,'stopping conversation prevents automatic restarts');
  assert.ok(aborts>=1);
}

async function publicVoiceResumesAndStaleAudioCannotPlay(){
  let resumes=0,stops=0;
  const context={
    voiceTurnBusy:false,voiceTurnGeneration:0,conversationRestartTimer:null,recognition:{stop:()=>stops++},
    st:{conversationOn:true},showTranscript:()=>{},agent:()=>{},command:async()=>{},
    pendingReply:Promise.resolve(),musicFollowupUntil:0,Date:{now:()=>1000},
    listeningPrompt:()=> 'Listening…',scheduleConversationListen:()=>resumes++,say:async()=>{},clearTimeout:()=>{}
  };
  vm.createContext(context);
  vm.runInContext(extract(publicApp,'submitVoiceTurn')+';globalThis.run=submitVoiceTurn',context);
  await context.run('Hello Stonefellow');
  assert.equal(stops,1);
  assert.equal(context.voiceTurnBusy,false);
  assert.equal(resumes,1,'public microphone must resume after reply completes');

  let completeDownload,constructedAudio=0,released=0;
  const downloaded=new Promise(resolve=>{completeDownload=resolve});
  const stale={
    st:{auth:{authenticated:true,csrf:'token'},voiceAudio:null},
    cfg:{apiBase:'/api'},voiceRepliesEnabled:true,voicePlaybackSequence:5,
    fetch:async()=>({ok:true,blob:async()=>{await downloaded;return {}}}),
    URL:{createObjectURL:()=> 'blob:voice',revokeObjectURL:()=>{released++}},
    Audio:function(){constructedAudio++},loadAuth:async()=>{},
  };
  vm.createContext(stale);
  vm.runInContext(extract(publicApp,'elevenLabsSpeak')+';globalThis.run=elevenLabsSpeak',stale);
  const request=stale.run('Old response',false,5);
  stale.voicePlaybackSequence=6;completeDownload();
  assert.equal(await request,null);
  assert.equal(constructedAudio,0,'delayed Agent audio must never start after an interruption');
  assert.equal(released,1,'discarded voice URL must be released');
}

Promise.resolve().then(monitorRejectsEchoAndDetectsForeground).then(adminConversationRestartsAndStops).then(publicVoiceResumesAndStaleAudioCannotPlay)
  .then(()=>console.log('Section 90L continuous voice, barge-in, echo/noise gate, duplicate prevention, and stop: PASS'))
  .catch(error=>{console.error(error);process.exitCode=1});
