(() => {
  'use strict';
  /**
   * Customer-only Agent Brain in the existing public canvas.
   * All data and writes go through the authenticated owner-scoped API.
   */
  async function render({canvas,api,post,esc,chooseConversation,navigate}){
    canvas.innerHTML='<article class="module"><h2>Your Agent Brain</h2><p role="status">Loading your private Agent context…</p></article>';
    const response=await api('agent-brain.php');
    const brain=response.brain||{},memories=brain.memories||[],threads=brain.conversations||[],decisions=brain.decisions||[];
    const cards=memories.map(m=>'<div class="brain-memory-row" style="display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #655744"><div><strong>'+esc(m.memory_key)+'</strong><p>'+esc(m.memory_text)+'</p></div><button type="button" class="text-control" data-forget="'+esc(m.memory_key)+'">Forget</button></div>').join('');
    const conversations=threads.map(t=>'<button type="button" class="text-control" data-resume="'+esc(t.id)+'" style="display:block;text-align:left;width:100%;margin:8px 0">'+esc(t.title||'Conversation')+' <small>'+esc((t.updated_at||'').slice(0,16))+'</small></button>').join('');
    const timeline=decisions.slice(0,20).map(t=>'<li><time>'+esc((t.created_at||'').slice(0,16))+'</time> · '+esc(t.phase||'route')+' · '+esc(t.route||t.action||'Agent')+'</li>').join('');
    canvas.innerHTML='<article class="module customer-agent-brain"><div class="module-kicker">YOUR PRIVATE AGENT</div><h2>My Agent Brain</h2><p>This is your personal Stonefellow Agent. Your memories and conversations are not shared with other customers or exposed in Admin operational reports.</p>'+
      '<div class="auth-actions"><button class="text-control" type="button" id="brainAccountPrivacy">Privacy settings</button><button class="text-control" type="button" id="brainClearMemories">Clear memories</button></div>'+
      '<section class="privacy-panel"><h3>Saved preferences</h3><p>'+ (brain.personalization_enabled?'Only preferences you save explicitly are remembered.':'Personalization is off. Enable it in Privacy settings before saving memories.') +'</p>'+
      (cards||'<p>No saved memories yet.</p>')+
      (brain.personalization_enabled?'<form id="brainRemember" class="auth-form"><label>Memory label<input name="label" maxlength="60" required placeholder="music preference"></label><label>What would you like the Agent to remember?<textarea name="text" maxlength="1200" required rows="3" placeholder="I enjoy acoustic recordings…"></textarea></label><button class="buy-primary" type="submit">Save preference</button></form>':'')+
      '<p id="brainMemoryStatus" role="status" class="player-note"></p></section>'+
      '<section class="privacy-panel"><h3>My conversations</h3>'+(conversations||'<p>Your conversations will appear here after chatting with Stonefellow.</p>')+'</section>'+
      '<section class="privacy-panel"><h3>My Agent activity</h3><p>Only routing and tool status are displayed here. Full private messages remain in your own conversations.</p><ul>'+ (timeline||'<li>No recorded Agent activity yet.</li>')+'</ul></section></article>';
    const one=q=>canvas.querySelector(q),all=q=>Array.from(canvas.querySelectorAll(q));
    one('#brainAccountPrivacy').onclick=()=>navigate('account-privacy');
    all('[data-resume]').forEach(button=>button.onclick=()=>{chooseConversation(button.dataset.resume);navigate('agent-chat');});
    all('[data-forget]').forEach(button=>button.onclick=async()=>{
      button.disabled=true;try{await post('agent-brain.php',{action:'forget',label:button.dataset.forget});await render({canvas,api,post,esc,chooseConversation,navigate});}
      catch(e){button.disabled=false;one('#brainMemoryStatus').textContent=e.message||'Could not change memory.';}
    });
    const save=one('#brainRemember');
    if(save)save.onsubmit=async event=>{
      event.preventDefault();const button=save.querySelector('button[type="submit"]');button.disabled=true;
      try{await post('agent-brain.php',{action:'remember',label:save.elements.label.value,text:save.elements.text.value});await render({canvas,api,post,esc,chooseConversation,navigate});}
      catch(e){button.disabled=false;one('#brainMemoryStatus').textContent=e.message||'Could not save memory.';}
    };
    one('#brainClearMemories').onclick=async()=>{
      if(!window.confirm('Clear every preference your personal Agent remembers? This does not delete your conversation history.'))return;
      try{await post('agent-brain.php',{action:'clear_memories',confirmation:'CLEAR'});await render({canvas,api,post,esc,chooseConversation,navigate});}
      catch(e){one('#brainMemoryStatus').textContent=e.message||'Could not clear memories.';}
    };
  }
  window.SFCustomerBrain={render};
})();
