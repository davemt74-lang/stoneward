(() => {
  'use strict';
  // Section 91B20F: a browser-only, private edit mask. No AI call or publication occurs here.
  let active=null;
  const clamp=(value,min,max)=>Math.min(max,Math.max(min,value));
  function open({reference,getCsrf,onSaved}={}){
    if(active){active.focus();return false;}
    const width=Number(reference?.width||0),height=Number(reference?.height||0);
    if(!reference?.uuid||!Number.isInteger(width)||!Number.isInteger(height)||width<1||height<1||
       width>4096||height>4096||width*height>16000000){
      alert('Choose a Media Library reference up to 4096 × 4096 pixels (16 MP) before opening the mask editor.');
      return false;
    }
    const overlay=document.createElement('div');
    overlay.className='imagegen-mask-editor-overlay';
    overlay.innerHTML=[
      '<section class="imagegen-mask-editor" role="dialog" aria-modal="true" aria-labelledby="imagegenMaskEditorTitle">',
      '<header class="imagegen-mask-editor-header"><div><small>91B20F · PRIVATE IMAGE MASK</small>',
      '<h2 id="imagegenMaskEditorTitle">Visual Mask Editor</h2>',
      '<p>Paint the cyan areas you want the AI to change. Everything else stays protected in the exported mask.</p></div>',
      '<button type="button" class="secondary" data-mask-close aria-label="Close mask editor">Close ×</button></header>',
      '<div class="imagegen-mask-editor-tools" role="toolbar" aria-label="Mask editing tools">',
      '<button type="button" data-mask-tool="brush" aria-pressed="true">Brush · edit</button>',
      '<button type="button" data-mask-tool="restore" aria-pressed="false">Restore · protect</button>',
      '<button type="button" data-mask-tool="rect" aria-pressed="false">Rectangle · edit</button>',
      '<button type="button" data-mask-tool="focus" aria-pressed="false">Crop / focus</button>',
      '<label>Brush size <input data-mask-brush type="range" min="8" max="180" value="54"><output data-mask-size>54 px</output></label>',
      '<button type="button" data-mask-undo disabled>Undo</button><button type="button" data-mask-redo disabled>Redo</button>',
      '<button type="button" data-mask-fit>Fit image</button>',
      '<label>Zoom <input data-mask-zoom type="range" min="100" max="400" step="25" value="100"><output data-mask-zoom-label>100%</output></label>',
      '</div><div class="imagegen-mask-editor-main">',
      '<div class="imagegen-mask-stage" data-mask-stage aria-label="Reference image and mask canvas">',
      '<div class="imagegen-mask-transform" data-mask-transform>',
      '<img data-mask-photo alt="Private reference selected for image edit" draggable="false">',
      '<canvas data-mask-display aria-label="Draw transparent edit areas with touch, mouse, or pen"></canvas></div>',
      '<div class="imagegen-mask-focus-rect" data-mask-focus hidden></div></div>',
      '<div class="imagegen-mask-editor-legend"><strong>Cyan = editable</strong><span>Unpainted areas remain opaque and protected in the uploaded PNG.</span><span>Crop / focus only zooms the workspace; exported pixels remain exactly '+width+' × '+height+'.</span></div>',
      '</div><footer class="imagegen-mask-editor-footer">',
      '<p role="status" data-mask-status>Loading your private image…</p>',
      '<div class="actions"><button type="button" class="secondary" data-mask-clear>Clear edit areas</button>',
      '<button type="button" class="primary" data-mask-upload disabled>Review & upload PNG mask</button></div>',
      '<p class="hint">This uploads one private PNG into Media Library. A separate permission checkbox, prompt approval, paid generation approval, and artwork publication are still required.</p>',
      '</footer></section>'
    ].join('');
    document.body.appendChild(overlay);
    const $=sel=>overlay.querySelector(sel);
    const stage=$('[data-mask-stage]'),transform=$('[data-mask-transform]');
    stage.style.setProperty('--mask-ratio',String(width/height));
    const photo=$('[data-mask-photo]'),display=$('[data-mask-display]');
    const focusRect=$('[data-mask-focus]'),status=$('[data-mask-status]');
    const upload=$('[data-mask-upload]'),brush=$('[data-mask-brush]'),zoomInput=$('[data-mask-zoom]');
    const undoButton=$('[data-mask-undo]'),redoButton=$('[data-mask-redo]');
    const mask=document.createElement('canvas');
    mask.width=display.width=width;mask.height=display.height=height;
    const ctx=mask.getContext('2d',{willReadFrequently:true}),preview=display.getContext('2d');
    let tool='brush',actions=[],cursor=-1,current=null,focusStart=null,focused=false,zoom=1,panX=0,panY=0,painting=false,dirty=false,submitting=false,closed=false,frame=0;
    if(!ctx||!preview){overlay.remove();alert('Your browser could not create the mask canvas.');return false;}
    const originalFocus=document.activeElement;
    active={focus:()=>upload.focus()};
    ctx.fillStyle='#000';ctx.fillRect(0,0,width,height);
    function schedulePreview(){
      if(frame)return;
      frame=requestAnimationFrame(()=>{
        frame=0;if(closed)return;
        preview.clearRect(0,0,width,height);
        preview.fillStyle='rgba(68,207,249,.52)';preview.fillRect(0,0,width,height);
        preview.globalCompositeOperation='destination-out';
        preview.drawImage(mask,0,0);
        preview.globalCompositeOperation='source-over';
      });
    }
    function paint(action,from=0){
      const pts=action.points;
      if(!pts.length)return;
      ctx.save();
      ctx.globalCompositeOperation=action.type==='restore'?'source-over':'destination-out';
      ctx.strokeStyle='#000';ctx.fillStyle='#000';ctx.lineWidth=action.size;
      ctx.lineCap='round';ctx.lineJoin='round';
      if(action.type==='rect'){
        const a=pts[0],b=pts[pts.length-1];
        ctx.fillRect(Math.min(a.x,b.x),Math.min(a.y,b.y),Math.abs(a.x-b.x),Math.abs(a.y-b.y));
      }else{
        if(from===0){ctx.beginPath();ctx.arc(pts[0].x,pts[0].y,action.size/2,0,Math.PI*2);ctx.fill();}
        if(pts.length>1){ctx.beginPath();ctx.moveTo(pts[Math.max(0,from-1)].x,pts[Math.max(0,from-1)].y);
          for(let i=Math.max(1,from);i<pts.length;i++)ctx.lineTo(pts[i].x,pts[i].y);ctx.stroke();}
      }
      ctx.restore();
    }
    function redraw(){
      ctx.globalCompositeOperation='source-over';ctx.fillStyle='#000';ctx.fillRect(0,0,width,height);
      for(let i=0;i<=cursor;i++)paint(actions[i]);
      schedulePreview();sync();
    }
    function sync(){
      undoButton.disabled=cursor<0||submitting;redoButton.disabled=cursor>=actions.length-1||submitting;
      upload.disabled=!focused||submitting;
      $('[data-mask-size]').textContent=brush.value+' px';
      $('[data-mask-zoom-label]').textContent=Math.round(zoom*100)+'%';
      zoomInput.value=String(Math.round(zoom*100));
    }
    function fitPan(){
      panX=clamp(panX,stage.clientWidth*(1-zoom),0);
      panY=clamp(panY,stage.clientHeight*(1-zoom),0);
      transform.style.transform='translate('+panX+'px,'+panY+'px) scale('+zoom+')';
      sync();
    }
    function zoomAt(value,cx=stage.clientWidth/2,cy=stage.clientHeight/2){
      const next=clamp(value,1,4),ratio=next/zoom;
      panX=cx-(cx-panX)*ratio;panY=cy-(cy-panY)*ratio;zoom=next;fitPan();
    }
    const point=e=>{
      const rect=display.getBoundingClientRect();
      return {x:clamp((e.clientX-rect.left)*width/Math.max(1,rect.width),0,width),
              y:clamp((e.clientY-rect.top)*height/Math.max(1,rect.height),0,height)};
    };
    function showFocus(a,b){
      const rect=display.getBoundingClientRect(),base=stage.getBoundingClientRect();
      const left=rect.left-base.left+Math.min(a.x,b.x)*rect.width/width;
      const top=rect.top-base.top+Math.min(a.y,b.y)*rect.height/height;
      focusRect.hidden=false;focusRect.style.left=left+'px';focusRect.style.top=top+'px';
      focusRect.style.width=Math.abs(b.x-a.x)*rect.width/width+'px';
      focusRect.style.height=Math.abs(b.y-a.y)*rect.height/height+'px';
    }
    function onDown(e){
      if(!focused||submitting||e.button>0)return;
      e.preventDefault();display.setPointerCapture?.(e.pointerId);
      const p=point(e);
      painting=true;
      if(tool==='focus'){focusStart=p;showFocus(p,p);return;}
      actions=actions.slice(0,cursor+1);
      current={type:tool,size:Number(brush.value),points:[p]};
      actions.push(current);cursor=actions.length-1;dirty=true;
      if(tool!=='rect'){paint(current);schedulePreview();}
      sync();
    }
    function onMove(e){
      if(!painting||submitting)return;
      e.preventDefault();const p=point(e);
      if(tool==='focus'){showFocus(focusStart,p);return;}
      if(!current)return;
      if(current.type==='rect'){current.points[1]=p;redraw();return;}
      const previous=current.points[current.points.length-1];
      if(Math.hypot(p.x-previous.x,p.y-previous.y)<Math.max(1,current.size/8))return;
      const from=current.points.length;current.points.push(p);paint(current,from);schedulePreview();
    }
    function onUp(e){
      if(!painting)return;
      e.preventDefault();painting=false;
      if(tool==='focus'&&focusStart){
        const end=point(e),dx=Math.abs(end.x-focusStart.x),dy=Math.abs(end.y-focusStart.y);
        focusRect.hidden=true;
        if(dx>=12&&dy>=12){
          const scale=clamp(Math.min(width/dx,height/dy),1,4);
          const cx=(focusStart.x+end.x)/2,cy=(focusStart.y+end.y)/2;
          zoom=scale;panX=stage.clientWidth/2-stage.clientWidth*cx/width*zoom;
          panY=stage.clientHeight/2-stage.clientHeight*cy/height*zoom;fitPan();
          status.textContent='Focused selected area. The PNG will still use the full reference dimensions.';
        }
        focusStart=null;
      }else if(current){if(current.type==='rect'){current.points[1]=point(e);redraw();}current=null;}
      sync();
    }
    const keydown=e=>{
      if(e.key==='Escape'){e.preventDefault();close();return;}
      if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='z'){e.preventDefault();e.shiftKey?redo():undo();return;}
      if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='y'){e.preventDefault();redo();return;}
      if(e.key!=='Tab')return;
      const els=Array.from(overlay.querySelectorAll('button:not([disabled]),input:not([disabled])'));
      if(!els.length)return;
      if(e.shiftKey&&document.activeElement===els[0]){e.preventDefault();els[els.length-1].focus();}
      else if(!e.shiftKey&&document.activeElement===els[els.length-1]){e.preventDefault();els[0].focus();}
    };
    function undo(){if(painting||submitting||cursor<0)return;cursor--;redraw();dirty=true;}
    function redo(){if(painting||submitting||cursor>=actions.length-1)return;cursor++;redraw();dirty=true;}
    function close(force=false){
      if(submitting||closed)return;
      if(!force&&dirty&&!confirm('Discard the unsaved mask edits?'))return;
      closed=true;cancelAnimationFrame(frame);window.removeEventListener('keydown',keydown,true);
      overlay.remove();active=null;originalFocus?.focus?.();
    }
    function alphaCoverage(){
      const rgba=ctx.getImageData(0,0,width,height).data;
      let count=0;
      for(let i=3;i<rgba.length;i+=4)if(rgba[i]<128)count++;
      return count;
    }
    async function save(){
      if(submitting||!focused)return;
      if(painting){status.textContent='Finish the current stroke first.';return;}
      const pixels=alphaCoverage();
      if(pixels<Math.max(32,Math.floor(width*height*.00002))){
        status.textContent='Paint a visible edit area before saving. This mask has no meaningful transparency.';return;
      }
      if(!confirm('Upload this '+width+' × '+height+' PNG as a PRIVATE edit mask? No image generation or publication will start.'))return;
      submitting=true;sync();status.textContent='Encoding private PNG mask…';
      try{
        const blob=await new Promise((resolve,reject)=>mask.toBlob(value=>value?resolve(value):reject(new Error('PNG encoding failed.')),'image/png'));
        if(blob.size>10*1024*1024)throw new Error('Mask exceeds the 10 MB Media Library limit. Simplify the mask.');
        const csrf=typeof getCsrf==='function'?getCsrf():'';
        if(!csrf)throw new Error('Admin security token unavailable. Refresh Admin and retry.');
        const file=new File([blob],'stonefellow-mask-'+Number(reference.id)+'-'+Date.now()+'.png',{type:'image/png'});
        const form=new FormData();form.append('file',file,file.name);
        status.textContent='Uploading private mask to Media Library…';
        const response=await fetch('api/media-upload.php',{method:'POST',credentials:'same-origin',
          headers:{'X-CSRF-Token':csrf},body:form});
        let result={};try{result=await response.json();}catch(_){throw new Error('Media Library returned an invalid response.');}
        if(!response.ok||!result.ok||!result.asset?.id)throw new Error(result.message||result.error||'Private mask upload failed.');
        if(result.asset.mime_type!=='image/png'||Number(result.asset.width)!==width||Number(result.asset.height)!==height)
          throw new Error('Mask uploaded but server geometry metadata did not match. Review it in Media Library.');
        status.textContent='Private PNG uploaded. Attaching it to the draft selector…';
        dirty=false;submitting=false;
        try{if(typeof onSaved==='function')await onSaved(result.asset);}
        catch(error){alert('Mask saved privately to Media Library, but automatic draft selection failed: '+(error.message||'Please select it from Media Library.'));}
        close(true);
      }catch(error){status.textContent=error.message||'Mask upload failed. No generation was started.';submitting=false;sync();}
    }
    $('[data-mask-close]').onclick=()=>close();
    $('[data-mask-undo]').onclick=undo;$('[data-mask-redo]').onclick=redo;
    $('[data-mask-fit]').onclick=()=>{zoom=1;panX=panY=0;fitPan();};
    $('[data-mask-clear]').onclick=()=>{
      if(!actions.length)return;
      if(!confirm('Clear every painted edit region? This stays local until you upload.'))return;
      actions=[];cursor=-1;dirty=true;redraw();
    };
    brush.oninput=sync;zoomInput.oninput=()=>zoomAt(Number(zoomInput.value)/100);
    for(const button of overlay.querySelectorAll('[data-mask-tool]'))button.onclick=()=>{
      tool=button.dataset.maskTool;
      for(const other of overlay.querySelectorAll('[data-mask-tool]'))other.setAttribute('aria-pressed',String(other===button));
      display.style.cursor=tool==='focus'?'zoom-in':'crosshair';
    };
    display.addEventListener('pointerdown',onDown);
    display.addEventListener('pointermove',onMove);
    display.addEventListener('pointerup',onUp);
    display.addEventListener('pointercancel',e=>{if(!painting)return;painting=false;focusStart=null;focusRect.hidden=true;current=null;redraw();});
    upload.onclick=save;window.addEventListener('keydown',keydown,true);
    photo.onload=()=>{
      if(closed)return;
      if(photo.naturalWidth!==width||photo.naturalHeight!==height){
        status.textContent='Reference dimensions changed. Close and refresh Media Library.';return;
      }
      focused=true;upload.disabled=false;status.textContent='Ready. Brush edit areas, optionally crop/focus, then review and upload.';
      schedulePreview();sync();
    };
    photo.onerror=()=>{status.textContent='Reference image could not be loaded. No mask can be exported.';};
    photo.src='../api/media.php?id='+encodeURIComponent(reference.uuid);
    overlay.querySelector('[data-mask-tool="brush"]').focus();
    sync();
    return true;
  }
  window.SFImageMaskEditor={open};
})();