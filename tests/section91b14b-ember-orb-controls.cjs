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
  assert.ok(begin>0&&end>begin);
  let calls=0;
  const nodes={'#voiceTranscript':{hidden:true,dataset:{}},'#voiceTranscriptText':{textContent:''}};
  const ctx={$:key=>nodes[key],window:{SFPublicAgentCanvas:{syncTranscript:()=>calls++}}};
  vm.createContext(ctx);
  vm.runInContext(app.slice(begin,end)+'\nthis.update=showTranscript;',ctx);
  ctx.update('You: And','listening');
  assert.equal(nodes['#voiceTranscript'].hidden,false);
  assert.equal(nodes['#voiceTranscript'].dataset.phase,'listening');
  assert.equal(nodes['#voiceTranscriptText'].textContent,'You: And');
  ctx.update('', 'listening');
  assert.equal(nodes['#voiceTranscript'].hidden,true);
  assert.equal(calls,2);
});
check('Agent Chat messages are shown in the orb preview, not duplicated or spoken',()=>{
  assert.match(app,/SFPublicAgentCanvas\?\.showConversation\?\.\(role,String\(text\)\)/);
  assert.match(app,/initialConversation:popupEntries\.at\(-1\)\|\|null/);
  assert.match(canvas,/function showConversation\(role,text\)/);
  assert.match(canvas,/liveMessage\.textContent=/);
  assert.match(canvas,/showConversation:\(role,text\)=>current\?\.showConversation\(role,text\)/);
  assert.match(canvas,/role==='user'\?'You: ':'Stonefellow: '/);
});
check('Old drag hint and redundant footer transcript row are gone from Agent Canvas',()=>{
  assert.doesNotMatch(canvas,/DRAG TO MOVE|sf-agent-hint|sf-agent-listen-actions/);
  assert.doesNotMatch(css,/sf-agent-hint|sf-agent-listen-actions/);
  assert.match(canvas,/class="sf-agent-live-feed"/);
  assert.match(css,/\.sf-agent-live-feed\.has-live-transcript \.sf-agent-live-preview\{display:none;/);
  assert.match(canvas,/if\(e\.target\.closest\?\.\('\.sf-agent-live-feed'\)\)return;/);
});
check('Two song actions are now icon-only and sit above the orb',()=>{
  assert.match(canvas,/class="sf-agent-orbit-tools"/);
  assert.match(canvas,/data-orbit-action="listen"[^>]*data-tip="Listen &amp; Interpret"/);
  assert.match(canvas,/data-orbit-action="record"[^>]*data-tip="Record Clip"/);
  assert.match(canvas,/data-orbit-action="stop"[^>]*data-tip="Stop listening"/);
  assert.ok(canvas.indexOf('class="sf-agent-orbit-tools"')<canvas.indexOf('class="sf-agent-sphere"'));
  assert.match(canvas,/function positionOrbitTools\(\)/);
  assert.match(canvas,/Math\.min\(168,56\+\(count-2\)\*42\)/);
  assert.match(canvas,/\.filter\(button=>!button\.hidden\)/);
  assert.match(css,/\.sf-agent-orbit-tools button svg/);
  assert.match(css,/\.sf-agent-orbit-tools button:focus-visible/);
});
check('Toolbar keeps its existing player, stop and direct listening actions',()=>{
  assert.match(canvas,/action==='listen-interpret'/);
  assert.match(canvas,/action==='capture'/);
  assert.match(canvas,/action==='stop-listening'/);
  assert.match(canvas,/button\.hidden=capturing/);
  assert.match(canvas,/button\.hidden=!capturing/);
  assert.match(canvas,/positionOrbitTools\(\);/);
  assert.match(app,/executeSongCaptureAction\(\{type:'song_capture_live_start',interpret_on_stop:true\}\)/);
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
  assert.match(css,/@media\(max-width:720px\)\{[\s\S]*?\.sf-agent-orbit-tools button\{/);
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
  const version='1.3.202';
  for(const file of ['stonefellow-v120.php','admin/index.php','.htaccess','version.php','service-worker.js','assets/js/app.js','assets/css/turntable-builder.css'])assert.ok(read(file).includes(version),file);
  new vm.Script(canvas);new vm.Script(app);
});
console.log('Section 91B14B: '+passed+' orb actions + live caption checks passed');
