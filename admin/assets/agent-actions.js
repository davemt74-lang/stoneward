(() => {
  'use strict';
  // Section 44C: explicit three-stage Admin Agent campaign workbench.
  // Review and approval never mutate campaigns; only a separate execution saves a draft.
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  const {api,esc,say,openView}=ctx;
  let modal=null,proposal=null,requestKey='';
  const $=(selector,root=modal)=>root?.querySelector(selector);
  function newRequestKey(){
    const bytes=new Uint8Array(20);window.crypto.getRandomValues(bytes);
    return 'stonefellow-'+Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
  }
  function setStatus(text,bad=false){
    const el=$('#agentCampaignStatus');if(!el)return;
    el.textContent=String(text);el.style.color=bad?'#eab79c':'inherit';
  }
  function open(kind='newsletter',name=''){
    if(modal)modal.remove();
    proposal=null;requestKey=newRequestKey();
    modal=document.createElement('dialog');modal.className='agent-campaign-dialog';
    modal.setAttribute('aria-label','Agent campaign draft review');
    modal.style.cssText='border:1px solid #554638;border-radius:12px;background:#14110e;color:#f1ece5;padding:24px;width:min(640px,calc(100% - 24px));max-height:90vh;overflow:auto';
    modal.innerHTML=`<div style="display:flex;justify-content:space-between;align-items:center;gap:14px"><h2 style="margin:0">Agent campaign draft</h2><button type="button" id="agentCampaignClose" aria-label="Close campaign workbench">Close</button></div>
      <p>Prepare a private draft using Stonefellow's existing Campaign Builder. No campaign will be published and no email will be sent.</p>
      <form id="agentCampaignPrepare">
        <label>Campaign type <select name="kind"><option value="newsletter">Newsletter signup</option><option value="download">Free song download</option><option value="discount">Merch discount</option><option value="vip">VIP offer</option></select></label>
        <label>Draft name <input name="name" required maxlength="180" value="${esc(name)}"></label>
        <label>Headline <input name="headline" maxlength="255"></label>
        <label>Draft copy <textarea name="body_text" maxlength="12000" rows="4"></textarea></label>
        <label>Existing CRM segment (optional) <select name="segment_id"><option value="">All contacts — no segment filter</option></select></label>
        <p id="agentAudiencePreview" aria-live="polite">Audience figures will be checked using current newsletter consent.</p>
        <p id="agentCampaignStatus" role="status">Step 1: Prepare a reviewable proposal.</p>
        <button type="submit" class="primary" id="agentCampaignPrepareButton">Prepare for review</button>
      </form>
      <section id="agentCampaignReview" hidden>
        <h3>Step 2: Exact approval</h3>
        <p id="agentCampaignSummary"></p>
        <code id="agentCampaignHash" style="overflow-wrap:anywhere"></code>
        <p>Confirm the exact saved content hash. Type APPROVE to approve only this draft.</p>
        <form id="agentCampaignApproval"><input name="confirmation" required placeholder="Type APPROVE" autocomplete="off"><button type="submit" class="primary">Approve this exact draft</button></form>
      </section>
      <section id="agentCampaignExecution" hidden>
        <h3>Step 3: Save approved draft</h3>
        <p>This creates one unpublished Campaign Builder record. Publishing or sending remains a separate Admin decision.</p>
        <button type="button" class="primary" id="agentCampaignExecute">Save approved draft</button>
      </section>`;
    document.body.appendChild(modal);
    const form=$('#agentCampaignPrepare'),select=form.elements.kind;
    select.value=['newsletter','download','discount','vip'].includes(kind)?kind:'newsletter';
    const switchDefaults=()=>{
      const d=window.SFCampaignAdmin?.templateDraft?.(select.value);
      if(d&&!form.elements.name.value)form.elements.name.value=d.name;
      if(d&&!form.elements.headline.value)form.elements.headline.value=d.headline||'';
      if(d&&!form.elements.body_text.value)form.elements.body_text.value=d.body_text||'';
    };
    select.onchange=()=>{form.elements.name.value='';form.elements.headline.value='';form.elements.body_text.value='';switchDefaults();};
    switchDefaults();
    const audienceSelect=form.elements.segment_id;
    async function refreshAudience(){
      try{
        const id=Number(audienceSelect.value||0);
        const data=await api('agent-audience.php'+(id?'?segment_id='+encodeURIComponent(id):''));
        const el=$('#agentAudiencePreview');
        if(!id){
          el.textContent=(data.totals?.email_eligible||0)+' opted-in contacts across '+(data.totals?.contacts||0)+' CRM records. Draft does not send.';
          audienceSelect.querySelectorAll('option:not(:first-child)').forEach(o=>o.remove());
          for(const segment of data.segments||[]){const opt=document.createElement('option');opt.value=String(segment.id);opt.textContent=segment.name;audienceSelect.append(opt);}
        }else el.textContent=(data.email_eligible||0)+' opted-in, '+(data.suppressed||0)+' suppressed in this '+(data.capped?'capped ':'')+'segment preview. Eligibility is rechecked at send time.';
      }catch(e){$('#agentAudiencePreview').textContent='Audience preview unavailable; this does not authorize sending.';}
    }
    audienceSelect.onchange=refreshAudience;
    refreshAudience();
    $('#agentCampaignClose').onclick=()=>modal.close();
    modal.addEventListener('close',()=>{modal.remove();modal=null;});
    form.onsubmit=async ev=>{
      ev.preventDefault();const d=window.SFCampaignAdmin?.templateDraft?.(select.value);
      if(!d){setStatus('Campaign Builder templates are unavailable.',true);return;}
      d.name=form.elements.name.value.trim();d.headline=form.elements.headline.value.trim();
      d.body_text=form.elements.body_text.value.trim();d.status='draft';d.id=0;
      if(Number(audienceSelect.value||0)>0)d.audience={segment_id:Number(audienceSelect.value),newsletter_only:true};
      const button=$('#agentCampaignPrepareButton');button.disabled=true;
      try{
        const res=await api('agent-actions.php',{action:'prepare',request_key:requestKey,tool_id:'campaign_draft_save',draft:d});
        proposal=res.proposal;
        setStatus('Proposal saved for exact review; no campaign has been changed.');
        $('#agentCampaignSummary').textContent=proposal.payload.name+' · '+proposal.payload.goal+' · '+proposal.payload.graph.nodes.length+' builder nodes · draft only';
        $('#agentCampaignHash').textContent=proposal.payload_hash;
        $('#agentCampaignReview').hidden=false;form.querySelectorAll('input,select,textarea,button').forEach(x=>x.disabled=true);
      }catch(e){setStatus('Proposal preparation failed: '+e.message,true);button.disabled=false;}
    };
    $('#agentCampaignApproval').onsubmit=async ev=>{
      ev.preventDefault();if(!proposal)return;
      const typed=ev.currentTarget.elements.confirmation.value;
      try{
        const res=await api('agent-actions.php',{action:'approve',proposal_id:proposal.id,payload_hash:proposal.payload_hash,typed_confirmation:typed});
        proposal=res.proposal;setStatus('Exact proposal approved. No campaign has been saved yet.');
        $('#agentCampaignExecution').hidden=false;$('#agentCampaignApproval').querySelectorAll('input,button').forEach(x=>x.disabled=true);
      }catch(e){setStatus('Approval was not accepted: '+e.message,true);}
    };
    $('#agentCampaignExecute').onclick=async()=>{
      if(!proposal||proposal.status!=='approved')return;
      $('#agentCampaignExecute').disabled=true;
      try{
        const res=await api('agent-actions.php',{action:'execute',proposal_id:proposal.id});
        proposal=res.proposal;
        if(proposal.status!=='completed')throw new Error('Action needs review.');
        setStatus('Campaign draft saved. No publishing or email sending occurred.');
        const id=Number(proposal.result?.campaign_id||0);
        say('Agent draft saved in Campaign Builder for your review.');
        modal.close();openView('campaigns');
        if(id)window.SFCampaignAdmin?.openEditor?.(id);
      }catch(e){setStatus('Execution needs review. Check Campaign Builder and this proposal before attempting anything again.',true);}
    };
    if(modal.showModal)modal.showModal();else modal.setAttribute('open','');
  }
  window.SFAgentCampaigns={open};
})();
