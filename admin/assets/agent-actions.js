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
    modal.innerHTML=`<div style="display:flex;justify-content:space-between;align-items:center;gap:14px"><h2 style="margin:0">Agent campaign draft</h2><button type="button" id="agentCampaignClose" aria-label="Close campaign workbench">Close</button></div>
      <p>Prepare a private draft using Stonefellow's existing Campaign Builder. No campaign will be published and no email will be sent.</p>
      <form id="agentCampaignPrepare">
        <label>Campaign type <select name="kind"><option value="newsletter">Newsletter signup</option><option value="download">Free song download</option><option value="discount">Merch discount</option><option value="vip">VIP offer</option></select></label>
        <label>Draft name <input name="name" required maxlength="180" value="${esc(name)}"></label>
        <label>Headline <input name="headline" maxlength="255"></label>
        <label>Draft copy <textarea name="body_text" maxlength="12000" rows="4"></textarea></label>
        <label>Published Stonefellow reference (optional) <select name="reference"><option value="">Write original draft copy</option></select></label>
        <p id="agentCampaignSourceNote">Only published artist material is eligible. Review suggestions before approval.</p>
        <label>Existing CRM segment (optional) <select name="segment_id"><option value="">All contacts — no segment filter</option></select></label>
        <p id="agentAudiencePreview" aria-live="polite">Audience figures will be checked using current newsletter consent.</p>
        <p id="agentCampaignStatus" role="status">Step 1: Prepare a reviewable proposal.</p>
        <button type="submit" class="primary" id="agentCampaignPrepareButton">Prepare for review</button>
      </form>
      <section id="agentCampaignReview" hidden>
        <h3>Step 2: Exact approval</h3>
        <p id="agentCampaignSummary"></p>
        <p>Read the complete server-normalized campaign below, including copy, audience rules and every Campaign Builder node. The hash applies to this saved snapshot.</p>
        <pre id="agentCampaignExactPayload" tabindex="0" aria-label="Exact proposed campaign payload" style="max-height:280px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid #665847;padding:12px"></pre>
        <p>Exact payload SHA-256:</p><code id="agentCampaignHash" style="overflow-wrap:anywhere"></code>
        <p>Confirm the exact saved content hash. Type APPROVE to approve only this draft.</p>
        <form id="agentCampaignApproval"><label>Approval phrase <input name="confirmation" required placeholder="Type APPROVE" autocomplete="off"></label><button type="submit" class="primary">Approve this exact draft</button></form>
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
    const referenceSelect=form.elements.reference;const references=new Map();
    api('agent-campaign-sources.php').then(data=>{
      if(!modal?.open)return;
      for(const source of data.sources||[]){
        const key=source.kind+':'+source.id;references.set(key,source);
        const option=document.createElement('option');option.value=key;option.textContent=source.label;referenceSelect.appendChild(option);
      }
    }).catch(()=>{$('#agentCampaignSourceNote')?.replaceChildren(document.createTextNode('Published references are unavailable; enter verified content manually.'));});
    referenceSelect.onchange=()=>{
      const source=references.get(referenceSelect.value);if(!source)return;
      form.elements.headline.value=source.headline||'';
      form.elements.body_text.value=source.body_text||'';
      $('#agentCampaignSourceNote').textContent='Draft text reflects published '+source.kind+' content. Verify details and add your own copy before saving.';
    };
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
        $('#agentCampaignExactPayload').textContent=JSON.stringify(proposal.payload,null,2);
        $('#agentCampaignHash').textContent=proposal.payload_hash;
        $('#agentCampaignReview').hidden=false;form.querySelectorAll('input,select,textarea,button').forEach(x=>x.disabled=true);
      }catch(e){setStatus('Proposal preparation failed: '+e.message,true);button.disabled=false;}
    };
    $('#agentCampaignApproval').onsubmit=async ev=>{
      ev.preventDefault();if(!proposal)return;
      const typed=ev.currentTarget.elements.confirmation.value;
      try{
        const res=await api('agent-actions.php',{action:'approve',proposal_id:proposal.id,payload_hash:proposal.payload_hash,typed_confirmation:typed});
        proposal=res.proposal;
        if(proposal.status!=='approved')throw new Error('Approval was not confirmed; refresh the proposal.');
        setStatus('Exact proposal approved. No campaign has been saved yet.');
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
    form.elements.name.focus();
  }
  async function showHistory(){
    const data=await api('agent-actions.php');
    const history=document.createElement('dialog');
    history.setAttribute('aria-label','Reviewed Admin Agent actions and receipts');
    history.className='agent-history-dialog';
    const rows=(data.proposals||[]).map(p=>{
      const name=esc(p.payload?.name||(p.tool_id==='media_private_link_add'?'Private artwork · #'+(p.payload?.asset_id||'asset')+' → '+(p.payload?.entity_type||'record')+' '+(p.payload?.entity_id||''):(p.tool_id==='media_alt_text_update'?'Image alt text · #'+(p.payload?.asset_id||'asset'):(p.tool_id==='media_caption_update'?'Media caption · #'+(p.payload?.asset_id||'asset'):(p.tool_id==='knowledge_note_update'?'Knowledge note · '+(p.payload?.file_name||'document'):(p.tool_id==='stem_automation_update'?'Stem automation · '+(p.payload?.track_id||'song'):'Reviewed draft'))))));
      const status=esc(p.status||'unknown');
      const when=esc(p.created_at||'');
      const completed=Number(p.result?.campaign_id||0),completedJourney=Number(p.result?.automation_id||0),completedStem=String(p.result?.track_id||''),completedKnowledge=String(p.result?.file_id||''),completedMedia=Number(p.result?.asset_id||0);
      let action='Awaiting review';
      if(completed)action='<button type="button" data-agent-campaign-id="'+completed+'">Open campaign</button>';
      else if(completedJourney)action='<button type="button" data-agent-journey-id="'+completedJourney+'">Open journey</button>';
      else if(completedStem)action='<button type="button" data-agent-stem-song="'+esc(completedStem)+'">Open Stem Studio</button>';
      else if(completedKnowledge)action='<button type="button" data-agent-open-knowledge>Open Knowledge Base</button>';
      else if(completedMedia)action='<button type="button" data-agent-open-media>Open Media Library</button>';
      else if(p.status==='proposed'||p.status==='approved')action='<button type="button" data-agent-cancel-id="'+esc(p.id)+'">Cancel pending</button>';
      else if(p.status==='review_required'||p.status==='executing')action='<button type="button" data-agent-reconcile-id="'+esc(p.id)+'">Mark reviewed (no retry)</button>';
      else if(p.status==='reviewed_no_retry')action='Manually reviewed; no automatic retry.';
      else if(p.status==='cancelled')action='Cancelled before execution.';
      return '<tr><td>'+name+'</td><td>'+status+'</td><td>'+when+'</td><td>'+action+'</td></tr>';
    }).join('');
    history.innerHTML='<h2>Agent action timeline</h2><p>These are your reviewed Admin Agent campaign, journey and private Stem Studio proposals. Pending, interrupted, or uncertain operations never run automatically. Review the relevant Admin editor manually; do not retry automatically.</p><div style="overflow-x:auto"><table class="data-table"><thead><tr><th>Draft</th><th>Status</th><th>Created</th><th>Next step</th></tr></thead><tbody>'+(rows||'<tr><td colspan="4">No Agent draft actions yet.</td></tr>')+'</tbody></table></div><button type="button" id="closeAgentHistory">Close</button>';
    document.body.appendChild(history);
    history.querySelector('#closeAgentHistory').onclick=()=>history.close();
    history.addEventListener('close',()=>history.remove());
    history.querySelectorAll('[data-agent-campaign-id]').forEach(button=>button.onclick=()=>{
      const id=Number(button.dataset.agentCampaignId);history.close();openView('campaigns');window.SFCampaignAdmin?.openEditor?.(id);
    });
    history.querySelectorAll('[data-agent-journey-id]').forEach(button=>button.onclick=()=>{
      const id=Number(button.dataset.agentJourneyId);
      history.close();openView('automations');
      window.SFAutomationsAdmin?.openAutomation?.(id);
    });
    history.querySelectorAll('[data-agent-stem-song]').forEach(button=>button.onclick=()=>{
      const id=button.dataset.agentStemSong;history.close();window.SFStemStudio?.open?.(id);
    });
    history.querySelectorAll('[data-agent-open-knowledge]').forEach(button=>button.onclick=()=>{
      history.close();openView('knowledge');
    });
    history.querySelectorAll('[data-agent-open-media]').forEach(button=>button.onclick=()=>{
      history.close();openView('media');
    });
    async function recover(button,action,keyword){
      const id=action==='cancel'?button.dataset.agentCancelId:button.dataset.agentReconcileId;
      const explanation=action==='reconcile'
        ?'Inspect the correct destination Admin editor (including Stem Studio for automation edits) for possible partial saves first. This does NOT undo anything. Type RECONCILED to mark this action reviewed without retrying.'
        :'Only pending, never-started work can be cancelled. Type CANCEL to confirm.';
      const typed=window.prompt(explanation,'');
      if(typed!==keyword)return;
      button.disabled=true;
      try{await api('agent-actions.php',{action,proposal_id:id,typed_confirmation:typed});history.close();await showHistory();}
      catch(e){button.disabled=false;say('Review action failed: '+e.message);}
    }
    history.querySelectorAll('[data-agent-cancel-id]').forEach(b=>b.onclick=()=>recover(b,'cancel','CANCEL'));
    history.querySelectorAll('[data-agent-reconcile-id]').forEach(b=>b.onclick=()=>recover(b,'reconcile','RECONCILED'));
    if(history.showModal)history.showModal();else history.setAttribute('open','');
    history.querySelector('#closeAgentHistory').focus();
  }
  window.SFAgentCampaigns={open,showHistory};
})();
