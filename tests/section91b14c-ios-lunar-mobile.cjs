'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=name=>fs.readFileSync(path.join(root,name),'utf8');
const app=read('assets/js/app.js');
const canvas=read('assets/js/public-agent-canvas.js');
const css=read('assets/css/public-agent-canvas.css');
const site=read('assets/css/site.css');
const shell=read('stonefellow-v120.php');
let passed=0;
function check(label,run){run();passed++;console.log('91B14C PASS '+label);}

check('Moonlit image is an optimized local WebP asset shipped with the app',()=>{
  const bytes=fs.readFileSync(path.join(root,'assets/images/agent-atmosphere-lunar.webp'));
  assert.ok(bytes.length>30000&&bytes.length<300000);
  assert.equal(bytes.toString('ascii',0,4),'RIFF');
  assert.equal(bytes.toString('ascii',8,12),'WEBP');
  assert.match(css,/agent-atmosphere-lunar\.webp/);
  assert.match(read('service-worker.js'),/assets\/images\/agent-atmosphere-lunar\.webp/);
});
check('Lunar is an allowlisted, persistent and voice-selectable atmosphere',()=>{
  assert.match(canvas,/lunar:\{label:'Moonlit Horizon',rgb:\[219,228,244\],mesh:false\}/);
  assert.match(canvas,/listAtmospheres:\(\)=>\['ember','mesh','violet','lunar'\]/);
  assert.match(canvas,/moonlit horizon\|moonlight/);
  assert.match(shell,/data-sf-atmosphere="lunar" aria-pressed="false"/);
  assert.match(css,/data-sf-atmosphere="lunar"\]/);
  assert.match(site,/data-sf-atmosphere="lunar"\]/);
});
check('The plus navigation rail uses a translucent photographic overlay, not a full modal',()=>{
  assert.match(site,/body\.sf-agent-canvas-active \.chat-quick-menu\{[\s\S]*?rgba\(7,12,20,\.27\)/);
  assert.match(site,/backdrop-filter:blur\(9px\)/);
  assert.match(site,/body\.sf-agent-canvas-active \.chat-quick-menu button\{/);
  assert.match(site,/text-shadow:0 1px 5px/);
});
check('Start Chat begins voice listening and hides while an active microphone is listening',()=>{
  assert.match(canvas,/class="sf-agent-chat-start" data-agent-action="start-chat"/);
  assert.doesNotMatch(canvas,/class="sf-agent-player-reveal"/);
  assert.match(canvas,/chatStart\.hidden=active/);
  assert.match(canvas,/Resume Chat':'◉ Start Chat'/);
  assert.match(canvas,/if\(action==='start-chat'\)options\.onStartChat\?\.\(\)/);
  assert.match(app,/onStartChat:\(\)=>\{if\(!st\.conversationOn\|\|iosVoiceNeedsGesture\)void handleVoiceClick\(\);\}/);
  assert.match(app,/SFPublicAgentCanvas\?\.syncVoice\?\.\(\)/);
  assert.match(canvas,/data-orbit-action="player"[^>]*data-tip="Music Player"/);
});
check('iOS stays on one continuous recognition session through thinking and speech',()=>{
  assert.match(app,/recognition\.continuous=isIOSVoiceBrowser/);
  assert.match(app,/if\(!isIOSVoiceBrowser\)try\{recognition\?\.stop\(\)\}/);
  assert.match(app,/if\(!isIOSVoiceBrowser\)\{\s*try\{recognition\?\.abort\(\)\}/);
  assert.match(app,/voiceTurnBusy\|\|isIOSVoiceBrowser\)return;/);
  assert.match(app,/if\(isIOSVoiceBrowser&&st\.conversationOn&&iosVoiceNeedsGesture\)/);
  assert.match(app,/Microphone paused · tap Start Chat to resume/);
});
check('Continuous speech consumes new final segments once and ignores Agent echo',()=>{
  const begin=app.indexOf('    recognition.onresult=e=>{');
  const end=app.indexOf('    recognition.onerror=e=>{',begin);
  assert.ok(begin>0&&end>begin);
  const seen=[],captions=[];
  const ctx={
    recognition:{},st:{conversationOn:true,playing:false},voiceTurnBusy:false,
    voicePlaybackPending:false,voiceSpeechGuard:{blocked:false,reference:''},
    isIOSVoiceBrowser:true,iosVoiceMuteUntil:0,iosProcessedFinalIndex:-1,
    recognitionNetworkFailures:0,setVoiceInputActive:()=>{},
    showTranscript:(text,phase)=>captions.push({text,phase}),
    submitRecognizedVoiceTurn:spoken=>seen.push(spoken),
    shouldDuckConversationInput:()=>true,listeningPrompt:()=> 'Listening',
    Date
  };
  vm.runInNewContext(app.slice(begin,end),ctx);
  const final=transcript=>({0:{transcript},isFinal:true});
  const interim=transcript=>({0:{transcript},isFinal:false});
  ctx.recognition.onresult({resultIndex:0,results:[final('Play Earth Girl')]});
  assert.deepEqual(seen,['Play Earth Girl']);
  ctx.recognition.onresult({resultIndex:1,results:[final('Play Earth Girl'),interim('Next')]});
  assert.equal(captions.at(-1).text,'You: Next');
  ctx.recognition.onresult({resultIndex:1,results:[final('Play Earth Girl'),final('Next song')]});
  assert.deepEqual(seen,['Play Earth Girl','Next song']);
  ctx.voiceTurnBusy=true;
  ctx.recognition.onresult({resultIndex:2,results:[final('Play Earth Girl'),final('Next song'),final('Agent speaking')]});
  ctx.voiceTurnBusy=false;
  ctx.recognition.onresult({resultIndex:2,results:[final('Play Earth Girl'),final('Next song'),final('Agent speaking')]});
  assert.deepEqual(seen,['Play Earth Girl','Next song']);
  ctx.voiceSpeechGuard.reference='Stonefellow is here to help';
  ctx.iosVoiceMuteUntil=Date.now()-1000;
  ctx.recognition.onresult({resultIndex:3,results:[final('Play Earth Girl'),final('Next song'),final('Agent speaking'),final('Stonefellow is here')]});
  assert.deepEqual(seen,['Play Earth Girl','Next song']);
  ctx.iosVoiceMuteUntil=0;
  ctx.recognition.onresult({resultIndex:4,results:[final('Play Earth Girl'),final('Next song'),final('Agent speaking'),final('Stonefellow is here'),final('Stonefellow is here')]});
  assert.deepEqual(seen,['Play Earth Girl','Next song','Stonefellow is here']);
});
check('An iOS microphone restart blocked by speech or InvalidStateError leaves Resume Chat visible',()=>{
  const begin=app.indexOf('  function startConversationListen(){');
  const end=app.indexOf('  function stopConversation(){',begin);
  assert.ok(begin>0&&end>begin);
  let starts=0,updates=0,schedules=0;
  const ctx={
    st:{conversationOn:true},recognition:{start(){starts++}},recognitionActive:false,
    voiceTurnBusy:false,voicePlaybackPending:true,voiceSpeechGuard:{blocked:false},
    status:{textContent:'LISTENING'},isIOSVoiceBrowser:true,iosVoiceNeedsGesture:false,
    updateConversationButton:()=>updates++,showTranscript:()=>{},
    scheduleConversationListen:()=>schedules++,stopConversation:()=>{}
  };
  vm.runInNewContext(app.slice(begin,end),ctx);
  ctx.startConversationListen();
  assert.equal(starts,0);assert.equal(schedules,0);
  assert.equal(ctx.iosVoiceNeedsGesture,true);assert.equal(updates,1);
  ctx.voicePlaybackPending=false;ctx.iosVoiceNeedsGesture=false;
  ctx.recognition.start=()=>{const error=new Error('already started');error.name='InvalidStateError';throw error;};
  ctx.startConversationListen();
  assert.equal(ctx.iosVoiceNeedsGesture,true);assert.equal(updates,2);
  ctx.isIOSVoiceBrowser=false;ctx.startConversationListen();assert.equal(schedules,1);
});
check('iOS end needs a real tap instead of requesting permission in a timer',()=>{
  const begin=app.indexOf('    recognition.onend=()=>{');
  const end=app.indexOf('    updateConversationButton();',begin);
  assert.ok(begin>0&&end>begin);
  let schedules=0,updates=0;
  const ctx={
    recognition:{},recognitionActive:true,st:{conversationOn:true},
    isIOSVoiceBrowser:true,voiceTurnBusy:false,voicePlaybackPending:false,
    iosVoiceNeedsGesture:false,recognitionNetworkFailures:0,
    setVoiceInputActive:()=>{},scheduleConversationListen:()=>schedules++,
    updateConversationButton:()=>updates++,agent:()=>{},showTranscript:()=>{}
  };
  vm.runInNewContext(app.slice(begin,end),ctx);
  ctx.recognition.onend();
  assert.equal(ctx.iosVoiceNeedsGesture,true);
  assert.equal(ctx.recognitionActive,false);
  assert.equal(schedules,0);
  assert.equal(updates,1);
  ctx.isIOSVoiceBrowser=false;
  ctx.iosVoiceNeedsGesture=false;
  ctx.recognition.onend();
  assert.equal(schedules,1);
});
check('Mobile Activity is a right-side slide-out and chat input never triggers iOS zoom',()=>{
  assert.match(shell,/id="notificationButton"/);
  assert.match(shell,/id="notificationDrawer"/);
  assert.match(site,/@keyframes sfMobileNotificationIn/);
  assert.match(site,/width:min\(370px,88vw\);max-width:none;height:100dvh/);
  assert.match(site,/animation:sfMobileNotificationIn \.27s/);
  assert.match(site,/\.chat-composer-shell \.chatbar input\{font-size:16px!important/);
  assert.doesNotMatch(site,/user-scalable=no|maximum-scale=1/);
});
check('Version markers and updated browser JavaScript remain synchronized',()=>{
  const version=shell.match(/define\('SF_BUILD',\s*'([0-9.]+)'\)/)?.[1];
  assert.equal(version,'1.3.204');
  for(const file of ['stonefellow-v120.php','admin/index.php','.htaccess','version.php','service-worker.js','assets/js/app.js','assets/css/turntable-builder.css'])
    assert.ok(read(file).includes(version),file);
  new vm.Script(app);new vm.Script(canvas);
});
console.log('Section 91B14C: '+passed+' iPhone voice + Lunar mobile checks passed');
