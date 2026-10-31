'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const read=p=>fs.readFileSync(path.join(__dirname,'..',p),'utf8');
const publicApp=read('assets/js/app.js'),adminApp=read('admin/assets/admin.js');
function extract(source,name){
  const start=source.search(new RegExp('function '+name+'\\('));
  assert.ok(start>=0,'missing '+name);
  const open=source.indexOf('{',start);let depth=0;
  for(let i=open;i<source.length;i++){
    if(source[i]==='{')depth++;
    if(source[i]==='}'&&!--depth)return source.slice(start,i+1);
  }
  throw Error('Unbalanced '+name);
}
const sharedKey='stonefellow.voice.replies.v1';

function testSingleControls(){
  const pub=read('stonefellow-v120.php'),admin=read('admin/index.php'),canvas=read('admin/assets/agent-canvas.js');
  assert.equal((pub.match(/id="agentVoiceReplyToggle"/g)||[]).length,1,'public chat has one voice-output toggle');
  assert.equal((admin.match(/id="adminChatPopupVoice"/g)||[]).length,1,'Admin chat has one voice-output toggle');
  assert.doesNotMatch(canvas,/adminAgentSpeechToggle/,'full Admin chat does not duplicate it');
  assert.match(pub,/id="voiceButton"/,'public microphone remains independent');
  assert.match(admin,/id="adminVoiceButton"/,'Admin microphone remains independent');
  assert.match(admin,/id="adminAgentSpeechStop"/,'existing interruption control remains independent');
}

function testSavedPreferencePriority(){
  const start=adminApp.indexOf('  const sharedVoicePreference=');
  const end=adminApp.indexOf('  let adminSpeechActive=',start);
  assert.ok(start>=0&&end>start);
  function readState(shared,legacy){
    const values=new Map();if(shared!==null)values.set(sharedKey,shared);if(legacy!==null)values.set('stonefellow.admin.voice.replies.v1',legacy);
    const storage={getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value)};
    const sandbox={localStorage:storage};vm.createContext(sandbox);
    vm.runInContext(adminApp.slice(start,end)+';globalThis.initial=adminSpeechEnabled;',sandbox);
    return {enabled:sandbox.initial,value:values.get(sharedKey)};
  }
  assert.deepEqual(readState('off','on'),{enabled:false,value:'off'},'shared preference takes priority over legacy Admin value');
  assert.deepEqual(readState(null,'off'),{enabled:false,value:'off'},'existing Admin mute migrates on first use');
  assert.deepEqual(readState(null,null),{enabled:true,value:undefined},'fresh accounts preserve the voice-on default');
}

function testPublicSync(){
  const saved=[],events=[];const sandbox={voiceRepliesEnabled:true,
    localStorage:{setItem:(k,v)=>saved.push([k,v])},
    interruptVoicePlayback:()=>events.push('stop'),updateVoiceReplyToggle:()=>events.push('render')};
  vm.createContext(sandbox);
  vm.runInContext(extract(publicApp,'setVoiceRepliesEnabled'),sandbox);
  sandbox.setVoiceRepliesEnabled(false);
  assert.deepEqual(saved,[[sharedKey,'off']]);
  assert.deepEqual(events,['stop','render'],'muting immediately cancels only speech output');
  sandbox.setVoiceRepliesEnabled(true,false);
  assert.deepEqual(saved,[[sharedKey,'off']],'cross-tab synchronization does not write storage again');
  assert.equal(events.at(-1),'render');
  assert.match(publicApp,/addEventListener\('storage',event=>/);
}

function testAdminSync(){
  const saved=[],events=[];const sandbox={adminSpeechEnabled:true,
    localStorage:{setItem:(k,v)=>saved.push([k,v])},
    stopAdminSpeech:()=>events.push('stop'),updateAdminVoiceControl:()=>events.push('render')};
  vm.createContext(sandbox);
  vm.runInContext(extract(adminApp,'setAdminSpeechEnabled'),sandbox);
  sandbox.setAdminSpeechEnabled(false);
  assert.deepEqual(saved,[[sharedKey,'off']]);
  assert.deepEqual(events,['stop','render'],'Admin mute cancels private on-device speech');
  sandbox.setAdminSpeechEnabled(true,false);
  assert.equal(saved.length,1,'Admin cross-tab update must not write storage again');
  assert.equal(events.at(-1),'render');
  assert.match(adminApp,/addEventListener\?\.\('storage',event=>/);
  assert.match(adminApp,/voice\.localService===true/,'Admin speech remains local only');
}

testSingleControls();testSavedPreferencePriority();testPublicSync();testAdminSync();
console.log('Section 91B10B: one toggle per context, shared owner preference, legacy migration, cross-tab mute and privacy PASS');
