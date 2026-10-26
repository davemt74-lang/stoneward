'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');

function fixture(){
  const recognizers=[],timers=new Map();let timerSequence=0;
  class SpeechRecognition {
    constructor(){this.aborted=false;recognizers.push(this)}
    start(){this.started=true}
    abort(){this.aborted=true}
    emit(phrase,final=true){this.onresult?.({results:[{0:{transcript:phrase,confidence:0.99},isFinal:final}]})}
    end(){this.onend?.()}
  }
  const browser={SpeechRecognition,
    setTimeout:(callback,delay)=>{const id=++timerSequence;timers.set(id,{callback,delay});return id},
    clearTimeout:id=>timers.delete(id)};
  vm.runInNewContext(read('assets/js/conversation-barge-in.js'),{window:browser,AbortController});
  const flush=async()=>{await new Promise(resolve=>setImmediate(resolve))};
  const tick=async()=>{const entries=[...timers.values()];timers.clear();for(const {callback} of entries)callback();await flush()};
  return {browser,recognizers,timers,flush,tick};
}

async function shortStopDuringTts(){
  const {browser,recognizers,flush}=fixture();let interruptions=0,last='';
  const monitor=browser.StonefellowBargeIn.create(phrase=>{interruptions++;last=phrase},browser,
    signal=>browser.StonefellowBargeIn.verifyAgentSpeech('Here are the latest tracks and how to pause a song.',browser,signal));
  assert.equal(await monitor.start(),true);
  await flush();
  assert.equal(recognizers.length,1,'recognition must start before any loudness event');
  assert.equal(recognizers[0].continuous,true);
  recognizers[0].emit('stop');await flush();
  assert.equal(interruptions,1,'a short stop must cancel the response once');
  assert.equal(last,'stop');assert.equal(recognizers[0].aborted,true);
  assert.equal(monitor.active,false);
}

async function ignoresAgentEchoButAcceptsAnotherParticipant(){
  const {browser,recognizers,flush,tick,timers}=fixture();let heard=[];
  const reference='Stonefellow is playing the latest album with a warm guitar and slow drums.';
  const monitor=browser.StonefellowBargeIn.create(phrase=>heard.push(phrase),browser,
    signal=>browser.StonefellowBargeIn.verifyAgentSpeech(reference,browser,signal));
  await monitor.start();await flush();
  recognizers[0].emit('Stonefellow is playing the latest album with a warm guitar',true);
  assert.equal(heard.length,0,'Agent speaker echo must never count as a participant');
  recognizers[0].end();await flush();
  assert.equal(timers.size,1,'recognition must rearm if only Agent echo was recognized');
  await tick();assert.equal(recognizers.length,2);
  recognizers[1].emit('Can you play the earlier record instead?',false);
  assert.equal(heard.length,0,'interim user speech must not end the participant sentence');
  recognizers[1].emit('Can you play the earlier record instead?',true);
  await flush();
  assert.equal(heard.length,1);
  assert.equal(heard[0],'Can you play the earlier record instead?');
  assert.equal(monitor.active,false);
}

async function cancellationAndStaleSpeech(){
  const {browser,recognizers,flush,tick,timers}=fixture();let heard=[];
  const monitor=browser.StonefellowBargeIn.create(phrase=>heard.push(phrase),browser,
    signal=>browser.StonefellowBargeIn.verifyAgentSpeech('Let me explain the music.',browser,signal));
  await monitor.start();await flush();
  recognizers[0].emit('Wait, tell me about the producer',false);
  monitor.stop();
  recognizers[0].end();await flush();await tick();
  assert.equal(heard.length,0,'speech events after stop must not start a new turn');
  assert.equal(recognizers.length,1,'stale recognizers must not rearm after stop');
  assert.equal(timers.size,0,'stop must clear outstanding restart timers');
  assert.equal(monitor.active,false);
}

Promise.resolve().then(shortStopDuringTts)
  .then(ignoresAgentEchoButAcceptsAnotherParticipant)
  .then(cancellationAndStaleSpeech)
  .then(()=>console.log('Section 90P: primed stop, room participant, echo exclusion, rearming and cancellation PASS'))
  .catch(error=>{console.error(error);process.exitCode=1});
