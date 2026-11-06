'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const app=read('assets/js/app.js');
const canvas=read('assets/js/public-agent-canvas.js');
const css=read('assets/css/public-agent-canvas.css');
const site=read('assets/css/site.css');
const shell=read('stonefellow-v120.php');
let passed=0;
function check(label,run){run();passed++;console.log('91B14B PASS '+label);}

check('One live speech transcript moves out of the footer into the orb and is restored on exit',()=>{
  assert.equal((shell.match(/id="voiceTranscript"/g)||[]).length,1);
  assert.doesNotMatch(canvas,/id="voiceTranscript"/);
  assert.match(canvas,/document\.getElementById\?\.\('voiceTranscript'\)/);
  assert.match(canvas,/liveSlot\.appendChild\(voiceTranscript\)/);
  assert.match(canvas,/originalTranscriptParent\.insertBefore\(voiceTranscript,before\)/);
  assert.ok(canvas.indexOf('originalTranscriptParent.insertBefore')<canvas.indexOf('root.remove()'));
});
check('Live speech recognition keeps updating the real caption and its visibility',()=>{
  const begin=app.indexOf("  const voiceTranscript=$('#voiceTranscript')");
  const end=app.indexOf('  function syncAgentDockLayout()',begin);
  const briefStart=app.indexOf('  function canvasDesignBrief(');
  const briefEnd=app.indexOf('  function canvasLyricSection(',briefStart);
  assert.ok(begin>0&&end>begin);
  assert.ok(briefStart>0&&briefEnd>briefStart);
  let calls=0;
  const nodes={'#voiceTranscript':{hidden:true,dataset:{}},'#voiceTranscriptText':{textContent:''}};
  const ctx={$:key=>nodes[key],window:{SFPublicAgentCanvas:{syncTranscript:()=>calls++}}};
  vm.createContext(ctx);
  vm.runInContext(app.slice(briefStart,briefEnd)+app.slice(begin,end)+'\nthis.update=showTranscript;',ctx);
  ctx.update('You: And','listening');
  assert.equal(nodes['#voiceTranscript'].hidden,false);
  assert.equal(nodes['#voiceTranscript'].dataset.phase,'listening');
  assert.equal(nodes['#voiceTranscriptText'].textContent,'You: And');
  ctx.update('', 'listening');
  assert.equal(nodes['#voiceTranscript'].hidden,true);
  assert.equal(calls,2);
});
check('Agent Chat messages are shown in the orb preview, not duplicated or spoken',()=>{
  assert.match(app,/if\(role!=='user'\|\|\(!canvasBrief&&!canvasBriefInChat\(value\)\)\)window\.SFPublicAgentCanvas\?\.showConversation\?\.\(role,value\)/);
  assert.match(app,/initialConversation:popupEntries\.filter\(entry=>entry\.role!=='user'\|\|\(!entry\.canvasBrief&&!canvasBriefInChat\(entry\.text\)\)\)\.at\(-1\)\|\|null/);
  assert.match(canvas,/function showConversation\(role,text\)/);
  assert.match(canvas,/liveMessage\.textContent=/);
  assert.match(canvas,/showConversation:\(role,text\)=>current\?\.showConversation\(role,text\)/);
  assert.match(canvas,/role==='user'\?'You: ':'Stonefellow: '/);
});
check('Hiding the agent preserves the active conversation and speech controller',()=>{
  const begin=app.indexOf('  let manualAgentHidden=false;');
  const end=app.indexOf('  function handleAgentVisibilityCommand(',begin);
  assert.ok(begin>0&&end>begin);
  const classes=new Set(),restore={hidden:true};let stops=0,layouts=0;
  const ctx={st:{conversationOn:true},$:()=>restore,
    document:{body:{classList:{toggle(name,enabled){enabled?classes.add(name):classes.delete(name);}}}},
    stopConversation(){stops++;ctx.st.conversationOn=false;},stopAgentSpeaking(){stops++;},syncAgentDockLayout(){layouts++;}};
  vm.createContext(ctx);vm.runInContext(app.slice(begin,end),ctx);
  ctx.syncAgentVisibility(true);
  assert.equal(ctx.st.conversationOn,true);assert.equal(stops,0);
  assert.equal(restore.hidden,false);assert.equal(classes.has('sf-agent-hidden-by-scene'),true);
  ctx.syncAgentVisibility(false);
  assert.equal(ctx.st.conversationOn,true);assert.equal(stops,0);assert.equal(layouts,2);
  assert.equal(restore.hidden,true);assert.equal(classes.has('sf-agent-hidden-by-scene'),false);
  ctx.st.conversationOn=false;ctx.syncAgentVisibility(true);
  assert.equal(stops,0);assert.equal(ctx.st.conversationOn,false);
  for(const name of ['  async function agentSpeak(','  async function handleVoiceClick(']){
    const start=app.indexOf(name);assert.ok(start>0);
    assert.doesNotMatch(app.slice(start,start+700),/sf-agent-hidden-by-scene/);
  }
});
check('Old drag hint and redundant footer transcript row are gone from Agent Canvas',()=>{
  assert.doesNotMatch(canvas,/DRAG TO MOVE|sf-agent-hint|sf-agent-listen-actions/);
  assert.doesNotMatch(css,/sf-agent-hint|sf-agent-listen-actions/);
  assert.match(canvas,/class="sf-agent-live-feed"/);
  assert.match(css,/\.sf-agent-live-feed\.has-live-transcript \.sf-agent-live-preview\{display:none;/);
  assert.match(canvas,/if\(e\.target\.closest\?\.\('\.sf-agent-live-feed'\)\)return;/);
});
check('Song Lyrics and Record Studio retain their orbital positions',()=>{
  assert.match(canvas,/class="sf-agent-orbit-tools"/);
  assert.match(canvas,/data-orbit-action="listen"[^>]*data-tip="Song Lyrics"/);
  assert.match(canvas,/data-orbit-action="scene-history"[^>]*data-tip="Canvas History"/);
  assert.doesNotMatch(canvas,/<span>Song Lyrics<\/span>|data-agent-action="open-lyrics"/);
  assert.match(canvas,/data-orbit-action="record"[^>]*data-tip="Record Studio"/);
  assert.match(canvas,/data-orbit-action="stop"[^>]*data-tip="Stop recording"/);
  assert.match(canvas,/class="sf-agent-presence"/);
  assert.match(canvas,/function positionOrbitTools\(\)/);
  assert.match(canvas,/Math\.min\(168,56\+\(count-2\)\*42\)/);
  // Hidden controls retain their slots; hiding a tool must not shuffle the ring.
  assert.match(canvas,/\.filter\(button=>button\.dataset\.orbitAction!=='stop'\)/);
  assert.match(canvas,/stopSlot\.style\.left=listenSlot\.style\.left/);
  assert.match(canvas,/stopSlot\.style\.top=listenSlot\.style\.top/);
  assert.match(css,/\.sf-agent-orbit-tools button svg/);
  assert.match(css,/\.sf-agent-orbit-tools button:focus-visible/);
});
check('Toolbar keeps its existing player, stop and direct listening actions',()=>{
  assert.match(canvas,/action==='listen-interpret'/);
  assert.match(canvas,/action==='capture'/);
  assert.match(canvas,/action==='stop-listening'/);
  assert.match(canvas,/button\.hidden=capturing&&!lyricBusy/);
  assert.match(canvas,/button\.hidden=!capturing/);
  assert.match(canvas,/positionOrbitTools\(\);/);
  assert.match(app,/executeSongCaptureAction\(\{type:'song_capture_live_start',interpret_on_stop:true\}\)/);
});
check('Production orbital positioning keeps existing tools fixed across hidden and visible states',()=>{
  const start=canvas.indexOf('    function positionOrbitTools(){');
  const end=canvas.indexOf('    function syncListeningButtons()',start);
  assert.ok(start>=0&&end>start);
  const buttons=['listen','record','player','playlist','stop'].map(orbitAction=>({dataset:{orbitAction},style:{},hidden:false}));
  const ctx={orbitTools:{querySelectorAll:()=>buttons},visual:{offsetWidth:210}};
  vm.createContext(ctx);vm.runInContext(canvas.slice(start,end),ctx);
  ctx.positionOrbitTools();
  const before=buttons.map(button=>({...button.style}));
  buttons[0].hidden=true;buttons[2].hidden=true;buttons[4].hidden=false;
  ctx.positionOrbitTools();
  assert.deepEqual(buttons.map(button=>({...button.style})),before);
  assert.equal(buttons[4].style.left,undefined,'Stop is overlaid in the Listen slot by syncListeningButtons, never allocated another slot');
});
check('Ember uses ivory and gold tokens through the footer, player and notification bell',()=>{
  assert.match(site,/--accent:#ffe9be;--accent-rgb:255,233,190/);
  assert.match(site,/\.notification-badge\{[\s\S]*?background:#ffdf9f/);
  assert.match(site,/\.chatbar \.chat-action\.send\{[\s\S]*?background:#ffe6b0/);
  assert.match(site,/\.chatbar \.chat-action\.mic\.active\{[\s\S]*?background:#ffe4a9/);
  assert.match(css,/\.sf-agent-player-dock \.player-toggle\{[\s\S]*?background:#ffe7b7/);
  assert.match(css,/\.sf-agent-live-feed \.voice-transcript-dot/);
});
check('Mobile can use compact orbital icons and an attached, short caption',()=>{
  assert.match(css,/\.sf-agent-orbit-tools button\{[\s\S]*?width:44px;height:44px/);
  assert.match(css,/@media\(max-width:720px\)\{[\s\S]*?\.sf-agent-orbit-tools button svg\{width:17px/);
  assert.match(css,/\.sf-agent-live-feed\{[\s\S]*?width:min\(320px,calc\(100vw - 20px\)\)/);
  assert.match(css,/\.sf-agent-live-feed #voiceTranscriptText\{[\s\S]*?-webkit-line-clamp:2/);
  assert.match(canvas,/radius=visual\.offsetWidth\*\.5/);
});
check('Builder stays a standalone page; no iframe navigation was introduced',()=>{
  assert.match(shell,/href="\?view=builder" data-view="builder"/);
  assert.match(app,/const nativePanel=view!=='home'&&view!=='builder'/);
  assert.doesNotMatch(canvas,/<iframe/);
});
check('All build markers are in sync and browser scripts parse',()=>{
  const version=shell.match(/define\('SF_BUILD',\s*'([0-9.]+)'\)/)?.[1]||'';
  assert.match(version,/^1\.3\.\d+$/);
  for(const file of ['stonefellow-v120.php','admin/index.php','.htaccess','version.php','service-worker.js','assets/js/app.js','assets/css/turntable-builder.css'])assert.ok(read(file).includes(version),file);
  new vm.Script(canvas);new vm.Script(app);
});
check('Stonefellow introduces itself by name once through the existing echo-safe voice route',()=>{
  const runtime=read('api/agent-runtime.php');
  assert.match(runtime,/You are Stonefellow, the music-site conversational agent/);
  assert.match(runtime,/Do not repeat the introduction in ordinary follow-up replies/);
  assert.match(app,/const greeting=welcomeGreeting/);
  assert.match(app,/if\(voiceGreetingDelivered\|\|!voiceRepliesEnabled/);
  assert.match(app,/voiceGreetingDelivered=true/);
  assert.match(app,/void agentSpeak\(greeting\)/);
  assert.match(app,/who\.textContent=entry\.role==='user'\?'You':entry\.role==='notice'\?'Session update':'Stonefellow'/);
  assert.match(shell,/<strong>Stonefellow<\/strong><span id="agentChatPopupState"/);
});
console.log('Section 91B14B: '+passed+' orb actions + live caption checks passed');
