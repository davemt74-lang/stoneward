(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  const {api,esc}=ctx;
  let active=null;
  function open(action){
    const d=action?.draft;
    if(action?.type!=='attach_private_media'||!d||!action.request_key||
       d.public_visible!==false||d.download_allowed!==false||d.featured!==false)return;
    active?.close();active?.remove();
    const dialog=document.createElement('dialog');active=dialog;
    dialog.className='agent-catalog-review';
    dialog.setAttribute('aria-label','Review private image attachment');
    dialog.innerHTML='<div class="agent-catalog-review-head"><h2>Review private image attachment</h2><button type="button" data-close>Close</button></div>'+
      '<p>This creates one new <strong>private</strong> image attachment. It will not publish, replace, or feature existing artwork. Public visitors cannot access this new link.</p>'+
      '<dl><dt>Image</dt><dd>#'+esc(d.asset_id)+' — '+esc(d.asset_title)+'</dd>'+
      '<dt>Destination</dt><dd>'+esc(d.entity_type)+' '+esc(d.entity_id)+' — '+esc(d.entity_title)+'</dd>'+
      '<dt>Role</dt><dd>'+esc(d.role)+' (private candidate)</dd>'+
      '<dt>Image used elsewhere</dt><dd>'+esc(d.asset_link_count)+' attachments, '+esc(d.asset_public_link_count)+' public</dd>'+
      '<dt>Existing destination links</dt><dd>'+esc(d.target_link_count)+'</dd>'+
      '<dt>Target already public?</dt><dd>'+(d.target_public?'Yes — the new attachment still stays private':'No')+'</dd></dl>'+
      '<p data-status role="status">Step 1: Prepare the exact private attachment.</p>'+
      '<button type="button" class="primary" data-prepare>Prepare proposal</button>'+
      '<section data-review hidden><h3>Step 2: Approve exact proposal</h3><p>Inspect the canonical payload and SHA-256. Type APPROVE to authorize only this private link.</p>'+
      '<pre data-payload tabindex="0" aria-label="Exact private attachment proposal"></pre><code data-hash></code>'+
      '<form data-approval><label>Approval phrase <input name="confirmation" autocomplete="off" required placeholder="APPROVE"></label>'+
      '<button type="submit" class="primary">Approve private attachment</button></form></section>'+
      '<section data-execute hidden><h3>Step 3: Add private attachment</h3><p>Changes to the image, song/release, or existing relationships block the save. No published artwork changes.</p>'+
      '<button type="button" class="primary" data-save>Save approved attachment</button></section>'+
      '<button type="button" data-open-media hidden>Open Media Library</button>';
    document.body.append(dialog);
    const find=s=>dialog.querySelector(s);
    const status=(message,error=false)=>{const el=find('[data-status]');el.textContent=message;el.dataset.error=error?'true':'false';};
    let proposal=null;
    find('[data-close]').onclick=()=>dialog.close();
    dialog.addEventListener('close',()=>{dialog.remove();if(active===dialog)active=null;});
    find('[data-prepare]').onclick=async()=>{
      const button=find('[data-prepare]');button.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'prepare',tool_id:'media_private_link_add',
          request_key:action.request_key,draft:d});
        proposal=result.proposal;
        if(!proposal||proposal.status!=='proposed')throw new Error('Fresh review required.');
        find('[data-payload]').textContent=JSON.stringify(proposal.payload,null,2);
        find('[data-hash]').textContent=proposal.payload_hash;
        find('[data-review]').hidden=false;
        status('Inspect the target, privacy flags, asset links and exact hash. Nothing has changed.');
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
        status('Exact private attachment approved; no database link exists yet.');
      }catch(error){status(error.message||'Approval failed.',true);button.disabled=false;}
    };
    find('[data-save]').onclick=async()=>{
      if(!proposal||proposal.status!=='approved')return;
      const button=find('[data-save]');button.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'execute',proposal_id:proposal.id});
        proposal=result.proposal;
        if(!proposal||proposal.status!=='completed'||proposal.result?.public_visible!==false)
          throw new Error('Manual review required.');
        status('Private image attachment saved and verified. No public visibility changed.');
        find('[data-open-media]').hidden=false;
        ctx.say?.('Private attachment saved and verified.');
      }catch(error){status((error.message||'Save could not be confirmed.')+' Inspect Agent actions and Media Library before any new attempt. No automatic retry.',true);}
    };
    find('[data-open-media]').onclick=()=>{dialog.close();ctx.openView('media');};
    dialog.showModal();
  }
  window.SFAgentMediaLinkReview={open};
})();
