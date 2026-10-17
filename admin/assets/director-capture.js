(() => {
  'use strict';
  const esc=v=>String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  async function render(host){
    const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
    if(!ctx?.api||!host)return;
    let data;
    try{data=await ctx.api('story-capture.php');}
    catch(e){host.insertAdjacentHTML('beforeend','<section class="panel"><h2>Creative Director</h2><p role="alert">'+esc(e.message||'Capture unavailable')+'</p></section>');return;}
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
      '<p id="directorStatus" role="status"></p><h3>Existing shot list</h3>'+
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
