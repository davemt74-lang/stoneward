(() => {
  'use strict';
  // The modal is a human review surface, not an autonomous model executor.
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  const {api,esc}=ctx;
  let active=null;
  function open(agentAction){
    const draft=agentAction?.draft;
    if(agentAction?.type!=='edit_catalog_metadata'||!draft||!agentAction.request_key)return;
    active?.close();active?.remove();
    const modal=document.createElement('dialog');
    modal.className='agent-catalog-review';
    modal.setAttribute('aria-label','Review song metadata change');
    modal.innerHTML='<div class="agent-catalog-review-head"><h2>Review catalog change</h2><button type="button" data-close>Close</button></div>'+
      '<p>This changes one existing Stonefellow song, after your exact approval. It cannot upload files, publish music or change rights.</p>'+
      '<dl><dt>Track</dt><dd>'+esc(draft.track_title)+' ('+esc(draft.track_id)+')</dd>'+
      '<dt>Field</dt><dd>'+esc(draft.field)+'</dd><dt>Current value</dt><dd>'+esc(draft.previous||'(empty)')+'</dd>'+
      '<dt>Proposed value</dt><dd>'+esc(String(draft.value))+'</dd></dl>'+
      '<p data-status role="status">Step 1: Prepare an exact snapshot for your review.</p>'+
      '<button class="primary" type="button" data-prepare>Prepare proposal</button>'+
      '<section data-review hidden><h3>Step 2: Exact approval</h3><p>Confirm only after reviewing the server-normalized content and hash below.</p>'+
      '<pre data-payload tabindex="0" aria-label="Exact catalog edit payload"></pre><code data-hash></code>'+
      '<form data-approval><label>Type APPROVE<input name="typed_confirmation" autocomplete="off" required placeholder="APPROVE"></label>'+
      '<button class="primary" type="submit">Approve this change</button></form></section>'+
      '<section data-execute hidden><h3>Step 3: Save</h3><p>Stonefellow checks that the song has not changed since review. A stale revision blocks the edit.</p>'+
      '<button class="primary" type="button" data-save>Save approved metadata</button></section>'+
      '<button type="button" data-open-catalog hidden>Open Catalog</button>';
    document.body.append(modal);active=modal;
    let proposal=null;
    const find=selector=>modal.querySelector(selector);
    const status=(message,bad=false)=>{const el=find('[data-status]');el.textContent=message;el.dataset.error=bad?'true':'false';};
    find('[data-close]').onclick=()=>modal.close();
    modal.addEventListener('close',()=>{modal.remove();if(active===modal)active=null;});
    find('[data-prepare]').onclick=async()=>{
      const btn=find('[data-prepare]');btn.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'prepare',request_key:agentAction.request_key,
          tool_id:'catalog_metadata_update',draft});
        proposal=result.proposal;
        if(!proposal||proposal.status!=='proposed')throw new Error('This proposal needs a fresh status review.');
        find('[data-payload]').textContent=JSON.stringify(proposal.payload,null,2);
        find('[data-hash]').textContent=proposal.payload_hash;
        find('[data-review]').hidden=false;
        status('Review the exact song change before approval. No data has changed.');
      }catch(error){status(error.message||'Proposal could not be prepared.',true);btn.disabled=false;}
    };
    find('[data-approval]').onsubmit=async event=>{
      event.preventDefault();if(!proposal||proposal.status!=='proposed')return;
      const btn=find('[data-approval] button');btn.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'approve',proposal_id:proposal.id,
          payload_hash:proposal.payload_hash,typed_confirmation:find('[data-approval] input').value});
        proposal=result.proposal;
        if(!proposal||proposal.status!=='approved')throw new Error('Approval was not confirmed.');
        find('[data-execute]').hidden=false;
        status('Exact change approved. Nothing has been saved yet.');
      }catch(error){status(error.message||'Approval failed.',true);btn.disabled=false;}
    };
    find('[data-save]').onclick=async()=>{
      if(!proposal||proposal.status!=='approved')return;
      const btn=find('[data-save]');btn.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'execute',proposal_id:proposal.id});
        proposal=result.proposal;
        if(!proposal||proposal.status!=='completed')throw new Error('The result needs manual review.');
        status('Saved '+String(proposal.result?.field||'metadata')+' on '+String(draft.track_title)+'. Catalog and public index updated.');
        find('[data-open-catalog]').hidden=false;
        ctx.say?.('Catalog update saved and verified.');
      }catch(error){
        // An uncertain save is never retried automatically.
        status((error.message||'Save could not be confirmed.')+' Check Agent actions and the Catalog before starting another proposal.',true);
      }
    };
    find('[data-open-catalog]').onclick=async()=>{
      modal.close();
      try{await ctx.reloadCore?.();}catch(_error){/* Catalog editor will reload its own state. */}
      ctx.openView('catalog');
    };
    modal.showModal();
  }
  window.SFAgentCatalogReview={open};
})();
