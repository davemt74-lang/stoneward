(() => {
  'use strict';
  /** One Admin chat canvas + slide-out Brain; same server-owned mission ledger. */
  let conversationId=sessionStorage.getItem('stonefellow.admin.agent.thread')||'';
  let busy=false,lastBrain=null,previousFocus=null;
  const ctx=()=>window.STONEFELLOW_ADMIN_CONTEXT;
  const esc=s=>ctx()?.esc?.(String(s??''))||'';
  async function load(){
    const c=ctx();if(!c)throw new Error('Admin workspace not available.');
    try{return await c.api('agent-chat.php'+(conversationId?'?conversation_id='+encodeURIComponent(conversationId):''));}
    catch(e){if(conversationId){conversationId='';sessionStorage.removeItem('stonefellow.admin.agent.thread');return c.api('agent-chat.php');}throw e;}
  }
  function messageHtml(m){
    const who=m.role==='assistant'?'Stonefellow Admin Agent':'Admin';
    const feedback=m.role==='assistant'&&Number(m.id)>0?'<div class="admin-agent-feedback"><button type="button" class="secondary" data-admin-agent-feedback="helpful" data-message-id="'+Number(m.id)+'">Helpful</button><button type="button" class="secondary" data-admin-agent-feedback="not_helpful" data-message-id="'+Number(m.id)+'">Not helpful</button></div>':'';
    const action=m.action||{};
    let next='';
    if(m.role==='assistant'&&action.type==='prepare_campaign')next='<button type="button" data-admin-agent-action="campaigns" class="secondary">Prepare exact-review campaign draft</button>';
    else if(m.role==='assistant'&&action.type==='prepare_journey')next='<button type="button" data-admin-agent-action="journey" class="secondary">Prepare exact-review journey draft</button>';
    else if(m.role==='assistant'&&action.type==='review_actions')next='<button type="button" data-admin-agent-action="brain" class="secondary">Inspect action approvals</button>';
    else if(m.role==='assistant'&&action.type==='commerce_report')next='<button type="button" data-admin-agent-action="commerce-ops" class="secondary">Open Commerce Operations</button>';
    else if(m.role==='assistant'&&action.type==='draft_commerce_reply'){
      next=(action.reply_draft&&Number(m.id)>0?'<button type="button" class="secondary" data-agent-copy-reply="'+Number(m.id)+'">Copy unsent reply draft</button>':'')+
        '<button type="button" data-admin-agent-action="commerce-ops" class="secondary">Review actual order</button>';
    }
    else if(m.role==='assistant'&&action.type==='prepare_commerce_followup'){
      if(action.draft&&action.request_key){
        next='<button type="button" class="primary" data-agent-commerce-review="'+esc(action.request_key)+'">Review follow-up proposal</button>';
      }else next='<button type="button" class="secondary" data-admin-agent-action="commerce-ops">Open Commerce Operations</button>';
    }
    else if(m.role==='assistant'&&['crm_report','journey_report'].includes(action.type))next='<button type="button" data-admin-agent-action="'+esc(action.view)+'" class="secondary">Review '+esc(action.view)+'</button>';
    const brief=Array.isArray(action.insights)?action.insights.slice(0,3):[];
    const stages=action.mission_plan && Array.isArray(action.mission_plan.steps)?action.mission_plan.steps.slice(0,4):[];
    const safeStepAction={crm_report:'crm',journey_report:'automations',prepare_campaign:'campaigns',prepare_journey:'journey',commerce_report:'commerce-ops',prepare_commerce_followup:'commerce-ops'};
    const stageCards=stages.map(s=>{
      const route=safeStepAction[s.intent];
      return '<li>'+esc(s.intent)+' · '+esc(s.status)+(s.requires_exact_approval?' · exact approval required':'')+
        (route?'<button type="button" class="secondary" data-admin-agent-action="'+route+'" aria-label="Open '+esc(s.intent)+' tool">Open tool for review</button>':'')+'</li>';
    }).join('');
    const evidence=brief.map(i=>'<li><strong>'+esc(i.title)+'</strong> — '+esc(i.reason)+'</li>').join('');
    return '<article class="admin-agent-message '+(m.role==='assistant'?'agent':'human')+'"><strong>'+who+'</strong><p>'+esc(m.content||'')+'</p>'+(stageCards?'<div class="admin-agent-stages"><strong>Governed mission steps</strong><ul>'+stageCards+'</ul><p>Inspect the relevant editor before separately approving any changes.</p></div>':'')+(evidence?'<div class="admin-agent-insights"><strong>Evidence-backed opportunities</strong><ul>'+evidence+'</ul></div>':'')+next+feedback+'</article>';
  }
  function action(type){
    const c=ctx();if(!c)return;
    if(type==='brain'){showBrain();return;}
    if(type==='campaigns'){c.openView('campaigns');setTimeout(()=>window.SFAgentCampaigns?.open?.('newsletter'),0);return;}
    if(type==='journey'){c.openView('automations');setTimeout(()=>window.SFAutomationsAdmin?.openAutomation?.(0),0);return;}
    if(['crm','automations','analytics','commerce-ops'].includes(type))c.openView(type);
  }
  function bindActions(root){
    root.querySelectorAll('[data-admin-agent-action]').forEach(b=>b.onclick=()=>action(b.dataset.adminAgentAction));
    root.querySelectorAll('[data-agent-commerce-review]').forEach(b=>b.onclick=()=>{
      const entry=(window.SFAdminAgentCanvas?.commerceMessages||[]).find(m=>String(m.action?.request_key)===b.dataset.agentCommerceReview);
      if(entry?.action?.draft)window.SFAgentCommerceReview?.open?.(entry.action);
    });
    root.querySelectorAll('[data-agent-copy-reply]').forEach(button=>button.onclick=async()=>{
      const msg=(window.SFAdminAgentCanvas?.commerceMessages||[]).find(row=>Number(row.id)===Number(button.dataset.agentCopyReply));
      const draft=msg?.action?.reply_draft;
      if(!draft||draft.state!=='unsent_draft')return;
      try{await navigator.clipboard.writeText(String(draft.text||''));ctx()?.say?.('Draft copied for manual review. Nothing was sent.');}
      catch(e){ctx()?.say?.('Copy unavailable. Select the draft text in chat to copy it.');}
    });
    root.querySelectorAll('[data-admin-agent-feedback]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c)return;button.disabled=true;
      try{await c.api('agent-feedback.php',{message_id:Number(button.dataset.messageId),rating:button.dataset.adminAgentFeedback});c.say('Private response feedback saved.');}
      catch(e){c.say('Feedback could not be saved.');}
      finally{button.disabled=false;}
    });
  }
  async function render(){
    const c=ctx();if(!c)return;
    c.canvas.innerHTML=
      '<div class="admin-agent-chat" id="adminAgentChat">'+
      '<header class="admin-agent-chat-heading"><div><small>ADMIN AGENT · OPERATIONS</small><p>Private Admin Brain · Commerce, CRM, campaigns and operational research</p></div>'+
      '<div class="admin-agent-chat-controls"><button type="button" class="secondary" id="adminAgentOpenBrain">Agent Brain ↗</button><button type="button" class="secondary" id="adminAgentNewThread">New chat</button></div></header>'+
      '<section class="admin-agent-chat-thread" aria-label="Admin Agent conversation">'+
      '<div class="admin-agent-transcript" id="adminAgentTranscript" role="log" aria-live="polite" aria-relevant="additions text"><p>Loading your conversation…</p></div></section>'+
      '<p class="admin-agent-chat-status" id="adminAgentChatStatus" role="status"></p>'+
      '</div>';
    const root=c.canvas.querySelector('#adminAgentChat');
    root.querySelector('#adminAgentOpenBrain').onclick=showBrain;
    root.querySelector('#adminAgentNewThread').onclick=()=>{
      conversationId='';sessionStorage.removeItem('stonefellow.admin.agent.thread');
      render().then(()=>document.querySelector('#adminChatInput')?.focus()).catch(e=>c.say(e.message));
    };
    try{
      const get=await load();if(!root.isConnected)return;
      lastBrain=get.brain||null;
      const transcript=root.querySelector('#adminAgentTranscript'),items=get.messages||[];
      window.SFAdminAgentCanvas.commerceMessages=items;
      transcript.innerHTML=items.length?items.map(messageHtml).join(''):
        '<div class="admin-agent-welcome"><h2>What can I help with?</h2><p>Ask about orders, refunds, fulfillment, follow-ups, fans or campaigns.</p></div>';
      bindActions(transcript);
      transcript.scrollTop=transcript.scrollHeight;
    }catch(err){
      if(root.isConnected)root.querySelector('#adminAgentTranscript').innerHTML=
        '<p class="admin-agent-load-error">Conversation unavailable. Your saved messages have not been changed.</p>';
      throw err;
    }
  }
  async function send(text){
    if(busy)throw new Error('An Admin Agent request is already in progress.');
    const c=ctx();if(!c)throw new Error('Admin workspace unavailable.');
    const message=String(text||'').trim();if(!message)return;
    busy=true;
    try{
      const r=await c.api('agent-chat.php',{message,conversation_id:conversationId});
      conversationId=r.conversation_id||conversationId;
      if(conversationId)sessionStorage.setItem('stonefellow.admin.agent.thread',conversationId);
      c.openView('agent-chat');
      const status=c.canvas.querySelector('#adminAgentChatStatus');if(status)status.textContent='';
      return r;
    }finally{busy=false;}
  }
  function closeBrain(){
    document.querySelector('#adminAgentBrainBackdrop')?.remove();
    document.querySelector('#adminAgentBrainDrawer')?.remove();
    if(previousFocus&&previousFocus.isConnected)previousFocus.focus();
    previousFocus=null;
  }
  async function showBrain(){
    const c=ctx();if(!c)return;
    closeBrain();
    previousFocus=document.activeElement;
    const overlay=document.createElement('div');overlay.id='adminAgentBrainBackdrop';overlay.className='admin-brain-backdrop';
    const drawer=document.createElement('aside');drawer.id='adminAgentBrainDrawer';drawer.className='admin-brain-drawer';
    drawer.setAttribute('role','dialog');drawer.setAttribute('aria-modal','true');drawer.setAttribute('aria-label','Administrator Agent Brain');
    drawer.innerHTML='<div class="admin-brain-header"><div><small>STONEFELLOW</small><h2>Admin Agent Brain</h2></div><button type="button" id="closeAdminBrain" aria-label="Close Admin Brain">×</button></div><p>Operational decisions, tool authority, exact approvals and mission recovery. Customer private conversations are not exposed.</p><div id="adminBrainMissionContent" role="status">Loading mission state…</div>';
    document.body.append(overlay,drawer);
    const close=drawer.querySelector('#closeAdminBrain');close.onclick=closeBrain;overlay.onclick=closeBrain;
    drawer.addEventListener('keydown',e=>{
      if(e.key==='Escape'){e.preventDefault();closeBrain();return;}
      if(e.key==='Tab'){
        const focusable=Array.from(drawer.querySelectorAll('button,a[href],input,select,textarea')).filter(el=>!el.disabled);
        if(!focusable.length){e.preventDefault();return;}
        const first=focusable[0],last=focusable[focusable.length-1];
        if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
        else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
      }
    });
    close.focus();
    try{
      const result=await c.api('agent-chat.php'),brain=result.brain||lastBrain||{};
      lastBrain=brain;
      const commerce=brain.commerce||{},commerceCounts=commerce.summary||{};
      const commerceTriage=commerce.triage||{},triageRows=Array.isArray(commerceTriage.items)?commerceTriage.items.slice(0,6):[];
      const triageLines=triageRows.map(r=>'<li><strong>'+esc(r.severity)+' · '+esc(r.order_id)+'</strong> — '+esc(r.reason)+'</li>').join('');
      const missions=brain.missions||[],plans=brain.planned_missions||[],research=brain.research_opportunities||[],specialists=brain.specialist_assignments||[],counts=brain.mission_counts||{},tools=brain.tool_inventory||[],feedbackSummary=brain.feedback_summary||{},recovery=brain.recovery_summary||{};
      const list=missions.length?missions.map(m=>'<li><strong>'+esc(m.name)+'</strong><span> '+esc(m.status)+' · '+esc(m.tool_id)+'</span>'+ (m.review_required?'<p>Review the original work before any retry.</p>':'')+'</li>').join(''):'<li>No reviewed Agent actions yet.</li>';
      const specialistLines=specialists.slice(0,6).flatMap(p=>(p.assignments||[]).map(a=>'<li><strong>'+esc(a.specialist.replaceAll('_',' '))+'</strong> · '+esc(a.status)+(a.requires_exact_approval?' · exact approval required':'')+'</li>')).join('');
      const researchLines=research.slice(0,3).map(x=>'<li><strong>'+esc(x.title)+'</strong> — '+esc(x.why)+' <small>'+esc(x.source)+'</small></li>').join('');
      const planLines=plans.slice(0,10).map(p=>'<li>'+esc((p.steps||[]).map(s=>s.intent+': '+s.status).join(' → '))+'</li>').join('');
      const safeTools=tools.filter(t=>!['internal'].includes(t.kind)).map(t=>'<li>'+esc(t.label)+' · '+esc(t.kind)+'</li>').join('');
      drawer.querySelector('#adminBrainMissionContent').innerHTML='<div class="stats"><div class="stat"><strong>'+Number(missions.length)+'</strong><span>Recent actions</span></div><div class="stat"><strong>'+Number(counts.review_required||0)+'</strong><span>Needs review</span></div><div class="stat"><strong>'+Number(counts.completed||0)+'</strong><span>Completed</span></div></div><h3>Mission timeline</h3><ul class="admin-brain-list">'+list+'</ul><button type="button" class="secondary" id="adminBrainDetailedHistory">Review exact action history</button><h3>Evidence-backed research</h3><ul class="admin-brain-list">'+(researchLines||'<li>No new aggregate opportunities.</li>')+'</ul><h3>Feedback &amp; recovery</h3><p>'+Number(feedbackSummary.total||0)+' rated replies · '+Number(recovery.pending_approval||0)+' plan steps awaiting approval · '+Number(recovery.review_required||0)+' actions needing manual review. No automatic retries.</p><h3>Specialist assignments</h3><ul class="admin-brain-list">'+(specialistLines||'<li>No specialist assignments yet.</li>')+'</ul><h3>Planned missions</h3><ul class="admin-brain-list">'+(planLines||'<li>No staged plans yet.</li>')+'</ul><h3>Commerce Operations</h3><p>'+Number(commerce.total_attention||0)+' orders needing attention · '+Number(commerceCounts.payment_attention||0)+' payment checks · '+Number(commerceCounts.refund_attention||0)+' refunds · '+Number(commerceCounts.fulfillment_pending||0)+' fulfillment · '+Number(commerceCounts.open_followups||0)+' open follow-ups.</p><button type="button" class="secondary" id="brainCommerceOpen">Open Commerce Operations</button><h3>Priority commerce reviews</h3><p>Read-only suggestions; no automatic refund, email or shipping changes.</p><ul class="admin-brain-list">'+(commerceTriage.available?(triageLines||'<li>No currently flagged customer orders in the bounded snapshot.</li>'):'<li>Commerce data unavailable — do not assume the queue is empty.</li>')+'</ul><h3>Permitted tools</h3><ul class="admin-brain-list">'+safeTools+'</ul>';
      drawer.querySelector('#brainCommerceOpen').onclick=()=>{closeBrain();c.openView('commerce-ops');};
      drawer.querySelector('#adminBrainDetailedHistory').onclick=()=>{closeBrain();window.SFAgentCampaigns?.showHistory?.();};
    }catch(e){const p=drawer.querySelector('#adminBrainMissionContent');if(p)p.textContent='Operational Brain data is unavailable.';}
  }
  window.SFAdminAgentCanvas={render,send,showBrain};
})();
