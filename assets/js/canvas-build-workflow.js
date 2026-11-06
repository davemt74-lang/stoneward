/* One approved project: observe candidates, review evidence and repair before application. */
(() => {
  'use strict';
  const id=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');
  const abort=()=>new DOMException('The project was stopped.','AbortError');
  const terminal=new Set(['ready','applied','needs_attention','canceled','failed']);
  const pending=new Set(['reviewing','repairing']);
  function create(options){
    const requestId=options.requestId,owner=options.getOwner();
    if(!/^[a-f0-9]{32}$/.test(requestId))throw new Error('Choose a valid project request.');
    const controller=new AbortController();
    let snapshot=null,localStep=null,stopped=false,cancelPromise=null,finished=false;
    const owned=()=>!!owner&&owner===options.getOwner();
    const active=()=>owned()&&!controller.signal.aborted&&!options.signal?.aborted&&options.active()!==false;
    function check(){if(!active())throw abort();}
    function emit(){
      if(!owned()||!snapshot)return;
      const steps=(snapshot.steps||[]).map(step=>{
        const previous=(snapshot.steps||[]).find(value=>value.role==='testing'&&value.candidate_id===step.candidate_id&&value.report?.verdict==='repair');
        return {...step,summary:step.report?.summary||step.summary||({coding:'Coding Agent',testing:'Testing Agent',repair:'Repair Agent'}[step.role]||'Agent step'),
          checks:[...(step.evidence?.checks||step.checks||[]),...(step.evidence?.manual||[]).map(value=>({...value,label:value.message||value.code,status:'manual'}))],
          findings:step.report?.findings||step.findings||(step.role==='repair'?previous?.report?.findings:[])||[],
          fixes:step.report?.fixes||step.fixes||[]};
      });
      options.onUpdate?.({...snapshot,phase:snapshot.state,project:snapshot.project||options.project,
        summary:snapshot.message||undefined,steps:[...steps,...(localStep?[localStep]:[])]});
    }
    function accept(result){
      const next=result?.workflow;
      if(!next||next.request_id!==requestId||!Array.isArray(next.steps))throw new Error('The project returned an invalid status.');
      check();snapshot=next;emit();return next;
    }
    async function pause(){
      check();await new Promise((resolve,reject)=>{
        const stop=()=>{clearTimeout(timer);reject(abort());};
        const timer=setTimeout(()=>{controller.signal.removeEventListener('abort',stop);resolve();},1000);
        controller.signal.addEventListener('abort',stop,{once:true});
        if(controller.signal.aborted)stop();
      });check();
    }
    async function status(){return accept(await options.get(controller.signal));}
    async function settle(){
      while(pending.has(snapshot?.state)){
        await pause();await status();
      }
      return snapshot;
    }
    async function action(name,payload={},stream=false){
      check();
      const monitor=new AbortController();
      const watch=(async()=>{
        while(!monitor.signal.aborted&&active()){
          await new Promise(resolve=>{
            const stop=()=>{clearTimeout(timer);resolve();};
            const timer=setTimeout(()=>{monitor.signal.removeEventListener('abort',stop);resolve();},1000);
            monitor.signal.addEventListener('abort',stop,{once:true});
            if(monitor.signal.aborted)stop();
          });
          if(monitor.signal.aborted||!active())break;
          try{const result=await options.get(monitor.signal);if(!monitor.signal.aborted&&active())accept(result);}
          catch(_){/* The primary request carries the result; monitoring never starts more paid work. */}
        }
      })();
      try{
        const result=await options.post({action:name,request_id:requestId,...payload},{signal:controller.signal,
          ...(stream?{onDelta:text=>{if(active())options.onCode?.(text);},
            onRetry:reason=>{if(active())options.onRetry?.(reason);}}:{})});
        accept(result);
      }catch(error){
        if(!active())throw abort();
        if(![409,502,503,504].includes(error.status)&&error.name!=='TypeError'&&error.status!==undefined)throw error;
        // Ambiguous results recover by status, never by another model request.
        await status();
      }finally{monitor.abort();await watch;}
      return settle();
    }
    async function cancel(){
      stopped=true;controller.abort();
      if(cancelPromise)return cancelPromise;
      if(!owned())return null;
      cancelPromise=(async()=>{
        const result=await options.post({action:'cancel',request_id:requestId},{});
        if(result?.workflow?.request_id===requestId&&owned()){
          snapshot=result.workflow;localStep=null;emit();
        }
        return result;
      })();
      try{return await cancelPromise;}finally{cancelPromise=null;}
    }
    const externallyStopped=()=>{if(!finished)void cancel().catch(()=>{});};
    options.signal?.addEventListener('abort',externallyStopped,{once:true});
    async function run(initial){
      try{
        check();
        if(!Number.isSafeInteger(initial?.scene?.id))throw new Error('A saved candidate is required for testing.');
        await action('start',{scene_id:initial.scene.id});
        let scene=initial.scene;
        while(true){
          check();await settle();
          if(terminal.has(snapshot.state)){
            if(snapshot.state==='ready'||snapshot.state==='applied'){
              if(scene.id!==snapshot.candidate_scene_id)scene=await options.getScene(snapshot.candidate_scene_id,controller.signal);
              check();options.onCandidate?.(scene);
              finished=true;
              return {...initial,scene,state:'ready',workflow:snapshot,active_tokens_used:snapshot.total_active_tokens_used,
                active_tokens_remaining:snapshot.active_tokens_remaining};
            }
            finished=true;
            return {state:snapshot.state,workflow:snapshot};
          }
          if(scene.id!==snapshot.candidate_scene_id){
            scene=await options.getScene(snapshot.candidate_scene_id,controller.signal);check();
          }
          if(snapshot.state==='testing'){
            options.onCandidate?.(scene);
            localStep={id:'browser-'+id(),role:'testing',state:'running',summary:'Observing the candidate in an isolated browser frame.',checks:[]};emit();
            const evidence=await options.test(scene,{signal:controller.signal,onEvent:event=>{
              if(!active()||!localStep)return;
              if(event.type==='check')localStep.checks.push({id:event.id,label:event.label,status:event.status,detail:event.detail});
              emit();
            }});
            check();
            if(evidence?.state==='canceled')throw abort();
            localStep=null;
            await action('review',{step_id:id(),candidate_scene_id:scene.id,evidence});
          }else if(snapshot.state==='needs_repair'){
            const prior=scene;
            localStep=null;
            await action('repair',{step_id:id(),review_id:snapshot.review_id},true);
            if(snapshot.state==='testing'){
              scene=await options.getScene(snapshot.candidate_scene_id,controller.signal);check();
              if(scene.html===prior.html&&scene.css===prior.css&&(scene.js||'')===(prior.js||'')&&
                JSON.stringify(scene.agent_palette??null)===JSON.stringify(prior.agent_palette??null)&&
                (scene.agent_design??null)===(prior.agent_design??null))
                throw new Error('The repair did not change the candidate. Your current canvas is still available.');
            }
          }else throw new Error('The project cannot continue from its current status.');
        }
      }catch(error){
        if(stopped||controller.signal.aborted||options.signal?.aborted)return {state:'canceled',workflow:snapshot};
        throw error;
      }finally{
        finished=true;options.signal?.removeEventListener('abort',externallyStopped);
      }
    }
    return Object.freeze({run,cancel,getSnapshot:()=>snapshot});
  }
  window.SFCanvasBuildWorkflow=Object.freeze({create});
})();
