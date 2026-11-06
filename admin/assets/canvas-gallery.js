/* Immutable canvas submissions: read, explicitly preview, then review. */
(() => {
  'use strict';
  const statuses = ['pending','approved','returned','declined','withdrawn'];
  const validId = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
  const validHash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
  const label = value => String(value || '').replaceAll('_',' ').replace(/\b\w/g,letter=>letter.toUpperCase());
  const node = (tag,text,className) => {const value=document.createElement(tag);if(text!==undefined)value.textContent=String(text);if(className)value.className=className;return value;};
  const button = (text,className='secondary') => {const value=node('button',text,className);value.type='button';return value;};
  let active = null, sequence = 0;

  async function productionPreview(detail,host,{signal}){
    if(typeof window.SFPublicAgentCanvas?.prepareGalleryPreview!=='function')throw Error('The production preview renderer is unavailable. Reload this page.');
    const prepared=await window.SFPublicAgentCanvas.prepareGalleryPreview(detail.scene);
    if(signal.aborted)return;
    const frame=prepared?.frame;
    if(!frame)throw Error('The submitted scene could not be rendered.');
    frame.title='Submitted canvas: '+String(detail.item.title||'Untitled submission');
    let loaded=false,disposed=false;
    const images=[];
    const post=()=>{if(loaded&&!disposed&&!signal.aborted&&frame.isConnected&&images.length)frame.contentWindow?.postMessage({stonefellowSceneImages:images},'*');};
    const onLoad=()=>{loaded=true;post();};
    const cleanup=()=>{if(disposed)return;disposed=true;frame.removeEventListener('load',onLoad);signal.removeEventListener('abort',cleanup);frame.remove();};
    frame.addEventListener('load',onLoad);signal.addEventListener('abort',cleanup,{once:true});
    host.append(node('p','Visual preview; personal data and playback are not tested.','sf-canvas-gallery-note'),frame);
    const manifest=new Map((Array.isArray(detail.scene.image_assets)?detail.scene.image_assets:[]).map(asset=>[asset.id,asset]));
    let unavailable=0;
    await Promise.all(Array.from(prepared.imageAssets||[]).map(async([id,title])=>{
      try{
        const asset=manifest.get(id);if(!asset||typeof asset.url!=='string')throw Error('Missing submitted asset');
        const url=new URL(asset.url,document.baseURI);
        if(url.origin!==location.origin||!['http:','https:'].includes(url.protocol)||url.username||url.password)throw Error('Invalid submitted asset');
        const response=await fetch(url.href,{credentials:'same-origin',mode:'same-origin',redirect:'error',cache:'no-store',signal});
        if(!response.ok||Number(response.headers.get('content-length'))>16*1024*1024)throw Error('Unavailable submitted asset');
        const blob=await response.blob();
        if(!['image/png','image/jpeg','image/webp'].includes(blob.type)||!blob.size||blob.size>16*1024*1024)throw Error('Invalid submitted image');
        if(disposed||signal.aborted)return;
        images.push({id,title,blob});post();
      }catch(error){if(!signal.aborted)unavailable++;}
    }));
    if(unavailable&&!signal.aborted&&!disposed)host.append(node('p',unavailable+' submitted image preview'+(unavailable===1?' is':'s are')+' unavailable.','sf-canvas-gallery-note'));
    return cleanup;
  }

  function renderInto(target='adminCanvas',options={}) {
    const host=typeof target==='string'?document.getElementById(target):target;
    const api=options.api||window.STONEFELLOW_ADMIN_CONTEXT?.api;
    const previewRenderer=options.onPreview||productionPreview;
    if(!host||typeof api!=='function')return null;
    active?.destroy();
    const prefix='sfCanvasGallery-'+(++sequence), root=node('section',undefined,'sf-admin-canvas-gallery');
    const heading=node('div',undefined,'canvas-head');
    const headingCopy=node('div');headingCopy.append(node('div','CANVAS CATALOG','eyebrow'),node('h1','Canvas submissions'),
      node('p','Review the exact submitted version before publishing it to the public canvas catalog. Opening a submission does not run its code.'));
    const refreshButton=button('Refresh');heading.append(headingCopy,refreshButton);
    const toolbar=node('form',undefined,'sf-canvas-gallery-toolbar');
    const filterLabel=node('label','Submission status','field'),filter=node('select');
    filter.setAttribute('aria-label','Submission status');
    for(const value of [...statuses,'all']){const option=node('option',value==='all'?'All statuses':label(value));option.value=value;filter.append(option);}
    filterLabel.append(filter);
    const searchLabel=node('label','Search submissions','field'),search=node('input');search.type='search';search.maxLength=200;
    searchLabel.append(search);const searchButton=button('Search');searchButton.type='submit';toolbar.append(filterLabel,searchLabel,searchButton);
    const layout=node('div',undefined,'sf-canvas-gallery-layout'), index=node('section',undefined,'sf-canvas-gallery-index');index.setAttribute('aria-label','Canvas submissions');
    const listStatus=node('p','Loading submissions…','sf-canvas-gallery-status');listStatus.setAttribute('role','status');
    const listRetry=button('Retry list');listRetry.hidden=true;
    const list=node('ul',undefined,'sf-canvas-gallery-list');
    const pager=node('div',undefined,'sf-canvas-gallery-pager'),previous=button('Previous page'),next=button('Next page'),pageLabel=node('span');
    pager.append(previous,pageLabel,next);index.append(listStatus,listRetry,list,pager);
    const detail=node('section',undefined,'sf-canvas-gallery-detail');detail.setAttribute('aria-label','Canvas submission detail');
    detail.append(node('p','Choose a submission to review its immutable source, assets and creator attribution.','sf-canvas-gallery-empty'));
    layout.append(index,detail);root.append(heading,toolbar,layout);host.replaceChildren(root);

    let destroyed=false,epoch=0,listEpoch=0,detailEpoch=0,page=1,selectedId='',record=null,mutationBusy=false,preview=null;
    const owner=options.getOwner?.();
    const current=()=>!destroyed&&root.isConnected&&(!options.getOwner||owner===options.getOwner())&&!options.signal?.aborted;
    function stopPreview(){
      const old=preview;preview=null;
      if(!old)return;
      old.request.abort();
      try{typeof old.cleanup==='function'?old.cleanup():old.cleanup?.destroy?.();}catch(_){/* Unmount must still remove the isolated frame. */}
      old.host.replaceChildren();old.section.hidden=true;
      if(old.button.isConnected){old.button.disabled=false;old.button.textContent='Preview submitted version';}
    }
    function clearDetail(){
      detailEpoch++;selectedId='';record=null;stopPreview();root.classList.remove('has-submission-detail');detail.replaceChildren(node('p','Choose a submission to review its immutable source, assets and creator attribution.','sf-canvas-gallery-empty'));
      for(const entry of list.querySelectorAll('button'))entry.setAttribute('aria-pressed','false');
    }
    function notice(element,text,error=false){element.textContent=text;element.setAttribute('role',error?'alert':'status');}
    function sourceBlock(title,source){const disclosure=node('details');disclosure.append(node('summary',title));const pre=node('pre');pre.tabIndex=0;pre.append(node('code',source||'(empty)'));disclosure.append(pre);return disclosure;}
    function backButton(){const back=button('Back to submissions');back.onclick=()=>{const id=selectedId;clearDetail();(list.querySelector('[data-submission-id="'+id+'"]')||filter).focus();};return back;}
    function metadata(host,label,value){host.append(node('dt',label),node('dd',value||'—'));}
    async function startPreview(detailRecord,section,previewHost,trigger,status){
      if(!current()||selectedId!==detailRecord.item.id||mutationBusy)return;
      if(typeof previewRenderer!=='function'){notice(status,'The production preview renderer is unavailable. Reload this page.',true);return;}
      stopPreview();
      const request=new AbortController(),instance={request,host:previewHost,section,button:trigger,cleanup:null};preview=instance;
      section.hidden=false;trigger.disabled=true;notice(status,'Loading the submitted version in the production sandbox…');
      try{
        const cleanup=await previewRenderer(detailRecord,previewHost,{signal:request.signal});
        if(!current()||preview!==instance||request.signal.aborted){try{typeof cleanup==='function'?cleanup():cleanup?.destroy?.();}catch(_){}return;}
        instance.cleanup=cleanup;trigger.textContent='Preview is open';notice(status,'Sandbox preview open. Review actions remain separate.');
      }catch(error){
        if(preview!==instance||!current()||request.signal.aborted)return;
        stopPreview();notice(status,error.message||'The preview could not open. Try Preview again.',true);
      }
    }
    function renderDetail(value,focus=false){
      stopPreview();record=value;const item=value.item,scene=value.scene;
      detail.replaceChildren(backButton());
      const title=node('h2',item.title||'Untitled submission');title.tabIndex=-1;
      const status=node('span',label(item.status),'badge');status.dataset.status=item.status;
      const description=node('p',item.description||'No description supplied.','sf-canvas-gallery-description');
      const meta=node('dl',undefined,'sf-canvas-gallery-metadata');
      metadata(meta,'Creator',item.creator_label);metadata(meta,'Submitted',item.submitted_at);metadata(meta,'Reviewed',item.reviewed_at);
      metadata(meta,'Published',item.published_at);metadata(meta,'Snapshot SHA-256',item.snapshot_hash);
      detail.append(title,status,description,meta,node('p','This submission is an immutable copy. Source and asset changes require a new submission.','sf-canvas-gallery-note'));
      const source=node('section',undefined,'sf-canvas-gallery-source');source.setAttribute('aria-label','Immutable submitted source');
      source.append(node('h3','Submitted source'),sourceBlock('HTML',scene.html),sourceBlock('CSS',scene.css),sourceBlock('JavaScript',scene.js));
      const manifest=sourceBlock('Asset manifest',JSON.stringify(Array.isArray(scene.image_assets)?scene.image_assets:[],null,2));source.append(manifest);detail.append(source);
      const previewActions=node('div',undefined,'actions'),previewButton=button('Preview submitted version'),previewStatus=node('p',undefined,'sf-canvas-gallery-status');previewStatus.setAttribute('role','status');
      const previewSection=node('section',undefined,'sf-canvas-gallery-preview');previewSection.hidden=true;previewSection.setAttribute('aria-label','Submitted canvas preview');
      const previewHead=node('div',undefined,'sf-canvas-gallery-preview-head'),closePreview=button('Close preview');previewHead.append(node('h3','Submitted canvas preview'),closePreview);
      const previewHost=node('div',undefined,'sf-canvas-gallery-preview-host');previewSection.append(previewHead,previewHost);
      previewActions.append(previewButton);detail.append(previewActions,previewStatus,previewSection);
      previewButton.onclick=()=>void startPreview(value,previewSection,previewHost,previewButton,previewStatus);
      closePreview.onclick=()=>{stopPreview();notice(previewStatus,'Preview closed.');previewButton.focus({preventScroll:true});};
      const review=node('section',undefined,'sf-canvas-gallery-review');review.setAttribute('aria-label','Submission review actions');
      review.append(node('h3','Review decision'));
      if(item.feedback)review.append(node('p','Saved feedback: '+item.feedback,'sf-canvas-gallery-saved-feedback'));
      const allowed=item.status==='pending'?['approve','return','decline']:item.status==='approved'?['unpublish']:[];
      if(allowed.length){
        const feedbackLabel=node('label','Feedback to creator','field'),feedback=node('textarea');feedback.rows=4;feedback.maxLength=2000;
        feedbackLabel.append(feedback);review.append(feedbackLabel,node('p','Feedback is required when returning a submission for changes. Maximum 2,000 UTF-8 bytes.','sf-canvas-gallery-note'));
        const actions=node('div',undefined,'actions'),reviewStatus=node('p',undefined,'sf-canvas-gallery-status');reviewStatus.setAttribute('role','status');
        const labels={approve:'Approve and publish',return:'Return for changes',decline:'Decline',unpublish:'Unpublish'};
        const buttons=[];
        for(const action of allowed){
          const actionButton=button(labels[action],action==='approve'?'primary':action==='decline'||action==='unpublish'?'danger':'secondary');buttons.push(actionButton);actions.append(actionButton);
          actionButton.onclick=async()=>{
            if(mutationBusy||!current()||record!==value||selectedId!==item.id)return;
            const reason=feedback.value.trim();
            if(action==='return'&&!reason){notice(reviewStatus,'Add feedback explaining the changes the creator should make.',true);feedback.focus();return;}
            if(new TextEncoder().encode(reason).length>2000){notice(reviewStatus,'Please shorten the feedback to 2,000 UTF-8 bytes.',true);feedback.focus();return;}
            if(!validHash(item.snapshot_hash)){notice(reviewStatus,'The submitted snapshot could not be verified. Refresh before reviewing.',true);return;}
            mutationBusy=true;for(const button of buttons)button.disabled=true;feedback.disabled=true;
            notice(reviewStatus,labels[action]+'…');const captured=epoch;
            try{
              const result=await api('canvas-gallery.php',{action,id:item.id,snapshot_hash:item.snapshot_hash,feedback:reason});
              if(!current()||captured!==epoch||record!==value)return;
              if(result?.ok===false||result?.item?.id!==item.id)throw Error('The server did not confirm this review. Refresh before trying again.');
              mutationBusy=false;renderDetail({...value,item:result.item});
              const message=node('p',labels[action]+': saved.','sf-canvas-gallery-decision-saved');message.setAttribute('role','status');detail.prepend(message);
              options.onChange?.(result.item);void loadList(true);
            }catch(error){
              if(current()&&captured===epoch&&record===value)notice(reviewStatus,error.message||'The review could not be confirmed. Refresh before trying again.',true);
            }finally{mutationBusy=false;if(feedback.isConnected){feedback.disabled=false;for(const button of buttons)button.disabled=false;}}
          };
        }
        review.append(actions,reviewStatus);
      }else review.append(node('p','This submission has no available review actions.','sf-canvas-gallery-note'));
      detail.append(review);
      if(focus)title.focus();
    }
    async function open(id,focus=true){
      if(!validId(id)||!current()||mutationBusy)return;
      const captured=++detailEpoch;selectedId=id;record=null;stopPreview();root.classList.add('has-submission-detail');detail.replaceChildren(backButton(),node('p','Loading submitted snapshot…','sf-canvas-gallery-status'));detail.setAttribute('aria-busy','true');
      for(const entry of list.querySelectorAll('button[data-submission-id]'))entry.setAttribute('aria-pressed',String(entry.dataset.submissionId===id));
      try{
        const result=await api('canvas-gallery.php?id='+encodeURIComponent(id));
        if(!current()||captured!==detailEpoch||selectedId!==id)return;
        if(result?.ok===false||result?.item?.id!==id||!result.scene||typeof result.scene.html!=='string'||typeof result.scene.css!=='string')throw Error('The submitted snapshot could not be read.');
        renderDetail(result,focus);
      }catch(error){
        if(!current()||captured!==detailEpoch)return;
        detail.replaceChildren(backButton());const message=node('p',error.message||'The submission could not load.','sf-canvas-gallery-status');message.setAttribute('role','alert');const retry=button('Retry submission');retry.onclick=()=>void open(id);detail.append(message,retry);
      }finally{if(captured===detailEpoch)detail.removeAttribute('aria-busy');}
    }
    async function loadList(preserveDetail=false){
      if(!current())return;
      const captured=++listEpoch;
      if(!preserveDetail)clearDetail();
      list.replaceChildren();listRetry.hidden=true;pager.hidden=true;index.setAttribute('aria-busy','true');notice(listStatus,'Loading submissions…');
      const params=new URLSearchParams({status:filter.value,page:String(page),limit:'24'});if(search.value.trim())params.set('q',search.value.trim());
      try{
        const result=await api('canvas-gallery.php?'+params.toString());
        if(!current()||captured!==listEpoch)return;
        if(result?.ok===false||!Array.isArray(result.items))throw Error('The submissions list could not be read.');
        let shown=0;
        for(const item of result.items){
          if(!validId(item?.id))continue;
          const row=node('li'),entry=button('', 'sf-canvas-gallery-item');entry.dataset.submissionId=item.id;entry.setAttribute('aria-pressed',String(item.id===selectedId));
          const title=node('strong',item.title||'Untitled submission');title.id=prefix+'-row-'+shown;entry.setAttribute('aria-labelledby',title.id);
          entry.append(node('span',label(item.status),'badge'),title,node('span',item.creator_label||'Creator attribution unavailable'),node('small',item.submitted_at||''));
          entry.onclick=()=>void open(item.id);row.append(entry);list.append(row);shown++;
        }
        notice(listStatus,shown?(Number.isSafeInteger(result.total)?result.total+' submissions':'Submissions loaded'):'No submissions match this filter.');
        previous.disabled=page<=1;next.disabled=result.has_more!==true;pageLabel.textContent='Page '+page;pager.hidden=!shown&&page===1;
      }catch(error){if(current()&&captured===listEpoch){notice(listStatus,error.message||'Submissions could not load.',true);listRetry.hidden=false;}}
      finally{if(captured===listEpoch)index.removeAttribute('aria-busy');}
    }
    function destroy(){if(destroyed)return;destroyed=true;epoch++;listEpoch++;detailEpoch++;stopPreview();options.signal?.removeEventListener('abort',destroy);root.remove();if(active===controller)active=null;}
    const controller={refresh:async()=>{const id=selectedId;await loadList(true);if(id&&current())await open(id,false);},open,destroy,ready:null};
    filter.onchange=()=>{page=1;void loadList();};toolbar.onsubmit=event=>{event.preventDefault();page=1;void loadList();};
    previous.onclick=()=>{if(page>1){page--;void loadList();}};next.onclick=()=>{if(!next.disabled){page++;void loadList();}};
    refreshButton.onclick=()=>void controller.refresh();listRetry.onclick=()=>void loadList(!!record);
    options.signal?.addEventListener('abort',destroy,{once:true});active=controller;
    if(options.signal?.aborted)destroy();
    controller.ready=loadList();return Object.freeze(controller);
  }
  window.SFCanvasGalleryAdmin=Object.freeze({renderInto,destroy:()=>active?.destroy()});
})();
