(()=>{
'use strict';
/** Section 76B — Agent Chat invokes the existing governed action ledger. */
let live=null;
function open(action){
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT,draft=action?.draft;
  if(!ctx||!draft||!action?.request_key)return;
  close();
  const escape=ctx.esc;
  const backdrop=document.createElement('div'),dialog=document.createElement('section');
  backdrop.id='commerceReviewBackdrop';backdrop.className='admin-brain-backdrop';
  dialog.id='commerceReviewDialog';dialog.className='admin-brain-drawer';
  dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');
  dialog.setAttribute('aria-label','Exact customer follow-up review');
  dialog.innerHTML='<div class="admin-brain-header"><div><small>AGENT CHAT · EXACT APPROVAL</small><h2>Review follow-up note</h2></div>'+
    '<button type="button" id="commerceReviewClose" aria-label="Close">×</button></div>'+
    '<p>Review the exact Admin note. No purchase, refund, customer email or shipment action will execute.</p>'+
    '<section class="panel"><strong>Customer order</strong><p>'+escape(draft.order_id)+'</p>'+
    '<strong>Exact note</strong><p style="white-space:pre-wrap;overflow-wrap:anywhere">'+escape(draft.note)+'</p>'+
    '<p>Priority: '+escape(draft.priority||'normal')+' · Due: '+escape(draft.due_date||'Not set')+'</p></section>'+
    '<p role="status" id="commerceReviewStatus">Step 1: prepare an immutable proposal.</p>'+
    '<div class="actions" style="flex-wrap:wrap"><button class="secondary" id="commerceReviewPrepare" type="button">1 · Prepare</button>'+
    '<label class="field">Type APPROVE<input type="text" id="commerceReviewTyped" placeholder="APPROVE" autocomplete="off" disabled></label>'+
    '<button class="secondary" id="commerceReviewApprove" type="button" disabled>2 · Approve exact hash</button>'+
    '<button class="primary" id="commerceReviewExecute" type="button" disabled>3 · Save approved note</button></div>';
  document.body.append(backdrop,dialog);
  const status=dialog.querySelector('#commerceReviewStatus');
  const prepare=dialog.querySelector('#commerceReviewPrepare');
  const approve=dialog.querySelector('#commerceReviewApprove');
  const execute=dialog.querySelector('#commerceReviewExecute');
  const typed=dialog.querySelector('#commerceReviewTyped');
  let proposal=null;
  const escClose=e=>{if(e.key==='Escape')close();};
  live={backdrop,dialog,escClose};
  backdrop.onclick=close;dialog.querySelector('#commerceReviewClose').onclick=close;
  document.addEventListener('keydown',escClose);
  prepare.onclick=async()=>{
    prepare.disabled=true;
    try{
      const r=await ctx.api('agent-actions.php',{action:'prepare',tool_id:'commerce_followup_add',
        request_key:action.request_key,draft});
      proposal=r.proposal;
      if(proposal?.tool_id!=='commerce_followup_add'||!proposal.payload_hash)throw new Error('Unexpected approval contract.');
      if(proposal.status==='completed'){status.textContent='Already saved. No duplicate will be created.';return;}
      if(!['proposed','approved'].includes(proposal.status))throw new Error('Proposal needs manual review in action history.');
      if(JSON.stringify(proposal.payload)!==JSON.stringify(draft))
        throw new Error('Normalized payload changed. Inspect the prepared proposal before proceeding.');
      status.textContent='Exact SHA-256: '+proposal.payload_hash+'. Type APPROVE to confirm the content above.';
      typed.disabled=proposal.status!=='proposed';
      approve.disabled=proposal.status!=='proposed';
      execute.disabled=proposal.status!=='approved';
    }catch(e){status.textContent=String(e.message||e);prepare.disabled=false;}
  };
  approve.onclick=async()=>{
    if(typed.value!=='APPROVE'){status.textContent='Type APPROVE exactly.';return;}
    approve.disabled=true;
    try{
      const r=await ctx.api('agent-actions.php',{action:'approve',proposal_id:proposal.id,
        payload_hash:proposal.payload_hash,typed_confirmation:'APPROVE'});
      proposal=r.proposal;
      if(proposal.status!=='approved')throw new Error('Approval was not confirmed.');
      status.textContent='Approved. Click Save approved note separately to execute.';
      execute.disabled=false;
    }catch(e){status.textContent=String(e.message||e);}
  };
  execute.onclick=async()=>{
    execute.disabled=true;
    try{
      const r=await ctx.api('agent-actions.php',{action:'execute',proposal_id:proposal.id});
      if(r.proposal?.status!=='completed')throw new Error('Execution requires manual review.');
      status.textContent='Saved follow-up '+String(r.proposal.result?.followup_id||'')+'. It is available in Commerce Operations.';
      const go=document.createElement('button');go.type='button';go.className='secondary';
      go.textContent='Open Commerce Operations';go.onclick=()=>{close();ctx.openView('commerce-ops');};
      dialog.querySelector('.actions').append(go);
    }catch(e){status.textContent='Review required: '+String(e.message||e)+'. Do not retry automatically.';}
  };
  prepare.focus();
}
function close(){
  if(!live)return;
  document.removeEventListener('keydown',live.escClose);
  live.backdrop.remove();live.dialog.remove();live=null;
}
window.SFAgentCommerceReview={open,close};
})();
