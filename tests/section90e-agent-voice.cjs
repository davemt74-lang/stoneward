'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const publicApp=read('assets/js/app.js');
const adminApp=read('admin/assets/admin.js');
const adminCanvas=read('admin/assets/agent-canvas.js');
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
assert.match(read('stonefellow-v120.php'),/id="agentVoiceReplyToggle"/);
assert.match(publicApp,/voiceInteractionStarted=true;command\(input\.value\)/);
assert.match(publicApp,/voiceRepliesEnabled=localStorage\.getItem\('stonefellow\.voice\.replies\.v1'\)!=='off'/);
assert.match(publicApp,/if\(voiceReplyToggle\)voiceReplyToggle\.onclick=/);
assert.match(publicApp,/if\(\(st\.conversationOn\|\|voicePlaybackPending\)\&\&!audio\.paused\)/);
assert.match(adminCanvas,/id="adminAgentSpeechToggle"/);
assert.match(adminCanvas,/toggleAdminSpeech/);
assert.match(adminApp,/if\(response\.text\)void speakAdminReply\(response\.text\)/);
assert.match(read('admin/assets/agent-canvas.css'),/\[data-mode="speaking"\]/);

async function testTypedReply(enabled){
  let spoken=0;
  const sandbox={st:{conversationOn:false,playing:false},voiceRepliesEnabled:enabled,voiceInteractionStarted:true,Promise,setTimeout:(fn)=>{fn();return 1},agent:()=>{},popupEntry:()=>{},agentSpeak:async()=>{spoken++;return true}};
  vm.createContext(sandbox);
  vm.runInContext('let pendingReply;'+extract(publicApp,'say')+';globalThis.sayTest=say',sandbox);
  await sandbox.sayTest('Reply to typed prompt');
  assert.equal(spoken,enabled?1:0,'typed replies must honor the Voice On/Off setting');
}
async function testAdminPrivateVoice(){
  const local={lang:'en-US',localService:true},remote={lang:'en-US',localService:false};
  const spoken=[],states=[];
  const synthesis={getVoices:()=>[remote,local],cancel:()=>{},speak:utterance=>{spoken.push(utterance);utterance.onstart();utterance.onend()}};
  const sandbox={window:{speechSynthesis:synthesis,SpeechSynthesisUtterance:function(text){this.text=text}},adminSpeechEnabled:true,adminSpeechActive:false,adminSpeechResolve:null,agent:mode=>states.push(mode),$:()=>({textContent:''}),setTimeout};
  vm.createContext(sandbox);
  vm.runInContext(extract(adminApp,'stopAdminSpeech')+';'+extract(adminApp,'speakAdminReply')+';globalThis.speakTest=speakAdminReply',sandbox);
  assert.equal(await sandbox.speakTest('Private CRM reply'),true);
  assert.equal(spoken.length,1);
  assert.equal(spoken[0].voice,local,'Admin must use only on-device voices');
  assert.ok(states.includes('speaking'));
  sandbox.adminSpeechEnabled=false;
  await sandbox.speakTest('Muted reply');
  assert.equal(spoken.length,1,'Admin voice off must suppress playback');
}
Promise.all([testTypedReply(true),testTypedReply(false),testAdminPrivateVoice()]).then(()=>{
  console.log('Section 90E typed prompt voice, mute, Admin local-only speech and companion states: PASS');
}).catch(error=>{console.error(error);process.exitCode=1});
