(() => {
  'use strict';
  /**
   * Customer-only Agent Brain in the existing public canvas.
   * All data and writes go through the authenticated owner-scoped API.
   */
  async function render({canvas,api,post,esc,chooseConversation,navigate}){
    canvas.innerHTML='<article class="module"><h2>Your Agent Brain</h2><p role="status">Loading your private Agent context…</p></article>';
    const response=await api('agent-brain.php');
    const brain=response.brain||{},memories=brain.memories||[],threads=brain.conversations||[],decisions=brain.decisions||[],plans=brain.planned_missions||[],specialists=brain.specialist_assignments||[],suggestion=brain.proactive_suggestion||null,feedbackSummary=brain.feedback_summary||{},recovery=brain.recovery_summary||{};
    const cards=memories.map(m=>'<div class="brain-memory-row" style="display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #655744"><div><strong>'+esc(m.memory_key)+'</strong><p>'+esc(m.memory_text)+'</p></div><button type="button" class="text-control" data-forget="'+esc(m.memory_key)+'">Forget</button></div>').join('');
    const conversations=threads.map(t=>'<button type="button" class="text-control" data-resume="'+esc(t.id)+'" style="display:block;text-align:left;width:100%;margin:8px 0">'+esc(t.title||'Conversation')+' <small>'+esc((t.updated_at||'').slice(0,16))+'</small></button>').join('');
    const specialistLines=specialists.slice(0,6).flatMap(p=>(p.assignments||[]).map(a=>'<li>'+esc(a.specialist.replaceAll('_',' '))+' · '+esc(a.status)+'</li>')).join('');
    const missionLines=plans.slice(0,10).map(p=>'<li>'+esc((p.steps||[]).map(s=>s.intent+': '+s.status).join(' → '))+'</li>').join('');
    const timeline=decisions.slice(0,20).map(t=>'<li><time>'+esc((t.created_at||'').slice(0,16))+'</time> · '+esc(t.phase||'route')+' · '+esc(t.route||t.action||'Agent')+'</li>').join('');
    canvas.innerHTML='<article class="module customer-agent-brain"><div class="module-kicker">YOUR PRIVATE AGENT</div><h2>My Agent Brain</h2><p>This is your personal Stonefellow Agent. Your memories and conversations are not shared with other customers or exposed in Admin operational reports.</p>'+
      '<div class="auth-actions"><button class="text-control" type="button" id="brainAccountPrivacy">Privacy settings</button><button class="text-control" type="button" id="brainClearMemories">Clear memories</button></div>'+
      '<section class="privacy-panel"><h3>Saved preferences</h3><p>'+ (brain.personalization_enabled?'Only preferences you save explicitly are remembered.':'Personalization is off. Enable it in Privacy settings before saving memories.') +'</p>'+
      (cards||'<p>No saved memories yet.</p>')+
      (brain.personalization_enabled?'<form id="brainRemember" class="auth-form"><label>Memory label<input name="label" maxlength="60" required placeholder="music preference"></label><label>What would you like the Agent to remember?<textarea name="text" maxlength="1200" required rows="3" placeholder="I enjoy acoustic recordings…"></textarea></label><button class="buy-primary" type="submit">Save preference</button></form>':'')+
      '<p id="brainMemoryStatus" role="status" class="player-note"></p></section>'+
      '<section class="privacy-panel brain-recall-panel"><h3>Find something from an earlier chat</h3><p>Search your recent private conversations for something you told Stonefellow. Results are previous statements, not proof an action succeeded. This search stays on Stonefellow’s server and is not sent to the online LLM.</p>'+
      (brain.personalization_enabled?'<form id="brainRecallSearch" class="brain-recall-form"><label for="brainRecallQuery">Search earlier conversations</label><div class="brain-recall-controls"><input id="brainRecallQuery" name="query" type="search" maxlength="180" required placeholder="E.g. the playlist we discussed"><button type="submit" class="buy-primary">Find</button></div></form>':'<p>Turn on Personalization in Privacy settings to search previous conversations.</p>')+
      '<div id="brainRecallResults" class="brain-recall-results" role="status" aria-live="polite"></div></section>'+
      '<section class="privacy-panel"><h3>My conversations</h3>'+(conversations||'<p>Your conversations will appear here after chatting with Stonefellow.</p>')+'</section>'+
      '<section class="privacy-panel"><h3>Published music suggestion</h3><p>Opt in by saving a preference labeled “proactive suggestions” with the text “yes”. You can forget it anytime. Existing CRM engagement consent is also required.</p>'+(suggestion?'<p><strong>'+esc(suggestion.title)+'</strong> — '+esc(suggestion.reason)+'</p>':'<p>No opted-in suggestion available.</p>')+'</section>'+ 
      '<section class="privacy-panel"><h3>Feedback &amp; recovery</h3><p>'+Number(feedbackSummary.total||0)+' rated responses · '+Number(recovery.pending_approval||0)+' planned actions awaiting your approval. Your feedback stays in your account; nothing retries automatically.</p></section>'+ 
      '<section class="privacy-panel"><h3>My Agent specialists</h3><p>Specialists are scoped, read-only assignments. Changes still require your confirmation.</p><ul>'+(specialistLines||'<li>No specialist assignments yet.</li>')+'</ul></section>'+ 
      '<section class="privacy-panel"><h3>My mission plans</h3><p>Writes require separate approval; nothing here is automatically saved or sent.</p><ul>'+(missionLines||'<li>No mission plans yet.</li>')+'</ul></section>'+ 
      '<section class="privacy-panel"><h3>My Agent activity</h3><p>Only routing and tool status are displayed here. Full private messages remain in your own conversations.</p><ul>'+ (timeline||'<li>No recorded Agent activity yet.</li>')+'</ul></section></article>';
    const one=q=>canvas.querySelector(q),all=q=>Array.from(canvas.querySelectorAll(q));
    one('#brainAccountPrivacy').onclick=()=>navigate('account-privacy');
    all('[data-resume]').forEach(button=>button.onclick=()=>{chooseConversation(button.dataset.resume);navigate('agent-chat');});
    const findChats=one('#brainRecallSearch');
    if(findChats)findChats.onsubmit=async event=>{
      event.preventDefault();
      const q=String(findChats.elements.query.value||'').trim();if(!q)return;
      const button=findChats.querySelector('button'),results=one('#brainRecallResults');
      button.disabled=true;results.textContent='Searching your recent conversations…';
      try{
        const found=await api('agent-brain.php?memory_query='+encodeURIComponent(q));
        const rows=found.brain?.recalled_conversation_excerpts||[];
        results.replaceChildren();
        if(!rows.length){results.textContent='No matching earlier statements found in your recent conversations.';return;}
        rows.forEach(row=>{
          const item=document.createElement('div');item.className='brain-recall-result';
          const p=document.createElement('p');p.textContent=(row.date||'Earlier chat')+' · “'+String(row.excerpt||'')+'”';item.append(p);
          const open=document.createElement('button');open.type='button';open.className='text-control';open.textContent='Open conversation';
          open.onclick=()=>{chooseConversation(String(row.conversation_id||''));navigate('agent-chat');};
          item.append(open);results.append(item);
        });
      }catch(e){results.textContent=e.message||'Could not search your conversations.';}
      finally{button.disabled=false;}
    };
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
