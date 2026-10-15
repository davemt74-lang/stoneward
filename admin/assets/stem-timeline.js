(() => {
  'use strict';
  // Project timeline metadata operations — preserve source filename and offset.
  const number=x=>Number.isFinite(Number(x))?Number(x):0;
  function snap(value,bpm,enabled){
    const beat=60/Math.max(20,Math.min(400,number(bpm)||120));
    return enabled?Math.round(value/beat)*beat:value;
  }
  function location(position,length,duration){
    const max=Math.max(.1,number(duration));
    const left=Math.max(0,Math.min(100,number(position)/max*100));
    const width=Math.max(.1,Math.min(100-left,number(length)/max*100));
    return {left,width};
  }
  function render(track,duration,escape){
    return (track.clips||[]).map((clip,index)=>{
      const where=location(clip.position,clip.length,duration);
      const playable=String(clip.file||'').toLowerCase()===String(track.source_file||'').toLowerCase()&&
        !!track.asset_uuid;
      return '<button type="button" class="sf-studio-clip sf-studio-editable-clip'+
        (playable?'':' sf-studio-unlinked')+'" data-stem-clip-index="'+index+
        '" data-stem-clip-guid="'+escape(track.guid)+'" style="left:'+where.left+'%;width:'+where.width+
        '%" title="'+escape(clip.file||'Unlinked source')+' · start '+number(clip.position).toFixed(2)+
        's · duration '+number(clip.length).toFixed(2)+'s" aria-label="Edit clip '+
        (index+1)+' at '+number(clip.position).toFixed(2)+' seconds"><span>'+
        escape(clip.file||'Unlinked clip')+'</span><span class="sf-studio-trim-handle" aria-hidden="true"></span></button>';
    }).join('');
  }
  function bind(root,project,options){
    const duration=Math.max(.1,Number(options.duration)||1);
    const bpm=Number(project.tempo_bpm)||120;
    const clips=Array.from(root.querySelectorAll('[data-stem-clip-index]'));
    for(const button of clips){
      button.addEventListener('pointerdown',event=>{
        if(event.button!==0||options.locked?.())return;
        const guid=button.dataset.stemClipGuid,index=Number(button.dataset.stemClipIndex);
        const track=project.tracks.find(t=>t.guid===guid);
        const clip=track?.clips[index];if(!clip)return;
        const lane=button.parentElement;
        const rect=lane?.getBoundingClientRect();
        if(!rect?.width)return;
        const startX=event.clientX;
        const original={position:Number(clip.position),length:Number(clip.length),offset:Number(clip.offset)};
        const rightEdge=button.getBoundingClientRect().right;
        const trim=rightEdge-event.clientX<=14;
        let moved=false;
        const controller=new AbortController();
        const percent=seconds=>location(seconds,trim?original.length:original.length,duration);
        const onMove=e=>{
          const delta=(e.clientX-startX)/rect.width*duration;
          if(Math.abs(e.clientX-startX)>3)moved=true;
          if(!moved)return;
          if(trim){
            const len=Math.max(.02,Math.min(86400-original.position,
              snap(original.length+delta,bpm,options.snap?.())));
            button.style.width=location(original.position,len,duration).width+'%';
          }else{
            const pos=Math.max(0,Math.min(86400-original.length,
              snap(original.position+delta,bpm,options.snap?.())));
            button.style.left=location(pos,original.length,duration).left+'%';
          }
        };
        const onUp=e=>{
          controller.abort();
          if(!moved){options.onSelect?.(guid,index);return;}
          const delta=(e.clientX-startX)/rect.width*duration;
          const next={...original};
          if(trim)next.length=Math.max(.02,Math.min(86400-original.position,
            snap(original.length+delta,bpm,options.snap?.())));
          else next.position=Math.max(0,Math.min(86400-original.length,
            snap(original.position+delta,bpm,options.snap?.())));
          // No mutation of original project until server validates and commits.
          options.onEdit?.(guid,index,next);
        };
        button.ownerDocument.addEventListener('pointermove',onMove,{signal:controller.signal});
        button.ownerDocument.addEventListener('pointerup',onUp,{once:true,signal:controller.signal});
        button.ownerDocument.addEventListener('pointercancel',()=>{
          controller.abort();options.onSelect?.(guid,index);
        },{once:true,signal:controller.signal});
        event.preventDefault();
      });
      button.addEventListener('keydown',event=>{
        if(event.key==='Enter'||event.key===' '){
          options.onSelect?.(button.dataset.stemClipGuid,Number(button.dataset.stemClipIndex));
        }
      });
    }
  }
  window.SFStemTimeline={snap,location,render,bind};
})();
