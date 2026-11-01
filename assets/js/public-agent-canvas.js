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
      <div class="sf-agent-floater" data-mode="idle" aria-label="Floating Stonefellow agent; drag to move">
        <div class="sf-agent-visual">
          <div class="sf-agent-aura" aria-hidden="true"></div>
          <canvas class="sf-agent-cloud" aria-hidden="true"></canvas>
          <div class="sf-agent-ring" aria-hidden="true"></div>
          <div class="sf-agent-orbit" aria-hidden="true"></div>
          <div class="sf-agent-orbit secondary" aria-hidden="true"></div>
          <button class="sf-agent-sphere" type="button" aria-label="Expand Stonefellow Agent canvas" aria-expanded="false">
            <canvas class="sf-agent-points" aria-hidden="true"></canvas>
            <span class="sf-agent-face-fallback" aria-hidden="true"><span></span><span></span></span>
          </button>
        </div>
        <div class="sf-agent-state" role="status">AGENT READY</div>
        <p class="sf-agent-hint">DRAG TO MOVE · CLICK TO EXPAND</p>
        <div class="sf-agent-dock-stack">
          <div class="sf-agent-player-dock" aria-label="Agent music and interaction controls">
            <div class="sf-agent-player-ready">MUSIC / PLAYER READY</div>
            <div class="sf-agent-tools">
              <button type="button" data-agent-action="chat">Chat with Agent</button>
              <button type="button" data-agent-action="capture" hidden>Capture song lyrics</button>
            </div>
          </div>
          <aside class="sf-agent-panel" hidden aria-label="Active song lyric capture and analysis">
            <div class="sf-agent-panel-head"><span>LYRIC / CAPTURE · AGENT PROCESS</span><button type="button" data-agent-action="close-panel" aria-label="Hide session panel">×</button></div>
            <div class="sf-agent-session"></div>
          </aside>
        </div>
      </div>
      <section class="sf-agent-workspace" aria-label="Expanded Agent Canvas" aria-hidden="true" inert>
        <div class="sf-agent-workspace-inner">
          <div class="sf-agent-workspace-head"><strong>STONEFELLOW / AGENT CANVAS</strong><button type="button" data-agent-action="close-workspace">Close canvas ×</button></div>
          <div class="sf-agent-workspace-body">
            <h2>What shall we explore?</h2>
            <p>The agent can bring music, lyrics, analysis and conversation into this space. Your current session stays here when you expand the canvas.</p>
            <div class="sf-agent-expanded-session"></div>
            <div class="sf-agent-tools"><button type="button" data-agent-action="chat">Open Agent Chat</button><button type="button" data-agent-action="music">Explore music</button><button type="button" data-agent-action="capture" hidden>Capture song lyrics</button></div>
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
    const sphere = by(root, '.sf-agent-sphere');
    const status = by(root, '.sf-agent-state');
    const mesh = by(root, '.sf-agent-mesh');
    const cloud = by(root, '.sf-agent-cloud');
    const face = by(root, '.sf-agent-points');
    const workspace = by(root, '.sf-agent-workspace');
    const panel = by(root, '.sf-agent-panel');
    const playerDock = by(root, '.sf-agent-player-dock');
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
    const pointCount = window.matchMedia?.('(max-width: 720px)').matches ? 1350 : 2450;
    const facePoints = Array.from({length:pointCount}, (_, i) => {
      const r=Math.sqrt((i+.5)/pointCount)*.98, a=i*2.399963229728653;
      return {x:Math.cos(a)*r,y:Math.sin(a)*r,r,seed:i*.913};
    });
    let mode='idle', raf=0, destroyed=false, dragging=null, hovered=false, suppressClick=false;
    let frameSize=0,lastFrame=0,workspaceTimer=0,remotePanelDismissed=false;
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
    function syncPlayer() {
      const loaded=!!player && !player.hidden;
      playerDock.classList[loaded?'add':'remove']('has-track');
      updateDock();
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
      for (const control of root.querySelectorAll('[data-agent-action="capture"]')) control.hidden=!(audio && audio.src && !audio.paused)||!!options.isCapturing?.();
    }
    function session() {
      const active=!!window.StonefellowSongExperience?.active?.();
      if (!active) { panel.hidden=true; smallSession.replaceChildren(); bigSession.replaceChildren(); updateDock(); return; }
      if (!remotePanelDismissed) panel.hidden=false;
      window.StonefellowSongExperience.mount(smallSession);
      window.StonefellowSongExperience.mount(bigSession);
      updateDock();
      for (const control of root.querySelectorAll('[data-agent-action="capture"]')) control.hidden=!(audio && audio.src && !audio.paused)||!!options.isCapturing?.();
    }
    function updateDock() {
      floater.dataset.side='below';
      floater.dataset.vertical='below';
    }
    function position(x,y) {
      const margin=Math.min(root.clientWidth*.2,visual.offsetWidth*.65+24);
      const minX=Math.min(root.clientWidth*.5,Math.max(margin,floater.offsetWidth*.5+18));
      // Include the player and any live lyric panel in the moving body's bounds.
      const minY=Math.min(root.clientHeight*.5,floater.offsetHeight*.5+16);
      const xx=Math.max(minX,Math.min(root.clientWidth-minX,x));
      const yy=Math.max(minY,Math.min(root.clientHeight-minY,y));
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
      if(!floater.style.left)position(dim.w*.64,dim.h*.46);
    }
    function meshDraw(t) {
      const ctx=contexts[0];if(!ctx)return;
      const w=mesh.clientWidth||dim.w,h=mesh.clientHeight||dim.h,m=motionOff?0:t*.00023;
      ctx.clearRect(0,0,w,h);
      const halo=ctx.createRadialGradient(w*.68,h*.47,0,w*.68,h*.47,Math.max(w,h)*.6);
      halo.addColorStop(0,'#4e6fdb2b');halo.addColorStop(1,'#00000000');ctx.fillStyle=halo;ctx.fillRect(0,0,w,h);
      for(const s of stars){ctx.fillStyle='rgba(190,219,252,'+Math.max(.10,.25+.31*Math.sin(m*3+s.phase))+')';ctx.beginPath();ctx.arc(s.x*w,s.y*h,s.r,0,Math.PI*2);ctx.fill();}
      const cols=24,rows=13,grid=[];
      for(let y=0;y<=rows;y++){const r=[];for(let x=0;x<=cols;x++){
        const u=x/cols,v=y/rows,weight=Math.max(0,1-Math.abs(u-.65)*1.8);
        r.push({x:w*(.04+u*.92)+Math.sin(x*.57+y*.42+m*2)*12*weight+Math.cos(y*.5+m)*9+pointer.driftX*.014,
          y:h*(-.07+v*1.16)+Math.sin(x*.44+y*.58+m*1.6)*15*weight+pointer.driftY*.01,weight});
      }grid.push(r);}
      ctx.lineWidth=.8;
      const link=(a,b,alpha)=>{ctx.strokeStyle='rgba(104,172,241,'+Math.max(.035,alpha)+')';ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);ctx.stroke();};
      for(let y=0;y<=rows;y++)for(let x=0;x<=cols;x++){
        const p=grid[y][x],level=.16+.13*p.weight+.035*Math.sin((x+y)*.52-m*1.8);
        if(x<cols)link(p,grid[y][x+1],level);
        if(y<rows)link(p,grid[y+1][x],level);
        if(y<rows&&x<cols&&(x+y)%2===0)link(p,grid[y+1][x+1],level*.52);
        if((x+y)%3===0||p.weight>.8){ctx.fillStyle='rgba(174,220,255,'+(.24+.26*p.weight)+')';ctx.beginPath();ctx.arc(p.x,p.y,p.weight>.7?1.65:1.1,0,Math.PI*2);ctx.fill();}
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
      const tint=mode==='music'?'127,244,213':mode==='speaking'?'255,218,156':thinking?'213,162,255':'163,205,255';
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
    function faceDraw(t) {
      const ctx=contexts[2];if(!ctx)return;
      const size=Math.max(1,sphere.offsetWidth),dpr=Math.min(window.devicePixelRatio||1,2);
      if(size!==frameSize){frameSize=size;face.width=Math.round(size*dpr);face.height=Math.round(size*dpr);ctx.setTransform(dpr,0,0,dpr,0,0);}
      const c=size/2,time=motionOff?0:t*.001;
      ctx.clearRect(0,0,size,size);
      const color={idle:[177,161,253],listening:[112,215,255],recording:[255,122,165],thinking:[211,154,255],speaking:[255,204,160],music:[113,246,202]}[mode]||[177,161,253];
      const px=gaze.x/c*.65,py=gaze.y/c*.7;
      for(const p of facePoints){let x=p.x,y=p.y;
        const swirl=mode==='thinking'?Math.sin(time*2+p.r*6)*.08:Math.sin(time*.37+p.r*3)*.012;
        const cs=Math.cos(swirl),sn=Math.sin(swirl);
        x=p.x*cs-p.y*sn;y=p.x*sn+p.y*cs;
        const breath=mode==='speaking'?Math.sin(time*14+p.seed*.09)*.022:mode==='listening'?Math.sin(time*3+p.seed*.04)*.014:0;
        x*=1+breath;y*=1+breath;
        const light=Math.max(0,Math.min(1,.38+Math.sqrt(Math.max(0,1-p.r*p.r))*.52-x*.19-y*.23));
        const shade=.25+Math.floor(light*11)*.072;
        let ink='rgba('+Math.round(color[0]*shade)+','+Math.round(color[1]*shade)+','+Math.round(color[2]*shade)+',.98)';
        let radius=(size/220)*(p.r>.88?.85:1.38);
        for(const side of [-1,1]){const ex=side*.255,ey=.025;
          if(((p.x-ex)/.145)**2+((p.y-ey)/.205)**2<1.27){ink='#ced5ffbb';radius*=1.08;}
          if(((p.x-ex-px)/.100)**2+((p.y-ey-py)/(mode==='thinking'?.115:.16))**2<1){ink='#070c1ff7';radius*=1.32;}
        }
        if(Math.abs(p.x)<.17&&Math.abs(p.y-(.405+.037*(1-(p.x/.18)**2)))<(.018+(mode==='speaking'?.11:.015)*.46)){ink='#10152eeb';radius*=1.16;}
        ctx.fillStyle=ink;ctx.beginPath();ctx.arc(c+x*c*.97,c+y*c*.97,Math.max(.5,radius),0,Math.PI*2);ctx.fill();
      }
      for(const side of [-1,1]){ctx.fillStyle='#dcf6ffe6';ctx.beginPath();ctx.arc(c+(side*.255+px+.025)*c*.97,c+(.025+py-.065)*c*.97,Math.max(1,size*.009),0,Math.PI*2);ctx.fill();}
    }
    function tick(t){
      if(destroyed)return;
      raf=requestAnimationFrame(tick);
      if(document.hidden||t-lastFrame<33)return;
      lastFrame=t;
      wander(t);
      gaze.x+=(gaze.targetX-gaze.x)*.17;gaze.y+=(gaze.targetY-gaze.y)*.17;
      sphere.style.setProperty('--sf-eye-x',gaze.x.toFixed(2)+'px');sphere.style.setProperty('--sf-eye-y',gaze.y.toFixed(2)+'px');
      meshDraw(t);cloudDraw(t);faceDraw(t);
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
    function handleAction(event){
      const button=event.target.closest?.('[data-agent-action]');if(!button)return;
      const action=button.dataset.agentAction;
      if(action==='chat')options.onOpenChat?.();
      if(action==='capture')options.onStartCapture?.();
      if(action==='music'){closeWorkspace();options.onNavigate?.('music');}
      if(action==='close-panel'){panel.hidden=true;remotePanelDismissed=true;updateDock();}
      if(action==='close-workspace')closeWorkspace();
    }
    cleanup.push(on(root,'click',handleAction));
    cleanup.push(on(sphere,'click',()=>{if(!suppressClick)openWorkspace();suppressClick=false;}));
    cleanup.push(on(floater,'pointerdown',e=>{
      if(e.target.closest('.sf-agent-dock-stack'))return;
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
    cleanup.push(on(audio,'play',()=>{state('music');}));
    cleanup.push(on(audio,'pause',()=>{if(mode==='music')state('idle');}));
    cleanup.push(on(window,'resize',resize));
    const resizeObserver=typeof ResizeObserver==='function'?new ResizeObserver(resize):null;
    resizeObserver?.observe(root);
    resize();state(audio&&!audio.paused?'music':'idle');session();syncPlayer();
    if(!motionOff)raf=requestAnimationFrame(tick);
    else {meshDraw(0);cloudDraw(0);faceDraw(0);}
    current={root,state,session,syncPlayer,destroy(){
      destroyed=true;cancelAnimationFrame(raf);window.clearTimeout(workspaceTimer);
      resizeObserver?.disconnect();playerObserver?.disconnect();cleanup.forEach(fn=>fn());
      if(player && originalPlayerParent){
        const before = originalPlayerNext?.parentNode===originalPlayerParent ? originalPlayerNext : null;
        originalPlayerParent.insertBefore(player,before);
      }
      if(mesh.parentNode!==root)mesh.remove();
      root.remove();
    }};
    return true;
  }
  function unmount(){if(current){current.destroy();current=null;}}
  window.SFPublicAgentCanvas=Object.freeze({mount,unmount,setState:(mode,label)=>current?.state(mode,label),syncSongExperience:()=>current?.session(),syncPlayer:()=>current?.syncPlayer(),active:()=>!!current});
})();
