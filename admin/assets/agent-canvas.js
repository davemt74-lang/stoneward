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
    const action=m.action||{};
    let next='';
    if(m.role==='assistant'&&action.type==='prepare_campaign')next='<button type="button" data-admin-agent-action="campaigns" class="secondary">Prepare exact-review campaign draft</button>';
    else if(m.role==='assistant'&&action.type==='prepare_journey')next='<button type="button" data-admin-agent-action="journey" class="secondary">Prepare exact-review journey draft</button>';
    else if(m.role==='assistant'&&action.type==='review_actions')next='<button type="button" data-admin-agent-action="brain" class="secondary">Inspect action approvals</button>';
    else if(m.role==='assistant'&&['crm_report','journey_report'].includes(action.type))next='<button type="button" data-admin-agent-action="'+esc(action.view)+'" class="secondary">Review '+esc(action.view)+'</button>';
    const brief=Array.isArray(action.insights)?action.insights.slice(0,3):[];
    const evidence=brief.map(i=>'<li><strong>'+esc(i.title)+'</strong> — '+esc(i.reason)+'</li>').join('');
    return '<article class="admin-agent-message '+(m.role==='assistant'?'agent':'human')+'"><strong>'+who+'</strong><p>'+esc(m.content||'')+'</p>'+(evidence?'<div class="admin-agent-insights"><strong>Evidence-backed opportunities</strong><ul>'+evidence+'</ul></div>':'')+next+'</article>';
  }
  function action(type){
    const c=ctx();if(!c)return;
    if(type==='brain'){showBrain();return;}
    if(type==='campaigns'){c.openView('campaigns');setTimeout(()=>window.SFAgentCampaigns?.open?.('newsletter'),0);return;}
    if(type==='journey'){c.openView('automations');setTimeout(()=>window.SFAutomationsAdmin?.openAutomation?.(0),0);return;}
    if(['crm','automations','analytics'].includes(type))c.openView(type);
  }
  function bindActions(root){root.querySelectorAll('[data-admin-agent-action]').forEach(b=>b.onclick=()=>action(b.dataset.adminAgentAction));}
  async function render(){
    const c=ctx();if(!c)return;
    c.canvas.innerHTML=c.head('ADMIN AGENT · OPERATIONS','Stonefellow Admin Agent','A separate Admin Brain for operational research, reviewed proposals and mission history. It cannot read customer private Agent memory.', '<button type="button" class="secondary" id="adminAgentOpenBrain">Agent Brain <span aria-hidden="true">↗</span></button><button type="button" class="secondary" id="adminAgentNewThread">New chat</button>')+
      '<section class="panel"><div class="admin-agent-transcript" id="adminAgentTranscript" role="log" aria-live="polite"><p>Loading your operational conversation…</p></div><form id="adminAgentCanvasForm" class="form-grid"><label class="field span3">Ask Admin Agent<input name="message" required maxlength="4000" placeholder="Review our fan journeys and prepare a follow-up campaign"></label><div class="actions span3"><button class="primary" type="submit">Send message</button></div><p id="adminAgentCanvasStatus" role="status" class="span3"></p></form></section>';
    const get=await load();lastBrain=get.brain||null;
    const transcript=c.canvas.querySelector('#adminAgentTranscript');
    const items=get.messages||[];
    transcript.innerHTML=items.length?items.map(messageHtml).join(''):'<p>Ask your Admin Agent about CRM performance, campaigns, fan journeys or the next reviewed action.</p>';
    transcript.scrollTop=transcript.scrollHeight;
    bindActions(transcript);
    c.canvas.querySelector('#adminAgentOpenBrain').onclick=showBrain;
    c.canvas.querySelector('#adminAgentNewThread').onclick=()=>{conversationId='';sessionStorage.removeItem('stonefellow.admin.agent.thread');render().catch(e=>c.say(e.message));};
    c.canvas.querySelector('#adminAgentCanvasForm').onsubmit=async e=>{
      e.preventDefault();const form=e.currentTarget,button=form.querySelector('button');button.disabled=true;
      const msg=form.elements.message.value.trim();
      try{await send(msg);}catch(err){const status=c.canvas.querySelector('#adminAgentCanvasStatus');if(status)status.textContent='Request unavailable. No action was executed.';}
      finally{button.disabled=false;}
    };
  }
  async function send(text){
    if(busy)return;
    const c=ctx();if(!c)throw new Error('Admin workspace unavailable.');
    const message=String(text||'').trim();if(!message)return;
    busy=true;
    try{
      const r=await c.api('agent-chat.php',{message,conversation_id:conversationId});
      conversationId=r.conversation_id||conversationId;
      if(conversationId)sessionStorage.setItem('stonefellow.admin.agent.thread',conversationId);
      c.openView('agent-chat');
      c.say(r.text||'Admin Agent is ready.');
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
      const missions=brain.missions||[],plans=brain.planned_missions||[],counts=brain.mission_counts||{},tools=brain.tool_inventory||[];
      const list=missions.length?missions.map(m=>'<li><strong>'+esc(m.name)+'</strong><span> '+esc(m.status)+' · '+esc(m.tool_id)+'</span>'+ (m.review_required?'<p>Review the original work before any retry.</p>':'')+'</li>').join(''):'<li>No reviewed Agent actions yet.</li>';
      const planLines=plans.slice(0,10).map(p=>'<li>'+esc((p.steps||[]).map(s=>s.intent+': '+s.status).join(' → '))+'</li>').join('');
      const safeTools=tools.filter(t=>!['internal'].includes(t.kind)).map(t=>'<li>'+esc(t.label)+' · '+esc(t.kind)+'</li>').join('');
      drawer.querySelector('#adminBrainMissionContent').innerHTML='<div class="stats"><div class="stat"><strong>'+Number(missions.length)+'</strong><span>Recent actions</span></div><div class="stat"><strong>'+Number(counts.review_required||0)+'</strong><span>Needs review</span></div><div class="stat"><strong>'+Number(counts.completed||0)+'</strong><span>Completed</span></div></div><h3>Mission timeline</h3><ul class="admin-brain-list">'+list+'</ul><button type="button" class="secondary" id="adminBrainDetailedHistory">Review exact action history</button><h3>Planned missions</h3><ul class="admin-brain-list">'+(planLines||'<li>No staged plans yet.</li>')+'</ul><h3>Permitted tools</h3><ul class="admin-brain-list">'+safeTools+'</ul>';
      drawer.querySelector('#adminBrainDetailedHistory').onclick=()=>{closeBrain();window.SFAgentCampaigns?.showHistory?.();};
    }catch(e){const p=drawer.querySelector('#adminBrainMissionContent');if(p)p.textContent='Operational Brain data is unavailable.';}
  }
  window.SFAdminAgentCanvas={render,send,showBrain};
})();
