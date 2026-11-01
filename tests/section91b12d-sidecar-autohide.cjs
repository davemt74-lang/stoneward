'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const js=read('assets/js/public-agent-canvas.js');
const css=read('assets/css/public-agent-canvas.css');
const siteCss=read('assets/css/site.css');
const appJs=read('assets/js/app.js');
const php=read('stonefellow-v120.php');
let passed=0;
function test(name,fn){fn();passed++;console.log('91B12D PASS '+name);}
test('Lyric panel is a sibling of the player stack, not stacked underneath',()=>{
  const stack=js.indexOf('class="sf-agent-dock-stack"');
  const player=js.indexOf('id="sfAgentPlayerDock"');
  const reveal=js.indexOf('class="sf-agent-player-reveal"');
  const closeStack=js.indexOf('</div>',reveal);
  const panel=js.indexOf('<aside class="sf-agent-panel"');
  assert.ok(stack<player&&player<reveal&&reveal<closeStack&&closeStack<panel);
  assert.match(css,/\.sf-agent-panel \{ position:absolute; z-index:8; top:0; left:calc\(100% \+ 20px\)/);
  assert.match(css,/\.sf-agent-floater\[data-side="left"\] \.sf-agent-panel/);
  assert.match(css,/\.sf-agent-floater\[data-vertical="above"\] \.sf-agent-panel/);
});
test('Viewport-aware side selection and mobile stacked fallback retain session access',()=>{
  assert.match(js,/availableRight=root\.clientWidth-\(floater\.offsetLeft\+half\)/);
  assert.match(js,/availableLeft=floater\.offsetLeft-half/);
  assert.match(css,/@media\(max-width:1159px\)/);
  assert.match(css,/top:calc\(100% \+ 15px\)/);
  assert.match(css,/height:max\(calc\(100dvh - 169px\),1100px\)/);
  assert.match(js,/e\.target\.closest\('\.sf-agent-dock-stack,\.sf-agent-panel'\)/);
  assert.match(js,/StonefellowSongExperience\.mount\(smallSession\)/);
  assert.match(js,/StonefellowSongExperience\.mount\(bigSession\)/);
});
test('Player fades after idle timeout and retains keyboard-accessible explicit reveal',()=>{
  assert.match(js,/const PLAYER_IDLE_MS=5200/);
  assert.match(js,/playerTimer=window\.setTimeout\(hidePlayer,PLAYER_IDLE_MS\)/);
  assert.match(js,/playerDock\.classList\.add\('is-collapsed'\)/);
  assert.match(js,/playerReveal\.hidden=false/);
  assert.match(js,/if\(action==='show-player'\)showPlayer\(\)/);
  assert.match(js,/playerDock\.classList\.remove\('is-collapsed'\)/);
  assert.match(css,/\.sf-agent-player-dock\.is-collapsed \{ opacity:0; visibility:hidden/);
  assert.match(js,/aria-controls="sfAgentPlayerDock"/);
  assert.match(js,/on\(playerDock,'focusin',pausePlayerHide\)/);
});
test('Playback, interaction, and cleanup preserve the single authoritative player',()=>{
  assert.match(js,/on\(audio,'play',\(\)=>\{state\('music'\);showPlayer\(\);\}\)/);
  assert.match(js,/on\(playerDock,'pointerenter',pausePlayerHide\)/);
  assert.match(js,/on\(visual,'pointerenter',\(\)=>\{showPlayer\(\);pausePlayerHide\(\);\}\)/);
  assert.match(js,/pausePlayerHide\(\);\s*resizeObserver\?\.disconnect/);
  assert.match(js,/originalPlayerParent\.insertBefore\(player,before\)/);
  assert.equal((php.match(/id="persistentAudio"/g)||[]).length,1);
});
test('No new capture backend, network calls, or permission changes are introduced',()=>{
  assert.doesNotMatch(js,/\bfetch\s*\(|MediaRecorder|getUserMedia|captureStream/);
  assert.match(js,/options\.onStartCapture\?\.\(\)/);
  new vm.Script(js,{filename:'public-agent-canvas.js'});
});
test('Quick actions use a left-side blue control deck with animated signal affordances',()=>{
  assert.match(php,/id="chatQuickMenu" class="chat-quick-menu" role="dialog" aria-labelledby="chatQuickMenuTitle"/);
  assert.match(php,/id="chatQuickClose"/);
  assert.match(siteCss,/\.chat-quick-menu\{[\s\S]*?left:clamp\(14px,3vw,60px\);right:auto;bottom:calc\(100% \+ 10px\)/);
  assert.match(siteCss,/\.sf-quick-grid\{display:grid;grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/);
  assert.match(siteCss,/@keyframes sfQuickLed/);
  assert.match(siteCss,/@keyframes sfQuickMeter/);
  assert.match(siteCss,/@media\(prefers-reduced-motion:reduce\)\{\.sf-quick-live/);
});
test('Every public quick action remains wired and the close button restores focus',()=>{
  for(const action of ['record','playlist','shows','store','membership','tickets','newsletter']){
    assert.match(php,new RegExp(`data-quick-action="${action}"`));
    assert.match(appJs,new RegExp(`action==='${action}'`));
  }
  assert.match(php,/data-quick-action="community"/);
  assert.match(appJs,/\$\('#chatQuickClose'\)\?\.addEventListener\('click',\(\)=>closeQuickMenu\(true\)\)/);
  assert.match(appJs,/quickMenu\.querySelector\('\[data-quick-action\]'\)\?\.focus/);
});
test('Orb music widget keeps only playback and progress, not queue or extra buttons',()=>{
  assert.match(css,/grid-template-areas:"art meta toggle" "timeline timeline timeline"/);
  for(const button of ['playerPrev','playerNext','playerClose','playerQueueButton']){
    assert.match(css,new RegExp(`#${button}[,\\s]`));
  }
  assert.match(css,/\.sf-agent-player-dock #playerQueueButton \{ display:none!important; \}/);
  assert.doesNotMatch(js.slice(js.indexOf('id="sfAgentPlayerDock"'),js.indexOf('class="sf-agent-player-reveal"')),/data-agent-action="chat"|data-agent-action="capture"/);
  assert.match(js,/class="sf-agent-expanded-session"/);
  assert.match(js,/options\.onStartCapture\?\.\(\)/);
});
console.log('Section 91B12D: '+passed+' checks passed');
