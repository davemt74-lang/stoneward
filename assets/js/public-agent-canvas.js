/* Section 91B12A — floating particle agent, based on the approved public prototype.
 * Presentation only: actual capture, transcript and reports are supplied by
 * StonefellowSongExperience. This module never records or uploads audio.
 */
(() => {
  'use strict';
  let current = null;
  const by = (root, selector) => root.querySelector(selector);
  const reduced = () => !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const html = `
    <section class="sf-agent-landing" aria-label="Stonefellow interactive Agent Canvas">
      <canvas class="sf-agent-mesh" aria-hidden="true"></canvas>
      <aside class="sf-agent-page-rail" hidden inert aria-label="Stonefellow native page panel">
        <header class="sf-agent-page-head">
          <div><small>STONEFELLOW / LIVE WORKSPACE</small><strong class="sf-agent-page-title">PAGE</strong></div>
          <button type="button" data-agent-action="close-page" aria-label="Close page panel">×</button>
        </header>
        <div class="sf-agent-page-viewport" tabindex="0" aria-label="Page content">
          <div id="sfAgentNativePage" class="sf-agent-page-content"></div>
        </div>
      </aside>
      <div class="sf-agent-floater" data-mode="idle" aria-label="Floating Stonefellow agent; drag to move">
        <div class="sf-agent-visual">
          <div class="sf-agent-aura" aria-hidden="true"></div>
          <canvas class="sf-agent-cloud" aria-hidden="true"></canvas>
          <div class="sf-agent-ring" aria-hidden="true"></div>
          <div class="sf-agent-orbit" aria-hidden="true"></div>
          <div class="sf-agent-orbit secondary" aria-hidden="true"></div>
          <div class="sf-agent-orbit-tools" role="group" aria-label="Agent orb actions">
            <button type="button" data-agent-action="listen-interpret" data-orbit-action="listen" data-tip="Listen &amp; Interpret" aria-label="Listen and interpret the current lyrics">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h2l2-5 3 10 3-13 3 16 3-8h2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" data-agent-action="capture" data-orbit-action="record" data-tip="Record Clip" aria-label="Record a song clip">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7.1" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="3.8" fill="currentColor"/></svg>
            </button>
            <button type="button" data-agent-action="show-player" data-orbit-action="player" data-tip="Music Player" aria-label="Show music player">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18V5l11-2v13" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6.5" cy="18" r="2.5" fill="currentColor"/><circle cx="17.5" cy="16" r="2.5" fill="currentColor"/></svg>
            </button>
            <button type="button" data-agent-action="stop-listening" data-orbit-action="stop" data-tip="Stop listening" aria-label="Stop lyric listening" hidden>
              <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6.5" y="6.5" width="11" height="11" rx="1.5" fill="currentColor"/></svg>
            </button>
          </div>
          <button class="sf-agent-sphere" type="button" aria-label="Expand Stonefellow Agent canvas" aria-expanded="false">
            <canvas class="sf-agent-points" aria-hidden="true"></canvas>
            <span class="sf-agent-core-fallback" aria-hidden="true"></span>
          </button>
        </div>
        <div class="sf-agent-state" role="status">AGENT READY</div>
        <div class="sf-agent-live-feed" aria-label="Live transcription and Agent Chat">
          <div class="sf-agent-live-transcript-slot"></div>
          <div class="sf-agent-live-preview"><span class="sf-agent-live-label">AGENT CHAT</span><span class="sf-agent-live-message">Ready for a conversation</span></div>
          <button type="button" class="sf-agent-live-open" data-agent-action="chat" aria-label="Open Agent Chat" title="Open Agent Chat">↗</button>
        </div>
        <div class="sf-agent-dock-stack">
          <div id="sfAgentPlayerDock" class="sf-agent-player-dock" aria-label="Agent music player">
            <div class="sf-agent-player-ready">MUSIC / PLAYER READY</div>
          </div>
          <button type="button" class="sf-agent-chat-start" data-agent-action="start-chat" aria-label="Start voice chat with Stonefellow">◉ Start Chat</button>
        </div>
        <aside class="sf-agent-panel" hidden aria-label="Active song lyric capture and analysis">
          <div class="sf-agent-panel-head"><span>LYRIC / CAPTURE · AGENT PROCESS</span><button type="button" data-agent-action="close-panel" aria-label="Hide session panel">×</button></div>
          <div class="sf-agent-session"></div>
        </aside>
      </div>
      <section class="sf-agent-workspace" aria-label="Expanded Agent Canvas" aria-hidden="true" inert>
        <div class="sf-agent-workspace-inner">
          <div class="sf-agent-workspace-head"><strong>STONEFELLOW / AGENT CANVAS</strong><button type="button" data-agent-action="close-workspace">Close canvas ×</button></div>
          <div class="sf-agent-workspace-body">
            <h2>What shall we explore?</h2>
            <p>The agent can bring music, lyrics, analysis and conversation into this space. Your current session stays here when you expand the canvas.</p>
            <div class="sf-agent-expanded-session"></div>
            <div class="sf-agent-tools"><button type="button" data-agent-action="chat">Open Agent Chat</button><button type="button" data-agent-action="music">Explore music</button><button type="button" data-agent-action="listen-interpret">Listen &amp; Interpret</button><button type="button" data-agent-action="capture">Record Clip</button><button type="button" data-agent-action="stop-listening" hidden>Stop listening</button></div>
          </div>
        </div>
      </section>
    </section>`;

  function mount(target, options = {}) {
    unmount();
    if (!target) return false;
    target.innerHTML = html;
    const root = by(target, '.sf-agent-landing');
    const floater = by(root, '.sf-agent-floater');
    const visual = by(root, '.sf-agent-visual');
    const orbitTools = by(root, '.sf-agent-orbit-tools');
    const sphere = by(root, '.sf-agent-sphere');
    const status = by(root, '.sf-agent-state');
    const liveHost = by(root, '.sf-agent-live-feed');
    const liveSlot = by(root, '.sf-agent-live-transcript-slot');
    const liveMessage = by(root, '.sf-agent-live-message');
    const mesh = by(root, '.sf-agent-mesh');
    const cloud = by(root, '.sf-agent-cloud');
    const face = by(root, '.sf-agent-points');
    const workspace = by(root, '.sf-agent-workspace');
    const pageRail = by(root, '.sf-agent-page-rail');
    const pageTitle = by(root, '.sf-agent-page-title');
    const pageViewport = by(root, '.sf-agent-page-viewport');
    const pageHost = by(root, '.sf-agent-page-content');
    const panel = by(root, '.sf-agent-panel');
    const playerDock = by(root, '.sf-agent-player-dock');
    const chatStart = by(root, '.sf-agent-chat-start');
    const smallSession = by(root, '.sf-agent-session');
    const bigSession = by(root, '.sf-agent-expanded-session');
    const contexts = [mesh, cloud, face].map(c => c.getContext('2d'));
    // The mesh lives behind the entire shell, including the transparent
    // header and footer, instead of being cropped at the stage boundary.
    if(document.body?.appendChild)document.body.appendChild(mesh);
    if (contexts.some(c => !c)) root.classList.add('no-canvas');
    const motionOff = reduced();
    const pointer = {x:0,y:0,driftX:0,driftY:0};
    const gaze = {x:0,y:0,targetX:0,targetY:0};
    const dim = {w:1,h:1,dpr:1};
    const particles = Array.from({length:110}, (_, i) => ({a:i*2.39996,r:110+(i%10)*13,v:.25+(i%6)*.08,s:i*1.37}));
    const stars = Array.from({length:110}, (_, i) => ({x:((i*73.413+11)%100)/100,y:((i*39.127+7)%100)/100,r:i%11===0?1.6:.65,phase:i*1.717}));
    const presets=Object.freeze({
      ember:{label:'Ember',rgb:[255,183,110],mesh:false},
      mesh:{label:'Blue Mesh',rgb:[139,204,255],mesh:true},
      violet:{label:'Violet Signal',rgb:[205,170,255],mesh:true},
      lunar:{label:'Moonlit Horizon',rgb:[219,228,244],mesh:false}
    });
    const atmosphereKey='stonefellow.agent.atmosphere.v1';
    let atmosphere='ember';
    const pointCount=window.matchMedia?.('(max-width: 720px)').matches?1500:2900;
    // Fibonacci sphere: every point belongs to the same kinetic orb,
    // with no eyes, mouth or flattened face mask.
    const orbPoints=Array.from({length:pointCount},(_,i)=>{
      const y=1-2*(i+.5)/pointCount,r=Math.sqrt(Math.max(0,1-y*y)),a=i*2.399963229728653;
      return {x:Math.cos(a)*r,y,z:Math.sin(a)*r,seed:i*.913};
    });
    let mode='idle', raf=0, destroyed=false, dragging=null, hovered=false, suppressClick=false;
    let frameSize=0,lastFrame=0,workspaceTimer=0,playerTimer=0,remotePanelDismissed=false;
    const roam = {step:0,fromX:0,fromY:0,toX:0,toY:0,start:0,delay:0};
    const spots = [[.20,.24],[.80,.18],[.66,.74],[.13,.68],[.83,.49],[.47,.35]];
    const on = (node,event,fn,opts) => { node?.addEventListener(event,fn,opts); return () => node?.removeEventListener(event,fn,opts); };
    const cleanup = [];
    const audio = options.audio || null;
    // Reparent the ONE authoritative player; its existing controls, audio and
    // event listeners remain intact. Return it to the footer on unmount.
    const player = options.player || null;
    const originalPlayerParent = player?.parentNode || null;
    const originalPlayerNext = player?.nextSibling || null;
    if (player && originalPlayerParent) playerDock.insertBefore(player,playerDock.firstChild);
    // Keep one authoritative transcript node; move it into the orb's live
    // feed only while this canvas is mounted, and restore it on navigation.
    const voiceTranscript = document.getElementById?.('voiceTranscript') || null;
    const originalTranscriptParent = voiceTranscript?.parentNode || null;
    const originalTranscriptNext = voiceTranscript?.nextSibling || null;
    if(voiceTranscript && originalTranscriptParent && liveSlot)liveSlot.appendChild(voiceTranscript);
    function syncTranscript(){
      if(!liveHost)return;
      const visible=!!voiceTranscript&&!voiceTranscript.hidden;
      if(visible)liveHost.classList.add('has-live-transcript');
      else liveHost.classList.remove('has-live-transcript');
    }
    function showConversation(role,text){
      if(!liveMessage||!['user','assistant'].includes(role))return;
      const value=String(text||'').trim();if(!value)return;
      liveMessage.textContent=(role==='user'?'You: ':'Stonefellow: ')+value.slice(0,180);
      liveHost.classList.add('has-conversation');
    }
    if(options.initialConversation)showConversation(options.initialConversation.role,options.initialConversation.text);
    syncTranscript();
    const PLAYER_IDLE_MS=5200;
    function pausePlayerHide(){window.clearTimeout(playerTimer);playerTimer=0;}
    function schedulePlayerHide(){
      pausePlayerHide();
      playerTimer=window.setTimeout(hidePlayer,PLAYER_IDLE_MS);
    }
    function showPlayer(){
      playerDock.classList.remove('is-collapsed');
      schedulePlayerHide();
    }
    function hidePlayer(){
      if(destroyed)return;
      const interacting=playerDock.matches?.(':hover')||visual.matches?.(':hover')||
        playerDock.contains?.(document.activeElement)||workspace.classList.contains('open');
      if(interacting){schedulePlayerHide();return;}
      playerDock.classList.add('is-collapsed');
      pausePlayerHide();
    }
    function syncPlayer() {
      const loaded=!!player && !player.hidden;
      const changed=loaded!==playerDock.classList.contains('has-track');
      playerDock.classList[loaded?'add':'remove']('has-track');
      playerDock.hidden=!loaded;
      if(changed)showPlayer();
      if(!loaded)pausePlayerHide();
      syncListeningButtons();
      updateDock();
    }
    function syncVoice(){
      if(!chatStart)return;
      const active=!!options.isConversationActive?.()&&!options.needsVoiceGesture?.();
      chatStart.hidden=active;
      chatStart.textContent=options.needsVoiceGesture?.()?'◉ Resume Chat':'◉ Start Chat';
    }
    const playerObserver = player && typeof MutationObserver === 'function'
      ? new MutationObserver(syncPlayer) : null;
    playerObserver?.observe(player, {attributes:true,attributeFilter:['hidden']});
    function state(next,label) {
      const map={playing:'music',recording:'recording',listening:'listening',thinking:'thinking',speaking:'speaking',responding:'speaking',music:'music'};
      mode=map[next] || 'idle';
      if(mode==='recording')remotePanelDismissed=false;
      floater.dataset.mode=mode;
      status.textContent=label || ({idle:'AGENT READY',music:'MUSIC PLAYING',listening:'LISTENING',thinking:'THINKING',speaking:'RESPONDING',recording:'CAPTURING LYRICS'}[mode]);
      syncListeningButtons();
    }
    function positionOrbitTools(){
      if(!orbitTools)return;
      const visible=Array.from(orbitTools.querySelectorAll('button')).filter(button=>!button.hidden);
      const count=visible.length, radius=visual.offsetWidth*.5+(visual.offsetWidth>175?78:58);
      const spread=count<2?0:Math.min(168,56+(count-2)*42);
      visible.forEach((button,index)=>{
        const deg=-90+(count<2?0:-spread/2+spread*index/(count-1));
        const rad=deg*Math.PI/180;
        button.style.left='calc(50% + '+(Math.cos(rad)*radius).toFixed(1)+'px)';
        button.style.top='calc(50% + '+(Math.sin(rad)*radius).toFixed(1)+'px)';
      });
    }
    function syncListeningButtons() {
      const capturing=!!options.isCapturing?.();
      const playable=!!(audio && (audio.currentSrc || audio.src) && !audio.paused);
      for(const action of ['listen-interpret','capture'])for(const button of root.querySelectorAll(`[data-agent-action="${action}"]`)){
        button.hidden=capturing;
        button.disabled=!playable;
        button.title=!playable?'Play a Stonefellow song to use this tool.':action==='listen-interpret'?'Listen & Interpret':'Record Clip';
      }
      for(const button of root.querySelectorAll('[data-agent-action="stop-listening"]'))button.hidden=!capturing;
      for(const button of root.querySelectorAll('[data-orbit-action="player"]'))button.hidden=!player||player.hidden;
      positionOrbitTools();
    }
    function session() {
      const active=!!window.StonefellowSongExperience?.active?.();
      if (!active) { panel.hidden=true; smallSession.replaceChildren(); bigSession.replaceChildren(); updateDock(); return; }
      if (!remotePanelDismissed) panel.hidden=false;
      window.StonefellowSongExperience.mount(smallSession);
      window.StonefellowSongExperience.mount(bigSession);
      updateDock();
      syncListeningButtons();
    }
    function updateDock() {
      const half=floater.offsetWidth*.5;
      const availableRight=root.clientWidth-(floater.offsetLeft+half);
      const availableLeft=floater.offsetLeft-half;
      const room=(panel.offsetWidth||365)+20;
      floater.dataset.side=root.clientWidth<1160?'below':
        availableRight>=room?'right':availableLeft>=room?'left':availableRight>=availableLeft?'right':'left';
      floater.dataset.vertical=floater.offsetTop>root.clientHeight*.55?'above':'below';
    }
    function position(x,y) {
      const margin=Math.min(root.clientWidth*.2,visual.offsetWidth*.65+24);
      const minX=Math.min(root.clientWidth*.5,Math.max(margin,floater.offsetWidth*.5+18));
      // Reserve room for the orbit rings above the face and the player below.
      // The sidecar is absolute, so its height never forces the orb under the header.
      const minY=Math.min(root.clientHeight*.5,floater.offsetHeight*.5+78);
      const maxY=Math.max(minY,root.clientHeight-floater.offsetHeight*.5-16);
      const xx=Math.max(minX,Math.min(root.clientWidth-minX,x));
      const yy=Math.max(minY,Math.min(maxY,y));
      floater.style.left=xx+'px'; floater.style.top=yy+'px'; updateDock();
    }
    function nextSpot() {
      const spot=spots[roam.step++%spots.length];
      return {x:root.clientWidth*(.14+.72*spot[0]),y:root.clientHeight*(.14+.72*spot[1])};
    }
    function wander(time) {
      if (motionOff||dragging||hovered||workspace.classList.contains('open'))return;
      if (root.clientWidth<721) {
        position(root.clientWidth*.5,root.clientHeight*.36);return;
      }
      if(!roam.start){roam.fromX=floater.offsetLeft||root.clientWidth*.64;roam.fromY=floater.offsetTop||root.clientHeight*.46;const n=nextSpot();roam.toX=n.x;roam.toY=n.y;roam.start=time;}
      if(time<roam.delay)return;
      const pct=Math.max(0,Math.min(1,(time-Math.max(roam.start,roam.delay))/7800));
      const eased=(1-Math.cos(Math.PI*pct))*.5;
      position(roam.fromX+(roam.toX-roam.fromX)*eased,roam.fromY+(roam.toY-roam.fromY)*eased);
      if(pct>=1){roam.fromX=roam.toX;roam.fromY=roam.toY;const n=nextSpot();roam.toX=n.x;roam.toY=n.y;roam.start=time;roam.delay=0;}
    }
    function fit(canvas,ctx) {
      if (!ctx)return;
      const rect=canvas.getBoundingClientRect(),dpr=Math.min(window.devicePixelRatio||1,2);
      canvas.width=Math.max(1,Math.round(rect.width*dpr));canvas.height=Math.max(1,Math.round(rect.height*dpr));
      ctx.setTransform(dpr,0,0,dpr,0,0);
    }
    function resize() {
      dim.w=Math.max(1,root.clientWidth);dim.h=Math.max(1,root.clientHeight);
      for(let i=0;i<2;i++)fit([mesh,cloud][i],contexts[i]);
      frameSize=0;
      positionOrbitTools();
      if(!floater.style.left)position(dim.w*.64,dim.h*.46);
    }
    function applyAtmosphere(value,persist=true){
      const name=Object.prototype.hasOwnProperty.call(presets,String(value||''))?String(value):null;
      if(!name)return false;
      atmosphere=name;
      root.dataset.atmosphere=name;
      document.body.dataset.sfAtmosphere=name;
      for(const button of document.querySelectorAll('[data-sf-atmosphere]')){
        button.setAttribute('aria-pressed',String(button.dataset.sfAtmosphere===name));
      }
      if(persist){try{window.localStorage?.setItem(atmosphereKey,name)}catch(_){}}
      if(motionOff){meshDraw(0);cloudDraw(0);orbDraw(0)}
      return name;
    }
    function meshDraw(t) {
      const ctx=contexts[0];if(!ctx)return;
      const w=mesh.clientWidth||dim.w,h=mesh.clientHeight||dim.h,m=motionOff?0:t*.00023;
      ctx.clearRect(0,0,w,h);
      if(!presets[atmosphere].mesh){
        // Photographic atmospheres preserve the scene without a drawn grid.
        // Ember has warm sparks; Lunar has sparse silver moonlit dust.
        const lunar=atmosphere==='lunar';
        for(const star of stars.slice(0,44)){
          const alpha=Math.max(.04,(lunar?0.07:0.13)+(lunar?0.09:0.16)*Math.sin(m*3+star.phase));
          ctx.fillStyle=(lunar?'rgba(222,233,247,':'rgba(255,184,110,')+alpha+')';
          ctx.beginPath();ctx.arc(star.x*w,star.y*h,star.r*.65,0,Math.PI*2);ctx.fill();
        }
        return;
      }
      const violet=atmosphere==='violet';
      const halo=ctx.createRadialGradient(w*.68,h*.47,0,w*.68,h*.47,Math.max(w,h)*.6);
      halo.addColorStop(0,violet?'#8a4ce82b':'#4e6fdb2b');halo.addColorStop(1,'#00000000');ctx.fillStyle=halo;ctx.fillRect(0,0,w,h);
      for(const s of stars){ctx.fillStyle=(violet?'rgba(226,191,255,':'rgba(190,219,252,')+Math.max(.10,.25+.31*Math.sin(m*3+s.phase))+')';ctx.beginPath();ctx.arc(s.x*w,s.y*h,s.r,0,Math.PI*2);ctx.fill();}
      const cols=24,rows=13,grid=[];
      for(let y=0;y<=rows;y++){const r=[];for(let x=0;x<=cols;x++){
        const u=x/cols,v=y/rows,weight=Math.max(0,1-Math.abs(u-.65)*1.8);
        r.push({x:w*(.04+u*.92)+Math.sin(x*.57+y*.42+m*2)*12*weight+Math.cos(y*.5+m)*9+pointer.driftX*.014,
          y:h*(-.07+v*1.16)+Math.sin(x*.44+y*.58+m*1.6)*15*weight+pointer.driftY*.01,weight});
      }grid.push(r);}
      ctx.lineWidth=.8;
      const link=(a,b,alpha)=>{ctx.strokeStyle=(violet?'rgba(175,130,241,':'rgba(104,172,241,')+Math.max(.035,alpha)+')';ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);ctx.stroke();};
      for(let y=0;y<=rows;y++)for(let x=0;x<=cols;x++){
        const p=grid[y][x],level=.16+.13*p.weight+.035*Math.sin((x+y)*.52-m*1.8);
        if(x<cols)link(p,grid[y][x+1],level);
        if(y<rows)link(p,grid[y+1][x],level);
        if(y<rows&&x<cols&&(x+y)%2===0)link(p,grid[y+1][x+1],level*.52);
        if((x+y)%3===0||p.weight>.8){ctx.fillStyle=(violet?'rgba(220,176,255,':'rgba(174,220,255,')+(.24+.26*p.weight)+')';ctx.beginPath();ctx.arc(p.x,p.y,p.weight>.7?1.65:1.1,0,Math.PI*2);ctx.fill();}
      }
    }
    function gazeAt(x,y) {
      const r=sphere.getBoundingClientRect(),dx=x-r.left-r.width/2,dy=y-r.top-r.height/2;
      const d=Math.max(1,Math.hypot(dx,dy)),f=Math.min(1,d/110);
      gaze.targetX=dx/d*12*f;gaze.targetY=dy/d*9*f;
      const s=root.getBoundingClientRect();pointer.driftX=x-s.left-s.width/2;pointer.driftY=y-s.top-s.height/2;
      if(motionOff){gaze.x=gaze.targetX;gaze.y=gaze.targetY;}
    }
    function spectrum(t) {
      return Array.from({length:32},(_,i)=>Math.max(.04,Math.min(.98,.30+Math.sin(t*.008+i*.73)*.28+Math.sin(t*.004+i*.31)*.22)));
    }
    function cloudDraw(t) {
      const ctx=contexts[1];if(!ctx)return;
      const w=cloud.clientWidth,h=cloud.clientHeight,cx=w/2,cy=h/2,sec=motionOff?0:t*.001;
      ctx.clearRect(0,0,w,h);
      const strong=mode==='music'||mode==='speaking',thinking=mode==='thinking';
      const tint=mode==='recording'?'255,118,155':presets[atmosphere].rgb.join(',');
      if(strong){const levels=spectrum(t),radius=visual.clientWidth*.5+30;
        for(let i=0;i<levels.length;i++){const angle=i/levels.length*Math.PI*2-Math.PI/2,amp=levels[i],inner=radius+8;
          ctx.strokeStyle='rgba('+tint+','+(.27+amp*.67)+')';ctx.lineWidth=2.2+amp*3;ctx.beginPath();ctx.moveTo(cx+Math.cos(angle)*inner,cy+Math.sin(angle)*inner);ctx.lineTo(cx+Math.cos(angle)*(inner+7+amp*50),cy+Math.sin(angle)*(inner+7+amp*50));ctx.stroke();
        }
      }
      for(const p of particles){const angle=p.a+sec*p.v*(thinking?5:strong?2.6:1),distance=(p.r+Math.sin(sec*.8+p.s)*9)*(thinking?.68:strong?1.16:1);
        const x=cx+Math.cos(angle)*distance,y=cy+Math.sin(angle)*distance*.72;
        ctx.fillStyle='rgba('+tint+','+(.43+.24*Math.sin(sec*1.7+p.s))+')';ctx.beginPath();ctx.arc(x,y,strong?2.6:2,0,Math.PI*2);ctx.fill();
      }
    }
    function orbDraw(t){
      const ctx=contexts[2];if(!ctx)return;
      const size=Math.max(1,sphere.offsetWidth),dpr=Math.min(window.devicePixelRatio||1,2);
      if(size!==frameSize){frameSize=size;face.width=Math.round(size*dpr);face.height=Math.round(size*dpr);ctx.setTransform(dpr,0,0,dpr,0,0);}
      const c=size*.5,sec=motionOff?0:t*.001,base=presets[atmosphere].rgb;
      const color=mode==='recording'?[255,129,150]:base;
      const rgb=color.join(','),r=size*.427;
      ctx.clearRect(0,0,size,size);
      const glow=ctx.createRadialGradient(c,c,r*.12,c,c,r*1.19);
      glow.addColorStop(0,'rgba('+rgb+',.075)');
      glow.addColorStop(.56,'rgba('+rgb+',.045)');
      glow.addColorStop(.86,'rgba('+rgb+',.12)');
      glow.addColorStop(1,'rgba('+rgb+',0)');
      ctx.fillStyle=glow;ctx.beginPath();ctx.arc(c,c,r*1.19,0,Math.PI*2);ctx.fill();
      const spin=sec*(mode==='thinking'?.72:mode==='music'?.37:.19);
      const sin=Math.sin(spin),cos=Math.cos(spin);
      const tilt=.15+gaze.y*.014,st=Math.sin(tilt),ct=Math.cos(tilt);
      const breath=motionOff?1:1+.025*Math.sin(sec*(mode==='speaking'?5.2:1.35));
      const hover=mode==='speaking'?.08:mode==='listening'?.04:0;
      for(const p of orbPoints){
        const rx=p.x*cos-p.z*sin,rz=p.x*sin+p.z*cos;
        const ry=p.y*ct-rz*st,depth=p.y*st+rz*ct;
        const perspective=1/(1-depth*.15);
        const x=c+(rx+gaze.x*.002)*r*perspective*breath;
        const y=c+ry*r*perspective*breath;
        const front=(depth+1)*.5,shimmer=.5+.5*Math.sin(sec*2.1+p.seed*9);
        const alpha=Math.min(.99,.12+front*.68+shimmer*.18+hover);
        const dot=(.45+front*.93+(shimmer>.98?.7:0))*(size/230);
        ctx.fillStyle='rgba('+rgb+','+alpha.toFixed(3)+')';
        ctx.fillRect(x-dot*.5,y-dot*.5,dot,dot);
      }
      // Quiet orbital trails knit the point field into the surrounding canvas.
      ctx.lineWidth=Math.max(.55,size/320);
      for(let i=0;i<3;i++){
        const wobble=sec*(.11+i*.065),wide=r*(1.23+i*.12);
        ctx.strokeStyle='rgba('+rgb+','+(.12+i*.042)+')';
        ctx.beginPath();ctx.ellipse(c,c,wide,r*(.33+i*.055),wobble+i*.86,0,Math.PI*2);ctx.stroke();
      }
    }
    function tick(t){
      if(destroyed)return;
      raf=requestAnimationFrame(tick);
      if(document.hidden||t-lastFrame<33)return;
      lastFrame=t;
      wander(t);
      gaze.x+=(gaze.targetX-gaze.x)*.17;gaze.y+=(gaze.targetY-gaze.y)*.17;
      meshDraw(t);cloudDraw(t);orbDraw(t);
      if(audio&&mode==='music'&&audio.paused)state('idle');
    }
    function openWorkspace(){
      if(workspace.classList.contains('open'))return;
      const from=sphere.getBoundingClientRect(),rect=root.getBoundingClientRect();
      workspace.style.setProperty('--sf-from-x',from.left-rect.left+'px');
      workspace.style.setProperty('--sf-from-y',from.top-rect.top+'px');
      workspace.style.setProperty('--sf-from-size',from.width+'px');
      workspace.classList.add('growing');workspace.removeAttribute('inert');workspace.setAttribute('aria-hidden','false');
      sphere.setAttribute('aria-expanded','true');
      requestAnimationFrame(()=>workspace.classList.add('open'));
      workspaceTimer=window.setTimeout(()=>by(workspace,'[data-agent-action="close-workspace"]')?.focus(),820);
      session();
    }
    function closeWorkspace(){
      window.clearTimeout(workspaceTimer);
      workspace.classList.remove('open');workspace.setAttribute('aria-hidden','true');workspace.setAttribute('inert','');
      sphere.setAttribute('aria-expanded','false');
      workspaceTimer=window.setTimeout(()=>workspace.classList.remove('growing'),850);
      sphere.focus({preventScroll:true});
    }
    const pageLabels={builder:'CREATE RECORD',playlist:'PLAYLIST',shows:'TOUR DATES',show:'LIVE SHOW',store:'MERCH STORE',membership:'MEMBERSHIP + VIP',tickets:'TICKETS + VIP',community:'FAN COMMUNITY',newsletter:'NEWSLETTER',music:'MUSIC LIBRARY',releases:'RELEASES',track:'SONG DETAILS',release:'RELEASE DETAILS',cart:'CART',account:'ACCOUNT',login:'SIGN IN',register:'CREATE ACCOUNT'};
    function openPage(view){
      pageTitle.textContent=pageLabels[view]||String(view||'PAGE').replaceAll('-',' ').toUpperCase();
      pageHost.replaceChildren();
      pageViewport.scrollTop=0;
      pageRail.hidden=false;
      pageRail.removeAttribute('inert');
      root.classList.add('has-native-page');
      return pageHost;
    }
    function closePage(){
      pageRail.hidden=true;
      pageRail.setAttribute('inert','');
      pageHost.replaceChildren();
      root.classList.remove('has-native-page');
    }
    function handleAction(event){
      const button=event.target.closest?.('[data-agent-action]');if(!button)return;
      const action=button.dataset.agentAction;
      if(action==='chat')options.onOpenChat?.();
      if(action==='start-chat')options.onStartChat?.();
      if(action==='capture')options.onStartCapture?.();
      if(action==='listen-interpret')options.onListenInterpret?.();
      if(action==='stop-listening')options.onStopListening?.();
      if(action==='show-player')showPlayer();
      if(action==='music'){closeWorkspace();options.onNavigate?.('music');}
      if(action==='close-panel'){panel.hidden=true;remotePanelDismissed=true;updateDock();}
      if(action==='close-workspace')closeWorkspace();
       if(action==='close-page'){closePage();options.onClosePage?.();}
    }
    cleanup.push(on(root,'click',handleAction));
    cleanup.push(on(playerDock,'pointerenter',pausePlayerHide));
    cleanup.push(on(playerDock,'pointerleave',schedulePlayerHide));
    cleanup.push(on(playerDock,'focusin',pausePlayerHide));
    cleanup.push(on(playerDock,'focusout',schedulePlayerHide));
    cleanup.push(on(playerDock,'click',schedulePlayerHide));
    cleanup.push(on(visual,'pointerenter',()=>{showPlayer();pausePlayerHide();}));
    cleanup.push(on(visual,'pointerleave',schedulePlayerHide));
    cleanup.push(on(sphere,'click',()=>{if(!suppressClick)openWorkspace();suppressClick=false;}));
    cleanup.push(on(floater,'pointerdown',e=>{
      if(e.target.closest?.('.sf-agent-live-feed'))return;
      if(e.target.closest('.sf-agent-dock-stack,.sf-agent-panel'))return;
      if(e.target.closest('button,input')&&e.target!==sphere)return;
      dragging={id:e.pointerId,x:e.clientX,y:e.clientY,left:floater.offsetLeft,top:floater.offsetTop,moved:false};
      floater.setPointerCapture?.(e.pointerId);floater.classList.add('dragging');
    }));
    cleanup.push(on(floater,'pointermove',e=>{
      if(!dragging||dragging.id!==e.pointerId)return;
      const dx=e.clientX-dragging.x,dy=e.clientY-dragging.y;
      if(Math.hypot(dx,dy)>6)dragging.moved=true;
      position(dragging.left+dx,dragging.top+dy);
    }));
    cleanup.push(on(floater,'pointerup',e=>{
      if(!dragging||dragging.id!==e.pointerId)return;
      suppressClick=dragging.moved;dragging=null;floater.classList.remove('dragging');
      roam.start=0;roam.delay=performance.now()+3000;
    }));
    cleanup.push(on(floater,'pointercancel',()=>{dragging=null;floater.classList.remove('dragging');}));
    // Pause travel on both the face and its attached player/lyric controls.
    // Restart the path from the current position, never jump to an old waypoint.
    cleanup.push(on(floater,'pointerenter',()=>{hovered=true;}));
    cleanup.push(on(floater,'pointerleave',()=>{hovered=false;roam.start=0;roam.delay=0;}));
    cleanup.push(on(floater,'focusin',()=>{hovered=true;}));
    cleanup.push(on(floater,'focusout',e=>{if(!floater.contains(e.relatedTarget)){hovered=false;roam.start=0;roam.delay=0;}}));
    cleanup.push(on(window,'pointermove',e=>gazeAt(e.clientX,e.clientY),{passive:true}));
    cleanup.push(on(window,'keydown',e=>{if(e.key==='Escape'&&workspace.classList.contains('open'))closeWorkspace();}));
    cleanup.push(on(audio,'play',()=>{state('music');showPlayer();}));
    cleanup.push(on(audio,'pause',()=>{if(mode==='music')state('idle');}));
    cleanup.push(on(window,'resize',resize));
    const resizeObserver=typeof ResizeObserver==='function'?new ResizeObserver(resize):null;
    resizeObserver?.observe(root);
    let selectedAtmosphere='ember';try{selectedAtmosphere=window.localStorage?.getItem(atmosphereKey)||'ember'}catch(_){}
    applyAtmosphere(selectedAtmosphere,false);
    resize();state(audio&&!audio.paused?'music':'idle');session();syncPlayer();syncVoice();
    if(!motionOff)raf=requestAnimationFrame(tick);
    else {meshDraw(0);cloudDraw(0);orbDraw(0);}
    current={root,state,session,syncPlayer,syncVoice,openPage,closePage,syncTranscript,showConversation,setAtmosphere:applyAtmosphere,getAtmosphere:()=>atmosphere,destroy(){
      destroyed=true;cancelAnimationFrame(raf);window.clearTimeout(workspaceTimer);pausePlayerHide();
      resizeObserver?.disconnect();playerObserver?.disconnect();cleanup.forEach(fn=>fn());
      if(player && originalPlayerParent){
        const before = originalPlayerNext?.parentNode===originalPlayerParent ? originalPlayerNext : null;
        originalPlayerParent.insertBefore(player,before);
      }
      if(voiceTranscript && originalTranscriptParent){
        const before = originalTranscriptNext?.parentNode===originalTranscriptParent ? originalTranscriptNext : null;
        originalTranscriptParent.insertBefore(voiceTranscript,before);
      }
      if(mesh.parentNode!==root)mesh.remove();
      delete document.body.dataset.sfAtmosphere;
      root.remove();
    }};
    return true;
  }
  function unmount(){if(current){current.destroy();current=null;}}
  function applyCommand(text){
    const value=String(text||'').trim().toLowerCase();
    const match=value.match(/^(?:(?:stonefellow|agent)[, ]+)?(?:set|switch|change|use)(?: the)? (?:agent )?(?:atmosphere|environment|theme)(?: (?:to|as))? (ember|blue mesh|mesh|violet signal|violet|lunar|moonlit horizon|moonlight)[.!]?$/);
    if(!match)return null;
    const named=match[1]==='blue mesh'?'mesh':match[1]==='violet signal'?'violet':['moonlit horizon','moonlight'].includes(match[1])?'lunar':match[1];
    return current?.setAtmosphere(named)||null;
  }
  window.SFPublicAgentCanvas=Object.freeze({mount,unmount,applyCommand,setAtmosphere:name=>current?.setAtmosphere(name)||false,getAtmosphere:()=>current?.getAtmosphere(),listAtmospheres:()=>['ember','mesh','violet','lunar'],setState:(mode,label)=>current?.state(mode,label),syncSongExperience:()=>current?.session(),syncPlayer:()=>current?.syncPlayer(),syncVoice:()=>current?.syncVoice(),syncTranscript:()=>current?.syncTranscript(),showConversation:(role,text)=>current?.showConversation(role,text),openPage:view=>current?.openPage(view),closePage:()=>current?.closePage(),active:()=>!!current});
})();
