'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const app=read('assets/js/app.js'),page=read('stonefellow-v120.php'),css=read('assets/css/site.css');

function functionSource(name,asynchronous=false){
  const key=(asynchronous?'async ':'')+'function '+name+'(';
  const start=app.indexOf(key);
  assert.ok(start>=0,`Expected ${name} function`);
  const opening=app.indexOf('{',start);
  let depth=0;
  for(let i=opening;i<app.length;i++){
    if(app[i]==='{')depth++;
    if(app[i]==='}'&&!--depth)return app.slice(start,i+1);
  }
  throw new Error('Unbalanced function '+name);
}
const pending=[];
function check(name,fn){const result=fn();if(result&&typeof result.then==='function'){pending.push(result.then(()=>console.log('PASS '+name)));return}console.log('PASS '+name)}

check('Floating Agent exists outside route canvas and above the chat footer',()=>{
  assert.match(page,/<main id="mainContent"[\s\S]*?<\/main>\s*<!--[\s\S]*?-->\s*<section class="agent-stage"/);
  assert.match(page,/id="agentInterrupt"[\s\S]*?hidden/);
  assert.match(css,/\.chat-footer\{z-index:80\}/);
  assert.match(css,/body>\.agent-stage[^\n]*z-index:90/);
  assert.match(css,/body\.agent-chat-open>\.agent-stage[^\n]*var\(--sf-agent-popup-rise/);
  assert.ok(app.includes('dockObserver.observe(chatFooter)'));
  assert.ok(app.includes("document.body.classList.toggle('artist-home-chat-hidden',home.chat_bar_enabled===false)"));
  assert.ok(app.includes("if(document.body.classList.contains('artist-home-chat-hidden'))"));
});

check('Music is ducked, remains playing and original loudness is restored',()=>{
  const sandbox={audio:{paused:false,volume:.78},st:{conversationOn:true},voicePlaybackPending:false,musicOriginalVolume:null};
  vm.createContext(sandbox);
  vm.runInContext(functionSource('syncConversationMusic')+';globalThis.run=syncConversationMusic',sandbox);
  sandbox.run();assert.equal(sandbox.audio.volume,.4);
  sandbox.voicePlaybackPending=true;sandbox.run();assert.equal(sandbox.audio.volume,.16);
  sandbox.voicePlaybackPending=false;sandbox.run();assert.equal(sandbox.audio.volume,.4);
  sandbox.st.conversationOn=false;sandbox.run();assert.equal(sandbox.audio.volume,.78);
  assert.equal(sandbox.musicOriginalVolume,null);
});

check('Music wake phrase protects against lyrics while natural follow-ups work',()=>{
  const phrase=app.match(/^  const musicWakePhrase=(.*);$/m)?.[1];
  assert.ok(phrase);
  const turns=[],hints=[];
  const sandbox={st:{playing:true},audio:{paused:false},Date:{now:()=>100000},showTranscript:(message)=>hints.push(message),submitVoiceTurn:text=>turns.push(text)};
  vm.createContext(sandbox);
  vm.runInContext('const musicWakePhrase='+phrase+';let musicFollowupUntil=0;'+functionSource('submitRecognizedVoiceTurn')+';globalThis.run=submitRecognizedVoiceTurn;globalThis.followup=until=>{musicFollowupUntil=until}',sandbox);
  sandbox.run('I keep singing this line');assert.equal(turns.length,0);assert.match(hints.at(-1),/Stonefellow/);
  sandbox.run('Hey Stonefellow, what track is playing?');assert.deepEqual(turns,['what track is playing?']);
  sandbox.followup(118000);sandbox.run('Who wrote it?');assert.deepEqual(turns,['what track is playing?','Who wrote it?']);
  sandbox.followup(0);sandbox.run('Stonefellow');assert.equal(turns.length,2);assert.match(hints.at(-1),/listening/);
  sandbox.audio.paused=true;sandbox.run('Play something else');assert.equal(turns.at(-1),'Play something else');
});

check('ElevenLabs preferred; local system voice is fallback when unavailable',async()=>{
  let localCalls=0,remoteResult=false;
  const sandbox={st:{conversationOn:true,playing:false},voiceRepliesEnabled:true,voiceInteractionStarted:true,voicePlaybackPending:false,voicePlaybackSequence:0,recognition:null,recognitionActive:false,conversationRestartTimer:null,voiceTurnBusy:false,isIOSVoiceBrowser:false,agentInterrupt:{hidden:false},conversationBargeIn:{start:async()=>true,stop:()=>{}},clearTimeout:()=>{},scheduleConversationListen:()=>{},elevenLabsSpeak:async()=>remoteResult,speakWithDeviceVoice:async()=>{localCalls++;return true},syncConversationMusic:()=>{},restoreVoiceProbeMusic:()=>{},agent:()=>{}};
  vm.createContext(sandbox);
  vm.runInContext(functionSource('agentSpeak',true)+';globalThis.run=agentSpeak',sandbox);
  assert.equal(await sandbox.run('Test voice'),true);assert.equal(localCalls,1);
  remoteResult=true;assert.equal(await sandbox.run('Second voice'),true);assert.equal(localCalls,1);
  assert.equal(sandbox.voicePlaybackPending,false);assert.equal(sandbox.agentInterrupt.hidden,true);
});

check('Interrupt cancels playback without disabling listening',()=>{
  let stopped=0,restarted=0,restored=0;
  const sandbox={st:{conversationOn:true,voiceAudio:{paused:false,pause:()=>stopped++}},voicePlaybackSequence:0,voiceAudioCancel:null,conversationBargeIn:{stop:()=>{}},speechFallbackResolve:null,voicePlaybackPending:true,agentInterrupt:{hidden:false},window:{},syncConversationMusic:()=>restored++,restoreVoiceProbeMusic:()=>{},showTranscript:()=>{},agent:()=>{},scheduleConversationListen:()=>restarted++};
  vm.createContext(sandbox);
  vm.runInContext(functionSource('interruptVoicePlayback')+';globalThis.run=interruptVoicePlayback',sandbox);
  sandbox.run();assert.equal(stopped,1);assert.equal(restarted,1);assert.equal(restored,1);
  assert.equal(sandbox.st.conversationOn,true);assert.equal(sandbox.agentInterrupt.hidden,true);
});

Promise.all(pending).then(()=>console.log('Section 90D visual + voice + music conversation contracts verified')).catch(error=>{console.error(error);process.exitCode=1});
