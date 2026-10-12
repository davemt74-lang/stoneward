(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;if(!ctx)return;
  const {api,canvas,head,say,esc,money}=ctx;
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const state={summary:{},segments:[],automations:[],runs:[],tab:'segments',editor:null,segmentEditor:null};
  const stages=['lead','fan','customer','member','inactive'];
  const stepTypes={
    add_tag:['Add CRM Tag','Add a tag to the fan profile'],
    remove_tag:['Remove CRM Tag','Remove a tag from the fan profile'],
    set_stage:['Set CRM Stage','Move the fan to a lifecycle stage'],
    agent_message:['Agent Message','Governed in-app Agent outreach'],
    email:['Marketing Email','Consent-aware automated email'],
    enroll_campaign:['Enroll Campaign','Enter the fan into a published campaign'],
    wait:['Wait','Pause before the next action'],
    exit:['Exit','End the journey']
  };
  function blankRules(){return {newsletter:'any',account:'any',agent_auto_engage:'any',stages:[],tags_all:[],tags_any:[],purchase_required:false,min_orders:0,min_spend_cents:0,product_ids_any:[],campaign_ids_any:[],event_types_any:[],activity_within_days:0,inactive_for_days:0}}
  function blankAutomation(){return {id:0,name:'',status:'draft',trigger_type:'manual',trigger:{events:[],interval_hours:24},segment_id:0,cooldown_hours:24,max_runs_per_contact:10,steps:[{type:'add_tag',config:{tag:'engaged'}}]}}
  const csv=v=>String(v||'').split(',').map(x=>x.trim()).filter(Boolean);
  const arr=v=>Array.isArray(v)?v:[];
  const triggerLabel=a=>a.trigger_type==='crm_event'?'CRM event'+(a.trigger?.events?.length?' · '+a.trigger.events.join(', '):''):a.trigger_type==='scheduled'?'Every '+Number(a.trigger?.interval_hours||24)+'h':a.trigger_type.replaceAll('_',' ');
  const rulesSummary=r=>{r=r||{};const out=[];if(r.newsletter&&r.newsletter!=='any')out.push('newsletter '+r.newsletter);if(r.account&&r.account!=='any')out.push(r.account+' account');if(arr(r.stages).length)out.push('stage '+r.stages.join('/'));if(arr(r.tags_all).length)out.push('tags '+r.tags_all.join('+'));if(r.min_orders)out.push(r.min_orders+'+ orders');if(r.min_spend_cents)out.push(money(r.min_spend_cents)+'+ spend');if(arr(r.product_ids_any).length)out.push('products '+r.product_ids_any.join('/'));if(arr(r.event_types_any).length)out.push('events '+r.event_types_any.join('/'));if(r.activity_within_days)out.push('active ≤'+r.activity_within_days+'d');if(r.inactive_for_days)out.push('inactive ≥'+r.inactive_for_days+'d');return out.join(' · ')||'All CRM contacts'}
  async function load(){const j=await api('automations.php');state.summary=j.summary||{};state.segments=j.segments||[];state.automations=j.automations||[];state.runs=j.runs||[];return j}
  async function renderAutomations(){
    try{await load()}catch(e){canvas.innerHTML=head('FAN LIFECYCLE','Unavailable',e.message);return}
    const s=state.summary;
    canvas.innerHTML=head('CRM + AUTOMATION','Segments & Automations','Turn CRM, purchases and campaign behavior into reusable fan segments and governed lifecycle journeys.','<button class="secondary" id="automationTick" type="button">Run scheduler now</button>')+
      '<div class="stats"><div class="stat"><strong>'+Number(s.segments||0)+'</strong><span>Segments</span></div><div class="stat"><strong>'+Number(s.published||0)+'</strong><span>Published automations</span></div><div class="stat"><strong>'+Number(s.waiting||0)+'</strong><span>Waiting journeys</span></div><div class="stat"><strong>'+Number(s.completed||0)+'</strong><span>Completed journeys</span></div></div>'+
      '<div class="automation-tabs">'+[['segments','Segments'],['automations','Automations'],['runs','Run history'],['journey','Journey Intelligence']].map(x=>'<button type="button" data-auto-tab="'+x[0]+'" class="'+(state.tab===x[0]?'active':'')+'">'+x[1]+'</button>').join('')+'</div>'+
      '<div id="automationBody">'+(state.tab==='segments'?segmentsHtml():state.tab==='automations'?automationsHtml():state.tab==='runs'?runsHtml():journeySkeletonHtml())+'</div>';
    $('#automationTick').onclick=async()=>{if(!confirm('Run the lifecycle scheduler now? This can resume due waits and start eligible published scheduled journeys.'))return;try{const j=await api('automations.php',{action:'tick'});say('Lifecycle scheduler: '+j.result.runs_started+' started, '+j.result.runs_resumed+' resumed.');renderAutomations()}catch(e){alert(e.message)}};
    $$('[data-auto-tab]',canvas).forEach(b=>b.onclick=()=>{state.tab=b.dataset.autoTab;renderAutomations()});
    if(state.tab==='journey')renderJourneyIntelligence();
    else bindCurrentTab();
  }
  function journeySkeletonHtml(){
    return '<section class="panel" id="fanJourneyIntelligence"><h2>Fan journey intelligence</h2><p>Loading aggregated, consent-aware journey signals…</p></section>';
  }
  async function renderJourneyIntelligence(days=30){
    const mount=$('#fanJourneyIntelligence');if(!mount)return;
    try{
      const r=await api('fan-journeys.php?days='+encodeURIComponent(days)),journey=r.journey||{},c=journey.counts||{},rates=journey.rates||{};
      if(!document.contains(mount))return;
      const pct=value=>value===null||value===undefined?'—':Number(value).toFixed(1)+'%';
      const metrics=[
        ['Known CRM contacts',c.known_contacts],['Active CRM contacts',c.active_contacts],
        ['Listening contacts',c.listening_contacts],['Opted-in subscribers',c.newsletter_opted_in],
        ['Purchasing contacts',c.purchasing_contacts],['Campaign participants',c.campaign_participants],
        ['Campaign conversions',c.campaign_conversions],['Automation runs',c.journeys_started],
        ['Completed journeys',c.journeys_completed],['Failed journeys',c.journeys_failed]
      ];
      mount.innerHTML='<div class="panel-title"><div><h2>Fan journey intelligence</h2><p>Aggregate CRM, listening, campaign and automation measurements. Independent cohorts, not a fabricated sequential funnel.</p></div><button type="button" class="primary" id="journeyBuildExisting">Build journey</button></div>'+
        '<div class="actions" role="group" aria-label="Journey measurement window">'+[7,30,90].map(n=>'<button type="button" class="secondary" data-journey-days="'+n+'" aria-pressed="'+(n===days?'true':'false')+'">'+n+' days</button>').join('')+'</div>'+
        '<div class="stats">'+metrics.map(([label,value])=>'<div class="stat"><strong>'+Number(value||0).toLocaleString()+'</strong><span>'+esc(label)+'</span></div>').join('')+'</div>'+
        '<div class="stats"><div class="stat"><strong>'+pct(rates.campaign_conversion_pct)+'</strong><span>Campaign entry-cohort conversion</span></div><div class="stat"><strong>'+pct(rates.journey_completion_pct)+'</strong><span>Automation completion</span></div><div class="stat"><strong>'+pct(rates.journey_failure_pct)+'</strong><span>Automation failure</span></div></div>'+
        '<div class="notice"><strong>Measurement boundaries:</strong><ul>'+((journey.measurement_notes||[]).map(note=>'<li>'+esc(note)+'</li>').join(''))+'</ul></div>'+
        '<section class="panel" aria-label="Agent journey recommendations"><h3>Agent recommendations</h3><p>Evidence-backed suggestions only. Publishing or editing a journey requires a separate administrator action.</p><div id="journeyRecommendations" role="status">Reviewing aggregate evidence…</div></section>';

      $('#journeyBuildExisting',mount).onclick=()=>openAutomation(0);
      api('fan-journey-advice.php?days='+encodeURIComponent(days)).then(advice=>{
        const holder=$('#journeyRecommendations',mount);
        if(!holder||!document.contains(holder))return;
        const entries=advice.recommendations||[];
        holder.innerHTML=entries.length?'<ul>'+entries.map((item,i)=>'<li><strong>'+esc(item.title||'Review journey')+'</strong> — '+esc(item.evidence||'')+' <button type="button" class="secondary" data-journey-review="'+i+'">Review '+esc(item.review_view||'Admin')+'</button></li>').join('')+'</ul>':'No evidence-backed suggestions are available in this reporting window.';
        $('[data-journey-review]',holder).forEach(b=>b.onclick=()=>{
          const selected=entries[Number(b.dataset.journeyReview)];
          const view=String(selected?.review_view||'');
          if(view==='automations'){state.tab='automations';renderAutomations();}
          else if(view==='campaigns'||view==='crm')ctx.openView?.(view);
        });
      }).catch(()=>{const holder=$('#journeyRecommendations',mount);if(holder)holder.textContent='Journey recommendations are unavailable; no changes were made.';});
      $('[data-journey-days]',mount).forEach(button=>button.onclick=()=>renderJourneyIntelligence(Number(button.dataset.journeyDays)));
    }catch(e){mount.textContent='Journey intelligence is unavailable. '+(e.message||'Please try again.');}
  }
  function segmentsHtml(){
    return '<section class="panel"><div class="panel-title"><div><h2>Dynamic fan segments</h2><p>Reusable audiences calculated from CRM consent, account state, tags, purchases, campaigns and activity.</p></div><button class="primary" id="newSegment" type="button">New segment</button></div>'+
      (state.segments.length?'<table class="data-table"><thead><tr><th>Segment</th><th>Rules</th><th>Members</th><th>Updated</th><th></th></tr></thead><tbody>'+state.segments.map(s=>'<tr><td><strong>'+esc(s.name)+'</strong></td><td>'+esc(rulesSummary(s.rules))+'</td><td>'+Number(s.member_count||0)+'</td><td>'+esc((s.updated_at||'').replace('T',' ').slice(0,19))+'</td><td><button class="secondary" type="button" data-segment-edit="'+s.id+'">Edit</button></td></tr>').join('')+'</tbody></table>':'<div class="empty">No saved fan segments yet.</div>')+'</section>';
  }
  function automationsHtml(){
    return '<section class="panel"><div class="panel-title"><div><h2>Lifecycle journeys</h2><p>Published journeys are standing Admin approval for their configured actions. Fan consent and Agent permissions remain enforced at runtime.</p></div><button class="primary" id="newAutomation" type="button">New automation</button></div>'+
      (state.automations.length?'<table class="data-table"><thead><tr><th>Automation</th><th>Trigger</th><th>Audience</th><th>Status</th><th>Runs</th><th></th></tr></thead><tbody>'+state.automations.map(a=>'<tr><td><strong>'+esc(a.name)+'</strong><div class="file-list">'+a.steps.length+' steps · '+Number(a.cooldown_hours||0)+'h cooldown</div></td><td>'+esc(triggerLabel(a))+'</td><td>'+esc(a.segment_name||'All CRM contacts')+'</td><td><span class="badge '+(a.status==='published'?'good':a.status==='paused'?'warn':'')+'">'+esc(a.status.toUpperCase())+'</span></td><td>'+Number(a.run_count||0)+' <span class="file-list">'+Number(a.completed_count||0)+' complete</span></td><td><button class="secondary" type="button" data-automation-edit="'+a.id+'">Open</button></td></tr>').join('')+'</tbody></table>':'<div class="empty">No lifecycle automations yet.</div>')+'</section>';
  }
  function runsHtml(){
    return '<section class="panel"><div class="panel-title"><div><h2>Journey run history</h2><p>Per-fan execution ledger with wait, action, completion and failure state.</p></div></div>'+
      (state.runs.length?'<table class="data-table"><thead><tr><th>Time</th><th>Automation</th><th>Fan</th><th>Status</th><th>Step</th><th></th></tr></thead><tbody>'+state.runs.map(r=>'<tr><td>'+esc((r.started_at||'').replace('T',' ').slice(0,19))+'</td><td>'+esc(r.automation_name)+'</td><td><strong>'+esc(r.display_name||'Fan')+'</strong><div class="file-list">'+esc(r.email)+'</div></td><td><span class="badge '+(r.status==='completed'?'good':r.status==='failed'?'bad':r.status==='waiting'?'warn':'')+'">'+esc(r.status.toUpperCase())+'</span></td><td>'+Number(r.current_step||0)+(r.due_at?'<div class="file-list">due '+esc(r.due_at.replace('T',' ').slice(0,19))+'</div>':'')+'</td><td><button class="secondary" type="button" data-run-open="'+r.id+'">Timeline</button>'+(r.status==='review_required'?'<button class="secondary" type="button" data-run-reconcile="'+r.id+'">Mark reviewed (no replay)</button>':'')+'</td></tr>').join('')+'</tbody></table>':'<div class="empty">No lifecycle runs yet.</div>')+'</section>';
  }
  function bindCurrentTab(){
    if(state.tab==='segments'){const n=$('#newSegment');if(n)n.onclick=()=>openSegment(0);$$('[data-segment-edit]',canvas).forEach(b=>b.onclick=()=>openSegment(Number(b.dataset.segmentEdit)))}
    if(state.tab==='automations'){const n=$('#newAutomation');if(n)n.onclick=()=>openAutomation(0);$$('[data-automation-edit]',canvas).forEach(b=>b.onclick=()=>openAutomation(Number(b.dataset.automationEdit)))}
    if(state.tab==='runs')$('[data-run-open]',canvas).forEach(b=>b.onclick=()=>openRun(Number(b.dataset.runOpen)));
    if(state.tab==='runs')$('[data-run-reconcile]',canvas).forEach(b=>b.onclick=()=>reviewInterruptedRun(Number(b.dataset.runReconcile)));
  }
  async function openSegment(id){
    try{let seg={id:0,name:'',rules:blankRules()},contacts=[],count=0;if(id){const j=await api('automations.php?segment_id='+encodeURIComponent(id));seg=j.segment;contacts=j.contacts||[];count=j.count||0}state.segmentEditor={segment:seg,contacts,count};renderSegmentEditor()}catch(e){alert(e.message)}
  }
  function segmentFormRules(f){
    const d=new FormData(f);return {newsletter:String(d.get('newsletter')||'any'),account:String(d.get('account')||'any'),agent_auto_engage:String(d.get('agent_auto_engage')||'any'),stages:csv(d.get('stages')),tags_all:csv(d.get('tags_all')),tags_any:csv(d.get('tags_any')),purchase_required:d.get('purchase_required')==='on',min_orders:Number(d.get('min_orders')||0),min_spend_cents:Math.round(Number(d.get('min_spend')||0)*100),product_ids_any:csv(d.get('product_ids_any')),campaign_ids_any:csv(d.get('campaign_ids_any')).map(Number).filter(Boolean),event_types_any:csv(d.get('event_types_any')),activity_within_days:Number(d.get('activity_within_days')||0),inactive_for_days:Number(d.get('inactive_for_days')||0)}
  }
  function renderSegmentEditor(){
    const e=state.segmentEditor,s=e.segment,r={...blankRules(),...(s.rules||{})};
    canvas.innerHTML=head('DYNAMIC AUDIENCE',s.id?'Edit segment':'New segment','Preview exactly who matches before saving the reusable audience.','<button class="secondary" id="segmentBack" type="button">Segments</button>')+
      '<section class="panel"><form id="segmentForm" class="form-grid"><label class="field span2">Segment name<input name="name" value="'+esc(s.name||'')+'" required></label>'+
      '<label class="field">Newsletter<select name="newsletter">'+['any','subscribed','unsubscribed'].map(x=>'<option value="'+x+'" '+(r.newsletter===x?'selected':'')+'>'+x+'</option>').join('')+'</select></label>'+
      '<label class="field">Account<select name="account">'+[['any','Any'],['linked','Linked account'],['unlinked','Email-only']].map(x=>'<option value="'+x[0]+'" '+(r.account===x[0]?'selected':'')+'>'+x[1]+'</option>').join('')+'</select></label>'+
      '<label class="field">Proactive Agent<select name="agent_auto_engage">'+[['any','Any'],['enabled','Enabled'],['disabled','Disabled']].map(x=>'<option value="'+x[0]+'" '+(r.agent_auto_engage===x[0]?'selected':'')+'>'+x[1]+'</option>').join('')+'</select></label>'+
      '<label class="field span2">CRM stages<input name="stages" value="'+esc(arr(r.stages).join(', '))+'" placeholder="fan, customer, member"></label>'+
      '<label class="field span2">Must have all tags<input name="tags_all" value="'+esc(arr(r.tags_all).join(', '))+'"></label><label class="field span2">May have any tag<input name="tags_any" value="'+esc(arr(r.tags_any).join(', '))+'"></label>'+
      '<label class="check-field"><input name="purchase_required" type="checkbox" '+(r.purchase_required?'checked':'')+'> Has purchased</label><label class="field">Minimum orders<input name="min_orders" type="number" min="0" value="'+Number(r.min_orders||0)+'"></label><label class="field">Minimum merch spend $<input name="min_spend" type="number" min="0" step=".01" value="'+(Number(r.min_spend_cents||0)/100).toFixed(2)+'"></label>'+
      '<label class="field span2">Purchased product IDs<input name="product_ids_any" value="'+esc(arr(r.product_ids_any).join(', '))+'" placeholder="shirt-black, tour-poster"></label><label class="field span2">Campaign IDs<input name="campaign_ids_any" value="'+esc(arr(r.campaign_ids_any).join(', '))+'"></label>'+
      '<label class="field span2">CRM event types<input name="event_types_any" value="'+esc(arr(r.event_types_any).join(', '))+'" placeholder="merch_purchase, newsletter_signup"></label><label class="field">Active within days<input name="activity_within_days" type="number" min="0" value="'+Number(r.activity_within_days||0)+'"></label><label class="field">Inactive for days<input name="inactive_for_days" type="number" min="0" value="'+Number(r.inactive_for_days||0)+'"></label>'+
      '<div class="actions span3"><button class="secondary" id="segmentPreview" type="button">Preview audience</button><button class="primary" type="submit">Save segment</button>'+(s.id?'<button class="danger" id="segmentDelete" type="button">Delete</button>':'')+'</div><p id="segmentStatus" class="status-line span3"></p></form></section>'+
      '<section class="panel"><div class="panel-title"><div><h2>Audience preview</h2><p id="segmentPreviewCount">'+Number(e.count||0)+' current matches</p></div></div><div id="segmentPreviewTable">'+segmentPreviewTable(e.contacts||[])+'</div></section>';
    $('#segmentBack').onclick=()=>{state.tab='segments';renderAutomations()};
    $('#segmentPreview').onclick=()=>previewSegment();
    $('#segmentForm').onsubmit=saveSegment;
    const del=$('#segmentDelete');if(del)del.onclick=deleteSegment;
  }
  function segmentPreviewTable(rows){return rows.length?'<table class="data-table"><thead><tr><th>Fan</th><th>Stage</th><th>Newsletter</th><th>Orders</th><th>Merch spend</th><th>Last activity</th></tr></thead><tbody>'+rows.map(c=>'<tr><td><strong>'+esc(c.display_name||'Fan')+'</strong><div class="file-list">'+esc(c.email)+'</div></td><td>'+esc(c.status)+'</td><td>'+(c.marketing_opt_in?'yes':'—')+'</td><td>'+Number(c.order_count||0)+'</td><td>'+money(c.spend_cents||0)+'</td><td>'+esc((c.last_activity_at||'').replace('T',' ').slice(0,19))+'</td></tr>').join('')+'</tbody></table>':'<div class="empty">No fans match these rules.</div>'}
  async function previewSegment(){const f=$('#segmentForm'),status=$('#segmentStatus');status.textContent='Calculating…';try{const j=await api('automations.php',{action:'preview_segment',rules:segmentFormRules(f)});state.segmentEditor.contacts=j.contacts||[];state.segmentEditor.count=j.count||0;$('#segmentPreviewCount').textContent=j.count+(j.truncated?' shown (preview capped)':' current matches');$('#segmentPreviewTable').innerHTML=segmentPreviewTable(j.contacts||[]);status.textContent='Audience preview updated.'}catch(e){status.textContent=e.message}}
  async function saveSegment(ev){ev.preventDefault();const f=ev.currentTarget,d=new FormData(f),s=state.segmentEditor.segment,status=$('#segmentStatus');status.textContent='Saving…';try{const j=await api('automations.php',{action:'save_segment',segment:{id:s.id||0,name:d.get('name'),rules:segmentFormRules(f)}});say('Segment saved with '+j.member_count+' current members.');state.tab='segments';renderAutomations()}catch(e){status.textContent=e.message}}
  async function deleteSegment(){const s=state.segmentEditor.segment;if(!confirm('Delete this segment? Automations using it will lose their audience reference.'))return;try{await api('automations.php',{action:'delete_segment',id:s.id});say('Segment deleted.');state.tab='segments';renderAutomations()}catch(e){alert(e.message)}}

  async function openAutomation(id){
    try{let a=blankAutomation(),runs=[];if(id){const j=await api('automations.php?automation_id='+encodeURIComponent(id));a=j.automation;runs=j.runs||[];state.segments=j.segments||state.segments}state.editor={automation:a,runs};renderAutomationEditor()}catch(e){alert(e.message)}
  }
  function automationMetaFromForm(f){
    const d=new FormData(f),a=state.editor.automation,triggerType=String(d.get('trigger_type')||'manual');return {...a,name:String(d.get('name')||'').trim(),trigger_type:triggerType,segment_id:Number(d.get('segment_id')||0),cooldown_hours:Number(d.get('cooldown_hours')||0),max_runs_per_contact:Number(d.get('max_runs_per_contact')||10),trigger:{events:csv(d.get('trigger_events')),interval_hours:Number(d.get('interval_hours')||24)}}
  }
  function renderAutomationEditor(){
    const e=state.editor,a=e.automation||blankAutomation(),published=a.status==='published';
    canvas.innerHTML=head('LIFECYCLE JOURNEY',a.id?a.name:'New automation','Build a governed fan journey from a trigger, reusable audience and ordered actions.','<button class="secondary" id="automationBack" type="button">Automations</button><button class="secondary" id="automationValidate" type="button">Validate</button><button class="secondary" id="automationAgentReview" type="button">Agent-reviewed draft</button><button class="primary" id="automationSave" type="button">Save draft</button>'+(a.id?'<button class="'+(published?'secondary':'primary')+'" id="automationPublish" type="button">'+(published?'Pause':'Publish')+'</button><button class="secondary" id="automationRun" type="button">Run now</button>':''));
    canvas.innerHTML+='<section class="panel"><form id="automationForm" class="form-grid"><label class="field span2">Name<input name="name" value="'+esc(a.name||'')+'" required></label><label class="field">Trigger<select name="trigger_type">'+[['manual','Manual only'],['crm_event','CRM event'],['segment_enter','Segment entered'],['segment_exit','Segment exited'],['scheduled','Scheduled']].map(x=>'<option value="'+x[0]+'" '+(a.trigger_type===x[0]?'selected':'')+'>'+x[1]+'</option>').join('')+'</select></label><label class="field">Audience segment<select name="segment_id"><option value="0">All CRM contacts</option>'+state.segments.map(s=>'<option value="'+s.id+'" '+(Number(a.segment_id)===Number(s.id)?'selected':'')+'>'+esc(s.name)+' · '+Number(s.member_count||0)+'</option>').join('')+'</select></label><label class="field span2">CRM event triggers<input name="trigger_events" value="'+esc(arr(a.trigger?.events).join(', '))+'" placeholder="merch_purchase, newsletter_signup"></label><label class="field">Schedule every hours<input name="interval_hours" type="number" min="1" max="8760" value="'+Number(a.trigger?.interval_hours||24)+'"></label><label class="field">Cooldown hours<input name="cooldown_hours" type="number" min="0" max="8760" value="'+Number(a.cooldown_hours||24)+'"></label><label class="field">Max runs / fan<input name="max_runs_per_contact" type="number" min="1" max="1000" value="'+Number(a.max_runs_per_contact||10)+'"></label><div class="notice span3"><strong>Governance:</strong> publishing this automation is standing Admin approval for its configured actions. Automated email still requires newsletter opt-in. Agent messages still require a linked account and the fan’s proactive-Agent permission.</div></form></section>'+
      '<section class="panel"><div class="panel-title"><div><h2>Journey steps</h2><p>Steps run in order. Wait pauses the run and the CLI scheduler resumes it when due.</p></div><div class="automation-add-steps">'+Object.entries(stepTypes).map(([k,v])=>'<button class="secondary" type="button" data-add-step="'+k+'">+ '+esc(v[0])+'</button>').join('')+'</div></div><div id="automationSteps" class="automation-steps">'+stepsHtml(a.steps||[])+'</div></section>'+
      (a.id?'<section class="panel"><div class="panel-title"><div><h2>Recent runs</h2><p>'+e.runs.length+' recent fan journeys</p></div><button class="danger" id="automationDelete" type="button">Delete automation</button></div>'+runTable(e.runs)+'</section>':'')+
      '<p id="automationStatus" class="status-line"></p>';
    $('#automationBack').onclick=()=>{state.tab='automations';renderAutomations()};
    $('#automationSave').onclick=()=>saveAutomation('draft');
    $('#automationValidate').onclick=validateAutomation;
    $('#automationAgentReview').onclick=reviewJourneyDraft;
    const pub=$('#automationPublish');if(pub)pub.onclick=()=>saveAutomation(published?'paused':'published');
    const run=$('#automationRun');if(run)run.onclick=runAutomation;
    const del=$('#automationDelete');if(del)del.onclick=deleteAutomation;
    $$('[data-add-step]',canvas).forEach(b=>b.onclick=()=>{a.steps.push({type:b.dataset.addStep,config:defaultStepConfig(b.dataset.addStep)});renderAutomationEditor()});
    bindSteps();
    $$('[data-run-open]',canvas).forEach(b=>b.onclick=()=>openRun(Number(b.dataset.runOpen)));
  }
  function defaultStepConfig(type){if(type==='wait')return{hours:24};if(type==='set_stage')return{stage:'fan'};if(type==='email')return{subject:'',body:''};if(type==='agent_message')return{message:''};if(type==='enroll_campaign')return{campaign_id:0};if(type==='exit')return{reason:'journey_complete'};return{tag:''}}
  function stepFields(step,i){
    const c=step.config||{};if(['add_tag','remove_tag'].includes(step.type))return '<label class="field">CRM tag<input data-step-field="tag" value="'+esc(c.tag||'')+'"></label>';
    if(step.type==='set_stage')return '<label class="field">Stage<select data-step-field="stage">'+stages.map(x=>'<option value="'+x+'" '+(c.stage===x?'selected':'')+'>'+x+'</option>').join('')+'</select></label>';
    if(step.type==='agent_message')return '<label class="field span2">Agent message<textarea data-step-field="message">'+esc(c.message||'')+'</textarea></label>';
    if(step.type==='email')return '<label class="field span2">Subject<input data-step-field="subject" value="'+esc(c.subject||'')+'"></label><label class="field span2">Email body<textarea data-step-field="body">'+esc(c.body||'')+'</textarea></label>';
    if(step.type==='enroll_campaign')return '<label class="field">Campaign ID<input type="number" min="1" data-step-field="campaign_id" value="'+Number(c.campaign_id||0)+'"></label>';
    if(step.type==='wait')return '<label class="field">Hours<input type="number" min="1" max="8760" data-step-field="hours" value="'+Number(c.hours||24)+'"></label>';
    if(step.type==='exit')return '<label class="field">Reason<input data-step-field="reason" value="'+esc(c.reason||'journey_complete')+'"></label>';
    return'';
  }
  function stepsHtml(steps){return steps.length?steps.map((s,i)=>'<article class="automation-step" data-step-index="'+i+'"><div class="automation-step-number">'+String(i+1).padStart(2,'0')+'</div><div class="automation-step-main"><div class="automation-step-title"><strong>'+esc(stepTypes[s.type]?.[0]||s.type)+'</strong><span>'+esc(stepTypes[s.type]?.[1]||'')+'</span></div><div class="form-grid">'+stepFields(s,i)+'</div></div><div class="automation-step-actions"><button type="button" data-step-up="'+i+'" '+(i===0?'disabled':'')+'>↑</button><button type="button" data-step-down="'+i+'" '+(i===steps.length-1?'disabled':'')+'>↓</button><button type="button" data-step-remove="'+i+'">×</button></div></article>').join(''):'<div class="empty">Add at least one journey step.</div>'}
  function captureSteps(){
    const a=state.editor.automation;$$('[data-step-index]',canvas).forEach(card=>{const i=Number(card.dataset.stepIndex),step=a.steps[i];if(!step)return;$$('[data-step-field]',card).forEach(el=>{const k=el.dataset.stepField;step.config[k]=el.type==='number'?Number(el.value||0):el.value})});return a.steps
  }
  function bindSteps(){
    $$('[data-step-up]',canvas).forEach(b=>b.onclick=()=>moveStep(Number(b.dataset.stepUp),-1));$$('[data-step-down]',canvas).forEach(b=>b.onclick=()=>moveStep(Number(b.dataset.stepDown),1));$$('[data-step-remove]',canvas).forEach(b=>b.onclick=()=>{captureSteps();state.editor.automation.steps.splice(Number(b.dataset.stepRemove),1);renderAutomationEditor()})
  }
  function moveStep(i,d){captureSteps();const a=state.editor.automation,j=i+d;if(j<0||j>=a.steps.length)return;[a.steps[i],a.steps[j]]=[a.steps[j],a.steps[i]];renderAutomationEditor()}
  function buildAutomation(statusOverride=null){const a=automationMetaFromForm($('#automationForm'));a.steps=captureSteps();if(statusOverride)a.status=statusOverride;return a}
  async function reviewJourneyDraft(){
    if(!state.editor)return;
    const input=buildAutomation('draft');
    // Never edit a published automation through an Agent draft proposal.
    input.id=0;input.status='draft';
    const tokenBytes=new Uint8Array(18);
    crypto.getRandomValues(tokenBytes);
    const requestKey='journey-'+Array.from(tokenBytes,x=>x.toString(16).padStart(2,'0')).join('');
    const dialog=document.createElement('dialog');
    dialog.className='agent-campaign-dialog';
    dialog.setAttribute('aria-label','Review Agent lifecycle journey draft');
    dialog.innerHTML='<h2>Agent journey draft approval</h2>'+
      '<p>Stage 1: The server validates an immutable draft. No automation is published or executed.</p>'+
      '<p id="agentJourneyStatus" role="status">Preparing the proposal…</p>'+
      '<pre id="agentJourneyPayload" tabindex="0" style="overflow:auto;max-height:40vh;white-space:pre-wrap;overflow-wrap:anywhere"></pre>'+
      '<p>SHA-256: <code id="agentJourneyHash" style="overflow-wrap:anywhere"></code></p>'+
      '<form id="agentJourneyApproval" hidden><label>Stage 2: Type APPROVE to approve exactly the displayed payload<input name="confirmation" required autocomplete="off" placeholder="APPROVE"></label><button type="submit" class="primary">Approve reviewed journey</button></form>'+
      '<button type="button" id="agentJourneyExecute" class="primary" hidden>Stage 3: Save approved unpublished draft</button>'+
      '<button type="button" id="agentJourneyClose">Close</button>';
    document.body.appendChild(dialog);
    const $d=id=>dialog.querySelector(id);
    let proposal=null;
    const status=$d('#agentJourneyStatus');
    dialog.addEventListener('close',()=>dialog.remove());
    $d('#agentJourneyClose').onclick=()=>dialog.close();
    if(dialog.showModal)dialog.showModal();else dialog.setAttribute('open','');
    try{
      const res=await api('agent-actions.php',{action:'prepare',tool_id:'automation_draft_save',request_key:requestKey,draft:input});
      if(!dialog.isConnected)return;
      proposal=res.proposal;
      status.textContent='Validated. Review all trigger rules, segments, steps and limits before approving.';
      $d('#agentJourneyPayload').textContent=JSON.stringify(proposal.payload,null,2);
      $d('#agentJourneyHash').textContent=proposal.payload_hash;
      $d('#agentJourneyApproval').hidden=false;
    }catch(e){status.textContent='Proposal preparation failed: '+e.message;return;}
    $d('#agentJourneyApproval').onsubmit=async event=>{
      event.preventDefault();
      const form=event.currentTarget;
      const button=form.querySelector('button');button.disabled=true;
      try{
        const response=await api('agent-actions.php',{action:'approve',proposal_id:proposal.id,payload_hash:proposal.payload_hash,typed_confirmation:form.elements.confirmation.value});
        proposal=response.proposal;
        if(proposal.status!=='approved')throw new Error('Approval was not confirmed.');
        form.hidden=true;$d('#agentJourneyExecute').hidden=false;
        status.textContent='Approved. The automation has not yet been created.';
      }catch(e){status.textContent='Approval rejected: '+e.message;button.disabled=false;}
    };
    $d('#agentJourneyExecute').onclick=async event=>{
      const button=event.currentTarget;button.disabled=true;
      try{
        const result=await api('agent-actions.php',{action:'execute',proposal_id:proposal.id});
        proposal=result.proposal;
        if(proposal.status!=='completed'||!(Number(proposal.result?.automation_id)>0))throw new Error('No confirmed draft result.');
        const newId=Number(proposal.result.automation_id);
        status.textContent='Unpublished journey draft created. No outreach was sent.';
        dialog.close();
        say('Agent-reviewed lifecycle draft saved. Review it before publishing.');
        openAutomation(newId);
      }catch(e){
        status.textContent='Execution is uncertain. Check the existing automations and Agent action ledger before preparing a fresh proposal.';
      }
    };
  }
  async function validateAutomation(){const status=$('#automationStatus');try{const a=buildAutomation(),j=await api('automations.php',{action:'validate_automation',steps:a.steps});status.textContent='Valid journey: '+j.steps.length+' executable steps.'}catch(e){status.textContent=e.message}}
  async function saveAutomation(statusOverride){const status=$('#automationStatus');try{let a=buildAutomation(statusOverride);if(statusOverride==='published'&&!confirm('Publish this lifecycle automation? Eligible fans can receive its configured actions automatically. Consent, cooldown and Agent permissions will still be enforced.'))return;const j=await api('automations.php',{action:'save_automation',automation:a});state.editor.automation=j.automation;say('Automation saved as '+j.automation.status+'.');openAutomation(j.automation.id)}catch(e){status.textContent=e.message}}
  async function runAutomation(){const a=state.editor.automation;if(!confirm('Run this automation now for its eligible audience? This is an explicit Admin-triggered execution.'))return;try{const j=await api('automations.php',{action:'manual_run',id:a.id,confirmed:true});say('Manual run started '+j.result.started+' journeys; '+j.result.skipped+' skipped.');openAutomation(a.id)}catch(e){alert(e.message)}}
  async function deleteAutomation(){const a=state.editor.automation;if(!confirm('Delete this automation and its run/event history?'))return;try{await api('automations.php',{action:'delete_automation',id:a.id});say('Automation deleted.');state.tab='automations';renderAutomations()}catch(e){alert(e.message)}}
  function runTable(rows){return rows.length?'<table class="data-table"><thead><tr><th>Time</th><th>Fan</th><th>Status</th><th>Step</th><th></th></tr></thead><tbody>'+rows.map(r=>'<tr><td>'+esc((r.started_at||'').replace('T',' ').slice(0,19))+'</td><td><strong>'+esc(r.display_name||'Fan')+'</strong><div class="file-list">'+esc(r.email)+'</div></td><td><span class="badge '+(r.status==='completed'?'good':r.status==='failed'?'bad':r.status==='waiting'?'warn':'')+'">'+esc(r.status)+'</span></td><td>'+Number(r.current_step||0)+'</td><td><button class="secondary" type="button" data-run-open="'+r.id+'">Timeline</button></td></tr>').join('')+'</tbody></table>':'<div class="empty">No runs yet.</div>'}
  async function reviewInterruptedRun(runId){
    const typed=window.prompt('First inspect the journey Timeline and check whether any outreach was already delivered. This does not undo or retry any action. Type REVIEWED to acknowledge.','');
    if(typed!=='REVIEWED')return;
    try{
      await api('automations.php',{action:'acknowledge_interrupted',run_id:runId,typed_confirmation:typed});
      say('Interrupted run acknowledged; no action was retried.');
      await renderAutomations();
    }catch(e){alert(e.message);}
  }
  async function openRun(id){
    try{const j=await api('automations.php?run_id='+encodeURIComponent(id)),r=j.run,events=j.events||[];const overlay=document.createElement('div');overlay.className='automation-modal';overlay.innerHTML='<div class="automation-modal-card"><div class="panel-title"><div><div class="eyebrow">JOURNEY RUN</div><h2>'+esc(r.automation_name)+'</h2><p>'+esc(r.display_name||'Fan')+' · '+esc(r.email)+' · '+esc(r.status)+'</p></div><button class="secondary" type="button" data-auto-close>Close</button></div><div class="automation-run-timeline">'+events.map(e=>'<article><time>'+esc((e.created_at||'').replace('T',' ').slice(0,19))+'</time><strong>'+esc(String(e.event_type).replaceAll('_',' '))+'</strong><small>step '+Number(e.step_index)+'</small><code>'+esc(JSON.stringify(e.details||{}))+'</code></article>').join('')+'</div></div>';document.body.appendChild(overlay);$('[data-auto-close]',overlay).onclick=()=>overlay.remove()}catch(e){alert(e.message)}
  }
  window.SFAutomationsAdmin={renderAutomations,openAutomation,openSegment};
})();