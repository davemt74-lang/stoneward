(() => {
  'use strict';
  const channel='stonefellow-canvas-test';
  const validToken=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  const token=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');
  const namedErrors=new Set(['Error','TypeError','ReferenceError','SyntaxError','RangeError','EvalError','URIError','NotAllowedError','SecurityError','AbortError']);
  const imageTypes=new Set(['image/png','image/jpeg','image/webp']);
  const probeBody=String.raw`
    const documentId=Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');
    const script=document.currentScript;
    script?.remove();
    const send=parent.postMessage.bind(parent);
    const emit=(type,value={})=>send({channel:'stonefellow-canvas-test',version:1,nonce,documentId,type,...value},'*');
    const manualSeen=new Set();
    let closed=false;
    let eventCount=0;
    const report=(type,value)=>{if(!closed&&eventCount++<80)emit(type,value);};
    const manual=code=>{if(!manualSeen.has(code)){manualSeen.add(code);report('manual',{code});}};
    const denied=code=>{manual(code);const error=new Error('This action requires a manual canvas check.');error.name='NotAllowedError';return Promise.reject(error);};
    if(typeof HTMLMediaElement!=='undefined')HTMLMediaElement.prototype.play=function(){return denied('local_media_play');};
    for(const name of ['AudioContext','webkitAudioContext']){
      const prototype=window[name]?.prototype;
      if(prototype&&typeof prototype.resume==='function')prototype.resume=function(){return denied('local_audio_resume');};
    }
    if(typeof AudioNode!=='undefined'&&typeof AudioDestinationNode!=='undefined'){
      const connect=AudioNode.prototype.connect;
      AudioNode.prototype.connect=function(destination,...args){
        if(destination instanceof AudioDestinationNode){manual('local_audio_output');const error=new Error('Audio output requires manual review.');error.name='NotAllowedError';throw error;}
        return connect.call(this,destination,...args);
      };
    }
    addEventListener('error',event=>{
      if(!event.isTrusted)return;
      if(event.target instanceof HTMLImageElement)return;
      if(event.error?.code==='sf_preview_budget')return;
      const symbol=event.error?.name==='ReferenceError'?String(event.error.message||'').match(/^([A-Za-z_$][A-Za-z0-9_$]{0,79}) is not defined\b/)?.[1]:undefined;
      report('runtime_error',{code:'runtime_error',name:event.error?.name||'Error',line:event.lineno||0,column:event.colno||0,...(symbol?{symbol}:{})});
    },true);
    addEventListener('unhandledrejection',event=>{
      if(!event.isTrusted)return;
      if(event.reason?.code==='sf_preview_budget')return;
      if(event.reason?.name==='NotAllowedError'&&manualSeen.size){manual('blocked_action_rejection');return;}
      report('runtime_error',{code:'unhandled_rejection',name:event.reason?.name||'Error'});
    });
    addEventListener('securitypolicyviolation',event=>{
      if(!event.isTrusted)return;
      const directive=String(event.effectiveDirective||event.violatedDirective||'').split(' ')[0];
      report('policy_violation',{directive});
    });
    const tick=()=>new Promise(resolve=>requestAnimationFrame(resolve));
    async function snapshot(sequence){
      await tick();await tick();
      const images=Array.from(document.images);
      await Promise.allSettled(images.map(image=>typeof image.decode==='function'?image.decode():Promise.resolve()));
      if(closed)return;
      const main=document.querySelector('main');
      const body=document.body;
      const elements=body?Array.from(body.querySelectorAll('*')).filter(element=>!['SCRIPT','STYLE','TEMPLATE'].includes(element.tagName)):[];
      let visible=0;
      for(const element of elements){
        const bounds=element.getBoundingClientRect();const style=getComputedStyle(element);
        if(bounds.width>0&&bounds.height>0&&style.display!=='none'&&style.visibility!=='hidden'&&Number(style.opacity)>0)visible++;
      }
      const datasets=[];
      for(const element of elements){
        const ids=Array.from(element.classList).filter(name=>/^sf-dataset-[a-z][a-z0-9_-]{0,63}$/.test(name));
        if(ids.length!==1)continue;
        datasets.push({id:ids[0].slice(11),rows:Array.from(element.children).filter(child=>child.classList.contains('sf-data-row')).length,
          empty:!!element.querySelector('.sf-data-empty'),unavailable:!!element.querySelector('.sf-data-unavailable')});
        if(datasets.length===30)break;
      }
      const imageBindings=[];
      for(const property of document.documentElement.style){
        if(/^--sf-image-(?:ref-)?[a-f0-9]{32}$/.test(property)&&document.documentElement.style.getPropertyValue(property))imageBindings.push(property.slice(11));
      }
      const interactive=body?body.querySelectorAll('button,a,input,select,textarea,details,summary,[tabindex],[role="button"]').length:0;
      emit('snapshot',{sequence,observed:{main:!!main,elements:elements.length,visible,interactive,
        width:innerWidth,height:innerHeight,scrollWidth:Math.max(document.documentElement.scrollWidth,body?.scrollWidth||0),
        scrollHeight:Math.max(document.documentElement.scrollHeight,body?.scrollHeight||0),
        images:images.length,decodedImages:images.filter(image=>image.complete&&image.naturalWidth>0).length,
        failedImages:images.filter(image=>image.complete&&image.naturalWidth===0).length,imageBindings,datasets}});
    }
    let lastSequence=0;
    addEventListener('message',event=>{
      const message=event.data;
      if(!event.isTrusted||event.source!==parent||message?.channel!=='stonefellow-canvas-test'||message.version!==1||
        message.type!=='observe'||!Number.isSafeInteger(message.sequence)||message.sequence<=lastSequence)return;
      lastSequence=message.sequence;
      void snapshot(message.sequence);
    });
    addEventListener('pagehide',()=>{if(!closed)emit('unloaded');closed=true;},{once:true});
    emit('started');
    addEventListener('DOMContentLoaded',()=>emit('ready'),{once:true});
  `;

  /** The review sees the binding contract while account rows stay in the applied canvas. */
  function detachedData(scene){
    const personalIds=new Set((scene?.canvas_access?.permissions??[])
      .filter(permission=>permission?.personal===true&&typeof permission.id==='string'&&permission.id.startsWith('canvas.data.'))
      .map(permission=>permission.id.slice('canvas.data.'.length)));
    const datasets=Object.fromEntries(Object.entries(scene?.datasets??{}).map(([id,dataset])=>{
      if(dataset?.personal===true)personalIds.add(id);
      if(!personalIds.has(id)||!dataset)return [id,dataset];
      const {fields,filters,actions}=dataset;
      return [id,{fields,filters,actions,rows:[],state:'consent_required',
        ...(Object.hasOwn(dataset,'current_rows')?{current_rows:[]}:{})}];
    }));
    return {candidate:personalIds.size?{...scene,datasets}:scene,personalIds};
  }

  async function test(scene,options={}){
    const report={state:'testing',checks:[],errors:[],warnings:[],manual:[],observed:null};
    const emit=(type,value)=>{try{options.onEvent?.({type,...value});}catch(_){}};
    const entry=(list,code,message,details={})=>{
      if(list.length<24&&!list.some(item=>item.code===code)){list.push({code,message,...details});emit(list===report.errors?'error':list===report.manual?'manual':'warning',{code,message,...details});}
    };
    const check=(id,label,passed,detail)=>{
      const value={id,label,status:passed?'passed':'failed',...(detail?{detail}:{})};
      if(report.checks.length<48){report.checks.push(value);emit('check',value);}
    };
    if(options.signal?.aborted){report.state='canceled';return report;}
    emit('started',{message:'Checking this candidate in an isolated browser frame.'});
    let candidate,guard=null,preview;
    const nonce=token();
    try{
      preview=detachedData(scene);
      const original=scene?.js??'';
      if(typeof original!=='string')throw new Error('Candidate program unavailable.');
      if(original!==''){
        const originalDigest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(original));
        const originalHash=btoa(String.fromCharCode(...new Uint8Array(originalDigest)));
        if(originalHash!==scene.js_hash)throw new Error('Candidate program hash changed.');
        if(typeof window.SFCanvasPreviewGuard?.prepare!=='function')throw new Error('Preview instrumentation unavailable.');
        guard=await window.SFCanvasPreviewGuard.prepare(original);
        check('preview_guard','Original program hash and preview syntax were verified',true);
      }
      const budget=guard?`addEventListener(${JSON.stringify(guard.event)},()=>emit('budget',{limit:${guard.limit}}));${guard.bootstrap}`:'';
      const source=`(()=>{'use strict';const nonce=${JSON.stringify(nonce)};${probeBody}${budget}})();`;
      const bytes=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(source));
      const hash="'sha256-"+btoa(String.fromCharCode(...new Uint8Array(bytes)))+"'";
      const prepare=options.prepareScene||((value,probe,previewProgram)=>window.SFPublicAgentCanvas?.prepareSceneTest(value,{probe,previewProgram,currentTrackId:options.currentTrackId||''}));
      candidate=await prepare(preview.candidate,{source,hash},guard);
      if(!candidate?.frame||candidate.frame.tagName!=='IFRAME')throw new Error('Candidate document unavailable.');
    }catch(error){
      if(error?.code==='sf_preview_unsupported'){
        entry(report.manual,'sf_preview_unsupported','This program uses JavaScript features that the preview check cannot safely verify.');
        report.state='incomplete';report.observed={guarded:false,verification_complete:false,
          execution_budget_exhausted:false,instrumentation_unsupported:true};
        emit('complete',{report});return report;
      }
      check('renderer','Production renderer accepted the candidate',false);
      entry(report.errors,'invalid_candidate','The candidate could not be prepared with the production canvas renderer.');
      report.state='failed';report.observed={guarded:!!guard,verification_complete:true,execution_budget_exhausted:false};
      emit('complete',{report});return report;
    }
    if(options.signal?.aborted){candidate.frame.remove();report.state='canceled';return report;}
    check('renderer','Production renderer accepted the candidate',true);
    if(Array.isArray(scene.artist_inputs?.data_sources)&&scene.artist_inputs.data_sources.length)
      entry(report.manual,'live_data','External data and rules that depend on it need a check in the applied canvas.');
    const frame=candidate.frame;
    const request=new AbortController();
    const dummyAudio=document.createElement('audio');
    dummyAudio.muted=true;
    const owner=options.getOwner?.();
    const pendingReads=new Set();
    const images=[];
    let host=null,documentId=null,loaded=false,ready=false,sdkReady=!candidate.script?.js;
    let observing=false,settled=false,loadCount=0,sequence=0,observer=null,budgetExhausted=false;
    let resolveResult;
    const result=new Promise(resolve=>{resolveResult=resolve;});
    const authorized=()=>!request.signal.aborted&&(!options.getOwner||Object.is(owner,options.getOwner()))&&(!options.canUse||options.canUse()===true);
    function finish(state){
      if(settled)return;
      settled=true;report.state=state;
      report.observed={...(report.observed||{}),guarded:!!guard,verification_complete:state!=='canceled'&&state!=='incomplete',
        execution_budget_exhausted:budgetExhausted,...(guard?{execution_guard_limit:guard.limit}:{})};
      request.abort();host?.destroy();observer?.disconnect();
      window.removeEventListener('message',onMessage);
      window.removeEventListener('pagehide',onPageHide);
      options.signal?.removeEventListener('abort',onAbort);
      frame.remove();
      emit('complete',{report});resolveResult(report);
    }
    function onAbort(){finish('canceled');}
    function onPageHide(){finish('canceled');}
    function failure(code){const error=new Error('The preview read did not complete.');error.code=code;return error;}
    function read(callback,args,upstreamSignal){
      if(!authorized()||typeof callback!=='function')return Promise.reject(failure('preview_data_unavailable'));
      const controller=new AbortController();
      let rejectAbort;
      const aborted=new Promise((_,reject)=>{rejectAbort=reject;});
      const abort=()=>{controller.abort();rejectAbort(failure('audio_session_closed'));};
      request.signal.addEventListener('abort',abort,{once:true});
      upstreamSignal?.addEventListener('abort',abort,{once:true});
      const promise=Promise.race([Promise.resolve().then(()=>callback(...args,controller.signal)),aborted]).then(value=>{
        if(!authorized())throw failure('audio_session_closed');return value;
      }).finally(()=>{
        pendingReads.delete(promise);
        request.signal.removeEventListener('abort',abort);
        upstreamSignal?.removeEventListener('abort',abort);
      });
      pendingReads.add(promise);
      if(request.signal.aborted||upstreamSignal?.aborted)abort();
      return promise;
    }
    function denied(method){
      entry(report.manual,'host_'+method,'The '+method+' action requires a manual check in the applied canvas.');
      return Promise.reject(failure('preview_manual_required'));
    }
    function postImages(){
      if(loaded&&ready&&images.length&&!settled)frame.contentWindow?.postMessage({stonefellowSceneImages:images},'*');
    }
    const nextFrame=()=>new Promise(resolve=>requestAnimationFrame(resolve));
    async function observe(){
      if(observing||settled||!loaded||!ready||!sdkReady)return;
      observing=true;
      // Let the SDK deliver initialization reads, then observe that current batch.
      await nextFrame();await nextFrame();
      await Promise.allSettled([...pendingReads]);
      if(settled)return;
      if(!authorized()){finish('canceled');return;}
      postImages();
      sequence++;
      frame.contentWindow?.postMessage({channel,version:1,type:'observe',sequence},'*');
    }
    function processSnapshot(value){
      if(!value||typeof value!=='object'||!Number.isSafeInteger(value.elements)||!Number.isSafeInteger(value.visible)||
        !Number.isFinite(value.width)||!Number.isFinite(value.scrollWidth))return;
      const normalized={main:value.main===true};
      for(const key of ['elements','visible','interactive','width','height','scrollWidth','scrollHeight','images','decodedImages','failedImages'])
        normalized[key]=Number.isSafeInteger(value[key])?Math.min(10000000,Math.max(0,value[key])):0;
      normalized.imageBindings=Array.isArray(value.imageBindings)?value.imageBindings.filter(id=>typeof id==='string'&&/^(?:ref-)?[a-f0-9]{32}$/.test(id)).slice(0,4):[];
      normalized.datasets=Array.isArray(value.datasets)?value.datasets.filter(item=>item&&typeof item.id==='string'&&/^[a-z][a-z0-9_-]{0,63}$/.test(item.id)).slice(0,30)
        .map(item=>({id:item.id,rows:Number.isSafeInteger(item.rows)?Math.max(0,Math.min(240,item.rows)):0,empty:item.empty===true,unavailable:item.unavailable===true})):[];
      value=normalized;report.observed=value;
      check('runtime','Initialization completed without an observed JavaScript error',report.errors.length===0);
      check('document','Candidate document was observed in the browser',true);
      check('layout','Browser measured the candidate at the tested viewport',true,
        String(value.width)+'px viewport, '+String(value.scrollWidth)+'px document');
      if(value.visible===0)entry(report.manual,'visual_surface','This candidate uses no visible element boxes. Its visual surface needs manual review.');
      if(value.scrollWidth>value.width+2)entry(report.warnings,'horizontal_scroll','The candidate extends beyond the tested viewport width. Review whether this is intended.');
      if(value.scrollHeight>value.height+2)entry(report.warnings,'vertical_scroll','The candidate extends below the tested viewport.');
      if(value.interactive>0)entry(report.manual,'interactive_controls',String(value.interactive)+' interactive elements require manual pointer, keyboard or touch checks.');
      if(pendingReads.size)entry(report.manual,'pending_reads','Additional reads started after the initialization batch and were not observed to completion.');
      const assets=candidate.imageAssets;
      if(assets?.size){
        const bound=new Set(Array.isArray(value.imageBindings)?value.imageBindings:[]);
        const available=[...assets.keys()].every(id=>images.some(image=>image.id===id)&&bound.has(id));
        check('images','Declared image assets loaded through the production image bridge',available);
        if(!available)entry(report.errors,'image_unavailable','One or more declared image assets were unavailable during the check.');
      }
      if(value.failedImages>0){check('image_decode','Rendered images decoded',false);entry(report.errors,'image_decode','An image in the candidate did not decode.');}
      const declared=preview.candidate?.datasets??{};
      const requested=new Set(Array.from(String(scene.html||'').matchAll(/\bsf-dataset-([a-z][a-z0-9_-]{0,63})\b/g),match=>match[1]));
      for(const id of requested){
        const dataset=Object.hasOwn(declared,id)?declared[id]:null;
        const observed=Array.isArray(value.datasets)?value.datasets.find(item=>item.id===id):null;
        if(preview.personalIds.has(id)){
          entry(report.manual,'personal_data','Personal records are withheld from testing. Review the populated bindings in the applied canvas after granting access.');
          if(observed)check('dataset_'+id,'Observed '+id+' data binding',true,'Personal rows withheld');
          else entry(report.warnings,'dataset_'+id,'The rendered candidate did not retain the declared '+id+' data container.');
        }else if(!dataset||dataset.state==='unavailable')entry(report.errors,'dataset_'+id,'A declared data source was unavailable during this check.');
        else if(!observed)entry(report.warnings,'dataset_'+id,'The rendered candidate did not retain the declared '+id+' data container.');
        else check('dataset_'+id,'Observed '+id+' data binding',true,String(observed.rows)+' rendered rows');
      }
      entry(report.manual,'later_behavior','This check covers initialization and the observed data batch. Later animation, interaction and real playback need manual review.');
      finish(report.errors.length||report.checks.some(item=>item.status==='failed')?'failed':'passed');
    }
    function onMessage(event){
      const message=event.data;
      if(settled||!event.isTrusted||event.origin!=='null'||event.source!==frame.contentWindow||message?.channel!==channel||message.version!==1||
        message.nonce!==nonce||!validToken(message.documentId))return;
      if(documentId===null){if(message.type!=='started')return;documentId=message.documentId;}
      if(message.documentId!==documentId)return;
      if(message.type==='ready'){ready=true;postImages();void observe();}
      else if(message.type==='budget'&&guard&&message.limit===guard.limit){
        budgetExhausted=true;
        entry(report.manual,'sf_preview_budget','The instrumented preview exceeded its synchronous operation allowance. Verification is incomplete.');
        finish('incomplete');
      }
      else if(message.type==='runtime_error'){
        const name=namedErrors.has(message.name)?message.name:'Error';
        const details={};
        for(const field of ['line','column'])if(Number.isSafeInteger(message[field])&&message[field]>0&&message[field]<=10000000)details[field]=message[field];
        if(typeof message.symbol==='string'&&/^[A-Za-z_$][A-Za-z0-9_$]{0,79}$/.test(message.symbol))details.symbol=message.symbol;
        entry(report.errors,message.code==='unhandled_rejection'?'unhandled_rejection':'runtime_error',name+' observed while the candidate initialized.',details);
      }else if(message.type==='manual'){
        if(['local_media_play','local_audio_resume','local_audio_output','blocked_action_rejection'].includes(message.code))
          entry(report.manual,message.code,'Local audio playback requires a manual check in the applied canvas.');
      }else if(message.type==='policy_violation'){
        const directive=typeof message.directive==='string'&&/^[a-z-]{1,40}$/.test(message.directive)?message.directive:'sandbox policy';
        entry(report.errors,'policy_'+directive,'The browser blocked a candidate operation under '+directive+'.');
      }else if(message.type==='snapshot'&&message.sequence===sequence&&sequence>0)processSnapshot(message.observed);
      else if(message.type==='unloaded'){entry(report.errors,'candidate_unloaded','The candidate navigated away before observation completed.');finish('failed');}
    }
    window.addEventListener('message',onMessage);
    window.addEventListener('pagehide',onPageHide,{once:true});
    options.signal?.addEventListener('abort',onAbort,{once:true});
    frame.addEventListener('load',()=>{
      if(++loadCount>1){entry(report.errors,'candidate_reloaded','The candidate reloaded before observation completed.');finish('failed');return;}
      loaded=true;postImages();void observe();
    });
    try{
      if(candidate.script?.js){
        host=candidate.script.sdk.createHost({frame,audio:dummyAudio,getOwner:()=>options.getOwner?.()??owner,canUse:authorized,
          loadCatalog:(filters,signal)=>read(options.loadCatalog,[filters],signal),
          loadAudio:(id,signal)=>read(options.loadAudio,[id],signal),
          play:()=>denied('play'),pause:()=>denied('pause'),seek:()=>denied('seek'),setVolume:()=>denied('setVolume'),
          getState:()=>({...(options.getState?.()||{}),playing:false,muted:true}),
          onDiagnostic:value=>{
            if(value.event==='session_ready'){sdkReady=true;check('audio_sdk','Production audio SDK established an isolated session',true);void observe();}
            else if(value.event==='rpc_error'&&!report.manual.some(item=>item.code==='host_'+value.method))
              entry(report.errors,'audio_'+value.method,'The '+value.method+' read did not complete in the isolated session.');
          }});
      }
      for(const [id,title] of candidate.imageAssets||[]){
        const task=read(options.onSceneImage,[id]).then(blob=>{
          if(!(blob instanceof Blob)||!imageTypes.has(blob.type)||blob.size<1||blob.size>16*1024*1024)throw failure('invalid_image');
          images.push({id,title,blob});postImages();
        }).catch(()=>{if(!request.signal.aborted)entry(report.warnings,'image_read_'+id,'A declared image could not be read for this candidate.');});
        pendingReads.add(task);void task.finally(()=>pendingReads.delete(task));
      }
      const width=Number.isFinite(options.width)?Math.max(280,Math.min(2560,options.width)):Math.max(280,window.innerWidth);
      const height=Number.isFinite(options.height)?Math.max(240,Math.min(1800,options.height)):Math.max(240,window.innerHeight);
      Object.assign(frame.style,{position:'fixed',left:'0',top:'0',width:width+'px',height:height+'px',border:'0',opacity:'0',pointerEvents:'none',zIndex:'-2147483647'});
      document.body.append(frame);
      observer=new MutationObserver(()=>{if(!frame.isConnected&&!settled)finish('canceled');});
      observer.observe(document.body,{childList:true,subtree:true});
      if(options.signal?.aborted)onAbort();
    }catch(_){entry(report.errors,'preview_setup','The isolated candidate could not be started.');finish('failed');}
    return result;
  }
  window.SFCanvasSceneTester=Object.freeze({test});
})();
