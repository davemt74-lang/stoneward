(() => {
  'use strict';
  /** One Admin chat canvas + slide-out Brain; same server-owned mission ledger. */
  let conversationId=sessionStorage.getItem('stonefellow.admin.agent.thread')||'';
  let busy=false,lastBrain=null,previousFocus=null,songIntake=null,popupSequence=0;
  let localPlayerTurns=[];
  const ctx=()=>window.STONEFELLOW_ADMIN_CONTEXT;
  const esc=s=>ctx()?.esc?.(String(s??''))||'';
  const proofLabel=action=>String(action||'record_saved').replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
  function proofCard(e){
    if(!e||!e.title)return '';
    const verified=e.verified===true,url=String(e.url||'');
    const safeUrl=/^index\.php\?view=(?:catalog|releases|shows|products|media|operations|brain)(?:&[a-z]+=[A-Za-z0-9_.%\-]+)?$/.test(url)?url:'';
    const proposed=e.provenance==='agent_action_proposal';
    return '<article class="admin-proof-card"><div class="admin-proof-meta"><span class="'+(verified?'admin-proof-verified':'admin-proof-recorded')+'">'+
      (verified?'✓ Verified saved record':proposed?'Agent action · not verified as a saved record':'Audit recorded · entity not verified')+'</span><time>'+esc((e.at||'').replace('T',' ').slice(0,19))+'</time></div>'+
      '<div class="admin-proof-actor">'+esc(e.actor||'Administrator')+'</div>'+
      '<strong>'+esc(e.title)+'</strong><p>'+esc(proofLabel(e.action))+' · '+esc(e.state||'')+' · '+esc(e.entity_type||'')+
      (e.entity_id?' #'+esc(e.entity_id):'')+'</p>'+
      (safeUrl?'<a class="secondary" href="'+esc(safeUrl)+'">'+(verified?'Open verified record':proposed?'Review Agent action':'Review audit in Operations')+'</a>':'')+'</article>';
  }
  function proofHtml(items,limit=8){return Array.isArray(items)&&items.length?
    '<div class="admin-proof-list">'+items.slice(0,limit).map(proofCard).join('')+'</div>':
    '<p class="hint">No recorded actions yet. Completed work will appear after its saved record is confirmed.</p>'}
  function brainDecisionHtml(items){
    return Array.isArray(items)&&items.length?'<ol class="admin-brain-decision-list">'+items.map(d=>
      '<li><time>'+esc((d.created_at||'').replace('T',' ').slice(0,19))+'</time> <strong>'+esc(proofLabel(d.phase||'decision'))+'</strong> · '+
      esc(d.route||d.action||'Agent')+' <span>'+esc(d.provider_status||'recorded')+'</span>'+
      (Number(d.requires_confirmation)?' · Approval required':'')+'</li>').join('')+'</ol>':
      '<p class="hint">No Admin Agent decisions recorded yet.</p>';
  }
  async function loadProofsInto(target,limit=4){
    if(!target)return;
    try{const r=await ctx().api('activity-proof.php');
      if(target.isConnected)target.innerHTML='<h3>Saved work &amp; Agent action receipts</h3>'+proofHtml(r.events||[],limit);
    }catch(_){if(target.isConnected)target.textContent='Action history could not be loaded.';}
  }
  function refreshProofCards(){
    const main=document.querySelector('#adminAgentProofCards');
    if(main?.isConnected)void loadProofsInto(main,5);
    const popup=document.querySelector('#adminChatPopupProof');
    if(popup?.isConnected&&!document.querySelector('#adminChatPopup')?.hidden)
      void loadProofsInto(popup,3);
  }
  async function load(){
    const c=ctx();if(!c)throw new Error('Admin workspace not available.');
    try{return await c.api('agent-chat.php'+(conversationId?'?conversation_id='+encodeURIComponent(conversationId):''));}
    catch(e){if(conversationId){conversationId='';sessionStorage.removeItem('stonefellow.admin.agent.thread');return c.api('agent-chat.php');}throw e;}
  }
  function isPopupOpen(){const popup=document.querySelector('#adminChatPopup');return !!popup&&!popup.hidden;}
  const localPlayerMarkup=()=>localPlayerTurns.map(entry=>
    '<article class="admin-chat-popup-message '+(entry.role==='assistant'?'agent':'human')+'"><strong>'+
    (entry.role==='assistant'?'Stonefellow · local player':'You')+'</strong><p>'+esc(entry.text)+'</p></article>'
  ).join('');
  function recordLocalPlayerTurn(message,reply){
    localPlayerTurns.push({role:'user',text:String(message)},{role:'assistant',text:String(reply)});
    localPlayerTurns=localPlayerTurns.slice(-24);
    const transcript=document.querySelector('#adminChatPopupMessages');
    if(isPopupOpen()&&transcript){
      const empty=transcript.querySelector('.admin-chat-popup-empty');if(empty)empty.remove();
      transcript.insertAdjacentHTML('beforeend',localPlayerTurns.slice(-2).map(entry=>
        '<article class="admin-chat-popup-message '+(entry.role==='assistant'?'agent':'human')+'"><strong>'+
        (entry.role==='assistant'?'Stonefellow · local player':'You')+'</strong><p>'+esc(entry.text)+'</p></article>'
      ).join(''));
      transcript.scrollTo?.({top:transcript.scrollHeight,behavior:'instant'});
    }
  }
  async function refreshPopup(){
    const popup=document.querySelector('#adminChatPopup'),transcript=document.querySelector('#adminChatPopupMessages');
    if(!popup||popup.hidden||!transcript)return;
    const sequence=++popupSequence;
    transcript.innerHTML='<p class="admin-chat-popup-empty" role="status">Loading your Admin conversation…</p>';
    try{
      const response=await load();
      if(sequence!==popupSequence||popup.hidden)return;
      const messages=Array.isArray(response.messages)?response.messages:[];
      transcript.innerHTML=(messages.length?messages.map(m=>
        '<article class="admin-chat-popup-message '+(m.role==='assistant'?'agent':'human')+'"><strong>'+
        (m.role==='assistant'?'Stonefellow Admin Agent':'You')+'</strong><p>'+esc(m.content||'')+'</p></article>'
      ).join(''):'')+localPlayerMarkup();
      if(!transcript.innerHTML)transcript.innerHTML='<p class="admin-chat-popup-empty">Your Admin conversation will appear here. Use the bar below to ask your Agent.</p>';
      if(typeof document.createElement==='function'&&typeof transcript.appendChild==='function'){
        const proof=document.createElement('section');proof.className='admin-proof-inline';proof.id='adminChatPopupProof';
        proof.innerHTML='<h3>Checking saved work…</h3>';transcript.appendChild(proof);
        void loadProofsInto(proof,3);
      }
      transcript.scrollTo?.({top:transcript.scrollHeight,behavior:'instant'});
      const state=document.querySelector('#adminChatPopupState');if(state)state.textContent='PRIVATE CONVERSATION';
    }catch(error){
      if(sequence===popupSequence&&!popup.hidden){
        transcript.innerHTML='<p class="admin-chat-popup-empty" role="alert">Conversation unavailable. Your saved messages have not been changed.</p>';
        const state=document.querySelector('#adminChatPopupState');if(state)state.textContent='CONNECTION UNAVAILABLE';
      }
    }
  }
  function togglePopup(force){
    const popup=document.querySelector('#adminChatPopup');if(!popup)return;
    const show=typeof force==='boolean'?force:popup.hidden;
    popup.hidden=!show;
    document.querySelector('#adminApp')?.classList.toggle('admin-chat-popup-open',show);
    for(const id of ['adminChatPopupToggle','openAdminAgentChat']){
      const button=document.getElementById(id);if(button)button.setAttribute('aria-expanded',String(show));
    }
    if(show)void refreshPopup();else popupSequence++;
  }
  function newThread(){
    conversationId='';sessionStorage.removeItem('stonefellow.admin.agent.thread');
    localPlayerTurns=[];
    if(isPopupOpen()){
      popupSequence++;
      const transcript=document.querySelector('#adminChatPopupMessages');
      if(transcript)transcript.innerHTML='<p class="admin-chat-popup-empty">New conversation. Type or speak below to begin.</p>';
      const state=document.querySelector('#adminChatPopupState');if(state)state.textContent='NEW CHAT';
    }
    else if(ctx()?.app?.view==='agent-chat')void render().catch(e=>ctx()?.say?.(e.message));
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
  function directorHtml(report,matches){
    if(!report||!Array.isArray(report.gaps))return '';
    return '<section class="admin-agent-calendar" aria-label="AI Director evidence-based recommendations">'+
      '<strong>AI Director · coverage recommendations</strong>'+
      '<p class="hint">Suggestions derived from editorially approved asset counts. No footage frames were inspected, no AI model was invoked and no shot was automatically assigned.</p>'+
      '<p>'+Number(report.pending_review||0)+' pending submissions · '+Number(report.needs_likeness_or_release_review||0)+' need external AI rights review</p>'+
      (report.gaps.length?report.gaps.map(g=>
        '<div class="admin-agent-calendar-task"><div><strong>'+esc(g.title)+'</strong>'+
        '<p>'+Number(g.approved_assets)+' / '+Number(g.recommended_minimum)+' '+esc(g.shot_type)+' assets · target '+esc(g.target_role)+'</p>'+
        '<p>'+esc(g.brief)+'</p><p class="hint">'+esc(g.reason)+
        (g.draft_request_id?' · Existing draft #'+Number(g.draft_request_id):
          ' · Draft not created')+'</p>'+
        (!g.draft_request_id?'<button type="button" class="secondary" data-agent-create-shot-draft="'+esc(g.code)+'">Create private shot-request draft</button>':'')+
        '</div></div>'
      ).join(''):'<p>All minimum editorial coverage targets are currently satisfied.</p>')+
      '<p class="hint">Human review is required before opening or assigning any shot request. Draft creation is private and cannot publish.</p>'+
      (matches&&Array.isArray(matches.matches)?
        '<h4>Reviewed scene matches'+(matches.query?' · '+esc(matches.query):'')+'</h4>'+
        '<p class="hint">Literal matching against verified scene metadata, not automated image interpretation.</p>'+
        (matches.matches.length?matches.matches.map(m=>
          '<div class="admin-agent-calendar-task"><div><strong>Scene #'+Number(m.scene_id)+' · '+esc(m.capture_title)+'</strong>'+
          '<p>Clip '+(Number(m.start_ms)/1000).toFixed(2)+'–'+(Number(m.end_ms)/1000).toFixed(2)+'s · '+
          Number(m.match_count)+' term matches · '+esc(m.location||'Location unspecified')+'</p>'+
          '<p>'+esc(m.description)+'</p><p class="hint">Publication: '+esc(m.publication_clearance)+
          ' · people release: '+esc(m.people_release_status)+'</p></div></div>').join(''):
          '<p>No verified scene matches for this topic.</p>'):'')+'</section>';
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
        ' · publishing: '+esc(s.publication_clearance)+'</p>'+
        (s.media_category==='video'?
          '<div class="admin-agent-footage-preview"><video controls playsinline preload="none" aria-label="Verified scene clip #'+Number(s.scene_id)+'"'+
          ' data-agent-scene-start="'+(Number(s.start_ms)/1000).toFixed(3)+'" data-agent-scene-end="'+(Number(s.end_ms)/1000).toFixed(3)+'"'+
          ' src="api/story-footage-preview.php?submission_id='+Number(s.submission_id)+'"></video></div>':
          s.media_category==='image'?
          '<div class="admin-agent-footage-preview"><img loading="lazy" alt="Verified still scene" src="api/story-footage-preview.php?submission_id='+Number(s.submission_id)+'"></div>':'')+
        '</div></div>').join(''):
        '<p>No verified scene annotations are available.</p>')+'</section>';
  }
  function timelineInsertHtml(preview){
    if(!preview)return '';
    const topic=String(preview.topic||'').trim(),assembly=preview.assembly;
    if(preview.requires_selection){
      const projects=Array.isArray(preview.assemblies)?preview.assemblies:[];
      return '<section class="admin-agent-calendar" aria-label="Choose the editorial assembly for a verified shot">'+
        '<strong>Insert verified footage · select an assembly</strong>'+
        '<p class="hint">Nothing has changed. Choose the draft project to inspect eligible footage and placement.</p>'+
        (projects.length?projects.map(a=>'<div class="admin-agent-calendar-task"><div>'+
          '<strong>Assembly #'+Number(a.id)+' · '+esc(a.title)+'</strong>'+
          '<p>Revision '+Number(a.revision)+' · '+Number(a.item_count)+' timeline items</p>'+
          '<button type="button" class="secondary" data-agent-insert-assembly="'+Number(a.id)+'"'+
          ' data-insert-topic="'+esc(topic)+'" data-insert-placement="'+esc(preview.placement||'')+'"'+
          ' data-insert-slot="'+(Number.isInteger(preview.slot)?Number(preview.slot):'')+'">Use this assembly</button>'+
          '</div></div>').join(''):'<p>No editable draft assemblies found. Create one in the existing story editor.</p>')+
        '</section>';
    }
    if(!assembly||!Array.isArray(preview.matches))return '';
    const id=Number(assembly.id),count=Number(assembly.item_count),revision=Number(assembly.revision);
    const selected=Number.isInteger(preview.slot)?preview.slot:null;
    const options=Array.from({length:Math.max(0,Math.min(51,count+1))},(_,i)=>
      '<option value="'+i+'"'+(selected===i?' selected':'')+'>'+
      (i===0?'At beginning':'After clip #'+i)+'</option>').join('');
    const matches=preview.matches;
    return '<section class="admin-agent-calendar admin-agent-timeline-insert" aria-label="Review verified footage insertion">'+
      '<strong>Proposed insertion · '+esc(assembly.title)+' · revision '+revision+'</strong>'+
      '<p class="hint">Choose a source and exact slot. Editorial rights are separate from publication clearance. This preview makes no edits.</p>'+
      (preview.position_warning?'<p class="hint">'+esc(preview.position_warning)+'</p>':'')+
      '<label>Position<select data-agent-insert-position>'+
      (selected===null?'<option value="" selected>Choose insertion position</option>':'')+options+'</select></label>'+
      '<label>Editor review evidence<textarea rows="2" maxlength="400" data-agent-insert-basis placeholder="Why does this verified scene belong here? 25+ characters."></textarea></label>'+
      (matches.length?matches.map(item=>'<div class="admin-agent-calendar-task"><div>'+
        '<strong>Verified scene #'+Number(item.source_id)+' · '+esc(item.label)+'</strong>'+
        '<p>Submission #'+Number(item.submission_id)+' · '+(Number(item.in_ms)/1000).toFixed(2)+'–'+
          (Number(item.out_ms)/1000).toFixed(2)+'s · '+esc(item.category)+'</p>'+
        '<p class="hint">Publication clearance: '+esc(item.publication_clearance)+
          ' · metadata matches: '+Number(item.term_matches)+'</p>'+
        (item.category==='video'?
          '<details><summary>Preview source footage</summary><div class="admin-agent-footage-preview"><video controls playsinline preload="none" src="api/story-footage-preview.php?submission_id='+
            Number(item.submission_id)+'"></video></div></details>':'')+
        '<button type="button" class="secondary" data-agent-insert-source="'+Number(item.source_id)+'"'+
        ' data-assembly-id="'+id+'" data-revision="'+revision+'" data-source-label="'+esc(item.label)+'">Review and insert</button>'+
        '</div></div>').join(''):
        '<p>No new verified scenes match this request. Approve the contribution and scene evidence in the review workspace, or try another topic.</p>')+
      '<p class="hint">After confirmation the entire source-bound timeline is validated again. Existing clips keep their order and trims; no rendering or publishing.</p></section>';
  }
  function timelineMediaHtml(preview){
    if(!preview)return '';
    const kind=preview.kind==='transcript'?'transcript':'track',name=kind==='track'?'Stonefellow music':'verified dialogue';
    const topic=String(preview.topic||'').trim();
    if(preview.requires_selection){
      const projects=Array.isArray(preview.assemblies)?preview.assemblies:[];
      return '<section class="admin-agent-calendar" aria-label="Choose music or dialogue assembly">'+
        '<strong>Place '+esc(name)+' · choose a production</strong>'+
        (projects.length?projects.map(a=>'<div class="admin-agent-calendar-task">'+
          '<strong>Assembly #'+Number(a.id)+' · '+esc(a.title)+'</strong>'+
          '<button type="button" class="secondary" data-agent-media-choose="'+Number(a.id)+'"'+
          ' data-media-kind="'+kind+'" data-media-topic="'+esc(topic)+'"'+
          ' data-media-placement="'+esc(preview.placement||'')+'"'+
          ' data-media-slot="'+(Number.isInteger(preview.slot)?Number(preview.slot):'')+'">Review placement</button>'+
          '</div>').join(''):'<p>No editable draft assemblies found.</p>')+'</section>';
    }
    const a=preview.assembly,matches=Array.isArray(preview.matches)?preview.matches:[];
    if(!a)return '';
    const id=Number(a.id),revision=Number(a.revision),count=Number(a.item_count);
    const selected=Number.isInteger(preview.slot)?preview.slot:null;
    const choices=Array.from({length:Math.max(0,Math.min(count+1,51))},(_,i)=>
      '<option value="'+i+'"'+(selected===i?' selected':'')+'>'+
      (i===0?'At beginning':'After clip #'+i)+'</option>').join('');
    return '<section class="admin-agent-calendar admin-agent-media-placement" aria-label="Review original music or verified dialogue">'+
      '<strong>Place '+esc(name)+' · '+esc(a.title)+' · revision '+revision+'</strong>'+
      '<p class="hint">'+(kind==='track'?
        'Original Stonefellow music: sync and master-use approved automatically under standing artist authorization. Explicit rights exceptions are excluded.':
        'Dialogue must be independently editorially verified; contributor publication clearance remains separate.')+
        ' This preview makes no timeline changes.</p>'+
      (preview.position_warning?'<p class="hint">'+esc(preview.position_warning)+'</p>':'')+
      '<label>Sequence position<select data-agent-media-position>'+
      (selected===null?'<option value="" selected>Choose exact position</option>':'')+choices+'</select></label>'+
      '<label>Editor placement reason<textarea rows="2" maxlength="400" data-agent-media-basis placeholder="Why should this cue be placed here? At least 25 characters."></textarea></label>'+
      (matches.length?matches.map(item=>
        '<div class="admin-agent-calendar-task"><strong>'+esc(item.label)+'</strong>'+
        '<p>Source '+esc(item.source_id)+' · '+(Number(item.duration_ms)/1000).toFixed(2)+'s'+
        (kind==='track'?' · artist music approval: automatic':
          ' · publication: '+esc(item.publication_clearance))+'</p>'+
        '<button type="button" class="secondary" data-agent-media-source="'+esc(item.source_id)+'"'+
        ' data-media-kind="'+kind+'" data-assembly-id="'+id+'" data-revision="'+revision+
        '">Review placement</button></div>').join(''):
        '<p>No matching approved '+esc(name)+' sources. Check the existing catalog or verified transcripts.</p>')+
      '<p class="hint">Original files stay unchanged. Publication, contribution rights and rendering certification remain separate.</p></section>';
  }
  function timelineEditHtml(preview){
    if(!preview)return '';
    const op=preview.operation==='replace'?'replace':'remove',target=preview.index;
    const topic=String(preview.topic||'').trim();
    const command=(id,index)=>op+' clip '+(index===null?'':'#'+(index+1)+' ')+
      'in assembly #'+id+(op==='replace'&&topic?' with '+topic+' footage':'');
    if(preview.requires_selection){
      const projects=Array.isArray(preview.assemblies)?preview.assemblies:[];
      return '<section class="admin-agent-calendar admin-agent-timeline-edit" aria-label="Choose editorial assembly">'+
        '<strong>Review clip '+op+' · select an assembly</strong>'+
        '<p class="hint">No editing has occurred. Select a draft assembly to review the current sequence.</p>'+
        (projects.length?projects.map(a=>'<div class="admin-agent-calendar-task">'+
          '<strong>Assembly #'+Number(a.id)+' · '+esc(a.title)+'</strong>'+
          '<p>Revision '+Number(a.revision)+' · '+Number(a.item_count)+' items</p>'+
          '<button type="button" class="secondary" data-agent-edit-command="'+
          esc(command(Number(a.id),target))+'">Review this assembly</button></div>').join(''):
          '<p>No editable assemblies found.</p>')+'</section>';
    }
    const a=preview.assembly,items=Array.isArray(preview.items)?preview.items:[];
    if(!a)return '';
    const id=Number(a.id),revision=Number(a.revision);
    const head='<strong>Assembly #'+id+' · '+esc(a.title)+' · revision '+revision+'</strong>'+
      '<p class="hint">Private editorial change only. Source media files remain intact. Rights for publication remain separate.</p>';
    if(!Number.isInteger(target)||target<0||target>=items.length){
      return '<section class="admin-agent-calendar admin-agent-timeline-edit" aria-label="Select exact clip to '+op+'">'+head+
        '<p>Choose the exact current clip to '+op+'. No changes have been applied.</p>'+
        (items.length?items.map((item,i)=>'<div class="admin-agent-calendar-task">'+
          '<strong>#'+(i+1)+' · '+esc(item.kind)+' · source '+esc(item.source_id)+'</strong>'+
          '<p>'+esc(item.notes||'')+'</p>'+
          ((op==='remove'||item.kind==='scene')?
            '<button type="button" class="secondary" data-agent-edit-command="'+
              esc(command(id,i))+'">Review '+op+'</button>':
            '<p class="hint">Only verified scenes can replace other scenes in this workflow.</p>')+
          '</div>').join(''):'<p>The assembly contains no clips to change.</p>')+'</section>';
    }
    const old=items[target],matches=Array.isArray(preview.matches)?preview.matches:[];
    return '<section class="admin-agent-calendar admin-agent-timeline-edit" aria-label="Confirm private clip '+op+'">'+head+
      '<p><strong>Clip #'+(target+1)+'</strong> · '+esc(old.kind)+' · source '+esc(old.source_id)+
      ' · '+(Number(old.in_ms)/1000).toFixed(2)+'–'+(Number(old.out_ms)/1000).toFixed(2)+'s</p>'+
      '<p>'+esc(old.notes||'')+'</p>'+
      '<label>Human editorial justification<textarea rows="2" maxlength="400" data-agent-edit-basis placeholder="Explain why this exact clip should change (at least 25 characters)."></textarea></label>'+
      (op==='remove'?
        '<button type="button" class="secondary" data-agent-edit-confirm="remove" data-assembly-id="'+id+
          '" data-revision="'+revision+'" data-index="'+target+'" data-old-source="'+esc(old.source_id)+'">Review removal</button>':
        old.kind!=='scene'?'<p>Replacement is limited to verified scene-to-scene swaps.</p>':
        matches.length?'<strong>Choose a verified replacement</strong>'+
          matches.map(m=>'<div class="admin-agent-calendar-task"><div>'+
          '<strong>Scene #'+Number(m.source_id)+' · '+esc(m.label)+'</strong>'+
          '<p>Submission #'+Number(m.submission_id)+' · '+(Number(m.in_ms)/1000).toFixed(2)+
          '–'+(Number(m.out_ms)/1000).toFixed(2)+'s</p>'+
          '<p class="hint">Publication clearance: '+esc(m.publication_clearance)+
          ' · '+Number(m.term_matches)+' search terms matched</p>'+
          (m.category==='video'?'<details><summary>Inspect original video</summary>'+
            '<div class="admin-agent-footage-preview"><video controls playsinline preload="none" src="api/story-footage-preview.php?submission_id='+
            Number(m.submission_id)+'"></video></div></details>':'')+
          '<button type="button" class="secondary" data-agent-edit-confirm="replace"'+
          ' data-assembly-id="'+id+'" data-revision="'+revision+'" data-index="'+target+
          '" data-old-source="'+esc(old.source_id)+'" data-new-source="'+Number(m.source_id)+'">Review replacement</button>'+
          '</div></div>').join(''):'<p>No eligible replacement scenes found. Search another topic or verify new submitted footage first.</p>')+
      '<p class="hint">Confirmation checks the original clip, live revision and source validity. Every committed change is written to Team Activity and the revision history.</p></section>';
  }
  function footageIntelligenceHtml(view){
    if(!view)return '';
    const eligible=Array.isArray(view.eligible_captures)?view.eligible_captures:[];
    const jobs=Array.isArray(view.jobs)?view.jobs:[];
    return '<section class="admin-agent-calendar admin-agent-footage-intelligence" aria-label="AI footage evidence and retake review">'+
      '<strong>AI Director · visual footage intelligence</strong>'+
      '<p class="hint">Source-bound video intelligence uses three sampled frames, not a continuous video analysis. Audio jobs provide transcription, not unverified sound classification. External AI permission must be reviewed independently.</p>'+
      '<p>Provider execution: '+(view.enabled?'enabled':'disabled')+' · '+
      eligible.length+' consented captures shown · '+jobs.length+' job records</p>'+
      (!view.enabled?'<button type="button" class="secondary" data-agent-footage-enable>Review AI footage opt-in</button>':'')+
      (eligible.length?'<details open><summary>Eligible private capture sources</summary>'+
        eligible.map(c=>'<div class="admin-agent-calendar-task"><strong>#'+Number(c.submission_id)+
          ' · '+esc(c.title)+'</strong><p>'+esc(c.category)+' · '+esc(c.task_type)+'</p>'+
          '<button type="button" class="secondary" data-agent-footage-enqueue="'+Number(c.submission_id)+
          '" data-task-type="'+esc(c.task_type)+'">Request '+(c.task_type==='scene'?'visual':'audio transcript')+
          ' analysis</button></div>').join('')+'</details>':
        '<p class="hint">No independently cleared visual or audio sources. Review contributions, immutable source bytes and external-AI rights first.</p>')+
      '<strong>Supervised jobs and editorial results</strong>'+
      (jobs.length?jobs.map(j=>
        '<div class="admin-agent-calendar-task admin-agent-footage-analysis">'+
        '<strong>Job #'+Number(j.job_id)+' · '+esc(j.capture_title)+'</strong>'+
        '<p>'+esc(j.task_type)+' · worker '+esc(j.status)+' · human review '+esc(j.review_status)+
        ' · external AI '+esc(j.external_ai_clearance)+'</p>'+
        (j.description?'<p>'+esc(j.description)+'</p>':'')+
        (j.text_summary?'<p>Transcribed audio (whole-clip only): '+esc(j.text_summary)+'</p>':'')+
        (j.frames||[]).map((v,i)=>'<div class="admin-agent-calendar-task"><strong>Sample #'+(i+1)+
          ' · '+(Number(v.sampled_frame_ms)/1000).toFixed(2)+'s</strong>'+
          '<p>'+esc(v.description)+'</p><p class="hint">Candidate usable: '+
          (v.usable_suggestion?'suggested yes':'review needed / suggested no')+'</p>'+
          (v.retake_note?'<p>Retake idea: '+esc(v.retake_note)+'</p>':'')+'</div>').join('')+
        (j.shot_recommendations||[]).map(t=>'<p class="hint">Additional shot: '+esc(t)+'</p>').join('')+
        (j.review_status==='pending_review'&&j.external_ai_clearance==='cleared'?
          '<div class="actions"><button type="button" class="secondary" data-agent-footage-decision="approved" data-job-id="'+Number(j.job_id)+'">Approve private analysis draft</button>'+
          '<button type="button" class="secondary" data-agent-footage-decision="rejected" data-job-id="'+Number(j.job_id)+'">Reject draft</button></div>':'')+
        (j.review_status==='approved'&&!j.imported_id?
          '<p class="hint">Approved machine observation. To create a timecoded canonical scene, explicitly import and independently verify in the existing footage review; no automatic promotion.</p>':'')+
        '</div>').join(''):'<p>There are no supervised analyses yet.</p>')+
      '<button type="button" class="secondary" data-agent-footage-refresh>Refresh visual intelligence</button>'+
      '<p class="hint">Queueing never immediately invokes a provider. A separate supervised server worker processes one bounded job; machine drafts are neither identity evidence nor publication approval.</p></section>';
  }
  function directorContinuityHtml(plan,title){
    if(!plan||!Array.isArray(plan.issues)||!Array.isArray(plan.coverage_gaps))return '';
    const music=plan.music_pacing||{},arcs=Array.isArray(plan.character_arcs)?plan.character_arcs:[];
    const issues=plan.issues.slice(0,12),gaps=plan.coverage_gaps.slice(0,12);
    return '<details class="admin-agent-calendar-task" aria-label="Source-grounded continuity review">'+
      '<summary>'+esc(title)+' · '+issues.length+' handoff reviews · '+gaps.length+' coverage suggestions</summary>'+
      '<p class="hint">Verified fictional story links and source-duration estimates only. This is not an inferred real-world identity, measured music tempo, video-quality score or publication clearance.</p>'+
      '<p>Music cues '+Number(music.cue_count||0)+' · approximate cue duration '+
        (Number(music.cue_duration_ms||0)/1000).toFixed(1)+'s · estimated sequence share '+
        (100*Number(music.estimated_music_fraction||0)).toFixed(1)+'%'+
        ' · music/dialogue adjacencies '+Number(music.dialogue_adjacent_to_music||0)+'</p>'+
      (arcs.length?'<strong>Verified character-story appearances</strong>'+
        arcs.slice(0,10).map(a=>'<p>'+esc(a.label)+' · '+Number(a.appearance_count)+
          ' linked clips · first #'+Number(a.first_clip)+' · last #'+Number(a.last_clip)+
          ' · '+esc(a.arc_status)+'</p>').join(''):'<p class="hint">No editor-verified character links are available.</p>')+
      (issues.length?'<strong>Continuity handoffs to review</strong>'+
        issues.map(i=>'<p>Clip '+Number(i.position||0)+' · '+esc(i.message)+'</p>').join(''):
        '<p class="hint">No specific story handoff flagged by these deterministic rules.</p>')+
      (gaps.length?'<strong>Missing-coverage ideas</strong>'+
        gaps.map(g=>'<p>'+esc(g.message)+' · suggested role '+esc(g.target_role||'editor')+'</p>').join(''):'')+
      '<p class="hint">Review in Agent Chat before authorizing any sequence changes. Music licensing for eligible original Stonefellow tracks follows standing authorization, with explicit exceptions honored.</p>'+
      '</details>';
  }
  function aiDirectorSequenceHtml(view){
    if(!view)return '';
    if(view.requires_selection){
      const drafts=Array.isArray(view.assemblies)?view.assemblies:[];
      return '<section class="admin-agent-calendar" aria-label="Choose private AI Director production">'+
        '<strong>AI Director · select a draft film</strong>'+
        '<p class="hint">All suggestions remain private until an editor separately reviews and applies them.</p>'+
        (drafts.length?drafts.map(a=>'<div class="admin-agent-calendar-task"><strong>Assembly #'+
          Number(a.id)+' · '+esc(a.title)+'</strong><p>Rev '+Number(a.revision)+
          ' · '+Number(a.item_count)+' cues</p>'+
          '<button type="button" class="secondary" data-agent-director-open="'+Number(a.id)+
          '">Review AI sequence</button></div>').join(''):
          '<p>There are no editable draft assemblies.</p>')+'</section>';
    }
    const a=view.assembly;if(!a)return '';
    const id=Number(a.id),revision=Number(a.revision);
    const proposals=Array.isArray(view.proposals)?view.proposals:[];
    const sceneEvidence=Array.isArray(view.scene_recommendations)?view.scene_recommendations:[];
    const sceneSources=view.source_understanding&&typeof view.source_understanding==='object'?view.source_understanding:{};
    const verifiedStoryLinks=Object.values(sceneSources).reduce((n,v)=>n+
      (Array.isArray(v.story_connections)?v.story_connections.length:0),0);
    return '<section class="admin-agent-calendar admin-agent-director-sequence" aria-label="AI Director private sequence proposals">'+
      '<strong>AI Director · '+esc(a.title)+' · revision '+revision+'</strong>'+
      '<p class="hint">Existing '+Number(view.existing_count)+' verified cues · up to '+
        Number(view.candidate_count)+' additional eligible sources. No invented footage or unapproved media.</p>'+
      '<p class="hint">Scene understanding: '+verifiedStoryLinks+
        ' editor-verified story links · '+sceneEvidence.length+
        ' source-grounded editorial suggestions. Machine visual samples are observational, not verified people or release evidence.</p>'+
      (sceneEvidence.length?'<details><summary>Review linked scene, character-story and music evidence</summary>'+
        sceneEvidence.slice(0,14).map(e=>'<div class="admin-agent-calendar-task">'+
          '<strong>'+esc(e.source_key)+' · '+esc(e.kind)+'</strong>'+
          '<p>'+esc(e.suggestion)+'</p><p class="hint">'+Number(e.evidence_count)+
          ' grounded evidence item(s) · Human editorial review required</p>'+
          '</div>').join('')+'</details>':
        '<p class="hint">No verified story-graph or reviewed three-frame observations are linked to the eligible sources yet.</p>')+
      directorContinuityHtml(view.continuity_current,'Current story and soundtrack continuity')+
      '<p class="hint">Model access: '+(view.enabled?'enabled':'disabled')+
        ' · external-AI evidence: '+(view.eligible_for_external_ai?'eligible':'not yet cleared')+'</p>'+
      (!view.eligible_for_external_ai?'<p class="hint">'+esc(view.eligibility_note||'')+'</p>':'')+
      (!view.enabled?
        '<button type="button" class="secondary" data-agent-director-enable>Review AI Composer opt-in</button>':
        view.eligible_for_external_ai?
        '<button type="button" class="secondary" data-agent-director-queue data-assembly-id="'+id+
          '" data-revision="'+revision+'">Request a supervised AI sequence</button>':'')+
      '<p class="hint">Generating an AI proposal is a separate supervised server-side worker action. Queued means awaiting worker execution, not a completed AI result. Your Admin provider keys stay server-side.</p>'+
      (proposals.length?proposals.map(p=>{
        const status=String(p.status||''),current=p.is_current===true;
        const raw=Array.isArray(p.source_keys)?p.source_keys:[];
        const beats=Array.isArray(p.beats)?p.beats:[];
        const gaps=Array.isArray(p.missing_shots)?p.missing_shots:[];
        return '<div class="admin-agent-calendar-task admin-agent-director-proposal">'+
          '<strong>Proposal #'+Number(p.id)+' · '+esc(status)+' · revision '+Number(p.revision)+'</strong>'+
          '<p>Provider: '+esc(p.provider||'awaiting execution')+
            ' · '+Number(p.proposed_additions||0)+' verified additions</p>'+
          (p.logline?'<p>'+esc(p.logline)+'</p>':'')+
          directorContinuityHtml(p.continuity_preview,'Proposed sequence continuity')+
          (beats.length?'<details><summary>Inspect '+beats.length+' proposed editorial beats</summary>'+
            beats.map((b,i)=>'<div class="admin-agent-calendar-task"><strong>#'+(i+1)+
              ' · '+esc(b.transition)+'</strong>'+
              '<p class="hint">Verified source '+esc(raw[i]||String(b.source_index))+'</p>'+
              '<p>'+esc(b.reason)+'</p></div>').join('')+
            '</details>':'')+
          (gaps.length?'<details><summary>Suggested missing coverage</summary>'+
            gaps.map(g=>'<p>'+esc(typeof g==='string'?g:String(g.brief||''))+'</p>').join('')+
            '</details>':'')+
          (current&&status==='pending_review'?
            '<label>Human editorial decision basis<textarea data-agent-director-basis rows="2" maxlength="400" placeholder="Record 25+ characters about the proposed sources, order and story."></textarea></label>'+
            '<div class="actions"><button type="button" class="secondary" data-agent-director-review="approved" data-proposal-id="'+Number(p.id)+'" data-assembly-id="'+id+'" data-revision="'+revision+'">Approve proposal</button>'+
            '<button type="button" class="secondary" data-agent-director-review="rejected" data-proposal-id="'+Number(p.id)+'" data-assembly-id="'+id+'" data-revision="'+revision+'">Reject proposal</button></div>':'')+
          (current&&status==='approved'?
            '<p class="hint">Human-reviewed proposal. A second, explicit confirmation is required to change the private timeline.</p>'+
            '<button type="button" class="secondary" data-agent-director-apply data-proposal-id="'+Number(p.id)+'" data-assembly-id="'+id+'" data-revision="'+revision+'">Review and apply sequence</button>':'')+
          (!current?'<p class="hint">Previous revision — cannot be applied.</p>':'')+
          '</div>';
      }).join(''):'<p>No AI sequence proposals for this production revision yet.</p>')+
      '<button type="button" class="secondary" data-agent-director-open="'+id+'">Refresh proposal status</button>'+
      '<p class="hint">The AI may only propose known clip IDs and time-bound transitions. Recording, rendering, licenses and publication remain separate approvals.</p>'+
      '</section>';
  }
  function productionQualityHtml(report){
    if(!report)return '';
    if(report.requires_selection){
      const projects=Array.isArray(report.assemblies)?report.assemblies:[];
      return '<section class="admin-agent-calendar" aria-label="Select private production to certify">'+
        '<strong>Production certification · choose an assembly</strong>'+
        '<p class="hint">Read-only checks. No media will be changed or published.</p>'+
        (projects.length?projects.map(a=>'<div class="admin-agent-calendar-task">'+
          '<strong>Assembly #'+Number(a.id)+' · '+esc(a.title)+'</strong>'+
          '<p>Revision '+Number(a.revision)+' · '+esc(a.status)+'</p>'+
          '<button type="button" class="secondary" data-agent-quality-open="'+
            esc('audit assembly #'+Number(a.id))+'">Check this production</button></div>').join(''):
          '<p>No private production assemblies are available.</p>')+'</section>';
    }
    const a=report.assembly;
    if(!a)return '';
    const id=Number(a.id),revision=Number(a.revision);
    const gates=Array.isArray(report.gates)?report.gates:[];
    const blockers=Array.isArray(report.blockers)?report.blockers:[];
    const completed=Number(report.passed),total=Number(report.total);
    const job=Number(report.latest_private_job_id||0);
    return '<section class="admin-agent-calendar admin-agent-production-certification" aria-label="Verified production certification">'+
      '<strong>Production integrity · '+esc(a.title)+' · revision '+revision+'</strong>'+
      '<p><strong>'+completed+' / '+total+' current readiness gates passed</strong></p>'+
      '<p class="hint">These are objective release-safety checks, not an AI quality or legal-ownership score. The review is read-only.</p>'+
      gates.map(g=>'<div class="admin-agent-calendar-task">'+
        '<strong>'+(g.passed?'✓':'○')+' '+esc(g.label)+'</strong>'+
        '<p class="hint">'+esc(g.detail)+'</p></div>').join('')+
      (blockers.length?'<details><summary>Open source and rights issues ('+blockers.length+')</summary>'+
        blockers.map(b=>'<p class="hint">'+esc(b.message)+'</p>').join('')+'</details>':'')+
      '<p class="hint">Archived revisions: '+Number(report.history_count||0)+
        ' · Record integrity checked: '+Number((report.verified_history||[]).length)+
        ' most recent archives</p>'+
      (job>0?'<div class="admin-agent-footage-preview">'+
        '<strong>Certified private playback · job #'+job+'</strong>'+
        '<video controls playsinline preload="none" aria-label="Current revision private video" src="api/story-render-review.php?action=stream&amp;job_id='+job+'"></video>'+
        '</div>':
        '<p class="hint">No currently verified private render available. The storyboard is metadata only.</p>')+
      '<div class="actions">'+
        '<button type="button" class="secondary" data-agent-quality-open="'+
           esc('assembly #'+id+' timeline')+'">Review timeline</button>'+
        '<button type="button" class="secondary" data-agent-quality-open="'+
           esc('review transitions for assembly #'+id)+'">Review audio/video bridges</button>'+
        '<button type="button" class="secondary" data-agent-quality-open="'+
           esc('audit assembly #'+id)+'">Refresh checks</button>'+
      '</div>'+
      '<p class="hint">No changes or automatic publication. Only separately authorized, confirmed editor operations can modify this assembly.</p>'+
      '</section>';
  }
  function transitionHtml(review){
    if(!review)return '';
    if(review.requires_selection){
      const rows=Array.isArray(review.assemblies)?review.assemblies:[];
      return '<section class="admin-agent-calendar" aria-label="Choose an assembly for private playback">'+
        '<strong>Transitions and playback · choose a production</strong>'+
        (rows.length?rows.map(a=>'<div class="admin-agent-calendar-task">'+
          '<strong>Assembly #'+Number(a.id)+' · '+esc(a.title)+'</strong>'+
          '<p>Revision '+Number(a.revision)+' · '+esc(a.status)+'</p>'+
          '<button type="button" class="secondary" data-agent-transition-assembly="'+Number(a.id)+'">Review transitions here</button></div>').join(''):
          '<p>No assemblies available for private review.</p>')+'</section>';
    }
    const a=review.assembly;
    if(!a)return '';
    const id=Number(a.id),rev=Number(a.revision);
    const cards=Array.isArray(review.cards)?review.cards:[];
    const edl=review.edl||{},issues=Array.isArray(review.blockers)?review.blockers:[];
    const cross=review.transition||null;
    const preferredStyle=cross?.style||'fade',preferredOverlap=Number(cross?.overlap_ms||250);
    const options=(style)=>'<option value="fade"'+(style==='fade'?' selected':'')+
      '>Audio/video dissolve (audio bridge)</option>'+
      '<option value="wipeleft"'+(style==='wipeleft'?' selected':'')+'>Video wipe with audio crossfade</option>';
    const overlapOptions=(value)=>[250,500].map(n=>
      '<option value="'+n+'"'+(Number(value)===n?' selected':'')+'>'+n+' ms</option>').join('');
    const inputs='<label>Reviewed editorial reason<textarea rows="2" maxlength="400" data-agent-transition-basis placeholder="Document why this cut timing supports the production (25+ characters)."></textarea></label>';
    const settings=a.status==='draft'?
      '<section><strong>Revision-locked audio/video crossfade</strong>'+
      '<p class="hint">Pilot: 2–6 clips, up to 30 seconds, all original sources and music masters independently certified. Preview settings are not a render.</p>'+
      inputs+
      '<div class="actions"><label>Transition style<select data-agent-transition-style>'+options(preferredStyle)+'</select></label>'+
      '<label>Shared overlap<select data-agent-transition-overlap>'+overlapOptions(preferredOverlap)+'</select></label></div>'+
      '<button type="button" class="secondary" data-agent-transition-global data-assembly-id="'+id+'" data-revision="'+rev+'">Review and save crossfade</button>'+
      (cross&&Number(review.cut_count)>0?
        '<details><summary>Customize each video/audio bridge</summary>'+
        (Array.isArray(review.cuts)?review.cuts:[]).map((cut,i)=>
          '<div class="admin-agent-calendar-task" data-agent-transition-cut="'+i+'">'+
          '<strong>Boundary '+(i+1)+' → '+(i+2)+'</strong>'+
          '<div class="actions"><label>Style<select data-agent-cut-style>'+options(cut.style)+'</select></label>'+
          '<label>Overlap<select data-agent-cut-overlap>'+overlapOptions(cut.overlap_ms)+'</select></label></div></div>').join('')+
        '<button type="button" class="secondary" data-agent-transition-cuts data-assembly-id="'+id+'" data-revision="'+rev+'">Confirm individual audio/video bridges</button>'+
        '</details>':'<p class="hint">Save a shared crossfade first to unlock per-cut timing.</p>')+
      '</section>':'<p>Assembly is not an editable draft.</p>';
    const reviewVideos=Array.isArray(review.render_reviews)?review.render_reviews:[];
    return '<section class="admin-agent-calendar admin-agent-transition-review" aria-label="Private transition and playback review">'+
      '<strong>Storyboard / soundtrack · '+esc(a.title)+' · revision '+rev+'</strong>'+
      '<p class="hint">Metadata-only storyboard, never an invented video preview. Timings are source-derived.</p>'+
      (cards.length?cards.map((c,i)=>'<div class="admin-agent-calendar-task">'+
        '<strong>#'+(i+1)+' '+esc(c.kind)+' · '+esc(c.label)+'</strong>'+
        '<p>Story time '+(Number(c.start_ms)/1000).toFixed(2)+'–'+
        (Number(c.end_ms)/1000).toFixed(2)+'s · '+(c.source_verified?'verified':'not verified')+'</p>'+
        '<p class="hint">Publication: '+esc(c.publication_clearance)+
        (c.kind==='track'?' · sync: '+esc(c.sync_clearance)+' · master: '+esc(c.master_clearance):'')+
        '</p></div>').join(''):'<p>No storyboard cues recorded.</p>')+
      '<p><strong>Current cut plan:</strong> '+Number(review.cut_count)+' boundaries'+
        (cross?' · '+esc(cross.style)+' / '+Number(cross.overlap_ms)+'ms':' · hard cuts / no saved crossfade')+'</p>'+
      (edl.available?'<p class="hint">EDL output duration: '+(Number(edl.output_total_ms)/1000).toFixed(2)+'s · '+Number((edl.dialogue_duck_windows||[]).length)+' dialogue duck windows.</p>':
        '<p class="hint">No certified EDL projection available for this sequence.</p>')+
      (edl.dialogue_duck_windows||[]).slice(0,12).map(w=>
        '<p class="hint">Audio duck: '+(Number(w.start_ms)/1000).toFixed(2)+'–'+
        (Number(w.end_ms)/1000).toFixed(2)+'s</p>').join('')+
      (issues.length?'<details><summary>'+issues.length+' certification blockers</summary>'+
         issues.map(b=>'<p class="hint">'+esc(b.message)+'</p>').join('')+'</details>':'')+
      settings+
      '<section><strong>Private rendered playback</strong>'+
      (reviewVideos.length?reviewVideos.map(v=>'<div class="admin-agent-calendar-task">'+
        '<p>Certified render #'+Number(v.job_id)+' · '+esc(v.status)+' · revision '+Number(v.revision)+'</p>'+
        '<div class="admin-agent-footage-preview"><video controls playsinline preload="none" '+
          'aria-label="Verified private review video" src="api/story-render-review.php?action=stream&amp;job_id='+
          Number(v.job_id)+'"></video></div></div>').join(''):
        '<p class="hint">No verified private render for this revision. The storyboard above is a cue preview, not video playback.</p>')+
      '</section><p class="hint">No edits, renders, music transfers or publication were performed by this preview.</p>'+
      '</section>';
  }
  function timelineHtml(action){
    const t=action.timeline_inspection,list=action.timeline_assemblies;
    if(t&&Array.isArray(t.items)){
      return '<section class="admin-agent-calendar" aria-label="Governed editorial timeline">'+
        '<strong>Assembly #'+Number(t.id)+' · '+esc(t.title)+' · revision '+Number(t.revision)+'</strong>'+
        '<p class="hint">Private editorial plan. Exact clip reorder requires human confirmation and revision verification; no media export, render or publication.</p>'+
        (t.status==='draft'?
          '<label>Human editorial reason<textarea data-agent-timeline-basis="'+Number(t.id)+'" rows="2" maxlength="400" placeholder="Why should the sequence change? At least 25 characters."></textarea></label>':'')+
        t.items.map((i,index)=>'<div class="admin-agent-calendar-task"><div><strong>#'+(index+1)+' · '+esc(i.kind)+'</strong>'+
          '<p>Source '+esc(i.source_id)+' · '+(Number(i.in_ms)/1000).toFixed(2)+'–'+
          (Number(i.out_ms)/1000).toFixed(2)+'s</p><p>'+esc(i.notes||'')+'</p>'+
          (t.status==='draft'?
            '<div class="actions">'+
            (index>0?'<button type="button" class="secondary" data-agent-timeline-move="up" data-assembly-id="'+Number(t.id)+'" data-revision="'+Number(t.revision)+'" data-index="'+index+'">Move earlier</button>':'')+
            (index<t.items.length-1?'<button type="button" class="secondary" data-agent-timeline-move="down" data-assembly-id="'+Number(t.id)+'" data-revision="'+Number(t.revision)+'" data-index="'+index+'">Move later</button>':'')+
            '</div>':'')+
          (t.status==='draft'&&(i.kind!=='scene'||Number(i.out_ms)>Number(i.in_ms))?
            '<details class="admin-agent-timeline-trim"><summary>Adjust clip source range</summary>'+
            '<div class="actions"><label>In (ms)<input type="number" step="1" min="0" max="86400000" data-agent-trim-in value="'+Number(i.in_ms)+'"></label>'+
            '<label>Out (ms)<input type="number" step="1" min="1" max="86400000" data-agent-trim-out value="'+Number(i.out_ms)+'"></label></div>'+
            '<button type="button" class="secondary" data-agent-timeline-trim data-assembly-id="'+Number(t.id)+'" data-revision="'+Number(t.revision)+'" data-index="'+index+'">Review trim</button>'+
            '<p class="hint">Trim stays within the independently verified original scene or audio range.</p></details>':'')+
          '</div></div>').join('')+
        '<p class="hint">Original source and publication rights require independent review. All changes use the existing revision and audit ledger.</p></section>';
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
    const moduleRoute=action.tool_contract?.tool_id==='admin_module_navigate'&&adminModuleViews.has(action.view);
    if(m.role==='assistant'&&moduleRoute&&action.type==='upload_audio'){
      next='<button type="button" data-agent-song-start class="primary">Guided song upload</button> '+
        '<button type="button" data-admin-agent-action="upload_audio" class="secondary">Choose WAV / MP3 file</button> '+
        '<button type="button" data-admin-agent-action="media" class="secondary">Open Media Library</button>';
    }
    else if(m.role==='assistant'&&action.type==='navigate_admin_page'&&moduleRoute){
      next='<button type="button" data-admin-agent-action="'+esc(action.view)+'" class="secondary">Return to '+esc(adminModuleLabels[action.view])+'</button>';
    }
    else if(m.role==='assistant'&&moduleRoute){
      next='<button type="button" data-admin-agent-action="'+esc(action.view)+'" class="secondary">Open '+esc(adminModuleLabels[action.view]||'Admin workspace')+'</button>';
    }
    else if(m.role==='assistant'&&action.type==='prepare_campaign')next='<button type="button" data-admin-agent-action="campaigns" class="secondary">Prepare exact-review campaign draft</button>';
    else if(m.role==='assistant'&&action.type==='prepare_journey')next='<button type="button" data-admin-agent-action="journey" class="secondary">Prepare exact-review journey draft</button>';
    else if(m.role==='assistant'&&action.type==='production_editorial_status')next='<button type="button" data-agent-show-calendar class="secondary">Show production calendar here</button>';
    else if(m.role==='assistant'&&action.type==='production_schedule_calendar')next=calendarHtml(action.production_calendar);
    else if(m.role==='assistant'&&action.type==='production_footage_review')next=footageHtml(action.footage_queue);
    else if(m.role==='assistant'&&action.type==='production_footage_intelligence')next=footageIntelligenceHtml(action.footage_intelligence);
    else if(m.role==='assistant'&&action.type==='production_director_coverage')next=directorHtml(action.director_coverage,action.director_scene_matches);
    else if(m.role==='assistant'&&action.type==='production_scene_candidates')next=sceneCandidateHtml(action.scene_candidates);
    else if(m.role==='assistant'&&action.type==='production_timeline_inspect')next=timelineHtml(action);
    else if(m.role==='assistant'&&action.type==='production_timeline_transitions')next=transitionHtml(action.transition_preview);
    else if(m.role==='assistant'&&action.type==='production_timeline_certification')next=productionQualityHtml(action.quality_audit);
    else if(m.role==='assistant'&&action.type==='production_ai_director_sequence')next=aiDirectorSequenceHtml(action.ai_director_sequence);
    else if(m.role==='assistant'&&action.type==='production_timeline_insert')next=timelineInsertHtml(action.insert_preview);
    else if(m.role==='assistant'&&action.type==='production_timeline_edit')next=timelineEditHtml(action.edit_preview);
    else if(m.role==='assistant'&&action.type==='production_timeline_media')next=timelineMediaHtml(action.media_preview);
    else if(m.role==='assistant'&&action.type==='review_actions')next='<button type="button" data-admin-agent-action="brain" class="secondary">Inspect action approvals</button>';
    else if(m.role==='assistant'&&action.type==='commerce_report')next='<button type="button" data-admin-agent-action="commerce-ops" class="secondary">Open Commerce Operations</button>';
    else if(m.role==='assistant'&&['ticketing_inventory_read','merch_inventory_read','vinyl_inventory_read','membership_inventory_read'].includes(action.type) && adminModuleViews.has(action.view)){
      next='<button type="button" data-admin-agent-action="'+esc(action.view)+'" class="secondary">Inspect '+esc(adminModuleLabels[action.view]||'Admin records')+'</button>';
    }
    else if(m.role==='assistant'&&action.type==='draft_commerce_reply'){
      next=(action.reply_draft&&Number(m.id)>0?'<button type="button" class="secondary" data-agent-copy-reply="'+Number(m.id)+'">Copy unsent reply draft</button>':'')+
        '<button type="button" data-admin-agent-action="commerce-ops" class="secondary">Review actual order</button>';
    }
    else if(m.role==='assistant'&&action.type==='prepare_commerce_followup'){
      if(action.draft&&action.request_key){
        next='<button type="button" class="primary" data-agent-commerce-review="'+esc(action.request_key)+'">Review follow-up proposal</button>';
      }else next='<button type="button" class="secondary" data-admin-agent-action="commerce-ops">Open Commerce Operations</button>';
    }
    else if(m.role==='assistant'&&action.type==='edit_catalog_metadata'){
      if(action.draft&&action.request_key){
        next='<button type="button" class="primary" data-agent-catalog-review="'+esc(action.request_key)+'">Review and save catalog change</button>';
      }else next='<button type="button" class="secondary" data-admin-agent-action="catalog">Open Catalog</button>';
    }
    else if(m.role==='assistant'&&action.type==='edit_knowledge_note'){
      if(action.draft&&action.request_key){
        next='<button type="button" class="primary" data-agent-knowledge-review="'+esc(action.request_key)+'">Review Knowledge Base note</button>';
      }else next='<button type="button" class="secondary" data-admin-agent-action="knowledge">Open Knowledge Base</button>';
    }
    else if(m.role==='assistant'&&['edit_media_caption','edit_media_alt_text'].includes(action.type)){
      if(action.draft&&action.request_key){
        next='<button type="button" class="primary" data-agent-media-review="'+esc(action.request_key)+'">Review Media Library '+(action.type==='edit_media_alt_text'?'image alt text':'caption')+'</button>';
      }else next='<button type="button" class="secondary" data-admin-agent-action="media">Open Media Library</button>';
    }
    else if(m.role==='assistant'&&action.type==='attach_private_media'){
      if(action.draft&&action.request_key){
        next='<button type="button" class="primary" data-agent-media-link-review="'+esc(action.request_key)+'">Review private attachment</button>';
      }else next='<button type="button" class="secondary" data-admin-agent-action="media">Open Media Library</button>';
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
  const adminModuleLabels={dashboard:'Dashboard',uploads:'Folder Scan & Upload',media:'Media Library',templates:'Metadata Templates',catalog:'Catalog',releases:'Releases',distribution:'Distribution',press:'EPK + Press',shows:'Shows + Live',crm:'Fans + CRM',automations:'Segments + Automations',campaigns:'Campaigns',products:'Merch & Products',payments:'Payments & Stripe',knowledge:'Knowledge Base',orders:'Orders','commerce-ops':'Commerce Operations',fulfillment:'Fulfillment & Care',pod:'POD Handoffs',users:'Users & Tokens',team:'Team Workspace','production-review':'AI Production Review',packages:'Monthly Packages',membership:'Membership + VIP',ticketing:'Tickets + VIP',ai:'AI Providers',brain:'Agent Brain','agent-chat':'Admin Agent Chat',customer:'Customer Lifecycle',analytics:'Performance Intelligence',calendar:'Operating Calendar',rights:'Rights & Licensing',operations:'Operations',settings:'Site Settings'};
  const adminModuleViews=new Set(Object.keys(adminModuleLabels));
  function newSongKey(){
    const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);
    return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
  }
  function songDetailsHtml(){
    if(!songIntake)return '';
    const s=songIntake,asset=s.asset||{},details=s.fields||{};
    let body='';
    if(s.state==='choose')body='<p>Select an MP3 or WAV from this device. The recording stays private.</p>'+
      '<button type="button" class="primary" data-agent-song-choose>Choose audio file</button>';
    else if(s.state==='uploading')body='<p role="status">Uploading and analyzing the recording. Please keep this tab open…</p>';
    else if(['editing','saving','uploading_artwork'].includes(s.state)){
      const inputs=[['title','Song title','text',180],['release','Release / album (optional)','text',180],
        ['genre','Genre (optional)','text',120],['year','Year (optional)','number',4],
        ['words_by','Lyrics by','text',180],['music_by','Music by','text',180],
        ['artist_display','Performing artist','text',180],['master_owner','Master recording rights owner','text',180],
        ['composition_owner','Composition rights owner','text',180],
        ['isrc','ISRC (optional)','text',12],['release_date','Release date (optional)','date',10]];
      body='<p><strong>'+esc(asset.original_name||'Recording')+'</strong> · '+
        Number(asset.duration_seconds||0).toFixed(1)+' seconds · '+esc(asset.codec||'audio')+'</p>'+
        '<p class="hint">Metadata detected where available. Review ownership and artwork before preparing this private catalog package.</p>'+
        '<form data-agent-song-form><div class="admin-agent-song-fields">'+
        inputs.map(([name,label,type,max])=>'<label>'+esc(label)+'<input name="'+name+'" type="'+type+'" '+
          (type==='number'?'min="1900" max="2100"':type==='date'?'':'maxlength="'+max+'"')+
          ' value="'+esc(details[name]||'')+'" '+(name==='title'?'required':'')+'></label>').join('')+
        '</div><div class="admin-agent-song-artwork">'+
          (Number(s.artwork?.id||details.artwork_asset_id||0)>0?
            '<img alt="Private song artwork preview" loading="lazy" src="../api/media.php?id='+Number(s.artwork?.id||details.artwork_asset_id)+'&variant=thumb">':'')+
          '<div><strong>Cover artwork</strong><p class="hint">JPG, PNG, WebP or GIF. Private until later publication.</p>'+
          '<button type="button" class="secondary" data-agent-artwork-choose '+(s.state!=='editing'?'disabled':'')+'>'+
          (Number(s.artwork?.id||details.artwork_asset_id||0)>0?'Replace artwork':'Upload artwork')+'</button>'+
          '<input type="file" data-agent-artwork-file accept="image/jpeg,image/png,image/webp,image/gif" hidden></div></div>'+
        '<label><input name="pod_eligible" type="checkbox" '+(details.pod_eligible?'checked':'')+'> Available for vinyl print (off means this song cannot be selected for vinyl)</label>'+
        '<label><input name="explicit" type="checkbox" '+(details.explicit?'checked':'')+'> Explicit lyrics / content</label>'+
        '<label><input name="rights_confirmed" type="checkbox" '+(details.rights_confirmed?'checked':'')+
          '> I confirm I have authority over this original recording and its composition for catalog preparation.</label>'+
        '<label>Private notes (optional)<textarea name="notes" maxlength="1000" rows="3">'+esc(details.notes||'')+'</textarea></label>'+
        '<div class="actions"><button type="submit" class="primary" '+(s.state!=='editing'?'disabled':'')+'>'+
        (s.expectedHash?'Update private song draft':'Save private song draft')+'</button> '+
        '<button type="button" class="secondary" data-agent-song-cancel '+(s.state==='saving'?'disabled':'')+'>Cancel</button></div></form>';
    }else if(s.state==='saved'){
      body='<p><strong>'+esc(s.draft?.title||'Song')+'</strong> saved as a private draft.</p>'+
        '<p class="hint">Vinyl printing: '+(s.draft?.pod_eligible?'Available (if published)':'Not available')+'</p>'+
        '<p class="hint">Recording #'+Number(s.draft?.asset_id||0)+' is stored. No release, public catalog change, or publication occurred.</p>'+
        '<p><strong>Catalog preflight:</strong> '+Number(s.readiness?.score||0)+'% '+
        (s.readiness?.ready?'· Ready for private handoff':'· Needs review')+'</p>'+
        (s.readiness?.blockers?.length?'<ul>'+s.readiness.blockers.map(x=>'<li>'+esc(x)+'</li>').join('')+'</ul>':'')+
        '<p>Next: review artwork, composition and master rights, then prepare the private catalog handoff.</p>'+
        '<div class="actions"><button type="button" class="primary" data-agent-song-review>Review artwork and rights</button> '+
        '<button type="button" class="secondary" data-agent-song-prepare '+(s.readiness?.ready?'':'disabled')+'>Prepare for catalog</button> '+
        '<button type="button" class="secondary" data-agent-song-start>Upload another song</button></div>';
    }else if(s.state==='prepared'){
      body='<p><strong>'+esc(s.draft?.title||'Song')+'</strong> passed private catalog preflight.</p>'+
        '<p class="hint">Metadata, source recording, artwork and ownership checked. No public track or release was created. Publication requires a separate workflow.</p>'+
        '<div class="actions"><button type="button" class="primary" data-agent-song-publish-review>Review track publication</button> '+
        '<button type="button" class="secondary" data-agent-song-start>Upload another song</button></div>';
    }else if(['publish_review','uploading_preview','publishing'].includes(s.state)){
      const draft=s.draft||{},preview=s.preview||null;
      body='<p><strong>'+esc(draft.title||'Song')+'</strong> · '+esc(draft.artist_display||'Stonefellow')+'</p>'+
        '<p class="hint">The original '+esc(draft.filename||'recording')+' will remain private and never be used for public streaming. '+
        'Upload a separately exported MP3 for public listening, then explicitly confirm publication.</p>'+
        '<p><strong>Cover:</strong> '+(Number(draft.artwork_asset_id||0)>0?'Approved · managed image #'+Number(draft.artwork_asset_id):'Missing')+
        ' · <strong>Rights:</strong> '+(draft.rights_confirmed?'Confirmed by Admin':'Needs Admin review')+'</p>'+
        '<p><strong>Listening preview:</strong> '+(preview?esc(preview.original_name||'MP3')+' · '+Number(preview.duration_seconds||0).toFixed(1)+' seconds':'Not uploaded')+'</p>'+
        '<p class="hint">This publishes one track to the public catalog. It does not create or publish an album, schedule a release, or share the private master.</p>'+
        '<div class="actions"><button type="button" class="secondary" data-agent-song-preview-choose '+(s.state==='uploading_preview'||s.state==='publishing'?'disabled':'')+'>Choose separate MP3 preview</button> '+
        (Number(draft.candidate_preview_asset_id||0)>0?'<button type="button" class="secondary" data-agent-song-preview-scanned '+(s.state!=='publish_review'?'disabled':'')+'>Use scanned MP3 candidate</button> ':'')+
        '<button type="button" class="primary" data-agent-song-confirm-publish '+(!preview||s.state!=='publish_review'?'disabled':'')+'>Publish track</button></div>'+
        (s.state==='uploading_preview'?'<p role="status">Uploading and analyzing the public listening preview…</p>':'')+
        (s.state==='publishing'?'<p role="status">Publishing the reviewed track; do not close this tab…</p>':'')+
        '<input type="file" data-agent-song-preview-file accept=".mp3,audio/mpeg" hidden>';
    }else if(s.state==='published'){
      const id=String(s.trackId||s.draft?.published_track_id||'');
      body='<p><strong>'+esc(s.draft?.title||'Song')+'</strong> is published in the Stonefellow track catalog.</p>'+
        '<p class="hint">The original recording is private. The uploaded MP3 preview is public. No album or release was created.</p>'+
        '<div class="actions"><a class="primary" href="../track/'+encodeURIComponent(id)+'">View public track</a> '+
        '<button type="button" class="secondary" data-agent-song-start>Upload another song</button></div>';
    }
    return '<section id="adminAgentSongIntake" class="admin-agent-calendar admin-agent-song-intake" aria-label="Guided song upload">'+
      '<div class="admin-agent-calendar-head"><strong>New song · private intake and catalog review</strong><span>Step '+
      (s.state==='published'?'5':s.state==='prepared'||s.state==='publish_review'||s.state==='uploading_preview'||s.state==='publishing'?'4':s.state==='saved'?'3':s.state==='editing'||s.state==='saving'||s.state==='uploading_artwork'?'2':'1')+' of 5</span></div>'+
      (s.error?'<p class="form-error" role="alert">'+esc(s.error)+'</p>':'')+body+
      (s.state!=='saved'?'<input type="file" data-agent-song-file accept=".wav,.mp3,audio/wav,audio/mpeg" hidden>':'')+'</section>';
  }
  function showSongIntake(){
    const transcript=ctx()?.canvas?.querySelector('#adminAgentTranscript');if(!transcript||!songIntake)return;
    transcript.querySelector('#adminAgentSongIntake')?.remove();
    transcript.insertAdjacentHTML('beforeend',songDetailsHtml());
    const panel=transcript.querySelector('#adminAgentSongIntake');
    panel.querySelectorAll('[data-agent-song-start]').forEach(b=>b.onclick=startSongIntake);
    panel.querySelector('[data-agent-song-review]')?.addEventListener('click',()=>{
      const d=songIntake.draft;if(!d)return;
      songIntake.state='editing';songIntake.expectedHash=d.payload_hash;
      songIntake.fields=Object.fromEntries(songFieldNames.map(k=>[k,d[k]??'']));
      songIntake.artwork={id:Number(d.artwork_asset_id||0)};
      showSongIntake();
    });
    panel.querySelector('[data-agent-song-prepare]')?.addEventListener('click',()=>prepareSongDraft());
    panel.querySelector('[data-agent-song-publish-review]')?.addEventListener('click',()=>{
      if(songIntake?.draft?.status!=='catalog_ready')return;
      songIntake.state='publish_review';songIntake.preview=null;songIntake.error='';showSongIntake();
    });
    const previewPicker=panel.querySelector('[data-agent-song-preview-file]');
    panel.querySelector('[data-agent-song-preview-choose]')?.addEventListener('click',()=>previewPicker?.click());
    panel.querySelector('[data-agent-song-preview-scanned]')?.addEventListener('click',async()=>{
      const active=songIntake,id=Number(active?.draft?.candidate_preview_asset_id||0);
      if(!id||active?.state!=='publish_review')return;
      try{
        const result=await ctx().api('media.php?asset_id='+id),asset=result.asset||{};
        if(asset.category!=='audio'||asset.extension!=='mp3'||Number(asset.id)!==id
          ||Number(asset.duration_seconds||0)<=0||asset.sha256===active.draft.asset_sha256)
          throw new Error('Scanned MP3 is not a separate valid listening export. Upload a different MP3 preview.');
        if(songIntake!==active)return;
        active.preview=asset;active.error='';showSongIntake();
      }catch(e){if(songIntake!==active)return;active.error=e.message||'Scanned preview unavailable.';showSongIntake();}
    });
    previewPicker?.addEventListener('change',async()=>{
      const file=previewPicker.files?.[0];if(!file||songIntake?.state!=='publish_review')return;
      if(!/\.mp3$/i.test(file.name)||file.size<1){songIntake.error='Select a nonempty exported MP3 preview.';showSongIntake();return;}
      const active=songIntake;active.state='uploading_preview';active.error='';showSongIntake();
      try{
        const form=new FormData();form.append('file',file,file.name);
        const result=await ctx().uploadApi('media-upload.php',form);
        if(songIntake!==active)return;
        const a=result.asset||{};
        if(a.category!=='audio'||a.extension!=='mp3'||Number(a.id||0)<1||Number(a.duration_seconds||0)<=0)
          throw new Error('Uploaded file is not an analyzed MP3 preview.');
        if(Number(a.id)===Number(active.draft?.asset_id)||a.sha256===active.draft?.asset_sha256)
          throw new Error('The private original cannot be used as the public listening preview.');
        active.preview=a;active.state='publish_review';showSongIntake();
      }catch(e){if(songIntake!==active)return;active.state='publish_review';active.error=e.message||'MP3 preview upload failed.';showSongIntake();}
    });
    panel.querySelector('[data-agent-song-confirm-publish]')?.addEventListener('click',async()=>{
      const active=songIntake,d=active?.draft;
      if(active?.state!=='publish_review'||!active.preview||d?.status!=='catalog_ready')return;
      if(!window.confirm('Publish "'+d.title+'" as a PUBLIC Stonefellow track with the selected MP3 preview and artwork? The original master stays private. No album/release is created.'))return;
      active.state='publishing';active.error='';showSongIntake();
      try{
        const response=await ctx().api('agent-song-catalog.php',{action:'publish_track',request_key:d.request_key,
          expected_hash:d.payload_hash,preview_asset_id:Number(active.preview.id),confirmed:true});
        if(songIntake!==active)return;
        active.trackId=response.track_id;active.draft=response.draft;active.state='published';showSongIntake();await loadSongDrafts();
      }catch(e){if(songIntake!==active)return;active.state='publish_review';active.error=e.message||'Publication failed. Review and retry safely.';showSongIntake();}
    });
    panel.querySelector('[data-agent-song-cancel]')?.addEventListener('click',()=>{songIntake=null;panel.remove();});
    const artworkPicker=panel.querySelector('[data-agent-artwork-file]');
    panel.querySelector('[data-agent-artwork-choose]')?.addEventListener('click',()=>artworkPicker?.click());
    artworkPicker?.addEventListener('change',async()=>{
      const file=artworkPicker.files?.[0];if(!file||!songIntake)return;
      if(!/\.(jpg|jpeg|png|webp|gif)$/i.test(file.name)||file.size<1){
        songIntake.error='Choose a nonempty JPG, PNG, WebP or GIF artwork file.';showSongIntake();return;
      }
      const active=songIntake;
      const form=panel.querySelector('[data-agent-song-form]');
      if(form)songIntake.fields=songFieldsFromForm(form);
      songIntake.state='uploading_artwork';songIntake.error='';showSongIntake();
      try{
        const data=new FormData();data.append('file',file,file.name);
        const response=await ctx().uploadApi('media-upload.php',data);
        if(songIntake!==active)return;
        if(response.asset?.category!=='image'||Number(response.asset?.id||0)<1)
          throw new Error('Uploaded artwork did not resolve to a managed image.');
        songIntake.artwork=response.asset;
        songIntake.fields.artwork_asset_id=Number(response.asset.id);
        songIntake.state='editing';showSongIntake();
      }catch(e){if(songIntake!==active)return;songIntake.state='editing';songIntake.error=e.message||'Artwork upload failed.';showSongIntake();}
    });
    const picker=panel.querySelector('[data-agent-song-file]');
    panel.querySelector('[data-agent-song-choose]')?.addEventListener('click',()=>picker?.click());
    picker?.addEventListener('change',async()=>{
      const active=songIntake;
      const file=picker.files?.[0];if(!file)return;
      if(!/\.(wav|mp3)$/i.test(file.name)||file.size<1){songIntake.error='Choose a nonempty MP3 or WAV file.';showSongIntake();return;}
      songIntake.state='uploading';songIntake.error='';showSongIntake();
      try{
        const form=new FormData();form.append('file',file,file.name);
        const result=await ctx().uploadApi('media-upload.php',form);
        if(songIntake!==active)return;
        const asset=result.asset||{};
        if(!Number.isSafeInteger(Number(asset.id))||Number(asset.id)<1||asset.category!=='audio')
          throw new Error('The uploaded file did not resolve to an audio asset.');
        const tags=asset.technical?.normalized_tags||{};
        songIntake.asset=asset;
        songIntake.fields={title:tags.title||asset.title||file.name.replace(/\.[^.]+$/,''),
          release:tags.album||'Unreleased',genre:tags.genre||'',year:/^\d{4}$/.test(String(tags.year||''))?tags.year:'',
          words_by:'',music_by:'',notes:'',artist_display:'Stonefellow',master_owner:'',composition_owner:'',
          isrc:'',release_date:'',explicit:false,pod_eligible:false,rights_confirmed:false,artwork_asset_id:0};
        songIntake.state='editing';showSongIntake();
      }catch(e){if(songIntake!==active)return;songIntake.state='choose';songIntake.error=e.message||'The recording was not uploaded.';showSongIntake();}
    });
    panel.querySelector('[data-agent-song-form]')?.addEventListener('submit',async event=>{
      event.preventDefault();if(songIntake.state==='saving'||!songIntake.asset)return;
      const active=songIntake;
      const fields=songFieldsFromForm(event.currentTarget);
      fields.artwork_asset_id=Number(songIntake.artwork?.id||fields.artwork_asset_id||0);
      if(!fields.title){songIntake.error='Please enter a song title.';songIntake.fields=fields;showSongIntake();return;}
      songIntake.fields=fields;songIntake.state='saving';songIntake.error='';showSongIntake();
      try{
        const response=await ctx().api('agent-song-intake.php',{action:songIntake.expectedHash?'update_draft':'save_draft',
          request_key:songIntake.key,asset_id:Number(songIntake.asset.id),expected_hash:songIntake.expectedHash||'',...fields});
        if(songIntake!==active)return;
        songIntake.state='saved';songIntake.draft=response.draft;songIntake.readiness=response.readiness;showSongIntake();
        await loadSongDrafts();
      }catch(e){if(songIntake!==active)return;songIntake.state='editing';songIntake.error=e.message||'Song draft was not saved.';showSongIntake();}
    });
    if(songIntake.state!=='uploading')panel.scrollIntoView({block:'nearest'});
  }
  const songFieldNames=['title','release','genre','year','words_by','music_by','notes',
    'artist_display','master_owner','composition_owner','isrc','release_date','explicit','pod_eligible','rights_confirmed','artwork_asset_id'];
  function songFieldsFromForm(form){
    const data=new FormData(form);
    return Object.fromEntries(songFieldNames.map(k=>[k,
      k==='explicit'||k==='pod_eligible'||k==='rights_confirmed'?data.get(k)==='on':
      k==='artwork_asset_id'?Number(songIntake?.artwork?.id||songIntake?.fields?.artwork_asset_id||0):
      String(data.get(k)||'').trim()]));
  }
  async function prepareSongDraft(){
    if(!songIntake?.draft||songIntake.state!=='saved')return;
    const d=songIntake.draft;
    if(!window.confirm('Prepare this private song for catalog handoff? This will NOT publish or expose the song.'))return;
    songIntake.error='';
    try{
      const response=await ctx().api('agent-song-intake.php',{
        action:'prepare_catalog',request_key:d.request_key,expected_hash:d.payload_hash,confirmed:true});
      songIntake.draft=response.draft;songIntake.state='prepared';showSongIntake();await loadSongDrafts();
    }catch(e){songIntake.error=e.message||'Preflight failed. Review missing fields and artwork.';showSongIntake();}
  }
  function startSongIntake(){
    songIntake={state:'choose',key:newSongKey(),error:'',asset:null,fields:null};
    showSongIntake();
  }
  async function openSongDraft(requestKey){
    const c=ctx();if(!c||!requestKey)throw new Error('Private song draft is missing.');
    const result=await c.api('agent-song-intake.php');
    const draft=(result.drafts||[]).find(d=>d.request_key===requestKey);
    if(!draft)throw new Error('Private song draft was not found. Use Admin Agent Chat to review saved drafts.');
    if(draft.status==='published')throw new Error('This song is already published. Open its Catalog record to manage audio revisions.');
    songIntake={state:draft.status==='catalog_ready'?'publish_review':'editing',
      key:draft.request_key,error:'',expectedHash:draft.payload_hash,draft,
      asset:{id:draft.asset_id,original_name:draft.filename,duration_seconds:draft.duration_seconds,codec:draft.codec},
      artwork:{id:Number(draft.artwork_asset_id||0)},
      fields:Object.fromEntries(songFieldNames.map(k=>[k,draft[k]??'']))};
    c.openView('agent-chat');
    await render();
  }
  async function loadSongDrafts(){
    const c=ctx();if(!c)return;
    try{
      const result=await c.api('agent-song-intake.php');
      const target=c.canvas.querySelector('#adminAgentSongDrafts');if(!target)return;
      const drafts=Array.isArray(result.drafts)?result.drafts.slice(0,6):[];
      target.innerHTML='<details><summary>Private song drafts ('+drafts.length+((result.drafts||[]).length>6?'+':'')+')</summary>'+
        (drafts.length?drafts.map(d=>'<p><strong>'+esc(d.title)+'</strong> · '+esc(d.filename)+
          ' · '+esc(d.created_at||'')+' · '+(d.status==='published'?'Published':d.status==='catalog_ready'?'Prepared for catalog':'Unpublished')+
          ' · Preflight '+Number(d.readiness?.score||0)+'% '+(d.status==='draft'?'<button type="button" class="secondary" data-agent-song-edit="'+esc(d.id)+'">Edit / review draft</button>':
            d.status==='catalog_ready'?'<button type="button" class="primary" data-agent-song-publish-draft="'+esc(d.id)+'">Review publication</button>':
            d.status==='published'?'<a href="../track/'+encodeURIComponent(String(d.published_track_id||''))+'">View track</a>':'')+'</p>').join(''):
          '<p class="hint">No song drafts yet.</p>')+'</details>';
      target.querySelectorAll('[data-agent-song-edit]').forEach(button=>button.onclick=()=>{
        const draft=result.drafts.find(d=>d.id===button.dataset.agentSongEdit);
        if(!draft)return;
        songIntake={state:'editing',key:draft.request_key,error:'',expectedHash:draft.payload_hash,
          asset:{id:draft.asset_id,original_name:draft.filename,duration_seconds:draft.duration_seconds,codec:draft.codec},
          artwork:{id:Number(draft.artwork_asset_id||0)},
          fields:Object.fromEntries(songFieldNames.map(k=>[k,draft[k]??'']))};
        showSongIntake();
      });
      target.querySelectorAll('[data-agent-song-publish-draft]').forEach(button=>button.onclick=()=>{
        const draft=result.drafts.find(d=>d.id===button.dataset.agentSongPublishDraft);
        if(!draft||draft.status!=='catalog_ready')return;
        songIntake={state:'publish_review',key:draft.request_key,error:'',expectedHash:draft.payload_hash,
          draft,preview:null};showSongIntake();
      });
    }catch(_){const target=c.canvas.querySelector('#adminAgentSongDrafts');if(target)target.textContent='Private draft list unavailable.';}
  }
  function action(type){
    const c=ctx();if(!c)return;
    if(type==='brain'){showBrain();return;}
    if(type==='campaigns'){c.openView('campaigns');setTimeout(()=>window.SFAgentCampaigns?.open?.('newsletter'),0);return;}
    if(type==='journey'){c.openView('automations');setTimeout(()=>window.SFAutomationsAdmin?.openAutomation?.(0),0);return;}
    if(type==='upload_audio'){
      c.openView('media');
      // Must run from the user's own click: browsers forbid agent-initiated file selection.
      if(typeof window.SFMediaAdmin?.pickUploadFiles==='function')window.SFMediaAdmin.pickUploadFiles('','','audio_candidate');
      return;
    }
    if(type==='production-review'){c.openView('production-review');return;}
    if(adminModuleViews.has(type)){c.openView(type);return;}
    if(['crm','automations','analytics','commerce-ops'].includes(type))c.openView(type);
  }
  function bindActions(root){
    root.querySelectorAll('[data-agent-song-start]').forEach(b=>b.onclick=startSongIntake);
    root.querySelectorAll('[data-admin-agent-action]').forEach(b=>b.onclick=()=>action(b.dataset.adminAgentAction));
    root.querySelectorAll('video[data-agent-scene-start]').forEach(video=>{
      const start=Math.max(0,Number(video.dataset.agentSceneStart)||0);
      const end=Number(video.dataset.agentSceneEnd)||0;
      if(!(end>start))return;
      video.addEventListener('loadedmetadata',()=>{
        if(Number.isFinite(video.duration)&&start<video.duration)video.currentTime=start;
      });
      video.addEventListener('timeupdate',()=>{
        if(video.currentTime>=end){video.pause();}
      });
      video.addEventListener('play',()=>{
        if(video.currentTime>=end)video.currentTime=start;
      });
    });

    root.querySelectorAll('[data-agent-open-footage-review]').forEach(b=>b.onclick=()=>ctx()?.openView?.('team'));
    root.querySelectorAll('[data-agent-create-shot-draft]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const code=button.dataset.agentCreateShotDraft||'';
      if(!/^[a-z0-9_]{3,60}$/.test(code))return;
      if(!window.confirm('Create a private AI Director shot-request draft for '+code+'? It will not be opened to contributors or assigned.'))return;
      button.disabled=true;
      try{
        await c.api('story-director.php',{action:'create_draft',recommendation_code:code});
        c.say('Private shot-request draft created. Review it before opening to contributors.');
        await send('director shot list');
      }catch(e){c.say(e.message||'Shot-request draft could not be created.');button.disabled=false;}
    });

    root.querySelectorAll('[data-agent-capture-review]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const id=Number(button.dataset.submissionId),decision=button.dataset.agentCaptureReview;
      const basis=button.closest('.admin-agent-calendar-task')?.querySelector('[data-agent-capture-basis="'+id+'"]')?.value.trim()||'';
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
    root.querySelectorAll('[data-agent-catalog-review]').forEach(button=>button.onclick=()=>{
      const entry=(window.SFAdminAgentCanvas?.commerceMessages||[]).find(row=>
        String(row.action?.request_key)===button.dataset.agentCatalogReview);
      if(entry?.action?.draft)window.SFAgentCatalogReview?.open?.(entry.action);
    });
    root.querySelectorAll('[data-agent-knowledge-review]').forEach(button=>button.onclick=()=>{
      const entry=(window.SFAdminAgentCanvas?.commerceMessages||[]).find(row=>
        String(row.action?.request_key)===button.dataset.agentKnowledgeReview);
      if(entry?.action?.draft)window.SFAgentKnowledgeReview?.open?.(entry.action);
    });
    root.querySelectorAll('[data-agent-media-review]').forEach(button=>button.onclick=()=>{
      const entry=(window.SFAdminAgentCanvas?.commerceMessages||[]).find(row=>
        String(row.action?.request_key)===button.dataset.agentMediaReview);
      if(entry?.action?.draft)window.SFAgentMediaReview?.open?.(entry.action);
    });
    root.querySelectorAll('[data-agent-media-link-review]').forEach(button=>button.onclick=()=>{
      const entry=(window.SFAdminAgentCanvas?.commerceMessages||[]).find(row=>
        String(row.action?.request_key)===button.dataset.agentMediaLinkReview);
      if(entry?.action?.draft)window.SFAgentMediaLinkReview?.open?.(entry.action);
    });
    root.querySelectorAll('[data-agent-copy-reply]').forEach(button=>button.onclick=async()=>{
      const msg=(window.SFAdminAgentCanvas?.commerceMessages||[]).find(row=>Number(row.id)===Number(button.dataset.agentCopyReply));
      const draft=msg?.action?.reply_draft;
      if(!draft||draft.state!=='unsent_draft')return;
      try{await navigator.clipboard.writeText(String(draft.text||''));ctx()?.say?.('Draft copied for manual review. Nothing was sent.');}
      catch(e){ctx()?.say?.('Copy unavailable. Select the draft text in chat to copy it.');}
    });

    root.querySelectorAll('[data-agent-footage-refresh]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      button.disabled=true;
      try{await send('footage intelligence');}catch(e){c.say(e.message||'Refresh failed.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-footage-enable]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      if(!window.confirm('Enable supervised visual and audio inference for independently consented production captures? Future confirmed job requests may send private images, three MP4 frames, or audio to the configured AI provider. No automatic publication or reprocessing of uncertain jobs.'))return;
      button.disabled=true;
      try{
        await c.api('story-inference.php',{action:'configure_execution',enabled:true});
        c.say('Supervised footage analysis enabled. Each eligible capture must still be requested separately.');
        await send('footage intelligence');
      }catch(e){c.say(e.message||'AI analysis opt-in was not saved.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-footage-enqueue]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const submission_id=Number(button.dataset.agentFootageEnqueue),task_type=button.dataset.taskType;
      if(!Number.isSafeInteger(submission_id)||submission_id<1||!['scene','transcript'].includes(task_type))return;
      if(!window.confirm('Queue one '+task_type+' analysis for private capture #'+submission_id+
          '? Exact source fingerprint and independent external-AI consent will be checked again. A supervised worker must run before results exist.'))return;
      button.disabled=true;
      try{
        await c.api('story-inference.php',{action:'enqueue',submission_id,task_type});
        c.say('Private inference queued or existing job reused; provider execution is separate.');
        await send('footage intelligence');
      }catch(e){c.say(e.message||'Capture source and consent verification failed.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-footage-decision]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const job_id=Number(button.dataset.jobId),decision=button.dataset.agentFootageDecision;
      if(!Number.isSafeInteger(job_id)||job_id<1||!['approved','rejected'].includes(decision))return;
      if(!window.confirm((decision==='approved'?'Approve':'Reject')+' machine observation #'+job_id+
          ' for private editorial consideration? This does not verify or publish the canonical footage.'))return;
      button.disabled=true;
      try{
        await c.api('story-inference.php',{action:'review_result',job_id,decision});
        c.say('Human review recorded. Canonical timecode selection and verification remain separate.');
        await send('footage intelligence');
      }catch(e){c.say(e.message||'Review could not be recorded.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-director-open]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const id=Number(button.dataset.agentDirectorOpen);
      if(!Number.isSafeInteger(id)||id<1)return;
      button.disabled=true;
      try{await send('ai director sequence assembly #'+id);}
      catch(e){c.say(e.message||'Director inspection failed.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-director-enable]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      if(!window.confirm('Enable the governed AI Composer? Future explicit requests may send cleared, metadata-only Stonefellow production evidence to your configured AI provider. This does not grant media edits, publication or recurring permission for uncleared contributors.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:'configure_composer',enabled:true});
        c.say('AI Composer enabled with explicit owner opt-in. You must still choose an assembly and request each proposal separately.');
        const section=button.closest('.admin-agent-director-sequence');
        const match=section?.querySelector('[data-agent-director-open]');
        if(match)await send('ai director sequence assembly #'+Number(match.dataset.agentDirectorOpen));
      }catch(e){c.say(e.message||'AI Composer opt-in was not saved.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-director-queue]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const id=Number(button.dataset.assemblyId),revision=Number(button.dataset.revision);
      if(!Number.isSafeInteger(id)||id<1||!Number.isSafeInteger(revision)||revision<1)return;
      if(!window.confirm('Queue one metadata-only AI Director proposal for assembly #'+id+' revision '+revision+'? Only independently consented scene/dialogue summaries and eligible original Stonefellow music can be sent to your configured model. The supervised server worker generates the proposal; no timeline changes now.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:'chat_composer_queue',assembly_id:id,revision,confirmed:true});
        c.say('AI Director request queued or already reserved for this revision. Refresh after the supervised composer worker runs.');
        await send('ai director sequence assembly #'+id);
      }catch(e){c.say(e.message||'AI proposal was not queued. Check provider, rights and the enabled switch.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-director-review]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const card=button.closest('.admin-agent-director-proposal');
      const basis=card?.querySelector('[data-agent-director-basis]')?.value.trim()||'';
      const proposal_id=Number(button.dataset.proposalId),assembly_id=Number(button.dataset.assemblyId),
        revision=Number(button.dataset.revision),decision=button.dataset.agentDirectorReview;
      if(basis.length<25||!['approved','rejected'].includes(decision)||
        !Number.isSafeInteger(proposal_id)||proposal_id<1||
        !Number.isSafeInteger(assembly_id)||assembly_id<1||
        !Number.isSafeInteger(revision)||revision<1){
        c.say('Document at least 25 characters explaining the editorial approval or rejection.');return;
      }
      if(!window.confirm((decision==='approved'?'Approve':'Reject')+' AI Director proposal #'+proposal_id+
        ' at revision '+revision+'? Approval does not yet change any production clip.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:'chat_composer_review',
          assembly_id,revision,proposal_id,decision,review_basis:basis,confirmed:true});
        c.say('Human AI Director review saved to the existing Agent Brain activity ledger.');
        await send('ai director sequence assembly #'+assembly_id);
      }catch(e){c.say(e.message||'The proposal review failed or was already decided.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-director-apply]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const proposal_id=Number(button.dataset.proposalId),assembly_id=Number(button.dataset.assemblyId),
        revision=Number(button.dataset.revision);
      if(!Number.isSafeInteger(proposal_id)||proposal_id<1||
        !Number.isSafeInteger(assembly_id)||assembly_id<1||
        !Number.isSafeInteger(revision)||revision<1)return;
      if(!window.confirm('Apply the reviewed AI sequence proposal #'+proposal_id+
        ' to private assembly #'+assembly_id+' revision '+revision+
        '? This will reorder verified clips and optionally add approved footage, dialogue or original music. It will save a new audited revision without rendering or publishing.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:'chat_composer_apply',
          assembly_id,revision,proposal_id,confirmed:true});
        c.say('Approved AI Director sequence saved. Reviewing the exact new private timeline.');
        await send('assembly #'+assembly_id+' timeline');
      }catch(e){c.say(e.message||'AI sequence could not be applied: source, consent or revision may have changed.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-quality-open]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const command=button.dataset.agentQualityOpen||'';
      if(command.length<10||command.length>200)return;
      button.disabled=true;
      try{await send(command);}
      catch(e){c.say(e.message||'Production evidence refresh unavailable.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-transition-assembly]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const id=Number(button.dataset.agentTransitionAssembly);
      if(!Number.isSafeInteger(id)||id<1)return;
      button.disabled=true;
      try{await send('review transitions for assembly #'+id);}
      catch(e){c.say(e.message||'Transition review unavailable.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-transition-global]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const card=button.closest('.admin-agent-transition-review');
      const style=card?.querySelector('[data-agent-transition-style]')?.value||'';
      const overlap=Number(card?.querySelector('[data-agent-transition-overlap]')?.value);
      const basis=card?.querySelector('[data-agent-transition-basis]')?.value.trim()||'';
      const id=Number(button.dataset.assemblyId),revision=Number(button.dataset.revision);
      if(!['fade','wipeleft'].includes(style)||![250,500].includes(overlap)||basis.length<25||
         !Number.isSafeInteger(id)||id<1||!Number.isSafeInteger(revision)||revision<1){
        c.say('Choose an approved audio/video crossfade and write a 25+ character review basis.');return;
      }
      if(!window.confirm('Save '+style+' '+overlap+' ms synchronized audio/video crossfade for assembly #'+id+
        ' revision '+revision+'? Every original media and rights gate must pass.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:'chat_transition',assembly_id:id,revision,style,
          overlap_ms:overlap,review_basis:basis,confirmed:true});
        c.say('Revision-locked crossfade reviewed and saved. Opening updated transition preview.');
        await send('review transitions for assembly #'+id);
      }catch(e){c.say(e.message||'Transition failed source/rights or revision checks.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-transition-cuts]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const card=button.closest('.admin-agent-transition-review');
      const basis=card?.querySelector('[data-agent-transition-basis]')?.value.trim()||'';
      const id=Number(button.dataset.assemblyId),revision=Number(button.dataset.revision);
      const cuts=[...(card?.querySelectorAll('[data-agent-transition-cut]')||[])].map(node=>({
        style:node.querySelector('[data-agent-cut-style]')?.value||'',
        overlap_ms:Number(node.querySelector('[data-agent-cut-overlap]')?.value)
      }));
      if(!Number.isSafeInteger(id)||id<1||!Number.isSafeInteger(revision)||revision<1||
         basis.length<25||cuts.length<1||cuts.length>5||
         cuts.some(x=>!['fade','wipeleft'].includes(x.style)||![250,500].includes(x.overlap_ms))){
        c.say('Select exact approved cut effects and document the editor review basis.');return;
      }
      if(!window.confirm('Save '+cuts.length+' individually timed audio/video bridges in assembly #'+id+
        ' revision '+revision+'? No render will be started.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:'chat_cuts',assembly_id:id,revision,
          cuts,review_basis:basis,confirmed:true});
        c.say('Each audio/video cut is now scoped to the current editorial revision.');
        await send('review transitions for assembly #'+id);
      }catch(e){c.say(e.message||'Per-cut review was not saved.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-media-choose]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const id=Number(button.dataset.agentMediaChoose),kind=button.dataset.mediaKind,
        topic=button.dataset.mediaTopic||'',place=button.dataset.mediaPlacement||'';
      const slot=button.dataset.mediaSlot===''?null:Number(button.dataset.mediaSlot);
      if(!Number.isSafeInteger(id)||id<1||!['track','transcript'].includes(kind))return;
      const position=place==='after_opening'?' after opening scene':
        place==='after_clip'&&slot!==null?' after clip #'+slot:
        place==='before_clip'&&slot!==null?' before clip #'+(slot+1):
        place==='start'?' at beginning':place==='end'?' at end':'';
      button.disabled=true;
      try{await send('add '+(topic||'original')+' '+(kind==='track'?'music':'dialogue')+
        ' to assembly #'+id+position);}
      catch(e){c.say(e.message||'Could not select this assembly.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-media-source]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const card=button.closest('.admin-agent-media-placement');
      const raw=card?.querySelector('[data-agent-media-position]')?.value??'';
      const index=Number(raw),basis=card?.querySelector('[data-agent-media-basis]')?.value.trim()||'';
      const id=Number(button.dataset.assemblyId),revision=Number(button.dataset.revision),
        kind=button.dataset.mediaKind,sourceId=String(button.dataset.agentMediaSource||'');
      if(!/^\\d+$/.test(raw)||!Number.isSafeInteger(index)||index<0||!Number.isSafeInteger(id)||id<1||
         !Number.isSafeInteger(revision)||revision<1||!['track','transcript'].includes(kind)||
         !sourceId||basis.length<25){
        c.say('Choose an exact timeline position and document the editorial reason (25+ characters).');return;
      }
      if(!window.confirm('Place '+(kind==='track'?'original Stonefellow music':'verified dialogue')+
          ' '+sourceId+' at clip '+(index+1)+' in assembly #'+id+
          ' revision '+revision+'? Music permission is automatic only for eligible originals.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:'chat_add_media',assembly_id:id,revision,index,
          kind,source_id:sourceId,review_basis:basis,confirmed:true});
        c.say('Music or dialogue placement saved to the existing revision and Team Activity ledger.');
        await send('assembly #'+id+' timeline');
      }catch(e){c.say(e.message||'Placement was not saved; refresh current assembly.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-edit-command]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const command=button.dataset.agentEditCommand||'';
      if(command.length<8||command.length>250)return;
      button.disabled=true;
      try{await send(command);}
      catch(e){c.say(e.message||'Unable to refresh the editor review.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-edit-confirm]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const card=button.closest('.admin-agent-timeline-edit');
      const basis=card?.querySelector('[data-agent-edit-basis]')?.value.trim()||'';
      const operation=button.dataset.agentEditConfirm;
      const id=Number(button.dataset.assemblyId),revision=Number(button.dataset.revision),
        index=Number(button.dataset.index);
      const expected=String(button.dataset.oldSource||''),replacement=String(button.dataset.newSource||'');
      if(!['remove','replace'].includes(operation)||!Number.isSafeInteger(id)||id<1||
         !Number.isSafeInteger(revision)||revision<1||!Number.isSafeInteger(index)||index<0||
         !expected||basis.length<25||(operation==='replace'&&!/^[1-9]\\d*$/.test(replacement))){
        c.say('Select the exact clip and record at least 25 characters of human review evidence.');return;
      }
      const description=operation==='remove'?
        'Remove clip #'+(index+1)+' (source '+expected+')':
        'Replace scene '+expected+' with verified scene '+replacement+' at clip #'+(index+1);
      if(!window.confirm(description+' from assembly #'+id+' revision '+revision+
          '? This changes the private editorial timeline, not the original files or publishing rights.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:operation==='remove'?'chat_remove':'chat_replace',
          assembly_id:id,revision,index,expected_source_id:expected,
          ...(operation==='replace'?{new_source_id:replacement}:{}),
          review_basis:basis,confirmed:true});
        c.say('Editor-confirmed '+operation+' saved to revision and Team Activity history. Reloading timeline.');
        await send('assembly #'+id+' timeline');
      }catch(e){c.say(e.message||'Edit rejected. Reload the current timeline revision.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-insert-assembly]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const id=Number(button.dataset.agentInsertAssembly),topic=button.dataset.insertTopic||'';
      const placement=button.dataset.insertPlacement||'';
      const slot=button.dataset.insertSlot===''?null:Number(button.dataset.insertSlot);
      if(!Number.isSafeInteger(id)||id<1)return;
      const position=placement==='after_opening'?' after opening scene':
        placement==='after_clip'&&slot!==null?' after clip #'+slot:
        placement==='before_clip'&&slot!==null?' before clip #'+(slot+1):
        placement==='start'?' at beginning':placement==='end'?' at end':'';
      button.disabled=true;
      try{await send('add verified '+(topic||'scene')+' clip to assembly #'+id+position);}
      catch(e){c.say(e.message||'Unable to load insertion preview.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-insert-source]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const card=button.closest('.admin-agent-timeline-insert');
      const rawPosition=card?.querySelector('[data-agent-insert-position]')?.value??'';
      const position=Number(rawPosition);
      const basis=card?.querySelector('[data-agent-insert-basis]')?.value.trim()||'';
      const id=Number(button.dataset.assemblyId),revision=Number(button.dataset.revision);
      const sourceId=String(button.dataset.agentInsertSource||'');
      if(!/^\\d+$/.test(rawPosition)||!Number.isSafeInteger(position)||position<0||
         !Number.isSafeInteger(id)||id<1||!Number.isSafeInteger(revision)||revision<1||
         !/^[1-9]\\d*$/.test(sourceId)||basis.length<25){
        c.say('Choose a timeline position and document the source selection in at least 25 characters.');return;
      }
      if(!window.confirm('Insert verified scene #'+sourceId+' into assembly #'+id+
        ' at position '+(position+1)+' using revision '+revision+
        '? Only the private editorial timeline will change.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:'chat_insert',assembly_id:id,revision,
          index:position,source_id:sourceId,review_basis:basis,confirmed:true});
        c.say('Verified scene added with an auditable review decision. Reloading the assembly timeline.');
        await send('assembly #'+id+' timeline');
      }catch(e){c.say(e.message||'Insert not applied. Refresh the current assembly revision.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-timeline-trim]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const card=button.closest('.admin-agent-calendar-task');
      const id=Number(button.dataset.assemblyId),revision=Number(button.dataset.revision),
        index=Number(button.dataset.index);
      const rawIn=card?.querySelector('[data-agent-trim-in]')?.value??'';
      const rawOut=card?.querySelector('[data-agent-trim-out]')?.value??'';
      const inMs=Number(rawIn),outMs=Number(rawOut);
      const basis=button.closest('.admin-agent-calendar')?.querySelector('[data-agent-timeline-basis="'+id+'"]')?.value.trim()||'';
      if(!Number.isSafeInteger(id)||id<1||!Number.isSafeInteger(revision)||revision<1||
         !Number.isSafeInteger(index)||index<0||!/^\\d+$/.test(rawIn)||!/^\\d+$/.test(rawOut)||
         !Number.isSafeInteger(inMs)||!Number.isSafeInteger(outMs)||outMs-inMs<500||basis.length<25){
        c.say('Select an exact clip interval of at least 500 ms and document the editorial reason (25+ characters).');return;
      }
      if(!window.confirm('Trim clip '+(index+1)+' in assembly #'+id+' revision '+revision+
        ' to '+inMs+'–'+outMs+' milliseconds? This changes only the private editorial timeline.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:'chat_trim',assembly_id:id,revision,index,
          in_ms:inMs,out_ms:outMs,review_basis:basis,confirmed:true});
        c.say('Clip trim saved with independent source checks and human review evidence.');
        await send('assembly #'+id+' timeline');
      }catch(e){c.say(e.message||'Trim not saved. Refresh the current timeline.');button.disabled=false;}
    });
    root.querySelectorAll('[data-agent-timeline-move]').forEach(button=>button.onclick=async()=>{
      const c=ctx();if(!c||busy)return;
      const id=Number(button.dataset.assemblyId),revision=Number(button.dataset.revision),
        index=Number(button.dataset.index),direction=button.dataset.agentTimelineMove;
      const basis=button.closest('.admin-agent-calendar')?.querySelector('[data-agent-timeline-basis="'+id+'"]')?.value.trim()||'';
      if(!Number.isSafeInteger(id)||id<1||!Number.isSafeInteger(revision)||revision<1||
         !Number.isSafeInteger(index)||index<0||!['up','down'].includes(direction)||basis.length<25){
        c.say('Enter a documented editorial reason (25+ characters) before reordering.');return;
      }
      if(!window.confirm('Move clip '+(index+1)+' '+direction+' in assembly #'+id+
        ' revision '+revision+'? This changes the private timeline and writes an audit event.'))return;
      button.disabled=true;
      try{
        await c.api('story-assembly.php',{action:'chat_move',assembly_id:id,revision,index,direction,
          review_basis:basis,confirmed:true});
        c.say('Timeline revision saved with human review evidence. Refreshing the assembly in chat.');
        await send('assembly #'+id+' timeline');
      }catch(e){c.say(e.message||'Timeline was not modified. Refresh its current revision.');button.disabled=false;}
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
      '<div class="admin-agent-chat-controls"><button type="button" class="secondary" id="adminAgentSpeechToggle" aria-pressed="true" title="Toggle on-device speech for Admin Agent replies">Voice on</button><button type="button" class="secondary" id="adminAgentOpenBrain">Agent Brain ↗</button><button type="button" class="secondary" id="adminAgentNewThread">New chat</button></div></header>'+
      '<div class="admin-agent-song-drafts" id="adminAgentSongDrafts" aria-label="Saved private song drafts"></div>'+
      '<section class="admin-agent-chat-thread" aria-label="Admin Agent conversation">'+
      '<div class="admin-agent-transcript" id="adminAgentTranscript" role="log" aria-live="polite" aria-relevant="additions text"><p>Loading your conversation…</p></div></section>'+
      '<p class="admin-agent-chat-status" id="adminAgentChatStatus" role="status"></p>'+
      '</div>';
    const root=c.canvas.querySelector('#adminAgentChat');
    root.querySelector('#adminAgentSpeechToggle').onclick=()=>c.toggleAdminSpeech?.();
    c.updateAdminVoiceControl?.();
    root.querySelector('#adminAgentOpenBrain').onclick=showBrain;
    root.querySelector('#adminAgentNewThread').onclick=()=>{newThread();document.querySelector('#adminChatInput')?.focus();};
    try{
      const get=await load();if(!root.isConnected)return;
      lastBrain=get.brain||null;
      const transcript=root.querySelector('#adminAgentTranscript'),items=get.messages||[];
      window.SFAdminAgentCanvas.commerceMessages=items;
      transcript.innerHTML=items.length?items.map(messageHtml).join(''):
        '<div class="admin-agent-welcome"><h2>What can I help with?</h2><p>Ask for a song upload, production calendar, overdue tasks, crew schedule, approvals or assembly #123. Review everything in chat.</p><button type="button" data-agent-song-start class="secondary">Upload a new song</button></div>';
      const receiptPanel=document.createElement('section');receiptPanel.className='admin-proof-inline';
      receiptPanel.id='adminAgentProofCards';receiptPanel.setAttribute('aria-label','Saved work and Agent action receipts');
      receiptPanel.innerHTML='<h3>Checking saved work…</h3>';transcript.appendChild(receiptPanel);
      bindActions(transcript);
      if(songIntake)showSongIntake();
      loadSongDrafts();
      void loadProofsInto(receiptPanel,5);
      // Section 90E: the document owns Agent Chat scrolling. The composer is fixed.
      requestAnimationFrame(()=>{
        if(root.isConnected&&document.body.contains(root))window.scrollTo({top:document.documentElement.scrollHeight,behavior:'auto'});
      });
    }catch(err){
      if(root.isConnected)root.querySelector('#adminAgentTranscript').innerHTML=
        '<p class="admin-agent-load-error">Conversation unavailable. Your saved messages have not been changed.</p>';
      throw err;
    }
  }
  async function send(text,options={}){
    if(busy)throw new Error('An Admin Agent request is already in progress.');
    const c=ctx();if(!c)throw new Error('Admin workspace unavailable.');
    const message=String(text||'').trim();if(!message)return;
    busy=true;
    try{
      const preserveView=options.preserveView===true&&c.app?.view!=='agent-chat';
      const r=await c.api('agent-chat.php',{message,conversation_id:conversationId,
        client_state:{view:c.app?.view||'agent-chat'}});
      conversationId=r.conversation_id||conversationId;
      if(conversationId)sessionStorage.setItem('stonefellow.admin.agent.thread',conversationId);
      if(preserveView){if(isPopupOpen())await refreshPopup();}else c.openView('agent-chat');
      const status=c.canvas.querySelector('#adminAgentChatStatus');if(status)status.textContent='';
      if(r.action?.auto_navigate===true &&
         r.action?.tool_contract?.tool_id==='admin_module_navigate' &&
         adminModuleViews.has(r.action.view)){
        if(r.action.type==='open_catalog_record' && r.action.view==='catalog' && r.action.track_id)
          c.openCatalogTrack?.(r.action.track_id);
        else c.openView(r.action.view);
      }
      return r;
    }finally{busy=false;}
  }
  function closeBrain(){
    document.querySelector('#adminAgentBrainBackdrop')?.remove();
    document.querySelector('#adminAgentBrainDrawer')?.remove();
    if(previousFocus&&previousFocus.isConnected)previousFocus.focus();
    previousFocus=null;
  }
  async function showBrain(initialTab='brain'){
    const c=ctx();if(!c)return;
    closeBrain();
    previousFocus=document.activeElement;
    const overlay=document.createElement('div');overlay.id='adminAgentBrainBackdrop';overlay.className='admin-brain-backdrop';
    const drawer=document.createElement('aside');drawer.id='adminAgentBrainDrawer';drawer.className='admin-brain-drawer';
    drawer.setAttribute('role','dialog');drawer.setAttribute('aria-modal','true');drawer.setAttribute('aria-label','Administrator Agent Brain');
    drawer.innerHTML='<div class="admin-brain-header"><div><small>STONEFELLOW</small><h2>Notifications &amp; Agent Brain</h2></div><button type="button" id="closeAdminBrain" aria-label="Close Admin Brain">×</button></div>'+
      '<div class="admin-brain-tabs" role="tablist" aria-label="Agent activity sections">'+
      '<button type="button" role="tab" data-brain-tab="activity">Notifications</button><button type="button" role="tab" data-brain-tab="brain">Agent Brain</button><button type="button" role="tab" data-brain-tab="history">History</button></div>'+
      '<section id="adminBrainActivityPanel" role="tabpanel"><p>Latest recorded work, checked against saved records.</p><div id="adminBrainActivityContent" role="status">Loading notifications…</div></section>'+
      '<section id="adminBrainCorePanel" role="tabpanel"><p>Operational decisions, tool authority, exact approvals and mission recovery. Customer private conversations are not exposed.</p><div id="adminBrainMissionContent" role="status">Loading mission state…</div></section>'+
      '<section id="adminBrainHistoryPanel" role="tabpanel"><p>Chronological action audit. Verified cards link directly to the saved record; unverified entries are labeled as audit-only.</p><div id="adminBrainHistoryContent" role="status">Loading history…</div></section>';
    document.body.append(overlay,drawer);
    const close=drawer.querySelector('#closeAdminBrain');close.onclick=closeBrain;overlay.onclick=closeBrain;
    const showTab=tab=>{
      const active=['activity','brain','history'].includes(tab)?tab:'brain';
      drawer.querySelectorAll('[data-brain-tab]').forEach(b=>{const selected=b.dataset.brainTab===active;
        b.setAttribute('aria-selected',String(selected));b.tabIndex=selected?0:-1;});
      for(const [key,id] of Object.entries({activity:'adminBrainActivityPanel',brain:'adminBrainCorePanel',history:'adminBrainHistoryPanel'}))
        drawer.querySelector('#'+id).hidden=key!==active;
    };
    drawer.querySelectorAll('[data-brain-tab]').forEach(b=>b.onclick=()=>showTab(b.dataset.brainTab));
    showTab(initialTab);
    void c.api('activity-proof.php').then(result=>{
      if(!drawer.isConnected)return;
      const events=result.events||[];
      drawer.querySelector('#adminBrainActivityContent').innerHTML=proofHtml(events.filter(e=>e.verified===true),8);
      drawer.querySelector('#adminBrainHistoryContent').innerHTML='<h3>Saved record receipts &amp; reviewed proposals</h3>'+
        proofHtml(events,80)+'<h3>Agent Brain decision timeline</h3><p>Read-only decisions and routing; these entries do not prove an action was saved.</p>'+
        brainDecisionHtml(result.brain_decisions||[]);
    }).catch(error=>{if(!drawer.isConnected)return;
      drawer.querySelector('#adminBrainActivityContent').textContent='Notifications unavailable: '+error.message;
      drawer.querySelector('#adminBrainHistoryContent').textContent='History unavailable: '+error.message;
    });
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
      // Optional, authenticated 91B1 read-only audit. An unavailable audit
      // must not prevent Admin from reviewing actual missions and evidence.
      const coverageResult=await c.api('agent-tools.php?audit=1').catch(()=>null);
      const coverage=coverageResult?.integration_coverage||null;
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
        '<h3>Agent capability coverage</h3><p>'+(coverage?.checks_passed?
          Number(coverage.registered_tools)+' registered tools · '+Number(coverage.registered_skills)+' skill definitions · '+
          Number(coverage.admin_navigation_only_count)+' module navigation routes · '+
          Number((coverage.reviewed_action_eligible||[]).length)+' reviewed proposal paths.':
          'Coverage diagnostic unavailable; no operational access inferred.')+
        ' Navigation is not record-level access. Registered actions still require their destination permissions and confirmations.</p>'+
        '<h3>Permitted tools</h3><ul class="admin-brain-list">'+safeTools+'</ul>';
      drawer.querySelector('#brainCommerceOpen').onclick=()=>{closeBrain();c.openView('commerce-ops');};
      drawer.querySelector('#brainProductionOpen').onclick=()=>{closeBrain();send('production calendar').catch(e=>c.say(e.message));};
      drawer.querySelector('#adminBrainDetailedHistory').onclick=()=>{closeBrain();window.SFAgentCampaigns?.showHistory?.();};
    }catch(e){const p=drawer.querySelector('#adminBrainMissionContent');if(p)p.textContent='Operational Brain data is unavailable.';}
  }
  window.SFAdminAgentCanvas={render,send,showBrain,togglePopup,isPopupOpen,refreshPopup,recordLocalPlayerTurn,refreshProofCards,newThread,openSongDraft};
})();
