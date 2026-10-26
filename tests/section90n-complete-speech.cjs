'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const publicApp=read('assets/js/app.js'),adminApp=read('admin/assets/admin.js');
function extract(source,name){
  const at=source.search(new RegExp('(?:async )?function '+name+'\\('));
  assert.ok(at>=0,`${name} missing`);const start=source.indexOf('{',at);let depth=0;
  for(let i=start;i<source.length;i++){
    if(source[i]==='{')depth++;
    if(source[i]==='}'&&!--depth)return source.slice(at,i+1);
  }
  throw Error('Unbalanced function '+name);
}
const speechModule={window:{}};
vm.runInNewContext(read('assets/js/speech-segments.js'),speechModule);
const split=speechModule.window.StonefellowSpeechSegments.split;
const full=Array.from({length:130},(_,i)=>`Sentence ${i+1} has unique spoken content and a clear ending.`).join(' ');
const normalized=value=>value.replace(/\s+/g,' ').trim();
const segments=split(full,180);
assert.ok(segments.length>30);
assert.ok(segments.every(segment=>segment.length<=180));
assert.equal(normalized(segments.join(' ')),normalized(full),'segmentation retains every word and ending');
assert.equal(split('Wait. This is next. More words follow.',20).join(' '),'Wait. This is next. More words follow.');
const hugeWord='a'.repeat(1700)+' 😀 '+'z'.repeat(145);
assert.equal(normalized(split(hugeWord,1400).join(' ')).replaceAll(' ',''),hugeWord.replaceAll(' ',''));

async function browserSpeakCompletesOrInterrupts(){
  const pending=[],spoken=[];
  const synth={cancel:()=>{},getVoices:()=>[{lang:'en-US',localService:true}],speak:utterance=>{spoken.push(utterance.text);pending.push(utterance)}};
  const context={window:{speechSynthesis:synth,SpeechSynthesisUtterance:class{constructor(text){this.text=text}}},
    StonefellowSpeechSegments:{split},voiceRepliesEnabled:true,voicePlaybackSequence:2,speechFallbackResolve:null,
    agent:()=>{}};
  vm.createContext(context);
  vm.runInContext(extract(publicApp,'speakWithDeviceVoice')+';globalThis.run=speakWithDeviceVoice',context);
  const running=context.run(full,2);
  await Promise.resolve();
  assert.equal(spoken.length,1,'first short utterance starts without cutting response');
  for(let i=0;i<segments.length;i++){
    const utterance=pending.shift();assert.ok(utterance,`segment ${i+1} plays`);
    utterance.onstart?.();utterance.onend?.();
  }
  assert.equal(await running,true);
  assert.equal(normalized(spoken.join(' ')),normalized(full),'browser voice reads whole response');
  const interrupted=context.run(full,2);
  await Promise.resolve();
  const count=spoken.length;
  context.voicePlaybackSequence++;
  context.speechFallbackResolve(null);
  pending.pop()?.onend?.();
  assert.equal(await interrupted,null);
  assert.equal(spoken.length,count,'interruption cannot queue the rest');
}

async function elevenLabsCompletesAndDoesNotReplayOnPartialFailure(){
  async function scenario(failSecond){
    const requests=[],played=[],revoked=[];
    const context={StonefellowSpeechSegments:{split},st:{auth:{authenticated:true,csrf:'token'},voiceAudio:null},
      cfg:{apiBase:'/api'},voiceRepliesEnabled:true,voicePlaybackSequence:4,
      fetch:async(_url,options)=>{const content=JSON.parse(options.body).text;requests.push(content);
        return requests.length===2&&failSecond?{ok:false,status:503}:{ok:true,blob:async()=>({})}},
      URL:{createObjectURL:()=> 'blob:voice-'+requests.length,revokeObjectURL:url=>revoked.push(url)},
      Audio:class{constructor(url){this.url=url}play(){played.push(this.url);queueMicrotask(()=>this.onended?.());return Promise.resolve()}pause(){this.onpause?.()}},
      agent:()=>{}};
    vm.createContext(context);
    vm.runInContext(extract(publicApp,'elevenLabsSpeak')+';globalThis.run=elevenLabsSpeak',context);
    const result=await context.run(full,false,4);
    if(!failSecond){
      assert.equal(result,true);
      assert.equal(normalized(requests.join(' ')),normalized(full));
      assert.equal(played.length,requests.length);
      assert.equal(revoked.length,played.length);
      assert.ok(requests.every(segment=>segment.length<=1400),'each API call stays inside provider limit');
    }else{
      assert.equal(requests.length,2);
      assert.equal(played.length,1);
      assert.ok(result&&typeof result.remaining==='string');
      assert.equal(normalized([requests[0],result.remaining].join(' ')),normalized(full),'only unspoken content falls back');
    }
  }
  await scenario(false);await scenario(true);
}

async function adminSpeakCompletesOrInterrupts(){
  const pending=[],spoken=[];
  const synth={cancel:()=>{},getVoices:()=>[{lang:'en-US',localService:true}],speak:utterance=>{pending.push(utterance);spoken.push(utterance.text)}};
  const context={window:{speechSynthesis:synth,SpeechSynthesisUtterance:class{constructor(text){this.text=text}}},
    StonefellowSpeechSegments:{split},adminSpeechEnabled:true,adminSpeechActive:false,
    adminSpeechResolve:null,adminSpeechPending:false,adminSpeechSequence:0,
    adminConversationMonitor:{start:async()=>true,stop:()=>{}},adminConversationResume:()=>{},adminConversationEnabled:true,
    agent:()=>{},$:()=>({textContent:''})};
  vm.createContext(context);
  vm.runInContext(extract(adminApp,'stopAdminSpeech')+';'+extract(adminApp,'speakAdminReply')+';globalThis.run=speakAdminReply;globalThis.stop=stopAdminSpeech',context);
  const running=context.run(full);
  await Promise.resolve();
  assert.equal(spoken.length,1);
  const count=split(full,180).length;
  for(let i=0;i<count;i++){
    const utterance=pending.shift();assert.ok(utterance);utterance.onstart?.();utterance.onend?.();
  }
  assert.equal(await running,true);
  assert.equal(normalized(spoken.join(' ')),normalized(full),'Admin voice reads all content');
  assert.equal(context.adminSpeechPending,false,'Admin keeps mic paused until last segment ends');
  const interrupted=context.run(full);
  await Promise.resolve();
  const beforeStop=spoken.length;
  context.stop();pending.pop()?.onend?.();
  assert.equal(await interrupted,false);
  assert.equal(spoken.length,beforeStop,'Admin interruption cancels remaining segments');
}

async function main(){
  assert.match(read('stonefellow-v120.php'),/speech-segments\.js/);
  assert.match(read('admin/index.php'),/speech-segments\.js/);
  assert.match(read('service-worker.js'),/speech-segments\.js/);
  assert.doesNotMatch(extract(publicApp,'elevenLabsSpeak'),/\.slice\(0,1800\)/);
  assert.doesNotMatch(extract(publicApp,'speakWithDeviceVoice'),/\.slice\(0,1600\)/);
  assert.doesNotMatch(extract(adminApp,'speakAdminReply'),/\.slice\(0,1600\)/);
  await browserSpeakCompletesOrInterrupts();
  await elevenLabsCompletesAndDoesNotReplayOnPartialFailure();
  await adminSpeakCompletesOrInterrupts();
  console.log('Section 90N: full-content speech, sentence segmentation, partial-provider fallback and public/Admin interruption PASS');
}
main().catch(error=>{console.error(error);process.exitCode=1});
