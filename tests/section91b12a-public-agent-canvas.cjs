'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
let count=0;
const check=(name,fn)=>{fn();count++;console.log('91B12A PASS '+name);};
const moduleSource=read('assets/js/public-agent-canvas.js');
const app=read('assets/js/app.js');
const shell=read('stonefellow-v120.php');
const css=read('assets/css/public-agent-canvas.css');
const settings=read('api/site-settings.php');
const settingsController=read('admin/api/settings.php');
const admin=read('admin/assets/admin.js');

check('Public template is opt-in and defaults to classic',()=>{
  assert.match(settings,/site\.homepage_template','classic'/);
  assert.match(settings,/'homepage_template'.*'agent_canvas'/);
  assert.match(settings,/\['classic','agent_canvas'\]/);
  assert.match(settings,/Invalid homepage template/);
});
check('Admin selector persists the homepage template through the existing settings endpoint',()=>{
  assert.match(admin,/name="homepage_template"/);
  assert.match(admin,/homepage_template:f\.get\('homepage_template'\)/);
  assert.match(settingsController,/'homepage_template'=>\$body\['homepage_template'\]/);
});
check('Original header and footer are preserved in PHP',()=>{
  assert.match(shell,/<header class="topbar">/);
  assert.match(shell,/<footer class="chat-footer">/);
  assert.match(shell,/<form id="chatForm" class="chatbar"/);
  assert.match(shell,/<audio id="persistentAudio"/);
  assert.match(shell,/homepageTemplate/);
  assert.ok(shell.indexOf('public-agent-canvas.js')<shell.indexOf('assets/js/app.js'));
});
check('New experience mounts only on the selected public Home, leaving classic logic intact',()=>{
  assert.match(app,/homepageTemplate==='agent_canvas'&&await renderAgentCanvas\(\)/);
  assert.match(app,/if\(artistHomepageEnabled&&await renderArtistHomepage\(\)\)/);
  assert.match(app,/if\(event\.detail\?\.view!=='home'\)/);
  assert.match(app,/SFPublicAgentCanvas\?\.unmount/);
});
check('Original agent lifecycle informs the new orb without changing speech backend',()=>{
  assert.match(app,/SFPublicAgentCanvas\?\.setState\?\.\(mode\)/);
  assert.match(app,/onOpenChat:\(\)=>\{void openChatPopup\(\)/);
  assert.match(moduleSource,/data-mode="idle"/);
});
check('Real lyric capture is invoked by the new agent, not reimplemented',()=>{
  assert.match(app,/onStartCapture:async\(\)=>\{/);
  assert.match(app,/executeSongCaptureAction\(\{type:'song_capture_start'\}\)/);
  assert.match(app,/SFPublicAgentCanvas\?\.syncSongExperience/);
  assert.match(moduleSource,/StonefellowSongExperience\.mount\(smallSession\)/);
  assert.match(moduleSource,/StonefellowSongExperience\.mount\(bigSession\)/);
});
check('Transcription, analysis and follow-up actions remain in the canonical session component',()=>{
  const card=read('assets/js/agent-song-experience-ui.js');
  assert.match(card,/button\('Stop listening', 'stop'\)/);
  assert.match(card,/button\('Transcribe excerpt', 'transcribe'\)/);
  assert.match(card,/button\(state\.reports\?\.length \? 'Interpret again'/);
  assert.match(card,/onAction\?\.\('ask', question\)/);
  assert.match(moduleSource,/\.sf-agent-expanded-session/);
});
check('New canvas performs no recording, inference, storage or provider upload',()=>{
  assert.doesNotMatch(moduleSource,/\bfetch\s*\(|MediaRecorder|captureStream|getUserMedia|sessionStorage|XMLHttpRequest/);
  assert.match(moduleSource,/stonefellow\.agent\.atmosphere\.v1/);
  assert.match(moduleSource,/localStorage\?\.setItem\(atmosphereKey,name\)/);
  assert.match(moduleSource,/This module never records or uploads audio/);
  assert.match(moduleSource,/options\.onStartCapture/);
});
check('Follows user and roams across entire mesh with usable touch drag',()=>{
  assert.match(moduleSource,/window,'pointermove'/);
  assert.match(moduleSource,/function wander\(time\)/);
  assert.match(moduleSource,/floater,'pointerdown'/);
  assert.match(moduleSource,/function orbDraw\(t\)/);
  assert.match(moduleSource,/2900/);
  assert.doesNotMatch(moduleSource,/function faceDraw\(/);
});
check('Expands from orb coordinates and closes with keyboard',()=>{
  assert.match(moduleSource,/from\.left-rect\.left/);
  assert.match(moduleSource,/workspace\.classList\.add\('open'\)/);
  assert.match(moduleSource,/e\.key==='Escape'/);
  assert.match(css,/\.sf-agent-workspace\.open/);
});
check('Responsive, reduced-motion and session-scroll rules are present',()=>{
  assert.match(css,/@media\(max-width:720px\)/);
  assert.match(css,/@media\(prefers-reduced-motion:reduce\)/);
  assert.match(css,/\.sf-agent-session \{ max-height/);
  assert.match(css,/\.sf-agent-dock-stack \{ position:relative/);
});
check('New module loads without initiating capture or network work',()=>{
  const context={window:{}};
  vm.runInNewContext(moduleSource,context,{filename:'public-agent-canvas.js'});
  assert.equal(typeof context.window.SFPublicAgentCanvas.mount,'function');
  assert.equal(context.window.SFPublicAgentCanvas.active(),false);
  assert.equal(context.window.SFPublicAgentCanvas.syncSongExperience(),undefined);
});
check('Mounted canvas delegates capture, exposes genuine session UI and cleans up on navigation',()=>{
  const events=new Map();
  const clazz=()=>({values:new Set(),add(v){this.values.add(v);},remove(v){this.values.delete(v);},contains(v){return this.values.has(v);}});
  const graphic={setTransform(){},clearRect(){},fillRect(){},beginPath(){},arc(){},fill(){},moveTo(){},lineTo(){},stroke(){},
    createRadialGradient(){return {addColorStop(){}};},ellipse(){}};
  class Element {
    constructor(name){this.name=name;this.listeners={};this.style={values:{},setProperty(k,v){this.values[k]=v;}};
      this.classList=clazz();this.dataset={};this.attributes={};this.hidden=false;this.paused=false;this.src='song.mp3';
      this.clientWidth=name==='root'?950:name==='cloud'?470:210;
      this.clientHeight=name==='root'?650:name==='cloud'?470:210;
      this.offsetWidth=210;this.offsetHeight=210;this.offsetLeft=0;this.offsetTop=0;}
    querySelector(selector){return elements[selector]||null;}
    querySelectorAll(selector){return selector==='[data-agent-action="capture"]'?captureButtons:[];}
    addEventListener(type,fn){this.listeners[type]=fn;}
    removeEventListener(type){delete this.listeners[type];}
    getBoundingClientRect(){return {left:30,top:40,width:this.clientWidth,height:this.clientHeight};}
    getContext(){return graphic;}
    setAttribute(k,v){this.attributes[k]=v;}
    removeAttribute(k){delete this.attributes[k];}
    setPointerCapture(){}
    appendChild(node){this.children=this.children||[];if(node.parentNode){node.parentNode.children=node.parentNode.children.filter(child=>child!==node);}this.children.push(node);node.parentNode=this;}
    insertBefore(node,other){this.children=this.children||[];if(node.parentNode){node.parentNode.children=node.parentNode.children.filter(child=>child!==node);}const index=other?this.children.indexOf(other):-1;if(index===-1)this.children.push(node);else this.children.splice(index,0,node);node.parentNode=this;}
    replaceChildren(){this.cleared=true;}
    remove(){this.removed=true;}
  }
  const selectors=['.sf-agent-landing','.sf-agent-floater','.sf-agent-visual','.sf-agent-sphere','.sf-agent-state',
    '.sf-agent-mesh','.sf-agent-cloud','.sf-agent-points','.sf-agent-workspace','.sf-agent-panel',
    '.sf-agent-session','.sf-agent-expanded-session','.sf-agent-player-dock','.sf-agent-player-reveal'];
  const elements={};for(const key of selectors)elements[key]=new Element(key);
  elements['.sf-agent-landing'].clientWidth=950;elements['.sf-agent-landing'].clientHeight=650;
  const rootElement=elements['.sf-agent-landing'];
  const target=new Element('target');target.querySelector=selector=>selector==='.sf-agent-landing'?rootElement:null;
  const audio=new Element('audio');const captureButtons=[new Element('capture1'),new Element('capture2')];
  const footer=new Element('footer'),player=new Element('player'),composer=new Element('composer');
  footer.appendChild(player);footer.appendChild(composer);player.nextSibling=composer;
  let captureInvoked=0,chatInvoked=0,mountCalls=0,capturing=false;
  let nextTimer=0;const timers=new Map();
  const window={matchMedia:()=>({matches:true}),addEventListener:(type,fn)=>events.set(type,fn),removeEventListener:type=>events.delete(type),
    setTimeout:(fn,delay)=>{const id=++nextTimer;timers.set(id,{fn,delay});return id;},clearTimeout:id=>timers.delete(id),devicePixelRatio:1,
    StonefellowSongExperience:{active:()=>true,mount:host=>{mountCalls++;host.mounted=true;}}};
  const scope={window,document:{body:{dataset:{}},querySelectorAll:()=>[]},ResizeObserver:class{observe(){}disconnect(){}},
    requestAnimationFrame:()=>1,cancelAnimationFrame(){},performance:{now:()=>1000}};
  vm.runInNewContext(moduleSource,scope);
  check('Initial UI mounts with a real capture/report card in both agent and workspace',()=>{
    assert.equal(window.SFPublicAgentCanvas.mount(target,{audio,player,isCapturing:()=>capturing,
      onStartCapture:()=>captureInvoked++,onOpenChat:()=>chatInvoked++}),true);
    assert.equal(window.SFPublicAgentCanvas.active(),true);
    assert.equal(elements['.sf-agent-panel'].hidden,false);
    assert.equal(mountCalls,2);
    assert.equal(captureButtons[0].hidden,false);
  });
  const fireAction=action=>rootElement.listeners.click({target:{closest:()=>({dataset:{agentAction:action}})}});
  fireAction('capture');fireAction('chat');
  assert.equal(captureInvoked,1);assert.equal(chatInvoked,1);
  check('Auto-hide fires after 5.2 seconds and the reveal action restores the real player',()=>{
    const idle=Array.from(timers.values()).find(t=>t.delay===5200);
    assert.ok(idle);
    idle.fn();
    assert.equal(elements['.sf-agent-player-dock'].classList.contains('is-collapsed'),true);
    assert.equal(elements['.sf-agent-player-reveal'].hidden,false);
    fireAction('show-player');
    assert.equal(elements['.sf-agent-player-dock'].classList.contains('is-collapsed'),false);
    assert.equal(elements['.sf-agent-player-reveal'].hidden,true);
  });
  capturing=true;window.SFPublicAgentCanvas.setState('recording');window.SFPublicAgentCanvas.syncSongExperience();
  assert.equal(elements['.sf-agent-floater'].dataset.mode,'recording');
  assert.equal(captureButtons[0].hidden,true);
  fireAction('close-panel');assert.equal(elements['.sf-agent-panel'].hidden,true);
  window.SFPublicAgentCanvas.setState('recording');window.SFPublicAgentCanvas.syncSongExperience();
  assert.equal(elements['.sf-agent-panel'].hidden,false);
  assert.equal(player.parentNode,elements['.sf-agent-player-dock']);
  assert.equal(elements['.sf-agent-player-dock'].classList.contains('has-track'),true);
  player.hidden=true;window.SFPublicAgentCanvas.syncPlayer();
  assert.equal(elements['.sf-agent-player-dock'].classList.contains('has-track'),false);
  assert.equal(elements['.sf-agent-player-dock'].hidden,false);
  player.hidden=false;window.SFPublicAgentCanvas.syncPlayer();
  assert.equal(elements['.sf-agent-player-dock'].classList.contains('has-track'),true);
  window.SFPublicAgentCanvas.unmount();
  assert.equal(player.parentNode,footer);
  assert.deepEqual(footer.children,[player,composer]);
  assert.equal(window.SFPublicAgentCanvas.active(),false);
  assert.equal(rootElement.removed,true);
});
check('Changed browser JavaScript parses',()=>{
  for(const f of ['assets/js/app.js','assets/js/public-agent-canvas.js','admin/assets/admin.js'])new vm.Script(read(f),{filename:f});
});
console.log('Section 91B12A: '+count+' canvas, integration and privacy assertions passed');
