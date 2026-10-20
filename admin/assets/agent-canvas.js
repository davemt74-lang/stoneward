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

  function localDateInput(utc){
    if(!utc)return '';
    const value=new Date(utc);if(!Number.isFinite(value.getTime()))return '';
    const local=new Date(value.getTime()-value.getTimezoneOffset()*60000);
    return local.toISOString().slice(0,16);
  }
  function calendarHtml(calendar){
    if(!calendar||!Array.isArray(calendar.tasks))return '';
    const requests=Array.isArray(calendar.pending_requests)?calendar.pending_requests:[];
    const counts=calendar.counts||{};
    return '<section class="admin-agent-calendar" aria-label="Private production calendar and human approval queue">'+
      '<div class="admin-agent-calendar-head"><strong>Production calendar</strong><small>Snapshot · '+esc(calendar.as_of||'')+
      ' · UTC source deadlines, displayed in your local time</small></div>'+
      '<p class="hint">'+Number(counts.overdue||0)+' overdue · '+Number(counts.due_soon||0)+' due soon · '+
      Number(counts.availability_conflict||0)+' crew conflicts · '+requests.length+' extension reviews</p>'+
      '<div class="admin-agent-calendar-list">'+calendar.tasks.map(t=>{
        const due=localDateInput(t.due_at);
        return '<div class="admin-agent-calendar-task">'+
          '<div><strong>#'+Number(t.task_id)+' · '+esc(t.title)+'</strong><p>'+esc(t.status)+' · '+
          esc(t.target_role)+' · assigned #'+Number(t.assigned_to||0)+
          (t.due_at?' · due '+esc(new Date(t.due_at).toLocaleString()):' · unscheduled')+
          (t.flags?.length?' · '+esc(t.flags.join(', ')):'')+'</p></div>'+
          (!['approved','rejected'].includes(t.status)?
            '<details><summary>Set deadline in chat</summary>'+
            '<label>Due (local)<input type="datetime-local" data-agent-task-due="'+Number(t.task_id)+'" value="'+esc(due)+'"></label>'+
            '<label>Priority<select data-agent-task-priority="'+Number(t.task_id)+'">'+
            ['normal','high','critical'].map(p=>'<option value="'+p+'" '+(t.priority===p?'selected':'')+'>'+p+'</option>').join('')+
            '</select></label>'+
            '<label>Reason<textarea data-agent-task-reason="'+Number(t.task_id)+'" maxlength="750" rows="2" placeholder="Why change this due date? 20+ characters"></textarea></label>'+
            '<button type="button" class="secondary" data-agent-task-schedule="'+Number(t.task_id)+'">Confirm due date</button></details>':'')+
          '</div>';
      }).join('')+'</div>'+
      '<h4>Deadline extension requests · human review</h4>'+
      (requests.length?requests.map(q=>
        '<div class="admin-agent-deadline-request" data-agent-request="'+Number(q.id)+'">'+
        '<strong>Request #'+Number(q.id)+' · '+esc(q.title)+'</strong>'+
        '<p>Contributor #'+Number(q.requested_by)+' · task #'+Number(q.task_id)+
        ' · current '+esc(new Date(q.expected_due_at).toLocaleString())+
        ' → requested '+esc(new Date(q.proposed_due_at).toLocaleString())+'</p>'+
        '<p>Reason: '+esc(q.reason)+'</p>'+
        '<label>Independent review basis<textarea rows="2" maxlength="900" data-agent-deadline-basis="'+Number(q.id)+'" placeholder="Your decision and evidence, at least 25 characters"></textarea></label>'+
        '<div class="actions"><button type="button" data-agent-deadline-review="approved" data-request-id="'+Number(q.id)+'">Approve date</button>'+
        '<button type="button" class="secondary" data-agent-deadline-review="rejected" data-request-id="'+Number(q.id)+'">Reject request</button></div></div>'
      ).join(''):'<p class="hint">No deadline extensions awaiting approval.</p>')+
      '<p class="hint">Every date change requires a separate authenticated click and is written to the existing audit history. No automatic crew reassignment or publication.</p>'+
      '</section>';
  }
  function footageHtml(queue){
    if(!Array.isArray(queue))return '';
    return '<section class="admin-agent-calendar" aria-label="Private footage review">'+
      '<strong>Footage inspection · recent submissions</strong>'+
      '<p class="hint">Private, editor-only source preview. Editorial approval and publication rights remain independent.</p>'+
      (queue.length?queue.map(item=>'<div class="admin-agent-calendar-task">'+
        '<div><strong>#'+Number(item.id)+' · '+esc(item.title)+'</strong>'+
        '<p>'+esc(item.submission_type)+' · '+esc(item.review_status)+
        ' · contributor #'+Number(item.contributor_id)+
        ' · external AI: '+esc(item.external_ai_clearance)+
        ' · publication: '+esc(item.publication_clearance)+'</p>'+
        '<p>'+esc(item.description||'')+'</p>'+
        '<div class="admin-agent-footage-preview">'+
        (item.submission_type==='video'?
          '<video controls playsinline preload="none" aria-label="Private video submission '+Number(item.id)+'" src="api/story-footage-preview.php?submission_id='+Number(item.id)+'"></video>':
          '<img loading="lazy" alt="Private contributor photo" src="api/story-footage-preview.php?submission_id='+Number(item.id)+'">')+
        '</div>'+
        (item.review_status==='pending_review'?
          '<details class="admin-agent-capture-decision"><summary>Review this submission in chat</summary>'+
          '<label>Human review evidence<textarea rows="2" maxlength="400" data-agent-capture-basis="'+Number(item.id)+'" placeholder="Document the evidence for your editorial decision (25+ characters)"></textarea></label>'+
          '<div class="actions">'+
          '<button type="button" data-agent-capture-review="approved_for_edit" data-submission-id="'+Number(item.id)+'">Approve for editing</button>'+
          '<button type="button" class="secondary" data-agent-capture-review="needs_release" data-submission-id="'+Number(item.id)+'">Needs release</button>'+
          '<button type="button" class="secondary" data-agent-capture-review="rejected" data-submission-id="'+Number(item.id)+'">Reject</button></div>'+
          '<p class="hint">Editorial approval is not an external AI clearance or a publication license.</p></details>':'')+
        '</div></div>').join(''):
        '<p>No video or photo contributions in the recent queue.</p>')+
      '<button type="button" class="secondary" data-agent-open-footage-review>Open Creative Director review in Team Workspace</button></section>';
  }
  function directorHtml(report){
    if(!report||!Array.isArray(report.gaps))return '';
    return '<section class="admin-agent-calendar" aria-label="AI Director evidence-based recommendations">'+
      '<strong>AI Director · coverage recommendations</strong>'+
      '<p class="hint">Suggestions derived from editorially approved asset counts. No footage frames were inspected, no AI model was invoked and no shot was automatically assigned.</p>'+
      '<p>'+Number(report.pending_review||0)+' pending submissions · '+Number(report.needs_likeness_or_release_review||0)+' need external AI rights review</p>'+
      (report.gaps.length?report.gaps.map(g=>
        '<div class="admin-agent-calendar-task"><div><strong>'+esc(g.title)+'</strong>'+
        '<p>'+Number(g.approved_assets)+' / '+Number(g.recommended_minimum)+' '+esc(g.shot_type)+' assets · target '+esc(g.target_role)+'</p>'+
        '<p>'+esc(g.brief)+'</p><p class="hint">'+esc(g.reason)+
        (g.draft_request_id?' · Existing draft #'+Number(g.draft_request_id):' · Draft not created')+'</p></div></div>'
      ).join(''):'<p>All minimum editorial coverage targets are currently satisfied.</p>')+
      '<p class="hint">Human review is required to draft, assign or open any shot request. Existing Creative Director tools retain those controls.</p></section>';
  }
  function sceneCandidateHtml(items){
    if(!Array.isArray(items))return '';
    return '<section class="admin-agent-calendar" aria-label="Verified scene clip recommendations"><strong>Verified scene candidates</strong>'+
      '<p class="hint">Timecoded, editor-reviewed observations only. Publication clearance is independent; no edit or render has been performed.</p>'+
      (items.length?items.map(s=>'<div class="admin-agent-calendar-task"><div>'+
        '<strong>Scene #'+Number(s.scene_id)+' · '+esc(s.capture_title)+'</strong>'+
        '<p>Submission #'+Number(s.submission_id)+' · '+(Number(s.start_ms)/1000).toFixed(2)+'s–'+
        (Number(s.end_ms)/1000).toFixed(2)+'s · '+esc(s.location||'Unspecified location')+'</p>'+
        '<p>'+esc(s.description)+'</p><p class="hint">Source: '+esc(s.origin)+
        ' · people release: '+esc(s.people_release_status)+
        ' · publishing: '+esc(s.publication_clearance)+'</p></div></div>').join(''):
        '<p>No verified scene annotations are available.</p>')+'</section>';
  }
  function timelineHtml(action){
    const t=action.timeline_inspection,list=action.timeline_assemblies;
    if(t&&Array.isArray(t.items)){
      return '<section class="admin-agent-calendar" aria-label="Read-only editorial timeline">'+
        '<strong>Assembly #'+Number(t.id)+' · '+esc(t.title)+' · revision '+Number(t.revision)+'</strong>'+
        '<p class="hint">Private editorial plan. No edits, media exports, renders or publication.</p>'+
        t.items.map(i=>'<div class="admin-agent-calendar-task"><div><strong>#'+Number(i.ordinal)+' · '+esc(i.kind)+'</strong>'+
          '<p>Source '+esc(i.source_id)+' · '+(Number(i.in_ms)/1000).toFixed(2)+'–'+
          (Number(i.out_ms)/1000).toFixed(2)+'s</p><p>'+esc(i.notes||'')+'</p></div></div>').join('')+
        '<p class="hint">Original source and publication rights require independent review.</p></section>';
    }
    return Array.isArray(list)?'<section class="admin-agent-calendar"><strong>Editorial assemblies</strong>'+
      list.map(a=>'<p>Assembly #'+Number(a.id)+' · '+esc(a.title)+' · '+Number(a.item_count)+' items · rev '+Number(a.revision)+'</p>').join('')+
      '<p class="hint">Ask: assembly #123 timeline</p></section>':'';
  }
  function messageHtml(m){
    const who=m.role==='assistant'?'Stonefellow Admin Agent':'Admin';
    const feedback=m.role==='assistant'&&Number(m.id)>0?'<div class="admin-agent-feedback"><button type="button" class="secondary" data-admin-agent-feedback="helpful" data-message-id="'+Number(m.id)+'">Helpful</button><button type="button" class="secondary" data-admin-agent-feedback="not_helpful" data-message-id="'+Number(m.id)+'">Not helpful</button></div>':'';
    const action=m.action||{};
    let next='';
    if(m.role==='assistant'&&action.type==='prepare_campaign')next='<button type="button" data-admin-agent-action="campaigns" class="secondary">Prepare exact-review campaign draft</button>';
    else if(m.role==='assistant'&&action.type==='prepare_journey')next='<button type="button" data-admin-agent-action="journey" class="secondary">Prepare exact-review journey draft</button>';
    else if(m.role==='assistant'&&action.type==='production_editorial_status')next='<button type="button" data-agent-show-calendar class="secondary">Show production calendar here</button>';
    else if(m.role==='assistant'&&action.type==='production_schedule_calendar')next=calendarHtml(action.production_calendar);
    else if(m.role==='assistant'&&action.type==='production_footage_review')next=footageHtml(action.footage_queue);
    else if(m.role==='assistant'&&action.type==='production_director_coverage')next=directorHtml(action.director_coverage);
    else if(m.role==='assistant'&&action.type==='production_scene_candidates')next=sceneCandidateHtml(action.scene_candidates);
    else if(m.role==='assistant'&&action.type==='production_timeline_inspect')next=timelineHtml(action);
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
    if(type==='production-review'){c.openView('production-review');return;}
    if(['crm','automations','analytics','commerce-ops'].includes(type))c.openView(type);
  }
  function bindActions(root){
    root.querySelectorAll('[data-admin-agent-action]').forEach(b=>b.onclick=()=>action(b.dataset.adminAgentAction));
    root.querySelectorAll('[data-agent-open-footage-review]').forEach(b=>b.onclick=()=>ctx()?.openView?.('team'));
    root.querySelectorAll('[data-agent-capture-review]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const id=Number(button.dataset.submissionId),decision=button.dataset.agentCaptureReview;
      const basis=root.querySelector('[data-agent-capture-basis="'+id+'"]')?.value.trim()||'';
      if(!Number.isSafeInteger(id)||id<1||!['approved_for_edit','needs_release','rejected'].includes(decision)||basis.length<25){
        c.say('Provide a specific human review basis (at least 25 characters).');return;
      }
      if(!window.confirm('Record '+decision.replaceAll('_',' ')+' for capture #'+id+'? This records an editorial review, never a publication or AI rights grant.'))return;
      button.disabled=true;
      try{
        await c.api('story-capture.php',{action:'review_submission',submission_id:id,decision,
          review_basis:basis,via_agent_chat:true,confirmed:true});
        c.say('Capture #'+id+' reviewed and recorded in Team Activity. Refreshing footage cards.');
        await send('review footage');
      }catch(e){c.say(e.message||'The capture review was not completed.');button.disabled=false;}
    });

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

    root.querySelectorAll('[data-agent-show-calendar]').forEach(button=>button.onclick=async()=>{
      button.disabled=true;try{await send('production calendar');}
      catch(e){ctx()?.say?.(e.message||'Calendar unavailable.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-deadline-review]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const id=Number(button.dataset.requestId),decision=button.dataset.agentDeadlineReview;
      const basis=root.querySelector('[data-agent-deadline-basis="'+id+'"]')?.value.trim()||'';
      if(!id||basis.length<25){c.say('Explain the human review decision in at least 25 characters.');return;}
      if(!window.confirm('Record '+decision+' for deadline request #'+id+'? Approval will change only this exact task due date.'))return;
      button.disabled=true;
      try{
        const result=await c.api('story-production-tasks.php',{action:'review_deadline',request_id:id,decision,basis});
        c.say('Reviewed request #'+id+' · '+esc(result.review?.status||decision)+'. Refreshing this chat calendar.');
        await send('production calendar');
      }catch(e){c.say(e.message||'Review could not be completed; status was not changed.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-task-schedule]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const id=Number(button.dataset.agentTaskSchedule);
      const value=root.querySelector('[data-agent-task-due="'+id+'"]')?.value||'';
      const reason=root.querySelector('[data-agent-task-reason="'+id+'"]')?.value.trim()||'';
      const priority=root.querySelector('[data-agent-task-priority="'+id+'"]')?.value||'normal';
      const time=new Date(value);
      if(!id||!value||!Number.isFinite(time.getTime())||reason.length<20){
        c.say('Select a valid date and provide a scheduling reason (20+ characters).');return;
      }
      if(!window.confirm('Save exact due date and priority for task #'+id+'? This will not change crew or approvals.'))return;
      button.disabled=true;
      try{
        await c.api('story-production-tasks.php',{action:'schedule',task_id:id,
          due_at:time.toISOString().replace('.000Z','Z'),priority,reason});
        c.say('Human-scheduled task #'+id+'. Refreshing the chat calendar.');
        await send('production calendar');
      }catch(e){c.say(e.message||'Deadline update unavailable.');button.disabled=false;}
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
      '<header class="admin-agent-chat-heading"><div><small>ADMIN AGENT · OPERATIONS</small><p>Private Admin Brain · Commerce, CRM, Artist World production and operational research</p></div>'+
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
        '<div class="admin-agent-welcome"><h2>What can I help with?</h2><p>Ask for the production calendar, overdue tasks, crew schedule, approvals or assembly #123. Review everything in chat.</p></div>';
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
      const editorial=brain.editorial_production||{},editorialRows=Array.isArray(editorial.assemblies)?editorial.assemblies.slice(0,8):[];
      const editorialList=editorialRows.map(a=>'<li>Assembly #'+Number(a.assembly_id)+' · rev '+
        Number(a.revision)+' · '+Number(a.item_count)+' clips · '+
        Number(a.approval_attention)+' review items</li>').join('');
      const commerce=brain.commerce||{},commerceCounts=commerce.summary||{};
      const commerceTriage=commerce.triage||{},triageRows=Array.isArray(commerceTriage.items)?commerceTriage.items.slice(0,6):[];
      const triageLines=triageRows.map(r=>'<li><strong>'+esc(r.severity)+' · '+esc(r.order_id)+'</strong> — '+esc(r.reason)+'</li>').join('');
      const missions=brain.missions||[],plans=brain.planned_missions||[],research=brain.research_opportunities||[],specialists=brain.specialist_assignments||[],counts=brain.mission_counts||{},tools=brain.tool_inventory||[],feedbackSummary=brain.feedback_summary||{},recovery=brain.recovery_summary||{};
      const list=missions.length?missions.map(m=>'<li><strong>'+esc(m.name)+'</strong><span> '+esc(m.status)+' · '+esc(m.tool_id)+'</span>'+ (m.review_required?'<p>Review the original work before any retry.</p>':'')+'</li>').join(''):'<li>No reviewed Agent actions yet.</li>';
      const specialistLines=specialists.slice(0,6).flatMap(p=>(p.assignments||[]).map(a=>'<li><strong>'+esc(a.specialist.replaceAll('_',' '))+'</strong> · '+esc(a.status)+(a.requires_exact_approval?' · exact approval required':'')+'</li>')).join('');
      const researchLines=research.slice(0,3).map(x=>'<li><strong>'+esc(x.title)+'</strong> — '+esc(x.why)+' <small>'+esc(x.source)+'</small></li>').join('');
      const planLines=plans.slice(0,10).map(p=>'<li>'+esc((p.steps||[]).map(s=>s.intent+': '+s.status).join(' → '))+'</li>').join('');
      const safeTools=tools.filter(t=>!['internal'].includes(t.kind)).map(t=>'<li>'+esc(t.label)+' · '+esc(t.kind)+'</li>').join('');
      drawer.querySelector('#adminBrainMissionContent').innerHTML='<div class="stats"><div class="stat"><strong>'+Number(missions.length)+'</strong><span>Recent actions</span></div><div class="stat"><strong>'+Number(counts.review_required||0)+'</strong><span>Needs review</span></div><div class="stat"><strong>'+Number(counts.completed||0)+'</strong><span>Completed</span></div></div><h3>Mission timeline</h3><ul class="admin-brain-list">'+list+'</ul><button type="button" class="secondary" id="adminBrainDetailedHistory">Review exact action history</button><h3>Evidence-backed research</h3><ul class="admin-brain-list">'+(researchLines||'<li>No new aggregate opportunities.</li>')+'</ul><h3>Feedback &amp; recovery</h3><p>'+Number(feedbackSummary.total||0)+' rated replies · '+Number(recovery.pending_approval||0)+' plan steps awaiting approval · '+Number(recovery.review_required||0)+' actions needing manual review. No automatic retries.</p><h3>Specialist assignments</h3><ul class="admin-brain-list">'+(specialistLines||'<li>No specialist assignments yet.</li>')+'</ul><h3>Planned missions</h3><ul class="admin-brain-list">'+(planLines||'<li>No staged plans yet.</li>')+'</ul><h3>Commerce Operations</h3><p>'+Number(commerce.total_attention||0)+' orders needing attention · '+Number(commerceCounts.payment_attention||0)+' payment checks · '+Number(commerceCounts.refund_attention||0)+' refunds · '+Number(commerceCounts.fulfillment_pending||0)+' fulfillment · '+Number(commerceCounts.open_followups||0)+' open follow-ups.</p><button type="button" class="secondary" id="brainCommerceOpen">Open Commerce Operations</button><h3>Priority commerce reviews</h3><p>Read-only suggestions; no automatic refund, email or shipping changes.</p><ul class="admin-brain-list">'+(commerceTriage.available?(triageLines||'<li>No currently flagged customer orders in the bounded snapshot.</li>'):'<li>Commerce data unavailable — do not assume the queue is empty.</li>')+'</ul>'+
        '<h3>Artist World · Editorial production</h3>'+
        '<p>'+(editorial.available?Number(editorial.attention_total)+' human review items across '+
           Number(editorial.total_shown)+' recent private assemblies.':
           'Private production ledger unavailable or access restricted.')+
        ' No automatic edits, external model export, rendering or publishing.</p>'+
        '<ul class="admin-brain-list">'+(editorial.available?(editorialList||'<li>No saved private assemblies.</li>'):
           '<li>Editor-only production evidence.</li>')+'</ul>'+
        '<button type="button" class="secondary" id="brainProductionOpen">Show production calendar in chat</button>'+
        '<h3>Permitted tools</h3><ul class="admin-brain-list">'+safeTools+'</ul>';
      drawer.querySelector('#brainCommerceOpen').onclick=()=>{closeBrain();c.openView('commerce-ops');};
      drawer.querySelector('#brainProductionOpen').onclick=()=>{closeBrain();send('production calendar').catch(e=>c.say(e.message));};
      drawer.querySelector('#adminBrainDetailedHistory').onclick=()=>{closeBrain();window.SFAgentCampaigns?.showHistory?.();};
    }catch(e){const p=drawer.querySelector('#adminBrainMissionContent');if(p)p.textContent='Operational Brain data is unavailable.';}
  }
  window.SFAdminAgentCanvas={render,send,showBrain};
})();
