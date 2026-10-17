(() => {
  'use strict';
  const $=(s,r=document)=>r.querySelector(s);
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let state={roles:{},roster:[],timeline:{},selected:0};
  const ctx=()=>window.STONEFELLOW_ADMIN_CONTEXT;
  function drawTimeline(items,reset=true){
    const target=$('#teamAdminTimeline');
    if(!target)return;
    if(reset)target.textContent='';
    for(const e of items||[]){
      const div=document.createElement('div');div.className='team-admin-timeline-item';
      div.innerHTML='<small>'+esc(e.created_at||'')+'</small> <strong>'+esc(e.actor_name||'System')+
        '</strong> <span>'+esc(e.description||e.event_type||'Activity')+'</span> '+
        '<small>'+esc(e.source||'')+' · '+esc(e.entity_type||'')+' '+esc(e.entity_id||'')+'</small>';
      target.append(div);
    }
    if(reset&&!(items||[]).length)target.innerHTML='<p>There is no recorded activity yet.</p>';
  }
  function choose(){
    const id=Number($('#teamAdminUser')?.value||0),user=ctx()?.app?.users?.find(u=>Number(u.id)===id);
    state.selected=id;
    const assigned=state.roster.find(u=>Number(u.id)===id)?.team_roles||[];
    const options=$('#teamAdminRolePicker');
    if(options)options.innerHTML=Object.entries(state.roles).map(([role,meta])=>
      '<label class="team-role-choice"><input type="checkbox" data-team-role="'+esc(role)+'" '+
      (assigned.includes(role)?'checked':'')+'><span><strong>'+esc(meta.label||role)+'</strong><small>'+
      esc((meta.permissions||[]).join(' · '))+'</small></span></label>').join('');
    const note=$('#teamAdminSelected');
    if(note)note.textContent=user?'Editing '+(user.display_name||user.email||'user')+
      '. Team roles do not grant Super Admin rights.':'Select an existing registered user.';
  }
  async function refresh(){
    const c=ctx(),result=await c.api('team-workspace.php');
    state.roles=result.roles||{};
    state.roster=result.roster||[];
    state.timeline=result.timeline||{};
    return result;
  }
  async function render(){
    const c=ctx();if(!c?.canvas||!c.api)return;
    c.canvas.innerHTML='<section class="panel" role="status"><h2>Team Workspace</h2><p>Loading team roles and activity…</p></section>';
    try{await refresh();}catch(e){
      c.canvas.innerHTML='<section class="panel"><h2>Team Workspace</h2><p role="alert">'+esc(e.message)+'</p></section>';return;
    }
    const users=(c.app.users||[]).filter(u=>u.status==='active');
    c.canvas.innerHTML=c.head('PEOPLE · PERMISSIONS · AUDIT','Team Workspace',
      'One login per person, with multiple assigned roles, scoped tools, and a timestamped operational timeline.')+
      '<section class="panel"><h2>Team roles</h2><p>Assign roles to existing registered Stonefellow accounts. Website Managers may upload media, but cannot alter billing, user security or Admin permissions.</p>'+
      '<label for="teamAdminUser">Team account</label><select id="teamAdminUser"><option value="0">Choose an account</option>'+
      users.map(u=>'<option value="'+Number(u.id)+'">'+esc(u.display_name||u.email||'Account')+
        ' ('+esc(u.email||'')+')</option>').join('')+'</select>'+
      '<p id="teamAdminSelected">Select an existing registered user.</p><div id="teamAdminRolePicker" class="team-admin-role-grid"></div>'+
      '<button id="teamAdminSave" class="primary" type="button">Save selected roles</button><p id="teamAdminStatus" role="status"></p></section>'+
      '<section class="panel"><div class="panel-title"><h2>Unified activity timeline</h2><p>Team changes, media uploads, existing admin actions and site events.</p></div>'+
      '<div id="teamAdminTimeline" class="team-admin-timeline"></div>'+
      '<button id="teamAdminNext" type="button" class="secondary">Older activity</button></section>';
    $('#teamAdminUser').onchange=choose;
    $('#teamAdminSave').onclick=async()=>{
      const target=state.selected,status=$('#teamAdminStatus'),btn=$('#teamAdminSave');
      if(!target){status.textContent='Choose an account first.';return;}
      const roles=Array.from(c.canvas.querySelectorAll('[data-team-role]:checked')).map(x=>x.dataset.teamRole);
      if(!window.confirm('Save '+roles.length+' team role(s) for this user? This can immediately grant or revoke team tools.'))return;
      btn.disabled=true;
      try{await c.api('team-workspace.php',{action:'assign_roles',user_id:target,roles,confirmed:true});
        status.textContent='Roles saved. Changes are effective immediately and appear in the timeline.';
        await refresh();choose();drawTimeline(state.timeline.events);
      }catch(e){status.textContent=e.message||'Cannot change roles.';}finally{btn.disabled=false;}
    };
    drawTimeline(state.timeline.events);
    let offset=Number(state.timeline.next_offset||80);
    const next=$('#teamAdminNext');
    if(!state.timeline.has_more)next.hidden=true;
    next.onclick=async()=>{
      next.disabled=true;
      try{const r=await c.api('team-workspace.php?limit=80&offset='+offset);
        const timeline=r.timeline||{};
        drawTimeline(timeline.events||[],false);
        offset=Number(timeline.next_offset||offset+80);
        if(!timeline.has_more)next.hidden=true;
      }catch(e){c.say?.(e.message);}finally{next.disabled=false;}
    };
  }
  window.SFTeamAdmin={render};
})();
