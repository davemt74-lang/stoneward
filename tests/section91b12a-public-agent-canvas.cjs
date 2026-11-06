'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const crypto=require('node:crypto');
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
  assert.match(app,/if\(event\.detail\?\.view==='builder'\)/);
  assert.match(app,/SFPublicAgentCanvas\?\.unmount/);
});
check('Original agent lifecycle informs the new orb without changing speech backend',()=>{
  assert.match(app,/SFPublicAgentCanvas\?\.setState\?\.\(mode\)/);
  assert.match(app,/onOpenChat:\(\)=>\{void openChatPopup\(\)/);
  assert.match(moduleSource,/data-mode="idle"/);
});
check('Real recording launches the dedicated vocal studio while listening stays delegated',()=>{
  assert.match(app,/onStartCapture:\(\)=>openVocalStudio\('vocal'\)/);
  assert.match(app,/onListenInterpret:async\(\)=>/);
  assert.match(app,/SFPublicAgentCanvas\?\.syncSongExperience/);
  assert.match(moduleSource,/StonefellowSongExperience\.mount\(smallSession\)/);
  assert.match(moduleSource,/StonefellowSongExperience\.mount\(bigSession\)/);
});
check('Vinyl ring action opens the existing record builder',()=>{
  assert.match(moduleSource,/data-agent-action="vinyl-builder" data-orbit-action="vinyl" data-tip="Vinyl Record Builder" aria-label="Open vinyl record builder"/);
  assert.match(app,/onOpenRecordBuilder:\(\)=>void navigate\('builder'\)/);
});
check('Transcription, analysis and follow-up actions remain in the canonical session component',()=>{
  const card=read('assets/js/agent-song-experience-ui.js');
  assert.match(card,/button\(state\.listening_mode==='catalog'\?'Stop':'Stop recording', 'stop'\)/);
  assert.match(card,/button\('Stop waiting', 'stop'\)/);
  assert.match(card,/button\('Transcribe excerpt', 'transcribe'\)/);
  assert.match(card,/button\(state\.reports\?\.length \? 'Interpret again'/);
  assert.match(card,/onAction\?\.\('ask', question\)/);
  assert.match(moduleSource,/\.sf-agent-expanded-session/);
});
check('New canvas performs no recording, inference, storage or provider upload',()=>{
  assert.doesNotMatch(moduleSource,/\bfetch\s*\(|MediaRecorder|createMediaElementSource|getUserMedia|sessionStorage|XMLHttpRequest/);
  assert.match(moduleSource,/audio\.captureStream\|\|audio\.mozCaptureStream/);
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
check('Native workspace retains its positioning and keyboard close behavior',()=>{
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
  const orbitButtons=[];
  const clazz=()=>({values:new Set(),add(v){this.values.add(v);},remove(v){this.values.delete(v);},contains(v){return this.values.has(v);},
    toggle(v,force){const add=force===undefined?!this.values.has(v):!!force;add?this.values.add(v):this.values.delete(v);return add;}});
  const graphic={setTransform(){},clearRect(){},fillRect(){},beginPath(){},arc(){},fill(){},moveTo(){},lineTo(){},stroke(){},
    createRadialGradient(){return {addColorStop(){}};},ellipse(){}};
  class Element {
    constructor(name){this.name=name;this.listeners={};this.style={values:{},setProperty(k,v){this.values[k]=v;},removeProperty(k){const old=this.values[k]||'';delete this.values[k];return old;}};
      this.classList=clazz();this.dataset={};this.attributes={};this.hidden=false;this.paused=false;this.src='song.mp3';
      this.clientWidth=name==='root'?950:name==='cloud'?470:210;
      this.clientHeight=name==='root'?650:name==='cloud'?470:210;
      this.offsetWidth=210;this.offsetHeight=210;this.offsetLeft=0;this.offsetTop=0;}
    querySelector(selector){return elements[selector]||null;}
    querySelectorAll(selector){return selector==='button'&&this.name==='.sf-agent-orbit-tools'?orbitButtons:selector==='[data-agent-action="capture"]'?captureButtons:[];}
    addEventListener(type,fn){this.listeners[type]=fn;}
    removeEventListener(type){delete this.listeners[type];}
    get offsetLeft(){return Number.parseFloat(this.style.left)||this._offsetLeft||0;}
    set offsetLeft(value){this._offsetLeft=value;}
    get offsetTop(){return Number.parseFloat(this.style.top)||this._offsetTop||0;}
    set offsetTop(value){this._offsetTop=value;}
    getBoundingClientRect(){
      const rect=(left,top,width,height)=>({left,top,width,height,right:left+width,bottom:top+height});
      const root=elements['.sf-agent-landing'],floater=elements['.sf-agent-floater'],visual=elements['.sf-agent-visual'];
      if(this===root)return rect(0,74,this.clientWidth,this.clientHeight);
      const cx=floater.offsetLeft,cy=74+floater.offsetTop,v=visual.offsetWidth;
      const visualTop=cy-floater.offsetHeight*.5;
      if(this===floater)return rect(cx-floater.offsetWidth*.5,cy-floater.offsetHeight*.5,this.offsetWidth,this.offsetHeight);
      if(this===visual||this===elements['.sf-agent-sphere'])return rect(cx-v*.5,visualTop,v,v);
      if(/^orbit\d+$/.test(this.name)){
        const delta=value=>Number(value?.match(/(-?[\d.]+)px/)?.[1]||0);
        return rect(cx+delta(this.style.left)-22,visualTop+v*.5+delta(this.style.top)-22,44,44);
      }
      if(this===elements['.sf-agent-state'])return rect(cx-90,visualTop+v+20,180,12);
      return rect(30,40,this.clientWidth,this.clientHeight);
    }
    getContext(){return graphic;}
    setAttribute(k,v){this.attributes[k]=v;}
    removeAttribute(k){delete this.attributes[k];}
    setPointerCapture(){}
    appendChild(node){this.children=this.children||[];if(node.parentNode){node.parentNode.children=node.parentNode.children.filter(child=>child!==node);}this.children.push(node);node.parentNode=this;}
    insertBefore(node,other){this.children=this.children||[];if(node.parentNode){node.parentNode.children=node.parentNode.children.filter(child=>child!==node);}const index=other?this.children.indexOf(other):-1;if(index===-1)this.children.push(node);else this.children.splice(index,0,node);node.parentNode=this;}
    replaceChildren(...children){this.cleared=true;this.children=[];children.forEach(child=>this.appendChild(child));}
    get childElementCount(){return this.children?.length||0;}
    contains(node){return node===this||!!this.children?.some(child=>child.contains(node));}
    focus(){}
    remove(){this.removed=true;if(this.parentNode){this.parentNode.children=this.parentNode.children.filter(child=>child!==this);this.parentNode=null;}}
  }
  const selectors=['.sf-agent-landing','.sf-agent-floater','.sf-agent-visual','.sf-agent-sphere','.sf-agent-state',
    '.sf-agent-scene','#sfAgentSceneTitle','.sf-agent-scene-art','.sf-agent-scene-signal','.sf-agent-scene-backdrop',
    '.sf-agent-scene-catalog','[data-agent-action="open-scene-story"]','.sf-agent-scene-image-picker','.sf-agent-scene-footer-actions','[data-agent-action="close-scene"]','[data-agent-action="toggle-scene-code"]','summary','ul',
    '[data-agent-action="play-scene-track"]','[data-agent-action="feedback-scene"]','[data-agent-action="scene-history"]','[data-agent-action="new-lyric-section"]',
    '.sf-agent-coding','.sf-agent-coding-status','[data-agent-action="stop-scene-build"]','.sf-agent-coding-source','.sf-agent-coding-code',
    '.sf-agent-coding-live','.sf-agent-coding-validated','.sf-agent-coding-raw','.sf-agent-coding-html','.sf-agent-coding-css','.sf-agent-coding-js','.sf-agent-coding-javascript',
    '.sf-agent-coding-image-dialog','.sf-agent-coding-image-edit','.sf-agent-coding-image-replace',
    '.sf-agent-mesh','.sf-agent-cloud','.sf-agent-points','.sf-agent-workspace','.sf-agent-panel',
    '.sf-agent-orbit-tools','.sf-agent-lyric-controls','[data-agent-action="stop-lyrics"]',
    '.sf-agent-song-cue','.sf-agent-song-cue-stamp','.sf-agent-song-cue-title','.sf-agent-song-cue-status','[data-orbit-action="moments"]',
    '.sf-agent-session','.sf-agent-expanded-session','.sf-agent-page-rail','.sf-agent-page-title','.sf-agent-page-viewport','.sf-agent-page-content','.sf-agent-player-dock','.sf-agent-chat-start'];
  const elements={};for(const key of selectors)elements[key]=new Element(key);
  elements['.sf-agent-scene-footer-actions'].hidden=true;
  elements['.sf-agent-landing'].clientWidth=950;elements['.sf-agent-landing'].clientHeight=650;
  const rootElement=elements['.sf-agent-landing'];
  const target=new Element('target');target.querySelector=selector=>selector==='.sf-agent-landing'?rootElement:null;
  const audio=new Element('audio');const captureButtons=[new Element('capture1'),new Element('capture2')];
  orbitButtons.push(...Array.from({length:10},(_,index)=>{const button=new Element('orbit'+index);button.dataset.orbitAction='action'+index;return button;}));
  const footer=new Element('footer'),player=new Element('player'),composer=new Element('composer'),social=new Element('social');
  footer.appendChild(player);footer.appendChild(composer);footer.appendChild(social);player.nextSibling=composer;
  let captureInvoked=0,chatInvoked=0,voiceInvoked=0,recordBuilderInvoked=0,mountCalls=0,capturing=false,conversationActive=false;
  let sceneAccess=false,sceneClosed=0,historyOpened=0,sceneStops=0;
  const scenePlays=[],sceneStories=[],sceneRestores=[],sceneFeedbacks=[];
  let nextTimer=0;const timers=new Map();
  const window={location:{origin:'https://stonefellow.test'},matchMedia:()=>({matches:true}),addEventListener:(type,fn)=>events.set(type,fn),removeEventListener:type=>events.delete(type),
    setTimeout:(fn,delay)=>{const id=++nextTimer;timers.set(id,{fn,delay});return id;},clearTimeout:id=>timers.delete(id),requestAnimationFrame:()=>1,devicePixelRatio:1,
    StonefellowSongExperience:{active:()=>true,mount:host=>{mountCalls++;host.mounted=true;}}};
  const scope={window,URL,TextEncoder,document:{baseURI:'https://stonefellow.test/studio/',body:new Element('body'),querySelector:selector=>selector==='.chat-footer .social-links'?social:null,querySelectorAll:()=>[],createElement:tag=>new Element(tag)},ResizeObserver:class{observe(){}disconnect(){}},
    requestAnimationFrame:()=>1,cancelAnimationFrame(){},performance:{now:()=>1000}};
  vm.runInNewContext(moduleSource,scope);
  check('Initial UI mounts with a real capture/report card in both agent and workspace',()=>{
    assert.equal(window.SFPublicAgentCanvas.mount(target,{audio,player,isCapturing:()=>capturing,
      isConversationActive:()=>conversationActive,
      canUseScenes:()=>sceneAccess,onSceneClose:()=>sceneClosed++,onOpenSceneHistory:()=>historyOpened++,onRestoreScene:id=>sceneRestores.push(id),
      onSceneStop:()=>{sceneStops++;},
      onSceneFeedback:id=>sceneFeedbacks.push(id),
      onScenePlay:id=>scenePlays.push(id),onSceneStory:(track,story)=>sceneStories.push([track,story.id]),
      onStartChat:()=>voiceInvoked++,onStartCapture:()=>captureInvoked++,onOpenChat:()=>chatInvoked++,onOpenRecordBuilder:()=>recordBuilderInvoked++}),true);
    assert.equal(window.SFPublicAgentCanvas.active(),true);
    assert.equal(elements['.sf-agent-panel'].hidden,false);
    assert.equal(mountCalls,2);
    assert.equal(captureButtons[0].hidden,false);
    assert.equal(elements['.sf-agent-scene-footer-actions'].parentNode,social);
    assert.equal(elements['.sf-agent-scene-footer-actions'].hidden,true);
  });
  const fireAction=action=>rootElement.listeners.click({target:{closest:()=>({dataset:{agentAction:action}})}});
  fireAction('capture');fireAction('chat');fireAction('start-chat');
  assert.equal(captureInvoked,1);assert.equal(chatInvoked,1);assert.equal(voiceInvoked,1);
  check('Activating the vinyl action invokes only its record-builder callback',()=>{
    fireAction('vinyl-builder');
    assert.equal(recordBuilderInvoked,1);
    assert.equal(captureInvoked,1);
  });
  check('Ten ring actions retain 44px separation and fit a 320px viewport',()=>{
    const visual=elements['.sf-agent-visual'],floater=elements['.sf-agent-floater'];
    rootElement.clientWidth=320;visual.offsetWidth=visual.clientWidth=148;floater.offsetWidth=148;floater.style.left='';
    events.get('resize')();
    const centre=Number.parseFloat(floater.style.left);
    const points=orbitButtons.map(button=>({x:Number(button.style.left.match(/(-?[\d.]+)px/)[1]),y:Number(button.style.top.match(/(-?[\d.]+)px/)[1])}));
    assert.ok(points.every((point,index)=>centre+point.x-22>=-1&&centre+point.x+22<=321&&
      points.slice(0,index).every(other=>Math.hypot(point.x-other.x,point.y-other.y)>=44)));
    rootElement.clientWidth=950;visual.offsetWidth=visual.clientWidth=210;floater.offsetWidth=210;floater.style.left='';
    events.get('resize')();
  });
  check('Private scenes use an isolated frame and existing player and Song Moment callbacks',()=>{
    const canvas=window.SFPublicAgentCanvas,preview=elements['.sf-agent-scene-backdrop'];
    const scene={id:17,track_id:'SF-1',title:'Song <em>title</em>',html:'<article class="room"><h1>New scene</h1></article>',
      css:'.room{color:red}',artwork:'api/media.php?id=10&variant=medium',story:{id:7,at_ms:12500,title:'Origin'}};
    assert.equal(canvas.showScene(scene),false);
    assert.equal(canvas.setSceneCoding('generating'),false);
    assert.equal(elements['[data-agent-action="scene-history"]'].hidden,true);
    sceneAccess=true;canvas.syncSceneAccess();
    assert.equal(elements['[data-agent-action="scene-history"]'].hidden,false);
    assert.equal(canvas.setSceneCoding('generating'),true);
    assert.equal(elements['.sf-agent-coding'].dataset.state,'generating');
    assert.equal(elements['.sf-agent-coding-source'].hidden,true);
    assert.equal(canvas.appendSceneCode('raw','outside generation'),false);
    assert.equal(canvas.setSceneCoding('streaming'),true);
    assert.equal(canvas.appendSceneCode('raw','<h1>Partial'),true);
    assert.equal(canvas.appendSceneCode('raw',' text'),true);
    assert.equal(elements['.sf-agent-coding-raw'].textContent,'<h1>Partial text');
    assert.equal(preview.childElementCount,0);
    assert.equal(canvas.showScene(scene),true);
    assert.equal(elements['.sf-agent-scene-footer-actions'].hidden,false);
    assert.equal(elements['[data-agent-action="feedback-scene"]'].hidden,false);
    elements['[data-agent-action="feedback-scene"]'].listeners.click({stopPropagation(){}});
    assert.deepEqual(sceneFeedbacks,[17]);
    const frame=preview.children[0];
    assert.equal(frame.name,'iframe');assert.equal(frame.attributes.sandbox,'');assert.equal(frame.attributes.referrerpolicy,'no-referrer');
    assert.equal(frame.attributes.tabindex,'-1');
    assert.match(frame.srcdoc,/default-src 'none'; connect-src 'none'; media-src blob:; img-src blob:/);
    assert.match(frame.srcdoc,/style-src 'unsafe-inline'/);
    assert.match(frame.srcdoc,/<h1>New scene<\/h1>/);
    assert.equal(elements['#sfAgentSceneTitle'].textContent,'Canvas 1 · Listening Room');
    assert.equal(elements['#sfAgentSceneTitle'].attributes['aria-label'],'Canvas 1 · Listening Room for '+scene.title);
    assert.equal(elements['.sf-agent-mesh'].hidden,true);
    assert.equal(elements['.sf-agent-coding-raw'].textContent,'');
    assert.equal(elements['.sf-agent-scene-art'].src,'https://stonefellow.test/studio/api/media.php?id=10&variant=medium');
    assert.equal(elements['.sf-agent-coding'].dataset.state,'ready');
    assert.equal(elements['.sf-agent-coding-html'].textContent,scene.html);
    assert.equal(elements['.sf-agent-coding-css'].textContent,scene.css);
    fireAction('play-scene-track');fireAction('open-scene-story');
    assert.deepEqual(scenePlays,['SF-1']);assert.deepEqual(sceneStories,[['SF-1',7]]);
    for(const rejected of [
      {...scene,html:'<script>alert(1)</script>'},
      {...scene,html:'<section onclick="alert(1)">Hello</section>'},
      {...scene,css:'</style><script>alert(1)</script>'},
    ])assert.equal(canvas.showScene(rejected),false);
    assert.equal(preview.children[0],frame);
    assert.equal(canvas.showScene({...scene,html:'<p>Revision</p>',artwork:'https://untrusted.test/api/media.php?id=10'}),true);
    assert.equal(elements['.sf-agent-scene-art'].hidden,true);
    assert.equal(sceneClosed,0);
    assert.equal(canvas.sceneActive(),true);
  });
  check('Restored code stays closed and manually hidden live coding keeps receiving source without stopping the build',()=>{
    const canvas=window.SFPublicAgentCanvas,code=elements['[data-agent-action="toggle-scene-code"]'];
    assert.equal(elements['.sf-agent-coding'].hidden,true);
    assert.equal(code.attributes['aria-expanded'],'false');
    code.listeners.click({stopPropagation(){}});
    assert.equal(elements['.sf-agent-coding'].hidden,false);
    canvas.setSceneCoding('generating');
    code.listeners.click({stopPropagation(){}});
    canvas.setSceneCoding('streaming');canvas.appendSceneCode('raw','live source while closed');
    assert.equal(elements['.sf-agent-coding'].hidden,true);
    assert.equal(elements['.sf-agent-coding-raw'].textContent,'live source while closed');
    assert.equal(sceneStops,0);
    code.listeners.click({stopPropagation(){}});
    assert.equal(elements['.sf-agent-coding'].hidden,false);
    assert.equal(code.attributes['aria-expanded'],'true');
  });
  check('Custom JavaScript uses server hashes, shows below CSS and tears down its audio host on replacement',()=>{
    const canvas=window.SFPublicAgentCanvas;let hosts=0,disposed=0;
    const sdk='window.fixtureSDK=true;',js='const note="<!--<script></script>";document.querySelector("main").dataset.fixture="ok";';
    const hash=text=>crypto.createHash('sha256').update(text).digest('base64');
    window.SFCanvasAudioSDK={source:sdk,hash:"'sha256-"+hash(sdk)+"'",createHost:()=>{hosts++;return {refresh(){},destroy(){disposed++;}};}};
    const scene={id:81,canvas_kind:'custom',track_id:'',title:'Script fixture',html:'<article>Fixture</article>',css:'',js,js_hash:hash(js)};
    assert.equal(canvas.showScene(scene,{focus:false}),true);
    const frame=elements['.sf-agent-scene-backdrop'].children[0];
    assert.equal(frame.attributes.sandbox,'allow-scripts');
    assert.match(frame.srcdoc,/connect-src 'none'; media-src blob:/);
    assert.ok(frame.srcdoc.includes("'sha256-"+hash(js)+"'"));
    assert.ok(frame.srcdoc.indexOf(sdk)<frame.srcdoc.indexOf('id="sfCanvasProgram"'));
    assert.ok(!frame.srcdoc.includes(js));
    assert.ok(frame.srcdoc.includes('\\u003c!--\\u003cscript>'));
    assert.equal(elements['.sf-agent-coding-js'].textContent,js);
    assert.equal(elements['.sf-agent-coding-javascript'].hidden,false);
    assert.equal(canvas.showScene({...scene,js_hash:'not-a-hash'}),false);
    assert.equal(hosts,1);
    assert.equal(canvas.showScene({...scene,js:'',js_hash:undefined},{focus:false}),true);
    assert.equal(disposed,1);
    assert.equal(elements['.sf-agent-coding'].hidden,true);
    delete window.SFCanvasAudioSDK;
  });
  check('Canvas History renders native buttons, guards ownership callbacks and clears the view on sign-out',()=>{
    const canvas=window.SFPublicAgentCanvas,host=elements['.sf-agent-page-content'];
    fireAction('scene-history');assert.equal(historyOpened,1);
    assert.equal(canvas.showSceneHistory([{id:42,title:'<img src=invalid>',created_at:'2026-11-04T00:00:00Z'},{id:0}]),true);
    const list=host.children[1],entry=list.children[0],button=entry.children[1];
    assert.equal(list.childElementCount,1);assert.equal(entry.children[0].children[0].textContent,'<img src=invalid>');
    rootElement.listeners.click({target:{closest:()=>button}});assert.deepEqual(sceneRestores,[42]);
    assert.equal(canvas.closeSceneHistory(),true);assert.equal(host.childElementCount,0);
    assert.equal(canvas.closeSceneHistory(),false);assert.equal(canvas.sceneActive(),true);
    sceneAccess=false;canvas.syncSceneAccess();
    assert.equal(canvas.sceneActive(),false);assert.equal(sceneClosed,0);
    assert.equal(elements['.sf-agent-scene-footer-actions'].hidden,true);
    assert.equal(elements['.sf-agent-scene-backdrop'].childElementCount,0);
    assert.equal(elements['.sf-agent-mesh'].hidden,false);
    assert.equal(elements['.sf-agent-coding'].hidden,true);
    assert.equal(elements['.sf-agent-coding-html'].textContent,'');
    assert.equal(elements['[data-agent-action="scene-history"]'].hidden,true);
    assert.equal(canvas.showSceneHistory([]),false);
  });
  check('Auto-hide fires after 5.2 seconds and the orbital action restores the real player',()=>{
    const idle=Array.from(timers.values()).find(t=>t.delay===5200);
    assert.ok(idle);
    idle.fn();
    assert.equal(elements['.sf-agent-player-dock'].classList.contains('is-collapsed'),true);
    assert.equal(elements['.sf-agent-chat-start'].hidden,false);
    fireAction('show-player');
    assert.equal(elements['.sf-agent-player-dock'].classList.contains('is-collapsed'),false);
    conversationActive=true;window.SFPublicAgentCanvas.syncVoice();
    assert.equal(elements['.sf-agent-chat-start'].hidden,true);
    conversationActive=false;window.SFPublicAgentCanvas.syncVoice();
    assert.equal(elements['.sf-agent-chat-start'].hidden,false);
  });
  capturing=true;window.SFPublicAgentCanvas.setState('recording');window.SFPublicAgentCanvas.syncSongExperience();
  assert.equal(elements['.sf-agent-floater'].dataset.mode,'recording');
  assert.equal(captureButtons[0].hidden,false); // Studio stays available while lyric listening is active
  fireAction('close-panel');assert.equal(elements['.sf-agent-panel'].hidden,true);
  window.SFPublicAgentCanvas.setState('recording');window.SFPublicAgentCanvas.syncSongExperience();
  assert.equal(elements['.sf-agent-panel'].hidden,false);
  assert.equal(player.parentNode,elements['.sf-agent-player-dock']);
  assert.equal(elements['.sf-agent-player-dock'].classList.contains('has-track'),true);
  player.hidden=true;window.SFPublicAgentCanvas.syncPlayer();
  assert.equal(elements['.sf-agent-player-dock'].classList.contains('has-track'),false);
  assert.equal(elements['.sf-agent-player-dock'].hidden,true);
  player.hidden=false;window.SFPublicAgentCanvas.syncPlayer();
  assert.equal(elements['.sf-agent-player-dock'].classList.contains('has-track'),true);
  window.SFPublicAgentCanvas.unmount();
  assert.equal(player.parentNode,footer);
  assert.deepEqual(footer.children,[player,composer,social]);
  assert.equal(social.childElementCount,0);
  assert.equal(elements['.sf-agent-scene-footer-actions'].removed,true);
  assert.equal(elements['[data-agent-action="close-scene"]'].listeners.click,undefined);
  assert.equal(window.SFPublicAgentCanvas.active(),false);
  assert.equal(rootElement.removed,true);
});
check('Changed browser JavaScript parses',()=>{
  for(const f of ['assets/js/app.js','assets/js/public-agent-canvas.js','admin/assets/admin.js'])new vm.Script(read(f),{filename:f});
});
console.log('Section 91B12A: '+count+' canvas, integration and privacy assertions passed');
