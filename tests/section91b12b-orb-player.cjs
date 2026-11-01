'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const css=read('assets/css/public-agent-canvas.css');
const canvas=read('assets/js/public-agent-canvas.js');
const app=read('assets/js/app.js');
const shell=read('stonefellow-v120.php');
let passes=0;
const test=(title,run)=>{run();console.log('91B12B PASS '+title);passes++;};

test('Transparent header is scoped to the opt-in Agent Canvas',()=>{
  assert.match(css,/body\.sf-agent-canvas-active \.topbar \{ background:transparent!important;/);
  assert.match(css,/backdrop-filter:none!important/);
});
test('Chat still opens manually, but capture progress cannot auto-open it on Agent Canvas',()=>{
  assert.match(app,/onOpenChat:\(\)=>\{void openChatPopup\(\)/);
  assert.match(app,/if\(open&&!window\.SFPublicAgentCanvas\?\.active\?\.\(\)\)void openChatPopup\(\)/);
  assert.match(app,/window\.SFPublicAgentCanvas\?\.syncSongExperience\?\.\(\)/);
});
test('Actual player passes into the floating canvas and keeps one authoritative audio element',()=>{
  assert.match(app,/player:footerPlayer/);
  assert.match(canvas,/playerDock\.insertBefore\(player,playerDock\.firstChild\)/);
  assert.match(canvas,/originalPlayerParent\.insertBefore\(player,before\)/);
  assert.match(canvas,/playerObserver\?\.disconnect\(\)/);
  assert.match(app,/SFPublicAgentCanvas\?\.syncPlayer\?\.\(\)/);
  assert.equal((shell.match(/id="persistentAudio"/g)||[]).length,1);
  assert.equal((shell.match(/id="footerPlayer"/g)||[]).length,1);
});
test('Lyric capture keeps the same real session underneath the player',()=>{
  assert.match(canvas,/sf-agent-dock-stack/);
  assert.match(canvas,/sf-agent-player-dock/);
  assert.match(canvas,/StonefellowSongExperience\.mount\(smallSession\)/);
  assert.match(canvas,/StonefellowSongExperience\.mount\(bigSession\)/);
  assert.match(canvas,/floater\.dataset\.side='below'/);
});
test('Player controls, seek interaction and mobile layout are usable',()=>{
  assert.match(css,/\.sf-agent-player-dock \.footer-player/);
  assert.match(css,/grid-template-areas:"art meta prev toggle next close"/);
  assert.match(css,/\.player-timeline \{ grid-area:timeline/);
  assert.match(canvas,/e\.target\.closest\('\.sf-agent-dock-stack'\)/);
  assert.match(css,/@media\(max-width:720px\)/);
  assert.match(css,/height:max\(calc\(100dvh - 169px\),760px\)/);
});
test('Agent responding state animates face rather than reopening chat',()=>{
  assert.match(canvas,/responding:'speaking'/);
  assert.match(css,/\[data-mode="speaking"\]/);
});
test('Changed scripts compile',()=>{
  new vm.Script(app,{filename:'app.js'});
  new vm.Script(canvas,{filename:'public-agent-canvas.js'});
});
console.log('Section 91B12B: '+passes+' targeted checks passed');
