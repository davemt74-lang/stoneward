'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=name=>fs.readFileSync(path.join(root,name),'utf8');
const shell=read('stonefellow-v120.php');
const app=read('assets/js/app.js');
const canvas=read('assets/js/public-agent-canvas.js');
const css=read('assets/css/public-agent-canvas.css');
const site=read('assets/css/site.css');
let passed=0;
function test(name,run){run();passed++;console.log('91B14A PASS '+name);}

test('Three named presets are allowlisted, restored per device, and exposed as canvas state',()=>{
  for(const key of ['ember','mesh','violet'])assert.match(canvas,new RegExp(key+':\\{label:'));
  assert.match(canvas,/Object\.prototype\.hasOwnProperty\.call\(presets/);
  assert.match(canvas,/stonefellow\.agent\.atmosphere\.v1/);
  assert.match(canvas,/setAtmosphere:name=>current\?\.setAtmosphere\(name\)/);
  assert.match(canvas,/applyCommand/);
  assert.match(canvas,/root\.dataset\.atmosphere=name/);
  assert.match(canvas,/document\.body\.dataset\.sfAtmosphere=name/);
});
test('Orb is a particle sphere with no face or expression overlay',()=>{
  assert.match(canvas,/const orbPoints=Array\.from/);
  assert.match(canvas,/function orbDraw\(t\)/);
  assert.match(canvas,/const perspective=1\/\(1-depth\*\.15\)/);
  assert.doesNotMatch(canvas,/function faceDraw\(|facePoints|sf-agent-face-fallback|sf-eye-x|sf-eye-y/);
  assert.doesNotMatch(css,/sf-agent-face-fallback|sf-eye-x|sf-eye-y/);
  assert.match(canvas,/sf-agent-core-fallback/);
});
test('Reference sunset background is a deployable local optimized image',()=>{
  const asset=fs.readFileSync(path.join(root,'assets/images/agent-atmosphere-ember.webp'));
  assert.ok(asset.length>15000&&asset.length<400000);
  assert.equal(asset.toString('ascii',8,12),'WEBP');
  assert.match(css,/agent-atmosphere-ember\.webp/);
  assert.match(css,/background-position:57% center/);
  assert.match(read('service-worker.js'),/assets\/images\/agent-atmosphere-ember\.webp/);
});
test('Main menu floats right with compact single-column right aligned links',()=>{
  assert.match(site,/body\.sf-agent-canvas-active \.menu-details \.menu-sheet\{/);
  assert.match(site,/position:fixed;z-index:80;top:62px;right:/);
  assert.match(site,/border:0;border-radius:0;box-shadow:none;background:transparent/);
  assert.match(site,/display:flex;flex-direction:column;align-items:stretch;gap:0/);
  assert.match(site,/padding:3px 18px 3px 4px/);
  assert.match(site,/text-align:right/);
  assert.match(site,/\.menu-atmosphere-options button\[aria-pressed="true"\]/);
  assert.match(app,/if\(menu\.open\)closeQuickMenu\(\)/);
});
test('Preset menu is actionable without navigation and remains accessible',()=>{
  for(const choice of ['ember','mesh','violet'])assert.match(shell,new RegExp('data-sf-atmosphere="'+choice+'" aria-pressed="false"'));
  assert.match(shell,/role="group" aria-label="Agent atmosphere presets"/);
  assert.match(app,/SFPublicAgentCanvas\?\.setAtmosphere\?\.\(choice\.dataset\.sfAtmosphere\)/);
  assert.match(canvas,/setAttribute\('aria-pressed',String\(button\.dataset\.sfAtmosphere===name\)\)/);
});
test('Builder stays standalone and native left page panel is never an iframe',()=>{
  assert.match(shell,/href="\?view=builder" data-view="builder">Create a record/);
  assert.match(app,/const nativePanel=view!=='home'&&view!=='builder'/);
  assert.match(app,/if\(action==='record'\)\{navigate\('builder'\);return\}/);
  assert.doesNotMatch(canvas,/<iframe/);
});
test('Release markers are version-synchronized',()=>{
  const version=shell.match(/define\('SF_BUILD',\s*'([0-9.]+)'\)/)?.[1]||'';
  assert.match(version,/^1\.3\.\d+$/);
  for(const name of ['stonefellow-v120.php','admin/index.php','.htaccess','version.php','service-worker.js','assets/js/app.js','assets/css/turntable-builder.css'])
    assert.ok(read(name).includes(version),name);
});
test('Canvas app files parse and inactive command cannot modify unrelated pages',()=>{
  new vm.Script(app,{filename:'app.js'});
  new vm.Script(canvas,{filename:'public-agent-canvas.js'});
  const context={window:{}};vm.runInNewContext(canvas,context);
  assert.equal(context.window.SFPublicAgentCanvas.applyCommand('set atmosphere to ember'),null);
  assert.equal(context.window.SFPublicAgentCanvas.setAtmosphere('url(javascript:alert(1))'),false);
});
console.log('Section 91B14A: '+passed+' atmosphere + menu checks passed');
