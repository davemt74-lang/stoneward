'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const app=read('assets/js/app.js'),admin=read('admin/assets/admin.js');
function extract(source,name){
  const at=source.search(new RegExp('(?:async )?function '+name+'\\('));
  assert.ok(at>=0,name+' missing');
  const open=source.indexOf('{',at);let depth=0;
  for(let i=open;i<source.length;i++){
    if(source[i]==='{')depth++;
    if(source[i]==='}'&&!--depth)return source.slice(at,i+1);
  }
  throw Error('Unbalanced '+name);
}
function speechFixture(){
  const instances=[];
  class Recognition{
    constructor(){this.aborted=false;instances.push(this)}
    start(){this.started=true}
    abort(){this.aborted=true}
    emit(transcript,final=true,confidence=0.95){
      this.onresult?.({results:[{0:{transcript,confidence},isFinal:final}]});
    }
  }
  const browser={SpeechRecognition:Recognition};
  const sandbox={window:browser,AbortController};
  vm.runInNewContext(read('assets/js/conversation-barge-in.js'),sandbox);
  return {browser,instances};
}
async function verificationRequiresSpeechNotEcho(){
  const {browser,instances}=speechFixture();
  const reference='Stonefellow has a new song playing. Let me tell you about the new song and its production.';
  const result=browser.StonefellowBargeIn.verifyAgentSpeech(reference,browser);
  assert.equal(instances.length,1);
  assert.equal(instances[0].started,true);
  let settled=false;
  result.then(()=>{settled=true});
  instances[0].emit('Let me tell you about the new song',true);
  await Promise.resolve();
  assert.equal(settled,false,'Agent words leaking into the mic cannot stop its own speech');
  instances[0].emit('Tell you production',true);
  await Promise.resolve();
  assert.equal(settled,false,'ASR dropping words from an Agent sentence still counts as echo');
  instances[0].emit('Actually please go back to the previous',false);
  await Promise.resolve();
  assert.equal(settled,false,'interim words cannot truncate the user interruption');
  instances[0].emit('Actually please go back to the previous track',true);
  assert.equal(await result,'Actually please go back to the previous track');
  assert.equal(instances[0].aborted,true,'successful recognition releases verification');
  const controller=new AbortController();
  const cancelled=browser.StonefellowBargeIn.verifyAgentSpeech(reference,browser,controller.signal);
  controller.abort();
  assert.equal(await cancelled,null,'stopping conversation cancels pending verification');
  assert.equal(instances[1].aborted,true);
  const ended=browser.StonefellowBargeIn.verifyAgentSpeech(reference,browser);
  instances[2].onend();
  assert.equal(await ended,null,'recognition ending without user content cannot interrupt');
  const unsupported=browser.StonefellowBargeIn.verifyAgentSpeech(reference,{},null);
  assert.equal(await unsupported,null,'unsupported recognition fails closed');
}
async function overlappingBargeTurnCannotBeOverwritten(){
  let firstResolve,secondResolve,resumes=0,listenMessages=[];
  const context={
    voiceTurnGeneration:0,voiceTurnBusy:false,conversationRestartTimer:null,
    recognition:{stop:()=>{}},st:{conversationOn:true},clearTimeout:()=>{},
    command:message=>new Promise(resolve=>{
      if(message==='first')firstResolve=resolve;else secondResolve=resolve;
    }),
    pendingReply:Promise.resolve(),showTranscript:()=>{},
    agent:(state)=>listenMessages.push(state),Date:{now:()=>1000},
    listeningPrompt:()=> 'Listening',scheduleConversationListen:()=>resumes++,say:async()=>{}
  };
  vm.createContext(context);
  vm.runInContext(extract(app,'submitVoiceTurn')+';globalThis.submit=submitVoiceTurn',context);
  const first=context.submit('first');
  const second=context.submit('second');
  firstResolve();await first;
  assert.equal(context.voiceTurnBusy,true,'old interrupted turn cannot unblock recognition during new reply');
  assert.equal(resumes,0,'old reply cannot prematurely restart microphone');
  secondResolve();await second;
  assert.equal(context.voiceTurnBusy,false);
  assert.equal(resumes,1,'latest spoken reply owns the sole listening restart');
}
async function wiring(){
  assert.match(app,/StonefellowBargeIn\.verifyAgentSpeech\(voicePlaybackText,window,signal\)/);
  assert.match(app,/interruptVoicePlayback\(\);\s*void submitVoiceTurn\(spoken\)/);
  assert.match(admin,/StonefellowBargeIn\.verifyAgentSpeech\(adminSpeechText,window,signal\)/);
  assert.match(admin,/stopAdminSpeech\(\);\s*void submit\(spoken\)/);
  assert.match(admin,/async function submit\(voiceOverride=null\)/);
  assert.match(read('service-worker.js'),/conversation-barge-in\.js/);
}
Promise.resolve().then(verificationRequiresSpeechNotEcho).then(overlappingBargeTurnCannotBeOverwritten)
  .then(wiring).then(()=>console.log('Section 90O: echo never interrupts; confirmed user speech, cancellation and overlapping turns PASS'))
  .catch(error=>{console.error(error);process.exitCode=1});
