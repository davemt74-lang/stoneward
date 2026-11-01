'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const read=name=>fs.readFileSync(path.join(__dirname,'..',name),'utf8');
const app=read('assets/js/app.js'),settings=read('assets/js/account-voice-engine.js'),page=read('stonefellow-v120.php');
let passed=0;
function check(label,run){run();passed++;console.log('91B13D PASS '+label)}
function extract(source,name){
  const functionAt=source.indexOf('function '+name+'(');
  assert.ok(functionAt>=0,name+' present');
  const start=source.slice(functionAt-6,functionAt)==='async '?functionAt-6:functionAt;
  const begin=source.indexOf('{',start);
  let depth=0;
  for(let i=begin;i<source.length;i++){
    if(source[i]==='{')depth++;
    if(source[i]==='}'&&!--depth)return source.slice(start,i+1);
  }
  throw new Error('Unbalanced '+name);
}
function voiceHarness(ios,engine){
  let originalStarts=0,monitorPrepare=0,speechStarts=0,remoteLookups=0,stopCount=0;
  const context={
    isIOSVoiceBrowser:ios,voiceEngineKey:'stonefellow.voice.engine.v1.7',
    localStorage:{getItem:key=>key==='stonefellow.voice.engine.v1.7'?engine:null},
    st:{conversationOn:false},voiceInteractionStarted:false,speechPilotActive:false,voiceGreetingArmed:false,recognitionNetworkFailures:0,
    window:{STONEFELLOW_VOICE_ENGINE_BOOTSTRAP:engine,StonefellowSpeechEngine:{
      async isSelected(){remoteLookups++;throw Error('This must not run in the click handler')},
      start(){speechStarts++;return Promise.resolve()}
    }},
    recognition:{},showTranscript(){},agent(){},updateConversationButton(){},syncConversationMusic(){},
    conversationBargeIn:{prepare(){monitorPrepare++;return Promise.resolve()}},
    startConversationListen(){originalStarts++},stopConversation(){stopCount++}
  };
  vm.createContext(context);
  vm.runInContext(extract(app,'handleVoiceClick')+';globalThis.clickMic=handleVoiceClick',context);
  return {context,counts:()=>({originalStarts,monitorPrepare,speechStarts,remoteLookups,stopCount})};
}
check('iPhone original recognition starts synchronously and alone',()=>{
  const h=voiceHarness(true,'legacy');
  h.context.clickMic();
  assert.deepEqual(h.counts(),{originalStarts:1,monitorPrepare:0,speechStarts:0,remoteLookups:0,stopCount:0});
});
check('Desktop original recognition does not open a redundant interruption microphone',()=>{
  const h=voiceHarness(false,'legacy');
  h.context.clickMic();
  assert.deepEqual(h.counts(),{originalStarts:1,monitorPrepare:0,speechStarts:0,remoteLookups:0,stopCount:0});
});
check('iPhone Speech Engine starts before any online preference lookup',()=>{
  const h=voiceHarness(true,'speech_engine');
  h.context.clickMic();
  assert.equal(h.counts().speechStarts,1);
  assert.equal(h.counts().remoteLookups,0);
  assert.equal(h.counts().originalStarts,0);
});
check('Second tap stops conversation',()=>{
  const h=voiceHarness(true,'legacy');
  h.context.st.conversationOn=true;h.context.clickMic();
  assert.equal(h.counts().stopCount,1);
  assert.equal(h.counts().originalStarts,0);
});
check('Account preference is warmed ahead of mic clicks',()=>{
  assert.match(settings,/loadEnginePreference\(\)\.catch\(\(\)=>\{\}\)/);
  assert.match(settings,/rememberEngine\(result\.speech_engine\)/);
  assert.ok(app.includes('window.STONEFELLOW_VOICE_ENGINE_BOOTSTRAP||localStorage.getItem(voiceEngineKey)'));
  assert.ok(settings.includes('window.STONEFELLOW_VOICE_ENGINE_BOOTSTRAP=engine'));
  assert.match(page,/sf_speech_engine_public_state/);
  assert.doesNotMatch(app,/await window\.StonefellowSpeechEngine\?\.isSelected/);
});
check('Neither iPhone nor desktop starts competing microphone monitoring during Agent speech',()=>{
  assert.doesNotMatch(app,/conversationBargeIn\?\.start\(\)/);
  assert.doesNotMatch(app,/conversationBargeIn\?\.prepare\(\)/);
  assert.match(app,/conversationBargeIn\?\.stop\(\)/);
});
function speechError(error,ios){
  let errorText='',stops=0;
  const begin=app.indexOf('recognition.onerror=e=>{');
  assert.ok(begin>0,'error handler exists');
  const end=app.indexOf('    recognition.onend=',begin);
  assert.ok(end>begin,'error handler boundary');
  const context={
    recognition:{},recognitionActive:true,st:{conversationOn:true},voiceTurnBusy:false,
    isIOSVoiceBrowser:ios,
    stopConversation(){stops++},setVoiceInputActive(){},showTranscript(text){errorText=text},
    scheduleConversationListen(){},recognitionNetworkFailures:0,Math
  };
  vm.createContext(context);
  vm.runInContext(app.slice(begin,end),context);
  context.recognition.onerror({error});
  return {errorText,stops};
}
check('Speech service failure is not mislabeled microphone permission denial',()=>{
  const result=speechError('service-not-allowed',true);
  assert.equal(result.stops,1);
  assert.match(result.errorText,/Safari speech recognition is unavailable/);
  assert.match(result.errorText,/Microphone access may already be allowed/);
  assert.doesNotMatch(result.errorText,/Microphone permission denied/);
});
check('Speech input refusal explains ambiguity on iPhone',()=>{
  const result=speechError('not-allowed',true);
  assert.equal(result.stops,1);
  assert.match(result.errorText,/does not necessarily mean/);
});
check('Audio capture failure gives actionable iPhone recovery',()=>{
  const result=speechError('audio-capture',true);
  assert.match(result.errorText,/Close other apps using the microphone/);
});
console.log('Section 91B13D mobile Safari microphone: '+passed+' passed');
