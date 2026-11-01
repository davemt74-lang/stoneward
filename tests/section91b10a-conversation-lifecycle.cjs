'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

const source=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
function extract(name){
  const start=source.search(new RegExp('function '+name+'\\('));
  assert.ok(start>=0,'missing '+name);
  const open=source.indexOf('{',start);
  let depth=0;
  for(let i=open;i<source.length;i++){
    if(source[i]==='{')depth++;
    if(source[i]==='}'&&!--depth)return source.slice(start,i+1);
  }
  throw Error('unbalanced '+name);
}

function listenerFixture({pending=false,guard=0,busy=false,conversation=true,recognizer=true}={}){
  const timers=[],cancelled=[];
  const context={
    st:{conversationOn:conversation},recognition:recognizer?{}:null,isIOSVoiceBrowser:false,
    voiceTurnBusy:busy,voicePlaybackPending:pending,conversationRestartTimer:42,
    voiceSpeechGuard:{remaining:()=>guard},
    clearTimeout:id=>cancelled.push(id),
    setTimeout:(fn,delay)=>{timers.push({fn,delay});return timers.length},
    startConversationListen:()=>{}
  };
  vm.createContext(context);
  vm.runInContext(extract('scheduleConversationListen'),context);
  return {context,timers,cancelled};
}

function testRecognitionScheduling(){
  const speaking=listenerFixture({pending:true,guard:Infinity});
  speaking.context.scheduleConversationListen();
  assert.equal(speaking.timers.length,0,'a live spoken response owns the microphone: do not poll it');
  assert.deepEqual(speaking.cancelled,[42],'a pending restart must be cancelled');

  const stuckGuard=listenerFixture({guard:Infinity});
  stuckGuard.context.scheduleConversationListen();
  assert.equal(stuckGuard.timers.length,1);
  assert.equal(stuckGuard.timers[0].delay,500,'unbounded guard should use a finite, throttled retry');

  const cooldown=listenerFixture({guard:1175});
  cooldown.context.scheduleConversationListen(200);
  assert.equal(cooldown.timers[0].delay,1175,'the echo tail must finish before resuming ASR');

  const normal=listenerFixture();
  normal.context.scheduleConversationListen();
  assert.equal(normal.timers[0].delay,320,'normal listening cadence must remain unchanged');

  const ios=listenerFixture();
  ios.context.isIOSVoiceBrowser=true;
  ios.context.scheduleConversationListen();
  assert.equal(ios.timers.length,0,'iPhone must not reopen recognition without a user tap');

  for(const options of [{conversation:false},{recognizer:false},{busy:true}]){
    const inactive=listenerFixture(options);
    inactive.context.scheduleConversationListen();
    assert.equal(inactive.timers.length,0,'never rearm a stopped or busy conversation');
  }
}

async function testTextOnlyConversationSettles(){
  const states=[],transcripts=[],restarts=[];
  const context={
    st:{conversationOn:true,playing:false},speechPilotActive:false,
    voiceRepliesEnabled:false,voiceInteractionStarted:true,voiceTurnBusy:false,
    pendingReply:Promise.resolve(),
    agent:(mode,text)=>states.push({mode,text}),
    popupEntry:()=>{},showTranscript:(text,phase)=>transcripts.push({text,phase}),
    listeningPrompt:()=> 'Listening · speak naturally.',
    scheduleConversationListen:()=>restarts.push('ready'),
    setTimeout:fn=>{fn();return 1},
    agentSpeak:()=>{throw Error('voice-off mode must not synthesize')}
  };
  vm.createContext(context);
  vm.runInContext(extract('say'),context);
  await context.say('Your playlist is ready.');
  assert.equal(states.at(-1).mode,'listening','typed response must restore listening mode when TTS is off');
  assert.equal(transcripts.at(-1).phase,'listening');
  assert.deepEqual(restarts,['ready'],'recognizer should rearm once');

  context.voiceTurnBusy=true;
  states.length=0;restarts.length=0;
  await context.say('The pending voice turn is finishing.');
  assert.equal(states.at(-1).mode,'responding','spoken-turn cleanup, not say(), owns the busy state');
  assert.deepEqual(restarts,[],'no recognizer restart before spoken-turn cleanup');
}

Promise.resolve().then(testRecognitionScheduling)
  .then(testTextOnlyConversationSettles)
  .then(()=>console.log('Section 91B10A: finite recognition scheduling, active-speech gate and text-only conversation recovery PASS'))
  .catch(error=>{console.error(error);process.exitCode=1});
