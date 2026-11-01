/* Section 91B12C — particle-face presentation for the existing Admin Agent.
 * This reads the authoritative data-mode set by admin.js; voice/chat ownership
 * and the existing button click handlers remain unchanged.
 */
(() => {
  'use strict';
  const orb=document.getElementById('adminFloatingAgent');
  const canvas=orb?.querySelector('.admin-floating-points');
  const ctx=canvas?.getContext?.('2d');
  if(!orb||!ctx)return;
  const reduced=!!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const count=1050;
  const dots=Array.from({length:count},(_,i)=>{
    const a=i*2.399963229728653,r=Math.sqrt((i+.5)/count)*.98;
    return {x:Math.cos(a)*r,y:Math.sin(a)*r,r,seed:i*.713};
  });
  const eye={x:0,y:0,toX:0,toY:0};
  let frame=0,last=0,size=0;
  const palette={
    idle:[167,181,251],listening:[112,215,255],thinking:[202,155,255],
    speaking:[255,202,160],recording:[255,125,156],music:[118,241,202]
  };
  function draw(time){
    const rect=canvas.getBoundingClientRect();
    if(!rect.width||!rect.height)return;
    const dpr=Math.min(window.devicePixelRatio||1,2),width=rect.width;
    if(width!==size){size=width;canvas.width=Math.round(width*dpr);canvas.height=Math.round(rect.height*dpr);ctx.setTransform(dpr,0,0,dpr,0,0);}
    const c=width*.5,mode=orb.dataset.mode||'idle',t=reduced?0:time*.001;
    const color=palette[mode]||palette.idle;
    ctx.clearRect(0,0,width,rect.height);
    eye.x+=(eye.toX-eye.x)*.18;eye.y+=(eye.toY-eye.y)*.18;
    const offsetX=eye.x/c*.65,offsetY=eye.y/c*.7;
    for(const p of dots){
      const swirl=mode==='thinking'?Math.sin(t*2+p.r*6)*.06:Math.sin(t*.5+p.r*4)*.009;
      const cos=Math.cos(swirl),sin=Math.sin(swirl);
      let x=p.x*cos-p.y*sin,y=p.x*sin+p.y*cos;
      if(mode==='speaking'){const pulse=1+Math.sin(t*13+p.seed*.08)*.018;x*=pulse;y*=pulse;}
      const light=Math.max(0,Math.min(1,.38+Math.sqrt(Math.max(0,1-p.r*p.r))*.52-x*.19-y*.23));
      const shade=.24+Math.floor(light*11)*.075;
      let colorCode='rgba('+Math.round(color[0]*shade)+','+Math.round(color[1]*shade)+','+Math.round(color[2]*shade)+',.99)';
      let dotRadius=(width/56)*(p.r>.88?.28:.45);
      for(const side of [-1,1]){
        const ex=side*.255,ey=.025;
        if(((p.x-ex)/.145)**2+((p.y-ey)/.205)**2<1.27){colorCode='#d9ebff';dotRadius*=1.1;}
        if(((p.x-ex-offsetX)/.100)**2+((p.y-ey-offsetY)/.16)**2<1){colorCode='#080e25';dotRadius*=1.35;}
      }
      const mouthY=.405+.037*(1-(p.x/.18)**2);
      if(Math.abs(p.x)<.17&&Math.abs(p.y-mouthY)<(mode==='speaking'?.07:.024)){colorCode='#090f2c';dotRadius*=1.18;}
      ctx.fillStyle=colorCode;ctx.beginPath();ctx.arc(c+x*c*.97,c+y*c*.97,Math.max(.25,dotRadius),0,Math.PI*2);ctx.fill();
    }
    for(const side of [-1,1]){
      ctx.fillStyle='#e8fbff';ctx.beginPath();ctx.arc(c+(side*.255+offsetX+.025)*c*.97,c+(.025+offsetY-.065)*c*.97,Math.max(.5,width*.012),0,Math.PI*2);ctx.fill();
    }
  }
  function pointer(event){
    const r=canvas.getBoundingClientRect(),dx=event.clientX-(r.left+r.width/2),dy=event.clientY-(r.top+r.height/2);
    const distance=Math.max(1,Math.hypot(dx,dy)),f=Math.min(1,distance/100);
    eye.toX=dx/distance*4*f;eye.toY=dy/distance*3*f;
    if(reduced)draw(0);
  }
  function tick(time){
    frame=window.requestAnimationFrame(tick);
    if(document.hidden||time-last<32)return;
    last=time;draw(time);
  }
  document.addEventListener('pointermove',pointer,{passive:true});
  orb.classList.add('particle-ready');
  draw(0);
  if(!reduced)frame=window.requestAnimationFrame(tick);
  window.addEventListener('pagehide',()=>{
    if(frame)window.cancelAnimationFrame(frame);
    document.removeEventListener('pointermove',pointer);
  },{once:true});
})();
