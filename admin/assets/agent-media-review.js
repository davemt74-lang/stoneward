(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  const {api,esc}=ctx;
  let active=null;
  function open(action){
    const draft=action?.draft;
    if(action?.type!=='edit_media_caption'||!draft||!action.request_key)return;
    active?.close();active?.remove();
    const dialog=document.createElement('dialog');active=dialog;
    dialog.className='agent-catalog-review';
    dialog.setAttribute('aria-label','Review Media Library caption change');
    dialog.innerHTML='<div class="agent-catalog-review-head"><h2>Review Media Library caption</h2><button type="button" data-close>Close</button></div>'+
      '<p>This changes one shared asset caption only. An asset can appear in several places. Publicly attached assets may show this caption to visitors after saving.</p>'+
      '<dl><dt>Asset</dt><dd>#'+esc(draft.asset_id)+' — '+esc(draft.asset_title)+'</dd>'+
      '<dt>Attachments</dt><dd>'+esc(draft.attachment_count)+' total; '+esc(draft.public_link_count)+' public</dd>'+
      '<dt>Current caption</dt><dd>'+esc(draft.previous||'(empty)')+'</dd>'+
      '<dt>Proposed caption</dt><dd>'+esc(draft.value)+'</dd></dl>'+
      '<p data-status role="status">Step 1: Prepare a governed proposal.</p>'+
      '<button type="button" class="primary" data-prepare>Prepare proposal</button>'+
      '<section data-review hidden><h3>Step 2: Exact approval</h3><p>Review the server-normalized payload and SHA-256. Type APPROVE only if this shared-asset change is correct.</p>'+
      '<pre data-payload tabindex="0" aria-label="Exact media caption proposal"></pre><code data-hash></code>'+
      '<form data-approval><label>Approval phrase <input name="confirmation" autocomplete="off" required placeholder="APPROVE"></label>'+
      '<button type="submit" class="primary">Approve exact caption</button></form></section>'+
      '<section data-execute hidden><h3>Step 3: Save caption</h3><p>A changed asset or attachment relationship blocks the save. No media files or links are changed.</p>'+
      '<button type="button" class="primary" data-save>Save approved caption</button></section>'+
      '<button type="button" data-open-media hidden>Open Media Library</button>';
    document.body.append(dialog);
    const find=s=>dialog.querySelector(s);
    let proposal=null;
    const status=(message,error=false)=>{const el=find('[data-status]');el.textContent=message;el.dataset.error=error?'true':'false';};
    find('[data-close]').onclick=()=>dialog.close();
    dialog.addEventListener('close',()=>{dialog.remove();if(active===dialog)active=null;});
    find('[data-prepare]').onclick=async()=>{
      const button=find('[data-prepare]');button.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'prepare',tool_id:'media_caption_update',
          request_key:action.request_key,draft});
        proposal=result.proposal;
        if(!proposal||proposal.status!=='proposed')throw new Error('Fresh review is required.');
        find('[data-payload]').textContent=JSON.stringify(proposal.payload,null,2);
        find('[data-hash]').textContent=proposal.payload_hash;
        find('[data-review]').hidden=false;
        status('Inspect the exact caption and its public-link count. Nothing has changed.');
      }catch(error){status(error.message||'Preparation failed.',true);button.disabled=false;}
    };
    find('[data-approval]').onsubmit=async event=>{
      event.preventDefault();if(!proposal||proposal.status!=='proposed')return;
      const button=find('[data-approval] button');button.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'approve',proposal_id:proposal.id,
          payload_hash:proposal.payload_hash,typed_confirmation:find('[data-approval] input').value});
        proposal=result.proposal;
        if(!proposal||proposal.status!=='approved')throw new Error('Approval was not confirmed.');
        find('[data-execute]').hidden=false;
        status('Exact caption approved. Nothing has been saved yet.');
      }catch(error){status(error.message||'Approval failed.',true);button.disabled=false;}
    };
    find('[data-save]').onclick=async()=>{
      if(!proposal||proposal.status!=='approved')return;
      const button=find('[data-save]');button.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'execute',proposal_id:proposal.id});
        proposal=result.proposal;
        if(!proposal||proposal.status!=='completed')throw new Error('Manual review is required.');
        status('Media caption saved and verified. Media files and attachment visibility are unchanged.');
        find('[data-open-media]').hidden=false;
        ctx.say?.('Media caption saved and verified.');
      }catch(error){status((error.message||'Save could not be confirmed.')+' Inspect Agent actions and the Media Library before proposing another change. No automatic retry.',true);}
    };
    find('[data-open-media]').onclick=()=>{dialog.close();ctx.openView('media');};
    dialog.showModal();
  }
  window.SFAgentMediaReview={open};
})();
