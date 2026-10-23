'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const app=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
const implementation=app.split('\n').find(x=>x.trimStart().startsWith('async function submitVoiceTurn('));
assert.ok(implementation,'Voice-turn implementation should remain present');

async function scenario(stopMidReply){
  const trace=[];
  let resolveCommand,resolveAudio;
  const command=new Promise(resolve=>{resolveCommand=resolve});
  const audio=new Promise(resolve=>{resolveAudio=resolve});
  const sandbox={
    recognition:{stop:()=>trace.push('stop-recognition')},
    clearTimeout:()=>trace.push('clear-timer'),
    conversationRestartTimer:null,
    st:{conversationOn:true},
    command:async()=>{trace.push('command');await command;trace.push('command-complete')},
    pendingReply:audio,
    agent:mode=>trace.push('status-'+mode),
    say:async()=>trace.push('unexpected-error'),
    scheduleConversationListen:()=>trace.push('restart')
  };
  vm.createContext(sandbox);
  vm.runInContext(`let voiceTurnBusy=false;${implementation}\nglobalThis.run=submitVoiceTurn;globalThis.isBusy=()=>voiceTurnBusy`,sandbox);
  const task=sandbox.run('Hello Stonefellow');
  assert.equal(sandbox.isBusy(),true,'Must become busy synchronously');
  assert.equal(trace.filter(x=>x==='command').length,1);
  assert.equal(trace.includes('restart'),false,'Recognition cannot restart before the reply');
  resolveCommand();await new Promise(resolve=>setImmediate(resolve));
  assert.equal(trace.includes('restart'),false,'Recognition must wait for voice/audio completion');
  if(stopMidReply)sandbox.st.conversationOn=false;
  resolveAudio();await task;
  assert.equal(sandbox.isBusy(),false,'Busy gate must be released');
  assert.equal(trace.includes('restart'),!stopMidReply,'Only active conversations resume listening');
  assert.equal(trace.includes('unexpected-error'),false);
}

(async()=>{await scenario(false);console.log('PASS wait for command and speech before listening');await scenario(true);console.log('PASS stop during reply prevents microphone restart')})().catch(err=>{console.error(err);process.exitCode=1});
