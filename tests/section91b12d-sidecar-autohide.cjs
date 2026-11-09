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
  const reveal=js.indexOf('class="sf-agent-chat-start"');
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
test('Player and attached controls share idle collapse and remain reachable from the orb',()=>{
  assert.match(js,/const PLAYER_IDLE_MS=5200/);
  assert.match(js,/playerTimer=window\.setTimeout\(hidePlayer,PLAYER_IDLE_MS\)/);
  assert.match(js,/playerDock\.classList\.toggle\('is-collapsed',collapsed\)/);
  assert.match(js,/floater\.classList\.toggle\('is-chrome-collapsed',collapsed\)/);
  assert.match(js,/node\.inert=collapsed/);
  assert.match(js,/data-orbit-action="player"[^>]*data-tip="Music Player"/);
  assert.match(js,/if\(action==='show-player'\)showPlayer\(\)/);
  assert.match(js,/function showPlayer\(\)\{\s*setAgentChromeCollapsed\(false\)/);
  assert.match(css,/\.sf-agent-player-dock\.is-collapsed \{ opacity:0; visibility:hidden/);
  assert.match(js,/aria-label="Show music player"/);
  assert.match(js,/on\(playerDock,'focusin',pausePlayerHide\)/);
});
test('Playback, interaction, and cleanup preserve the single authoritative player',()=>{
  assert.match(js,/on\(audio,'play',\(\)=>\{state\('music'\);showPlayer\(\);syncScenePlayback\(\);\}\)/);
  assert.match(js,/on\(playerDock,'pointerenter',pausePlayerHide\)/);
  for(const node of ['visual','floater']){
    const hover=js.split('\n').find(line=>line.includes(`on(${node},'pointerenter'`));
    assert.ok(hover);assert.match(hover,/pausePlayerHide\(\)/);assert.doesNotMatch(hover,/showPlayer\(\)/);
  }
  assert.match(js,/pausePlayerHide\(\)/);
  assert.match(js,/resizeObserver\?\.disconnect\(\)/);
  assert.match(js,/playerObserver\?\.disconnect\(\)/);
  assert.match(js,/originalPlayerParent\.insertBefore\(player,before\)/);
  assert.equal((php.match(/id="persistentAudio"/g)||[]).length,1);
});
test('No new capture backend, network calls, or permission changes are introduced',()=>{
  assert.doesNotMatch(js,/\bfetch\s*\(|MediaRecorder|getUserMedia|createMediaElementSource/);
  assert.match(js,/audio\.captureStream\|\|audio\.mozCaptureStream/);
  assert.match(js,/options\.onStartCapture\?\.\(\)/);
  new vm.Script(js,{filename:'public-agent-canvas.js'});
});
test('The searchable command list sits above the chat bar',()=>{
  assert.match(php,/<div class="chat-command-anchor">[\s\S]*?<nav id="chatQuickMenu"[^>]*aria-label="Slash commands"/);
  assert.match(php,/id="chatCommandSearch" type="search"/);
  assert.match(siteCss,/\.chat-command-anchor\{position:relative\}/);
  assert.match(siteCss,/\.chat-quick-menu\{\s*position:absolute;inset:auto auto calc\(100% \+ 8px\) 0/);
  assert.match(appJs,/search\.focus\(\{preventScroll:true\}\)/);
  assert.match(appJs,/event\.key==='Escape'[^\n]+closeQuickMenu\(true\)/);
});
test('Slash commands and existing navigation remain discoverable',()=>{
  for(const command of ['images','songs','albums','stories','knowledge','production','canvas','me','data']){
    assert.ok(appJs.includes("['"+command+"',"));
  }
  for(const action of ['record','playlist','shows','store','membership','tickets','newsletter','community']){
    assert.ok(php.includes('data-quick-action="'+action+'"'));
    assert.ok(appJs.includes("action==='"+action+"'"));
  }
  assert.match(appJs,/quickButton\.onclick=e=>\{e\.stopPropagation\(\);quickMenu\?\.hidden\?openQuickMenu\(\):closeQuickMenu\(\)/);
});
test('Orb music widget keeps only playback and progress, not queue or extra buttons',()=>{
  assert.match(css,/grid-template-areas:"art meta toggle" "timeline timeline timeline"/);
  for(const button of ['playerPrev','playerNext','playerClose','playerQueueButton']){
    assert.match(css,new RegExp(`#${button}[,\\s]`));
  }
  assert.match(css,/\.sf-agent-player-dock #playerQueueButton \{ display:none!important; \}/);
  assert.doesNotMatch(js.slice(js.indexOf('id="sfAgentPlayerDock"'),js.indexOf('class="sf-agent-chat-start"')),/data-agent-action="chat"|data-agent-action="capture"/);
  assert.match(js,/class="sf-agent-expanded-session"/);
  assert.match(js,/options\.onStartCapture\?\.\(\)/);
});
console.log('Section 91B12D: '+passed+' checks passed');
