(() => {
  'use strict';
  /**
   * A persistent conversation-first canvas over existing owner-scoped Agent
   * history, without creating another player or chat execution API.
   */
  let active=null;
  async function render({canvas,api,esc,post,send,conversationId,navigate,forceNew=false}){
    canvas.innerHTML='<article class="module"><h2>My Agent Chat</h2><p role="status">Loading your conversation…</p></article>';
    let activeId=conversationId()||'';
    let res=await api('agent-brain.php'+(activeId?'?conversation_id='+encodeURIComponent(activeId):''));
    let brain=res.brain||{};
    if(!activeId&&!forceNew&&brain.conversations?.[0]){
      activeId=brain.conversations[0].id;
      res=await api('agent-brain.php?conversation_id='+encodeURIComponent(activeId));
      brain=res.brain||brain;
    }
    const messages=res.brain?res.brain.messages||res.messages||[]:res.messages||[];
    const recentPlans=(brain.planned_missions||[]).slice(0,4);
    const safeStepRoute={public_catalog_search:'music',player_recommend:'music',create_playlist:'library',favorite_save:'library',favorite_remove:'library'};
    const missionCards=recentPlans.map(p=>'<li><strong>Mission '+esc(String(p.id||'').slice(0,8))+'</strong><ul>'+((p.steps||[]).slice(0,4).map(s=>{
      const route=safeStepRoute[s.intent];
      return '<li>'+esc(s.intent)+' · '+esc(s.status)+(s.requires_exact_approval?' (approval required)':'')+
        (route?'<button type="button" class="text-control" data-agent-navigate="'+route+'" aria-label="Open '+esc(s.intent)+' tool">Open tool</button>':'')+'</li>';
    }).join(''))+'</ul></li>').join('');
    const threadOptions=(brain.conversations||[]).map(t=>'<option value="'+esc(t.id)+'" '+(t.id===activeId?'selected':'')+'>'+esc(t.title||'Conversation')+'</option>').join('');
    canvas.innerHTML='<article class="module customer-agent-chat"><div class="module-kicker">PERSONAL MUSIC COMPANION</div>'+
      '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap"><h2>My Agent Chat</h2><div><button type="button" id="newAgentThread" class="text-control">New chat</button> <button type="button" id="openMyAgentBrain" class="text-control">My Agent Brain</button></div></div>'+
      '<p>This private conversation is connected to your Stonefellow music companion, playlists, saved preferences and current player. Admin cannot read your messages in operational reports.</p>'+
      '<label>Conversation <select id="agentThreadSelect"><option value="">New conversation</option>'+threadOptions+'</select></label>'+
      '<div class="agent-message-history" id="agentConversationMessages" role="log" aria-live="polite" aria-label="Agent conversation" style="max-height:55vh;overflow-y:auto;margin:14px 0">'+
      (messages.length?messages.map(m=>'<article class="agent-chat-bubble" style="padding:12px;margin:8px 0;border:1px solid #5e4e3c;border-radius:10px"><strong>'+(m.role==='assistant'?'Stonefellow Agent':'You')+'</strong><p style="white-space:pre-wrap;overflow-wrap:anywhere">'+esc(m.content||'')+'</p>'+(m.role==='assistant'&&Number(m.id)>0?'<div class="agent-response-feedback"><button type="button" class="text-control" data-agent-rating="helpful" data-agent-message-id="'+Number(m.id)+'">Helpful</button><button type="button" class="text-control" data-agent-rating="not_helpful" data-agent-message-id="'+Number(m.id)+'">Not helpful</button></div>':'')+'</article>').join(''):'<p>Start a conversation with your Agent below.</p>')+'</div>'+
      '<section class="privacy-panel"><h3>Recent governed mission steps</h3><p>These are recent plans across your private conversations. Opening a tool is not approval to save changes.</p><ul>'+(missionCards||'<li>No saved plans yet.</li>')+'</ul><div class="auth-actions"><button type="button" data-agent-navigate="music" class="text-control">Explore published music</button><button type="button" data-agent-navigate="library" class="text-control">Review my playlists</button><button type="button" data-agent-navigate="agent-brain" class="text-control">Review Agent Brain</button></div></section>'+ 
      '<form id="myAgentCanvasForm" class="auth-form"><label>Message to Stonefellow<input name="message" required maxlength="320" placeholder="What should we listen to tonight?"></label><button class="buy-primary" type="submit">Send to Agent</button></form>'+
      '<p id="myAgentCanvasStatus" role="status"></p></article>';
    const one=q=>canvas.querySelector(q);
    const log=one('#agentConversationMessages');if(log)log.scrollTop=log.scrollHeight;
    one('#newAgentThread').onclick=()=>{conversationId('');render({canvas,api,esc,post,send,conversationId,navigate,forceNew:true});};
    one('#openMyAgentBrain').onclick=()=>navigate('agent-brain');
    canvas.querySelectorAll('[data-agent-rating]').forEach(button=>button.onclick=async()=>{
      const status=one('#myAgentCanvasStatus');
      if(!post){status.textContent='Feedback service unavailable.';return;}
      button.disabled=true;
      try{await post('agent-feedback.php',{message_id:Number(button.dataset.agentMessageId),rating:button.dataset.agentRating});status.textContent='Feedback saved privately.';}
      catch(err){status.textContent='Feedback could not be saved.';}
      finally{button.disabled=false;}
    });
    canvas.querySelectorAll('[data-agent-navigate]').forEach(button=>button.onclick=()=>{
      const allowed={music:['music',{}],library:['library',{tab:'playlists'}],'agent-brain':['agent-brain',{}]};
      const destination=allowed[button.dataset.agentNavigate];
      if(destination)navigate(destination[0],destination[1]);
    });
    one('#agentThreadSelect').onchange=e=>{conversationId(e.target.value);render({canvas,api,esc,post,send,conversationId,navigate});};
    one('#myAgentCanvasForm').onsubmit=async e=>{
      e.preventDefault();
      const input=e.currentTarget.elements.message,btn=e.currentTarget.querySelector('button');
      const value=input.value.trim();if(!value)return;
      btn.disabled=true;one('#myAgentCanvasStatus').textContent='Asking your Agent…';
      try{await send(value);if(canvas.querySelector('#myAgentCanvasForm'))await render({canvas,api,esc,post,send,conversationId,navigate});}
      catch(err){btn.disabled=false;one('#myAgentCanvasStatus').textContent='Could not send the message.';}
    };
    active={canvas,refresh:()=>render({canvas,api,esc,post,send,conversationId,navigate})};
  }
  window.SFCustomerAgentChat={render,async onReply(){
    if(active&&active.canvas.isConnected&&active.canvas.querySelector('#myAgentCanvasForm')){
      try{await active.refresh();}catch(e){/* preserve existing chat on a network failure */}
    }
  }};
})();
