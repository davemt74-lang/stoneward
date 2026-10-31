#!/usr/bin/env node
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const read=path=>fs.readFileSync(path,'utf8');
const publicApp=read('assets/js/app.js');
const adminApp=read('admin/assets/admin.js');
const account=read('assets/js/account-voice-engine.js');

function functionSource(source,name,indent){
  const start=`${' '.repeat(indent)}function ${name}(){`;
  const first=source.indexOf(start);
  assert.notEqual(first,-1,`${name} must exist`);
  const last=source.indexOf(`\n${' '.repeat(indent)}}`,first);
  assert.notEqual(last,-1,`${name} must close`);
  return source.slice(first,last+indent+2);
}

function publicGreeting({enabled=true,preference='on',armed=true,delivered=false}={}){
  const entries=[],spoken=[],transcripts=[],storage={getItem:key=>key==='stonefellow.voice.greeting.v1'?preference:null};
  const sandbox={voiceGreetingArmed:armed,voiceGreetingDelivered:delivered,voiceRepliesEnabled:enabled,localStorage:storage,
    popupEntry:(role,text)=>entries.push({role,text}),showTranscript:(text,phase)=>transcripts.push({text,phase}),agentSpeak:text=>spoken.push(text)};
  vm.createContext(sandbox);
  vm.runInContext(functionSource(publicApp,'greetAfterMicrophoneReady',2),sandbox);
  const invoke=()=>vm.runInContext('greetAfterMicrophoneReady()',sandbox);
  return {sandbox,entries,spoken,transcripts,invoke};
}

function adminGreeting({enabled=true,preference='on',armed=true,delivered=false}={}){
  const spoken=[],status={textContent:''},microphone={aborts:0,abort(){this.aborts++}};
  const sandbox={voiceGreetingArmed:armed,voiceGreetingDelivered:delivered,adminSpeechEnabled:enabled,
    localStorage:{getItem:key=>key==='stonefellow.voice.greeting.v1'?preference:null},
    $:selector=>selector==='#adminAgentComposerStatus'?status:null,
    recognition:microphone,recognitionActive:true,speakAdminReply:text=>spoken.push(text)};
  vm.createContext(sandbox);
  vm.runInContext(functionSource(adminApp,'greetAdminAfterMicrophoneReady',4),sandbox);
  const invoke=()=>vm.runInContext('greetAdminAfterMicrophoneReady()',sandbox);
  return {sandbox,status,microphone,spoken,invoke};
}

{
  const t=publicGreeting();
  assert.equal(t.invoke(),true,'first authorized microphone start greets');
  assert.equal(t.spoken.length,1,'greeting speaks once');
  assert.equal(t.entries.length,1,'public transcript includes greeting');
  assert.equal(t.transcripts[0].phase,'responding');
  assert.equal(t.invoke(),false,'recognition restarts never repeat greeting');
  t.sandbox.voiceGreetingArmed=true;
  assert.equal(t.invoke(),false,'a new conversation on the same page does not repeat greeting');
  assert.equal(t.spoken.length,1);
}
for(const options of [{armed:false},{delivered:true},{enabled:false},{preference:'off'}]){
  const t=publicGreeting(options);
  assert.equal(t.invoke(),false,`public startup should respect ${JSON.stringify(options)}`);
  assert.equal(t.spoken.length,0);
}
{
  const t=adminGreeting();
  assert.equal(t.invoke(),true);
  assert.equal(t.microphone.aborts,1,'Admin must stop speech recognition before voice playback');
  assert.equal(t.spoken.length,1);
  assert.match(t.status.textContent,/Admin Agent is ready/);
  assert.equal(t.invoke(),false);
  assert.equal(t.spoken.length,1);
}
for(const options of [{armed:false},{delivered:true},{enabled:false},{preference:'off'}]){
  const t=adminGreeting(options);
  assert.equal(t.invoke(),false,`Admin startup should respect ${JSON.stringify(options)}`);
  assert.equal(t.spoken.length,0);
  assert.equal(t.microphone.aborts,0);
}

assert.match(publicApp,/voiceGreetingArmed=true;recognitionNetworkFailures=0/,'only a click-initiated public conversation arms the greeting');
assert.match(publicApp,/recognition\.onstart=\(\)=>\{[^\n]*greetAfterMicrophoneReady\(\)/,'public mic onstart triggers greeting after permission');
assert.match(publicApp,/function stopConversation\(\)\{voiceGreetingArmed=false/,'stopping disarms the public greeting');
assert.match(adminApp,/voiceGreetingArmed=true;networkFailures=0/,'Admin arming is click-initiated');
assert.match(adminApp,/if\(greetAdminAfterMicrophoneReady\(\)\)return/,'Admin greeting occurs after microphone readiness');
assert.match(adminApp,/adminConversationEnabled=false;voiceGreetingArmed=false/,'stopping disarms the Admin greeting');
assert.match(account,/data-voice-greeting-choice/,'Account Settings exposes opt-out');
assert.match(account,/greeting\.checked=localStorage\.getItem\(greetingKey\)!=='off'/);
assert.match(account,/localStorage\.setItem\(greetingKey,greeting\.checked\?'on':'off'\)/);
assert.match(account,/Speech Engine pilot manages its own greetings/,'the pilot is not greeted twice');
assert.match(publicApp,/playerToggle\.onclick=toggle/,'existing music player unchanged');
assert.match(adminApp,/musicVolume\.oninput=/,'Admin audio volume unchanged');
assert.match(adminApp,/voices=synthesis\.getVoices\(\)\.filter\(voice=>voice\.localService===true\)/,'Admin speech remains strictly on-device');
console.log('Section 91B10C: permission-gated first greeting, opt-out, privacy and player regression PASS');
