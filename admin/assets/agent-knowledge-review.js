(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  const {api,esc}=ctx;
  let active=null;
  function open(agentAction){
    const draft=agentAction?.draft;
    if(agentAction?.type!=='edit_knowledge_note'||!draft||!agentAction.request_key)return;
    active?.close();active?.remove();
    const modal=document.createElement('dialog');active=modal;
    modal.className='agent-catalog-review';
    modal.setAttribute('aria-label','Review Knowledge Base description change');
    modal.innerHTML='<div class="agent-catalog-review-head"><h2>Review Knowledge Base note</h2><button type="button" data-close>Close</button></div>'+
      '<p>Only the private description for one saved document can change. File contents, Agent visibility, and public sharing stay unchanged.</p>'+
      '<dl><dt>Document</dt><dd>'+esc(draft.file_name)+' ('+esc(draft.file_id)+')</dd>'+
      '<dt>Current note</dt><dd>'+esc(draft.previous||'(empty)')+'</dd>'+
      '<dt>New note</dt><dd>'+esc(draft.value)+'</dd></dl>'+
      '<p data-status role="status">Step 1: Prepare a governed snapshot for review.</p>'+
      '<button type="button" class="primary" data-prepare>Prepare proposal</button>'+
      '<section data-review hidden><h3>Step 2: Exact approval</h3>'+
      '<p>Inspect the complete server-normalized payload and SHA-256 before approving. Type APPROVE only if correct.</p>'+
      '<pre data-payload tabindex="0" aria-label="Exact Knowledge Base proposal"></pre><code data-hash></code>'+
      '<form data-approval><label>Approval phrase <input name="confirmation" autocomplete="off" required placeholder="APPROVE"></label>'+
      '<button type="submit" class="primary">Approve exact note</button></form></section>'+
      '<section data-execute hidden><h3>Step 3: Save approved note</h3>'+
      '<p>A changed Knowledge Base index or document revision blocks this save. Nothing is saved automatically.</p>'+
      '<button type="button" class="primary" data-save>Save approved note</button></section>'+
      '<button type="button" data-open-knowledge hidden>Open Knowledge Base</button>';
    document.body.append(modal);
    const find=s=>modal.querySelector(s);
    let proposal=null;
    const status=(message,bad=false)=>{const el=find('[data-status]');el.textContent=message;el.dataset.error=bad?'true':'false';};
    find('[data-close]').onclick=()=>modal.close();
    modal.addEventListener('close',()=>{modal.remove();if(active===modal)active=null;});
    find('[data-prepare]').onclick=async()=>{
      const button=find('[data-prepare]');button.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'prepare',tool_id:'knowledge_note_update',
          request_key:agentAction.request_key,draft});
        proposal=result.proposal;
        if(!proposal||proposal.status!=='proposed')throw new Error('The proposal needs fresh review.');
        find('[data-payload]').textContent=JSON.stringify(proposal.payload,null,2);
        find('[data-hash]').textContent=proposal.payload_hash;
        find('[data-review]').hidden=false;
        status('Review exact note and hash. No record has been changed.');
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
        status('Exact note approved. Nothing has been saved yet.');
      }catch(error){status(error.message||'Approval failed.',true);button.disabled=false;}
    };
    find('[data-save]').onclick=async()=>{
      if(!proposal||proposal.status!=='approved')return;
      const button=find('[data-save]');button.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'execute',proposal_id:proposal.id});
        proposal=result.proposal;
        if(!proposal||proposal.status!=='completed')throw new Error('The save requires manual review.');
        status('Knowledge Base note saved and verified. Document content and sharing remain unchanged.');
        find('[data-open-knowledge]').hidden=false;
        ctx.say?.('Internal Knowledge Base description saved and verified.');
      }catch(error){status((error.message||'Save could not be confirmed.')+' Check Agent actions and the Knowledge Base before any new proposal; no automatic retry.',true);}
    };
    find('[data-open-knowledge]').onclick=()=>{modal.close();ctx.openView('knowledge');};
    modal.showModal();
  }
  window.SFAgentKnowledgeReview={open};
})();
