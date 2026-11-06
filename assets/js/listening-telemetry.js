/* Canonical playback telemetry from parent-owned native media, never canvas claims. */
(() => {
  'use strict';
  let current=null,main=null;
  const cloneContext=value=>value?.app===true&&Object.keys(value).length===1?{app:true}:
    Number.isSafeInteger(value?.scene_id)&&value.scene_id>0&&Object.keys(value).length===1?{scene_id:value.scene_id}:
    typeof value?.gallery_id==='string'&&/^[a-f0-9]{32}$/.test(value.gallery_id)&&Object.keys(value).length===1?{gallery_id:value.gallery_id}:null;
  const id=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');
  const number=value=>Number.isFinite(Number(value))?Math.max(0,Number(value)):0;
  function create(options){
    let owner=String(options.getOwner?.()||''),generation=0,ready=null,loading=null,running=false,destroyed=false;
    const queue=[],observers=new Set(),requests=new Set();
    const clock=options.now||(()=>performance.now());
    const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
    const diagnostic=code=>options.onDiagnostic?.({event:'listening_telemetry',code});
    function syncOwner(){
      const next=String(options.getOwner?.()||'');if(next===owner)return false;
      owner=next;generation++;ready=null;loading=null;queue.length=0;
      for(const request of requests)request.abort();requests.clear();
      for(const observer of observers)observer.reset();return true;
    }
    function privacyChanged(){
      syncOwner();generation++;ready=null;loading=null;queue.length=0;
      for(const request of requests)request.abort();requests.clear();
      for(const observer of observers)observer.reset({privacy:true});
      void protocol().catch(()=>{diagnostic('protocol_unavailable');});
    }
    async function protocol(){
      syncOwner();if(ready)return ready;if(loading)return loading;
      const epoch=generation,request=new AbortController();requests.add(request);
      const timer=setTimeout(()=>request.abort(),15000);
      const task=(async()=>{const result=await options.readProtocol(request.signal);
        if(destroyed||epoch!==generation||request.signal.aborted)throw new DOMException('Listening account changed.','AbortError');
        if(String(result?.user_id||'')!==owner||(result?.user_id!==null&&(!Number.isInteger(result?.user_id)||result.user_id<0))||result?.ok!==true||typeof result.csrf!=='string'||!/^[a-f0-9]{32}$/.test(result.session_token||'')||!Number.isInteger(result.privacy_epoch)||result.privacy_epoch<0)throw Error('Invalid listening protocol');
        ready={csrf:result.csrf,session_token:result.session_token,privacy_epoch:result.privacy_epoch};return ready;
      })();loading=task;
      try{return await task;}finally{clearTimeout(timer);requests.delete(request);if(loading===task)loading=null;}
    }
    function invalidate(play,rollover=false,context=null){play.rolloverContext=context||play.context;play.rollover=rollover;play.rolloverPosition=play.position;play.valid=false;for(let i=queue.length-1;i>=0;i--)if(queue[i].play===play)queue.splice(i,1);}
    async function drain(){
      if(running||destroyed)return;running=true;
      try{
        while(queue.length&&!destroyed){
          syncOwner();const task=queue[0];if(!task)break;
          if(!task.play.valid||task.generation!==generation||task.owner!==owner){queue.shift();continue;}
          let delivered=false;
          for(let attempt=0;attempt<3&&!delivered;attempt++){
            const request=new AbortController();requests.add(request);const timer=setTimeout(()=>request.abort(),15000);
            try{
              const auth=task.play.auth||(task.play.auth=await protocol());
              if(!task.play.valid||task.generation!==generation||task.owner!==String(options.getOwner?.()||''))break;
              const payload=task.payload||(task.payload={...task.body,session_token:auth.session_token,privacy_epoch:auth.privacy_epoch});
              const result=await options.post(payload,auth.csrf,request.signal);
              if(result?.ok!==true)throw Error('Listening report unavailable');
              delivered=true;
            }catch(error){
              if(task.generation!==generation||task.owner!==String(options.getOwner?.()||''))break;
              if(error.status===409||error.status===401||error.status===403){ready=null;invalidate(task.play,error.status===409&&error.payload?.error==='playback_session_changed',task.play.method==='main'&&error.payload?.reason==='privacy_changed'?{app:true}:null);diagnostic('context_expired');break;}
              if(error.status>=400&&error.status<500){invalidate(task.play);diagnostic('report_rejected');break;}
              if(attempt<2)await delay(options.retryDelayMs??(500*(attempt+1)));
            }finally{clearTimeout(timer);requests.delete(request);}
          }
          if(!delivered&&task.play.valid){invalidate(task.play);diagnostic('report_unavailable');}
          if(queue[0]===task)queue.shift();
        }
      }finally{running=false;if(queue.length&&!destroyed)void drain();}
    }
    function enqueue(play,body){
      syncOwner();if(destroyed||!play.valid||play.generation!==generation||play.owner!==owner)return;
      if(queue.length>=256){invalidate(play);diagnostic('report_queue_full');return;}
      queue.push({play,body:{protocol:2,...body,event_id:id(),play_id:play.id},generation,owner});void drain();
    }
    function observe(audio,settings={}){
      if(!audio?.addEventListener||destroyed)return null;
      let play=null,prepared=null,continuation=null,disposed=false;
      const method=settings.method==='independent'?'independent':'main';
      const track=()=>{const value=settings.getTrack?.();const trackId=value?.track_id??value?.id;return typeof trackId==='string'&&/^[A-Za-z0-9_-]{1,180}$/.test(trackId)?{...value,track_id:trackId}:null;};
      const at=()=>number(audio.currentTime),duration=()=>number(audio.duration)||number(track()?.duration_seconds??track()?.duration);
      const rate=()=>Math.max(.25,Math.min(4,number(audio.playbackRate)||1));
      function sample(allowPaused=false){
        if(!play||!play.valid)return;const now=clock(),position=at();
        const elapsed=Math.max(0,(now-play.clock)/1000),advance=position-play.position;
        if(play.state==='playing'&&!audio.seeking&&(!audio.paused||allowPaused)&&advance>0&&advance<=elapsed*Math.max(play.rate,rate())+.5)
          play.elapsed+=Math.min(advance/Math.max(play.rate,rate()),elapsed);
        play.position=position;play.clock=now;play.rate=rate();
      }
      function report(type){if(!play||!play.valid)return;enqueue(play,{action:'event',event_type:type,position_seconds:at(),duration_seconds:duration(),elapsed_played_seconds:play.elapsed,playback_rate:rate()});}
      function begin(){
        if(disposed||audio.paused||audio.seeking||settings.isActive?.()===false)return;
        syncOwner();const selected=track();if(!selected)return;
        if(prepared?.trackId&&prepared.trackId!==selected.track_id)return;
        const context=cloneContext(prepared?.context||settings.getContext?.()||{app:true});if(!context)return;
        const initialEvent=prepared?.resume||number(prepared?.startAt)>0||(prepared?.startAt==null&&at()>.25)?'resume':'start';
        play={id:id(),owner,generation,valid:true,method,track_id:selected.track_id,context:Object.freeze(context),state:'playing',elapsed:0,position:at(),clock:clock(),rate:rate(),lastReport:clock(),auth:null};
        prepared=null;continuation=null;
        enqueue(play,{action:'begin',event_type:initialEvent,track_id:play.track_id,context:play.context,playback_method:method,
          position_seconds:at(),duration_seconds:duration(),elapsed_played_seconds:0,playback_rate:rate()});
      }
      function playing(){
        syncOwner();if(disposed||audio.paused||audio.seeking)return;
        if(!play||!play.valid){if(!prepared&&play?.rollover)prepared={context:play.rolloverContext||play.context,trackId:play.track_id,resume:true};begin();return;}
        if(track()?.track_id!==play.track_id){stop('skip');begin();return;}
        const previous=play.state;sample();play.state='playing';play.position=at();play.clock=clock();
        if(previous==='paused')report('resume');else if(previous!=='playing')report('playing');
      }
      function progress(){
        syncOwner();if(disposed)return;
        if(play&&!play.valid&&play.rollover&&play.state==='playing')continuation={position:play.rolloverPosition,context:play.rolloverContext||play.context,trackId:play.track_id};
        if(continuation&&!audio.paused&&!audio.seeking&&!audio.ended&&Number(audio.readyState)>=3&&at()>continuation.position+.001&&track()?.track_id===continuation.trackId){
          prepared={context:continuation.context,trackId:continuation.trackId,resume:true};begin();return;
        }
        if(!play||!play.valid)return;sample();
        if(play.state==='playing'&&clock()-play.lastReport>=10000){play.lastReport=clock();report('progress');}
      }
      function pause(){if(!play||!play.valid||disposed||audio.ended)return;sample(true);if(settings.isRecovering?.()){if(play.state!=='buffering'){play.state='buffering';report('buffer');}}else if(play.state!=='paused'){play.state='paused';report('pause');}}
      function buffer(){if(!play||!play.valid||disposed)return;sample();if(play.state==='playing'){play.state='buffering';report('buffer');}}
      function seeking(){if(!play||!play.valid||disposed)return;play.position=at();play.clock=clock();play.state=audio.paused?'paused':'seeking';report('seek');}
      function seeked(){if(!play||!play.valid||disposed)return;play.position=at();play.clock=clock();if(audio.paused)play.state='paused';else if(!audio.seeking&&Number(audio.readyState)>=3){play.state='playing';report('playing');}}
      function ended(){
        if(!play||!play.valid||disposed||!audio.ended)return;sample(true);
        const allowed=settings.canComplete?settings.canComplete():duration()>0&&at()>=Math.max(0,duration()-2);
        report(allowed?'complete':'pause');play=null;
      }
      function stop(reason='skip'){
        if(play?.valid){sample(true);report(reason==='complete'?'complete':reason==='pause'?'pause':'skip');}
        play=null;prepared=null;continuation=null;
      }
      function prepare(context,{trackId,restart=false,startAt=null}={}){
        syncOwner();const next=cloneContext(context);if(!next)return false;
        if(play?.valid){
          const sameTrack=!trackId||trackId===play.track_id,sameContext=JSON.stringify(next)===JSON.stringify(play.context);
          if(!restart&&sameTrack&&(!audio.paused||sameContext))return true;
          stop(play.state==='paused'?'pause':'skip');
        }
        prepared={context:Object.freeze(next),trackId,startAt};return true;
      }
      const handlers={playing,timeupdate:progress,pause,waiting:buffer,stalled:buffer,seeking,seeked,ended,ratechange:()=>{sample();if(play?.valid&&play.state==='playing'&&!audio.paused)report('playing');},error:()=>Promise.resolve().then(()=>{if(settings.isRecovering?.())buffer();else stop('pause');}),emptied:()=>{if(play&&track()?.track_id!==play.track_id)stop('skip');else if(play)buffer();}};
      for(const [name,handler] of Object.entries(handlers))audio.addEventListener(name,handler);
      const observer={prepare,stop,flush(){if(play?.valid&&play.state==='playing'){sample();report('progress');}},reset({privacy=false}={}){continuation=(method==='main'||privacy&&!!(play?.context.gallery_id||play?.context.scene_id)&&settings.isActive?.()!==false)&&play?.state==='playing'&&!audio.paused&&!audio.seeking?{position:at(),context:method==='main'?{app:true}:play.context,trackId:play.track_id}:null;if(play)play.valid=false;play=null;prepared=null;},dispose(){if(disposed)return;stop('skip');disposed=true;for(const [name,handler] of Object.entries(handlers))audio.removeEventListener(name,handler);observers.delete(observer);}};
      observers.add(observer);return observer;
    }
    const flush=()=>{syncOwner();for(const observer of observers)observer.flush();};
    const visibility=()=>{if(typeof document!=='undefined'&&document.visibilityState==='hidden')flush();};
    window.addEventListener?.('pagehide',flush);if(typeof document!=='undefined')document.addEventListener('visibilitychange',visibility);
    void protocol().catch(()=>{diagnostic('protocol_unavailable');});
    return {observe,setOwner:syncOwner,privacyChanged,destroy(){destroyed=true;generation++;queue.length=0;window.removeEventListener?.('pagehide',flush);if(typeof document!=='undefined')document.removeEventListener('visibilitychange',visibility);for(const observer of Array.from(observers))observer.dispose();for(const request of requests)request.abort();requests.clear();ready=null;loading=null;}};
  }
  window.SFListeningTelemetry=Object.freeze({create(options){current?.destroy();main=null;current=create(options);return current;},observeMain(audio,settings){main=current?.observe(audio,settings)||null;return main;},hasMain(){return !!main;},prepareMain(context,settings){return main?.prepare(context,settings);},observe(audio,settings){return current?.observe(audio,settings)||null;},setOwner(){current?.setOwner();},privacyChanged(){current?.privacyChanged();}});
})();
