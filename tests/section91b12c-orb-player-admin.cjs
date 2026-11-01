'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const js=read('assets/js/public-agent-canvas.js');
const css=read('assets/css/public-agent-canvas.css');
const shell=read('stonefellow-v120.php');
const admin=read('admin/index.php');
const theme=read('admin/assets/agent-mesh-theme.css');
const particle=read('admin/assets/agent-particle-orb.js');
let passed=0;
function test(name,fn){fn();passed++;console.log('91B12C PASS '+name);}
test('Audio panel sits beneath orb and embeds the action buttons',()=>{
  const start=js.indexOf('class="sf-agent-player-dock"'),controls=js.indexOf('class="sf-agent-tools"'),end=js.indexOf('</div>',controls);
  assert.ok(start>=0&&controls>start&&end>controls);
  assert.ok(js.indexOf('class="sf-agent-dock-stack"')<start);
  assert.match(css,/\.sf-agent-dock-stack \{ position:relative/);
  assert.doesNotMatch(css,/left:calc\(100% \+ 25px\)/);
  assert.match(css,/\.sf-agent-player-dock\.has-track \.sf-agent-player-ready \{ display:none/);
  assert.match(js,/playerDock\.insertBefore\(player,playerDock\.firstChild\)/);
  assert.match(js,/originalPlayerParent\.insertBefore\(player,before\)/);
  assert.equal((shell.match(/id="persistentAudio"/g)||[]).length,1);
});
test('Movement pauses on pointer hover and focus and resumes from current position',()=>{
  assert.match(js,/hovered\|\|workspace\.classList\.contains\('open'\)/);
  assert.match(js,/on\(floater,'pointerenter',\(\)=>\{hovered=true;/);
  assert.match(js,/on\(floater,'pointerleave',\(\)=>\{hovered=false;roam\.start=0;/);
  assert.match(js,/on\(floater,'focusin'/);
  assert.match(js,/on\(floater,'focusout'/);
  assert.match(css,/\.sf-agent-floater:hover \.sf-agent-visual \{ animation-play-state:paused/);
});
test('Transparent shell has one full-viewport mesh behind header and footer',()=>{
  assert.match(css,/body\.sf-agent-canvas-active \.topbar \{ background:transparent!important/);
  assert.match(css,/body\.sf-agent-canvas-active \.chat-footer \{ background:transparent!important/);
  assert.match(css,/\.sf-agent-mesh \{ position:fixed; z-index:0; inset:0; width:100vw; height:100dvh/);
  assert.match(js,/document\.body\?\.appendChild\)document\.body\.appendChild\(mesh\)/);
  assert.match(js,/if\(mesh\.parentNode!==root\)mesh\.remove\(\)/);
});
test('Admin has real particle-faced companion without replacing agent controls',()=>{
  assert.match(admin,/class="admin-floating-points"/);
  assert.match(admin,/assets\/agent-particle-orb\.js/);
  assert.match(admin,/id="adminFloatingAgent"/);
  assert.match(admin,/id="adminOrb"/);
  assert.match(particle,/orb\.dataset\.mode/);
  assert.match(particle,/document\.addEventListener\('pointermove',pointer/);
  assert.doesNotMatch(particle,/fetch\(|getUserMedia|MediaRecorder|localStorage/);
  assert.match(theme,/\.admin-floating-agent\.particle-ready \.admin-floating-eyes/);
});
test('Menus, Agent Brain, and chat slideout share the blue glass theme',()=>{
  for(const selector of ['.admin-profile-dropdown','.admin-brain-drawer','.admin-chat-popup','.admin-profile-toggle','.admin-brain-tabs button','.admin-proof-card']){
    assert.ok(theme.includes(selector),'Missing '+selector);
  }
  assert.match(theme,/rgba\(138,212,255,\.35\)/);
  assert.match(theme,/\.admin-brain-backdrop \{ background:rgba\(2,9,24,\.6\)/);
});
test('Changed scripts parse and render modules without DOM side effects during load',()=>{
  new vm.Script(js,{filename:'public-agent-canvas.js'});
  new vm.Script(particle,{filename:'agent-particle-orb.js'});
  const scope={window:{},document:{getElementById:()=>null}};
  vm.runInNewContext(particle,scope);
});
console.log('Section 91B12C: '+passed+' integration checks passed');
