/* Account-scoped canvas concepts, preferences and explicit project review. */
(() => {
  'use strict';
  const defaults=()=>({ideas_enabled:true,auto_approve:false});
  const requestId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');
  const copy=value=>JSON.parse(JSON.stringify(value));
  const element=(tag,text,className)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(className)node.className=className;return node;};
  function create(options){
    let owner='',epoch=0,preferences=defaults(),loaded=false,loading=null,waiter=null;
    const cards=new Map(),requests=new Set(),feedback=new Map(),feedbackPanels=new Map(),sceneCards=new Map();
    const field=document.getElementById('menuCanvasProjects');
    const ideas=document.getElementById('menuCanvasIdeas');
    const automatic=document.getElementById('menuCanvasAutoApprove');
    const status=document.getElementById('menuCanvasProjectsStatus');
    const suggestButton=document.getElementById('menuCanvasSuggest');
    const current=()=>!!owner&&owner===String(options.getOwner()||'');
    function syncMenu(busy=false){
      if(field)field.hidden=!owner;
      if(ideas){ideas.checked=preferences.ideas_enabled;ideas.disabled=!loaded||busy;}
      if(automatic){automatic.checked=preferences.auto_approve;automatic.disabled=!loaded||busy;}
      if(suggestButton)suggestButton.disabled=!loaded||!preferences.ideas_enabled||busy;
    }
    function abandon(){
      for(const request of requests)request.abort();requests.clear();
      if(waiter){waiter.reject(new DOMException('Project review ended.','AbortError'));waiter=null;}
      for(const record of cards.values())record.node.remove();cards.clear();
      for(const node of sceneCards.values())node.remove();sceneCards.clear();feedbackPanels.clear();feedback.clear();
    }
    async function call(payload,signal){
      const captured=epoch,request=new AbortController();requests.add(request);
      const abort=()=>request.abort();signal?.addEventListener('abort',abort,{once:true});
      if(signal?.aborted)request.abort();
      try{
        let result;
        do{
          result=await options.post(payload,request.signal);
          if(request.signal.aborted||captured!==epoch||!current())throw new DOMException('Project account changed.','AbortError');
          if(result.state==='processing')await new Promise((resolve,reject)=>{
            const stop=()=>{clearTimeout(timer);reject(new DOMException('Project review ended.','AbortError'));};
            const timer=setTimeout(()=>{request.signal.removeEventListener('abort',stop);resolve();},1000);
            request.signal.addEventListener('abort',stop,{once:true});
            if(request.signal.aborted)stop();
          });
        }while(result.state==='processing');
        if(result.preferences)preferences={ideas_enabled:result.preferences.ideas_enabled===true,auto_approve:result.preferences.auto_approve===true};
        syncMenu();return result;
      }finally{requests.delete(request);signal?.removeEventListener('abort',abort);}
    }
    async function refresh(){
      if(!current())return;
      if(loading)return loading;
      const captured=epoch,request=new AbortController();requests.add(request);
      const task=(async()=>{
        try{
          const result=await options.get(request.signal);
          if(captured!==epoch||request.signal.aborted||!current())return;
          preferences={ideas_enabled:result.preferences?.ideas_enabled===true,auto_approve:result.preferences?.auto_approve===true};
          loaded=true;syncMenu();
          if(Array.isArray(result.feedback)){
            feedback.clear();for(const entry of result.feedback){const key=feedbackKey(entry);if(key)feedback.set(key,entry);}
            for(const panel of feedbackPanels.values())panel.sync();
          }
          for(const proposal of result.proposals||[])if(['awaiting_approval','proposed','approved'].includes(proposal.status))present(proposal,false);
          if(status)status.textContent='';
        }finally{requests.delete(request);if(loading===task)loading=null;}
      })();
      loading=task;return task;
    }
    function setOwner(){
      const next=String(options.getOwner()||'');
      if(next===owner)return;
      abandon();epoch++;owner=next;preferences=defaults();loaded=false;loading=null;syncMenu();
      if(owner)void refresh().catch(()=>{if(status&&current())status.textContent='Project settings could not load. Try again before building.';});
    }
    function clearHistory(){
      abandon();epoch++;loaded=false;loading=null;syncMenu();
      if(owner)void refresh().catch(()=>{if(status&&current())status.textContent='Project settings could not load. Try again before building.';});
    }
    async function changePreference(key,value){
      if(!current()||!loaded)return;
      syncMenu(true);
      try{await call({action:'preferences',[key]:value});if(status)status.textContent='Saved.';}
      catch(error){syncMenu();if(status&&error.name!=='AbortError')status.textContent='Settings were not saved. Try again.';}
    }
    field?.addEventListener('click',event=>event.stopPropagation());
    ideas?.addEventListener('change',()=>{void changePreference('ideas_enabled',ideas.checked);});
    automatic?.addEventListener('change',()=>{void changePreference('auto_approve',automatic.checked);});
    suggestButton?.addEventListener('click',()=>options.onSuggest?.());
    function setState(record,message,busy=false){
      record.state.textContent=message;
      for(const button of record.actions.querySelectorAll('button'))button.disabled=busy;
    }
    function validProposal(proposal){
      return proposal&&Number.isSafeInteger(proposal.id)&&proposal.id>0&&proposal.project&&typeof proposal.project.brief==='string';
    }
    function feedbackKey(target){
      if(Number.isSafeInteger(target?.proposal_id)&&target.proposal_id>0)return 'proposal:'+target.proposal_id;
      if(Number.isSafeInteger(target?.scene_id)&&target.scene_id>0)return 'scene:'+target.scene_id;
      return '';
    }
    function feedbackPanel(target){
      const key=feedbackKey(target);
      if(feedbackPanels.has(key))return feedbackPanels.get(key).node;
      const node=element('section',undefined,'sf-project-feedback');node.setAttribute('aria-label','Canvas preference feedback');
      const label=element('small','Help Stonefellow learn your preferences');
      const buttons=element('div',undefined,'sf-project-feedback-actions');
      const like=element('button','Like'),dislike=element('button','Dislike');
      for(const button of [like,dislike]){button.type='button';buttons.append(button);}
      const details=element('details');details.append(element('summary','Add a reason (optional)'));
      const reasonLabel=element('label','What would you keep or change?'),reason=element('textarea');
      reason.rows=2;reason.maxLength=800;reasonLabel.append(reason);
      const save=element('button','Save reason'),clear=element('button','Clear feedback');save.type=clear.type='button';
      details.append(reasonLabel,save,clear);
      const message=element('small','Canceling a project does not count as dislike.');message.setAttribute('role','status');
      node.append(label,buttons,details,message);
      const panel={node,busy:false,epoch,sync(){
        const value=feedback.get(key);like.setAttribute('aria-pressed',String(value?.preference==='like'));
        dislike.setAttribute('aria-pressed',String(value?.preference==='dislike'));
        if(document.activeElement!==reason)reason.value=value?.reason||'';
      }};
      async function update(preference){
        if(panel.busy||panel.epoch!==epoch||!current())return;
        if(new TextEncoder().encode(reason.value.trim()).length>800){message.textContent='Please shorten your feedback reason.';return;}
        panel.busy=true;for(const button of [like,dislike,save,clear])button.disabled=true;
        try{
          const result=await call({action:'feedback',...target,preference,reason:reason.value.trim()});
          if(result.feedback)feedback.set(key,result.feedback);else feedback.delete(key);
          panel.sync();message.textContent=preference==='clear'?'Feedback cleared.':'Preference saved.';
        }catch(error){if(error.name!=='AbortError')message.textContent=error.message||'Feedback could not save.';}
        finally{panel.busy=false;for(const button of [like,dislike,save,clear])button.disabled=false;}
      }
      like.onclick=()=>void update('like');dislike.onclick=()=>void update('dislike');clear.onclick=()=>void update('clear');
      save.onclick=()=>{const value=feedback.get(key);if(value)void update(value.preference);else message.textContent='Choose Like or Dislike, then save your reason.';};
      feedbackPanels.set(key,panel);panel.sync();return node;
    }
    async function feedbackForScene(scene){
      setOwner();const captured=epoch;await refresh();
      if(captured!==epoch||!current()||!Number.isSafeInteger(scene?.id)||scene.id<1)return;
      let node=sceneCards.get(scene.id);
      if(!node){
        node=element('article',undefined,'sf-canvas-project sf-canvas-feedback-card');
        node.append(element('h4',String(scene.title||'Canvas feedback')),feedbackPanel({scene_id:scene.id}));sceneCards.set(scene.id,node);
      }
      options.getHost()?.append(node);options.onPresent?.('What would you keep or change about this canvas?');
    }
    function continueProject(record){
      if(!current()||record.epoch!==epoch)return;
      if(waiter?.id===record.proposal.id){
        const pending=waiter;waiter=null;pending.resolve(copy(record.proposal));
      }else options.onExecute?.(copy(record.proposal));
    }
    async function approve(record){
      if(record.busy||record.editor||!current()||record.epoch!==epoch)return;
      record.busy=true;setState(record,'Approving…',true);
      try{
        const result=await call({action:'approve',id:record.proposal.id});
        if(!validProposal(result.proposal)||result.proposal.status!=='approved')throw new Error('The project was not approved.');
        record.proposal=result.proposal;setState(record,'Approved. Building this project.',true);continueProject(record);
      }catch(error){record.busy=false;if(error.name!=='AbortError')setState(record,error.message||'Approval failed. Try again.');}
    }
    async function cancel(record){
      if(record.busy||!current()||record.epoch!==epoch)return;
      record.busy=true;setState(record,'Canceling…',true);
      try{
        await call({action:'cancel',id:record.proposal.id});record.proposal.status='canceled';setState(record,'Project canceled.',true);
        if(waiter?.id===record.proposal.id){const pending=waiter;waiter=null;pending.reject(new DOMException('Project canceled.','AbortError'));}
      }catch(error){record.busy=false;if(error.name!=='AbortError')setState(record,error.message||'Cancel failed. Try again.');}
    }
    function revise(record){
      if(record.busy||record.editor||!current()||record.epoch!==epoch)return;
      const editor=element('div',undefined,'sf-project-editor');
      const label=element('label','Revise the project brief');
      const textarea=element('textarea');textarea.value=record.proposal.project.brief;textarea.rows=4;label.append(textarea);
      const submit=element('button','Review revision');submit.type='button';
      const back=element('button','Keep original');back.type='button';
      editor.append(label,submit,back);record.node.append(editor);record.editor=editor;
      for(const button of record.actions.querySelectorAll('button'))button.disabled=true;
      back.onclick=()=>{editor.remove();record.editor=null;setState(record,'Waiting for your approval.');};
      submit.onclick=async()=>{
        if(record.busy||!textarea.value.trim()||!current()||record.epoch!==epoch)return;
        record.busy=true;submit.disabled=back.disabled=true;record.state.textContent='Reviewing your revision…';
        try{
          const project={...copy(record.proposal.project),brief:textarea.value.trim()};
          const result=await call({action:'propose',request_id:requestId(),project,replace_id:record.proposal.id});
          if(!validProposal(result.proposal))throw new Error('The revised project could not be prepared.');
          if(waiter?.id===record.proposal.id)waiter.id=result.proposal.id;
          record.proposal.status='canceled';editor.remove();record.editor=null;setState(record,'Replaced by your revised project.',true);
          const next=present(result.proposal,true);
          if(result.proposal.status==='approved'&&(result.proposal.approval_mode==='manual'||preferences.auto_approve)){
            next.busy=true;setState(next,'Auto-approved in your project settings.',true);continueProject(next);
          }
        }catch(error){record.busy=false;submit.disabled=back.disabled=false;if(error.name!=='AbortError')record.state.textContent=error.message||'Revision failed. Try again.';}
      };
      textarea.focus();
    }
    function present(proposal,open=true){
      if(!validProposal(proposal)||!current())throw new Error('The server returned an invalid project.');
      let record=cards.get(proposal.id);
      if(record)return record;
      const node=element('article',undefined,'sf-canvas-project');node.dataset.projectId=String(proposal.id);
      node.append(element('small','Stonefellow · Canvas project'),element('h4',String(proposal.title||'Canvas concept')),
        element('p',String(proposal.concept||proposal.project.brief)));
      if(proposal.source_scene_ids?.length)node.append(element('small','Uses '+proposal.source_scene_ids.length+' of your saved canvases as context.'));
      const brief=element('details');brief.append(element('summary','Project brief'),element('p',proposal.project.brief));node.append(brief);
      const state=element('p','Waiting for your approval.','sf-project-state');state.setAttribute('role','status');node.append(state);
      const actions=element('div',undefined,'sf-project-actions');
      const approveButton=element('button','Approve project'),reviseButton=element('button','Revise'),cancelButton=element('button','Cancel');
      for(const button of [approveButton,reviseButton,cancelButton]){button.type='button';actions.append(button);}
      node.append(actions);record={node,state,actions,proposal:copy(proposal),epoch,busy:false,editor:null};cards.set(proposal.id,record);
      approveButton.onclick=()=>{void approve(record);};reviseButton.onclick=()=>revise(record);cancelButton.onclick=()=>{void cancel(record);};
      node.append(feedbackPanel({proposal_id:proposal.id}));
      options.getHost()?.append(node);
      if(open)options.onPresent?.(proposal.concept||proposal.title||'Your canvas project is ready to review.');
      return record;
    }
    function attach(){
      if(!current())return;
      const host=options.getHost();if(!host)return;
      if(cards.size)host.querySelector('.agent-chat-empty')?.remove();
      for(const record of cards.values())if(record.epoch===epoch)host.append(record.node);
      for(const node of sceneCards.values())host.append(node);
    }
    function complete(id,state='built'){
      const record=cards.get(id);if(!record||!current())return;
      record.proposal.status=state;record.busy=true;
      setState(record,state==='built'?'Built. Saved in Canvas History.':state==='canceled'?'Build canceled.':state==='needs_attention'?
        'The candidate needs attention. Your current canvas is still open.':'The build did not finish. Your previous canvas is still available.',true);
    }
    async function prepare(project,{signal,reuseId=null}={}){
      setOwner();const captured=epoch;await refresh();
      if(captured!==epoch)throw new DOMException('Project account changed.','AbortError');
      if(!loaded||!current())throw new Error('Project settings could not be verified. Try again.');
      if(waiter)throw new Error('Review or cancel the current project first.');
      const result=await call({action:'propose',request_id:requestId(),project:copy(project),...(reuseId?{reuse_id:reuseId}:{})},signal);
      const proposal=result.proposal;
      if(!validProposal(proposal))throw new Error('The canvas project could not be prepared.');
      const record=present(proposal,true);
      if(proposal.status==='approved'&&(proposal.approval_mode==='manual'||preferences.auto_approve)){
        setState(record,preferences.auto_approve?'Auto-approved in your project settings.':'Project approved.',true);
        record.busy=true;return copy(proposal);
      }
      return new Promise((resolve,reject)=>{
        const abort=()=>{if(waiter?.id===proposal.id){waiter=null;reject(new DOMException('Project review ended.','AbortError'));}};
        signal?.addEventListener('abort',abort,{once:true});
        waiter={id:proposal.id,resolve:value=>{signal?.removeEventListener('abort',abort);resolve(value);},
          reject:error=>{signal?.removeEventListener('abort',abort);reject(error);}};
        if(signal?.aborted)abort();
      });
    }
    async function suggest({signal,currentCanvasId,brief='',codingModel}={}){
      setOwner();const captured=epoch;await refresh();
      if(captured!==epoch)throw new DOMException('Project account changed.','AbortError');
      if(!loaded||!current())throw new Error('Project settings could not be loaded.');
      if(!preferences.ideas_enabled)throw new Error('Turn on Canvas ideas in your menu to request a new idea.');
      const result=await call({action:'suggest',request_id:requestId(),...(currentCanvasId?{current_canvas_id:currentCanvasId}:{}),brief,
        ...(codingModel?{coding_model:codingModel}:{})},signal);
      const record=present(result.proposal,true);
      if(result.proposal.status==='approved'&&(result.proposal.approval_mode==='manual'||preferences.auto_approve)){
        record.busy=true;setState(record,'Auto-approved in your project settings.',true);continueProject(record);
      }
      return result.proposal;
    }
    async function discuss({signal,currentCanvasId,brief='',codingModel,sourceSceneIds,artistInputs,musicInputs,playlistInputs,imageReferenceIds}={}){
      setOwner();const captured=epoch;await refresh();
      if(captured!==epoch)throw new DOMException('Project account changed.','AbortError');
      if(!loaded||!current())throw new Error('Project settings could not be loaded.');
      const result=await call({action:'discuss',request_id:requestId(),brief,
        ...(currentCanvasId?{current_canvas_id:currentCanvasId}:{}),...(codingModel?{coding_model:codingModel}:{}),
        ...(sourceSceneIds?{source_scene_ids:sourceSceneIds}:{}),...(artistInputs?{artist_inputs:artistInputs}:{}),
        ...(musicInputs?{music_inputs:musicInputs}:{}),...(playlistInputs?{playlist_inputs:playlistInputs}:{}),...(imageReferenceIds?.length?{image_reference_ids:imageReferenceIds}:{})},signal);
      if(typeof result.reply==='string'&&result.reply.trim())options.onReply?.(result.reply.trim());
      if(result.proposal){
        const record=present(result.proposal,true);
        if(result.proposal.status==='approved'&&(result.proposal.approval_mode==='manual'||preferences.auto_approve)){
          record.busy=true;setState(record,'Auto-approved in your project settings.',true);continueProject(record);
        }
      }
      if(!result.proposal&&!result.reply)throw new Error('The agent did not return a canvas reply.');
      return result;
    }
    function handleCommand(text){
      if(!current())return false;
      const record=waiter?cards.get(waiter.id):Array.from(cards.values()).reverse().find(item=>!item.busy&&['awaiting_approval','proposed','approved'].includes(item.proposal.status));
      if(!record)return false;
      if(/^(?:\/approve|approve(?: (?:this|the))? project|build it)[.!?]?$/i.test(text)){
        if(record.editor){record.state.textContent='Review your revision or keep the original before approving.';return true;}
        void approve(record);return true;
      }
      if(/^(?:\/cancel-project|cancel(?: (?:this|the))? project)[.!?]?$/i.test(text)){void cancel(record);return true;}
      return false;
    }
    return Object.freeze({setOwner,refresh,prepare,suggest,discuss,feedbackForScene,clearHistory,handleCommand,attach,complete,destroy(){abandon();epoch++;owner='';},getPreferences:()=>({...preferences})});
  }
  window.SFCanvasProjects=Object.freeze({create});
})();
