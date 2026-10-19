(() => {
  'use strict';
  const esc=v=>String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  async function render(host){
    const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
    if(!ctx?.api||!host)return;
    let data;
    try{data=await ctx.api('story-capture.php');}
    catch(e){host.insertAdjacentHTML('beforeend','<section class="panel"><h2>Creative Director</h2><p role="alert">'+esc(e.message||'Capture unavailable')+'</p></section>');return;}
    let taskData={tasks:[],coverage:{gaps:[]},composer_queue:[],roster:[],task_updates:[],schedule_board:{tasks:[],counts:{}}};
    try{taskData=await ctx.api('story-production-tasks.php');}
    catch(e){/* Preserve existing shot review when optional task ledger is unavailable. */}
    const types=[['photo','Photo'],['video','Video'],['audio','Audio'],['ambient','Ambient sound'],['dialogue','Dialogue'],['story_idea','Story beat'],['screenshot','Suggested screenshot']];
    const roles=data.role_types||{};
    const queue=Array.isArray(data.review_queue)?data.review_queue:[],
      requests=Array.isArray(data.requests)?data.requests:[];
    const section=document.createElement('section');section.className='panel creative-director-panel';
    section.innerHTML='<div class="panel-title"><div><h2>Creative Director · Shot Requests</h2>'+
      '<p>Request evidence from the community; AI suggestions remain drafts until you approve them.</p></div></div>'+
      '<form id="directorRequestForm" class="director-form"><label>Shot title<input name="title" maxlength="180" required placeholder="A quiet moment backstage"></label>'+
      '<label>Capture type<select name="shot_type">'+types.map(t=>'<option value="'+t[0]+'">'+t[1]+'</option>').join('')+'</select></label>'+
      '<label>Who should see it?<select name="target_role">'+Object.entries(roles).map(([role,label])=>'<option value="'+esc(role)+'">'+esc(label)+'</option>').join('')+'</select></label>'+
      '<label>Story context<input name="world_context" maxlength="180" placeholder="Chapter / character / event"></label>'+
      '<label>What to capture<textarea name="brief" maxlength="2800" rows="3" required></textarea></label>'+
      '<label>Creative suggestion or proposed dialogue<textarea name="creative_suggestion" maxlength="2500" rows="2"></textarea></label>'+
      '<label>Origin<select name="suggestion_origin"><option value="human">Director / human</option><option value="ai_suggestion">AI suggestion pending review</option></select></label>'+
      '<label><input name="publish" type="checkbox"> Open for contributors now (human-created requests only)</label>'+
      '<button type="submit" class="primary">Add capture request</button></form>'+
      '<p id="directorStatus" role="status"></p>'+
      '<details class="director-task-panel" open><summary>Production tasks · crew and editor review</summary>'+
      '<p class="hint">Create proposals from verified Director coverage gaps or real pending AI editorial proposals. A human editor assigns and opens any linked shot request separately. No automatic publishing.</p>'+
      '<div class="director-task-actions">'+
      (taskData.coverage?.gaps||[]).map(g=>
        '<button type="button" data-task-gap="'+esc(g.code)+'">Propose '+esc(g.title)+' · '+Number(g.approved_assets)+'/'+Number(g.recommended_minimum)+'</button>').join('')+
      (taskData.composer_queue||[]).map(g=>
        '<button type="button" data-task-editorial="'+Number(g.id)+'">Propose AI editorial review #'+Number(g.id)+'</button>').join('')+
      '</div><div class="director-list">'+
      ((taskData.tasks||[]).map(t=>{
        const eligible=(taskData.roster||[]).filter(u=>t.target_role==='all'||(u.team_roles||[]).includes(t.target_role));
        const calendar=(taskData.schedule_board?.tasks||[]).find(x=>Number(x.task_id)===Number(t.id))||{};
        return '<div class="director-row"><div><small>Task #'+Number(t.id)+' · '+esc(t.kind)+
          ' · '+esc(t.status)+' · '+esc(t.target_role)+'</small><strong>'+esc(t.title)+'</strong>'+
          '<p>'+esc(t.brief)+'</p>'+
          (calendar.due_at?'<p class="hint">Due <strong>'+esc(calendar.due_at)+'</strong> · '+esc(calendar.priority)+
           ' priority · crew '+esc(calendar.availability||'unknown')+
           (calendar.flags?.length?' · '+esc(calendar.flags.join(', ')):'')+'</p>':'')+

          (t.kind==='shot'?'<p class="hint">Linked shot request #'+Number(t.source_id)+
            ' must be explicitly opened by a human before capture submission.</p>':'')+
          (t.evidence_id?'<p class="hint">Private evidence #'+Number(t.evidence_id)+
            ' · '+esc(t.contributor_note||'')+'</p>':'')+
          (t.reviewed_by?'<p class="hint">Human reviewer #'+Number(t.reviewed_by)+
            ': '+esc(t.review_basis||'')+'</p>':'')+
          '</div>'+
          (!['approved','rejected'].includes(t.status)?'<div class="director-task-controls">'+
           '<label>Target completion (local)<input type="datetime-local" data-task-due="'+Number(t.id)+'"></label>'+
           '<label>Priority<select data-task-priority="'+Number(t.id)+'"><option value="normal">Normal</option><option value="high">High</option><option value="critical">Critical</option></select></label>'+
           '<textarea rows="2" maxlength="750" data-task-schedule-reason="'+Number(t.id)+'" placeholder="Document scheduling purpose (20+ characters)"></textarea>'+
           '<button type="button" data-task-schedule="'+Number(t.id)+'">Set deadline</button></div>':'')+
          (['proposed','assigned'].includes(t.status)?'<div class="director-task-controls"><select data-task-assignee="'+Number(t.id)+'">'+
            '<option value="">Choose eligible team member</option>'+
            eligible.map(u=>'<option value="'+Number(u.id)+'" '+(Number(t.assigned_to)===Number(u.id)?'selected':'')+'>'+
              esc(u.display_name||('User #'+u.id))+'</option>').join('')+'</select>'+
            '<button type="button" data-task-assign="'+Number(t.id)+'">Assign</button></div>':'')+
          (t.status==='rejected'?'<div class="director-task-controls">'+
            '<p class="hint">Reopen only after documenting why the evidence was rejected. Old evidence stays in the audit history.</p>'+
            '<select data-task-recover-assignee="'+Number(t.id)+'">'+
            '<option value="">Choose eligible contributor</option>'+
            eligible.map(u=>'<option value="'+Number(u.id)+'" '+(Number(t.assigned_to)===Number(u.id)?'selected':'')+'>'+
              esc(u.display_name||('User #'+u.id))+'</option>').join('')+'</select>'+
            '<textarea data-task-recover-basis="'+Number(t.id)+'" rows="2" maxlength="900" placeholder="Reason and corrective direction (25+ characters)"></textarea>'+
            '<button type="button" data-task-reopen="'+Number(t.id)+'">Reopen with review</button></div>':'')+
          (t.status==='submitted'?'<div class="director-task-controls">'+
            '<textarea data-task-basis="'+Number(t.id)+'" maxlength="900" rows="2" placeholder="Independent verification and review basis (25+ characters)"></textarea>'+
            '<button type="button" data-task-review="'+Number(t.id)+'" data-decision="approved">Approve completion</button>'+
            '<button type="button" data-task-review="'+Number(t.id)+'" data-decision="rejected">Reject</button></div>':'')+
          '</div>';
      }).join('')||'<p>No production tasks assigned yet. Use a Director coverage recommendation to propose one.</p>')+
      '</div><p class="hint">Scheduling signals: '+Number(taskData.schedule_board?.counts?.overdue||0)+' overdue · '+
       Number(taskData.schedule_board?.counts?.due_soon||0)+' due soon · '+
       Number(taskData.schedule_board?.counts?.availability_conflict||0)+' crew availability conflicts. Review only, no automatic reassignment.</p>'+
       '<details><summary>Evidence-linked task activity · '+Number((taskData.task_updates||[]).length)+' recent updates</summary>'+
      '<ol class="director-task-activity">'+(taskData.task_updates||[]).map(e=>
        '<li><time>'+esc(e.created_at)+'</time> · Task #'+Number(e.task_id)+
        ' · '+esc(e.event_type)+' → '+esc(e.status_to)+'</li>').join('')+'</ol></details>'+
      '<p role="status" id="directorTaskStatus"></p></details><h3>Existing shot list</h3>'+

      '<div class="director-list">'+requests.map(r=>
        '<div class="director-row"><div><small>'+esc(r.review_status)+' · '+esc(r.shot_type)+' · '+esc(r.target_role)+'</small>'+
        '<strong>'+esc(r.title||'')+'</strong><p>'+esc(r.brief||'')+'</p></div>'+
        '<button type="button" data-shot-status="'+Number(r.id)+'" data-open="'+(r.review_status==='open'?'false':'true')+'">'+
        (r.review_status==='open'?'Close':'Approve / Open')+'</button></div>').join('')+'</div>'+
      '<h3>Private contributions awaiting review</h3><div class="director-list">'+
      (queue.map(r=>'<div class="director-row"><div><small>Contributor #'+Number(r.contributor_id)+' · '+esc(r.submission_type)+
        ' · '+esc(r.created_at)+'</small><strong>'+esc(r.title||'')+'</strong>'+
        '<p>'+esc(r.description||r.dialogue_text||'Media contribution')+'</p>'+
        '<small>'+esc(r.review_status)+' · people release: '+esc(r.people_release_status)+'</small></div>'+
        (r.review_status==='pending_review'?'<div class="director-actions">'+
          '<button type="button" data-capture-review="'+Number(r.id)+'" data-decision="approved_for_edit">Approve for edit</button>'+
          '<button type="button" data-capture-review="'+Number(r.id)+'" data-decision="needs_release">Needs release</button>'+
          '<button type="button" data-capture-review="'+Number(r.id)+'" data-decision="rejected">Reject</button></div>':'')+'</div>').join('')||'<p>No contributions yet.</p>')+
      '</div><p><small>Approval for editing does not publish, license, synthesize a likeness, or release any finished production.</small></p>';
    host.append(section);
    const taskStatus=section.querySelector('#directorTaskStatus');
    async function taskPost(payload){
      try{
        await ctx.api('story-production-tasks.php',payload);
        section.remove();await render(host);
      }catch(e){if(taskStatus)taskStatus.textContent=e.message||'Task update rejected.';}
    }
    section.querySelectorAll('[data-task-gap]').forEach(b=>b.onclick=()=>{
      if(window.confirm('Create one private Director task proposal? No request will be published.'))
        void taskPost({action:'propose_gap',code:b.dataset.taskGap});
    });
    section.querySelectorAll('[data-task-editorial]').forEach(b=>b.onclick=()=>{
      if(window.confirm('Create private human review task for this actual AI proposal?'))
        void taskPost({action:'propose_editorial',proposal_id:Number(b.dataset.taskEditorial)});
    });
    section.querySelectorAll('[data-task-assign]').forEach(b=>b.onclick=()=>{
      const user=Number(section.querySelector('[data-task-assignee="'+b.dataset.taskAssign+'"]')?.value);
      if(user>0&&window.confirm('Assign this private task to the selected role-eligible person?'))
        void taskPost({action:'assign',task_id:Number(b.dataset.taskAssign),assignee_id:user});
    });
    section.querySelectorAll('[data-task-review]').forEach(b=>b.onclick=()=>{
      const basis=section.querySelector('[data-task-basis="'+b.dataset.taskReview+'"]')?.value.trim()||'';
      if(basis.length<25){if(taskStatus)taskStatus.textContent='Record at least 25 characters of independent review evidence.';return;}
      if(window.confirm('Record '+b.dataset.decision+' for this completed task? This is not publication clearance.'))
        void taskPost({action:'review',task_id:Number(b.dataset.taskReview),
          decision:b.dataset.decision,basis});
    });
    section.querySelectorAll('[data-task-schedule]').forEach(button=>button.onclick=()=>{
      const id=Number(button.dataset.taskSchedule),
        value=section.querySelector('[data-task-due="'+id+'"]')?.value||'',
        reason=section.querySelector('[data-task-schedule-reason="'+id+'"]')?.value.trim()||'',
        priority=section.querySelector('[data-task-priority="'+id+'"]')?.value||'normal';
      const date=new Date(value);
      if(!value||!Number.isFinite(date.getTime())||reason.length<20){
        if(taskStatus)taskStatus.textContent='Select a deadline and explain it (20+ characters).';return;
      }
      if(window.confirm('Save this private task deadline? The AI Director will only recommend actions.'))
        void taskPost({action:'schedule',task_id:id,priority,
          due_at:date.toISOString().replace('.000Z','Z'),reason});
    });
    section.querySelectorAll('[data-task-reopen]').forEach(button=>button.onclick=()=>{
      const id=Number(button.dataset.taskReopen);
      const user=Number(section.querySelector('[data-task-recover-assignee="'+id+'"]')?.value||0);
      const basis=section.querySelector('[data-task-recover-basis="'+id+'"]')?.value.trim()||'';
      if(!user||basis.length<25){if(taskStatus)taskStatus.textContent='Choose an eligible contributor and give 25+ characters of corrective evidence.';return;}
      if(window.confirm('Reopen rejected work as a new assigned attempt? Prior evidence will remain in the private task activity history.'))
        void taskPost({action:'reopen',task_id:id,assignee_id:user,basis});
    });
    const status=section.querySelector('#directorStatus');
    const post=async body=>{
      try{await ctx.api('story-capture.php',body);status.textContent='Change recorded in the production audit timeline.';section.remove();await render(host);}
      catch(e){status.textContent=e.message||'Action failed.';}
    };
    section.querySelector('#directorRequestForm').onsubmit=e=>{
      e.preventDefault();const values=Object.fromEntries(new FormData(e.target).entries());
      values.publish=e.target.querySelector('[name="publish"]').checked;
      void post({...values,action:'create_request'});
    };
    section.querySelectorAll('[data-shot-status]').forEach(b=>b.onclick=()=>{
      if(!window.confirm('Change this shot request visibility?'))return;
      void post({action:'publish_request',request_id:Number(b.dataset.shotStatus),open:b.dataset.open==='true'});
    });
    section.querySelectorAll('[data-capture-review]').forEach(b=>b.onclick=()=>{
      if(!window.confirm('Record this editorial review decision?'))return;
      void post({action:'review_submission',submission_id:Number(b.dataset.captureReview),decision:b.dataset.decision});
    });
  }
  window.SFDirectorCaptureAdmin={render};
})();
