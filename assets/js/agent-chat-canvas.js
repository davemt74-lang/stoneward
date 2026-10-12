(() => {
  'use strict';
  /**
   * A persistent conversation-first canvas over existing owner-scoped Agent
   * history, without creating another player or chat execution API.
   */
  let active=null;
  async function render({canvas,api,esc,send,conversationId,navigate,forceNew=false}){
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
    const threadOptions=(brain.conversations||[]).map(t=>'<option value="'+esc(t.id)+'" '+(t.id===activeId?'selected':'')+'>'+esc(t.title||'Conversation')+'</option>').join('');
    canvas.innerHTML='<article class="module customer-agent-chat"><div class="module-kicker">PERSONAL MUSIC COMPANION</div>'+
      '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap"><h2>My Agent Chat</h2><div><button type="button" id="newAgentThread" class="text-control">New chat</button> <button type="button" id="openMyAgentBrain" class="text-control">My Agent Brain</button></div></div>'+
      '<p>This private conversation is connected to your Stonefellow music companion, playlists, saved preferences and current player. Admin cannot read your messages in operational reports.</p>'+
      '<label>Conversation <select id="agentThreadSelect"><option value="">New conversation</option>'+threadOptions+'</select></label>'+
      '<div class="agent-message-history" id="agentConversationMessages" role="log" aria-live="polite" aria-label="Agent conversation" style="max-height:55vh;overflow-y:auto;margin:14px 0">'+
      (messages.length?messages.map(m=>'<article class="agent-chat-bubble" style="padding:12px;margin:8px 0;border:1px solid #5e4e3c;border-radius:10px"><strong>'+(m.role==='assistant'?'Stonefellow Agent':'You')+'</strong><p style="white-space:pre-wrap;overflow-wrap:anywhere">'+esc(m.content||'')+'</p></article>').join(''):'<p>Start a conversation with your Agent below.</p>')+'</div>'+
      '<form id="myAgentCanvasForm" class="auth-form"><label>Message to Stonefellow<input name="message" required maxlength="320" placeholder="What should we listen to tonight?"></label><button class="buy-primary" type="submit">Send to Agent</button></form>'+
      '<p id="myAgentCanvasStatus" role="status"></p></article>';
    const one=q=>canvas.querySelector(q);
    const log=one('#agentConversationMessages');if(log)log.scrollTop=log.scrollHeight;
    one('#newAgentThread').onclick=()=>{conversationId('');render({canvas,api,esc,send,conversationId,navigate,forceNew:true});};
    one('#openMyAgentBrain').onclick=()=>navigate('agent-brain');
    one('#agentThreadSelect').onchange=e=>{conversationId(e.target.value);render({canvas,api,esc,send,conversationId,navigate});};
    one('#myAgentCanvasForm').onsubmit=async e=>{
      e.preventDefault();
      const input=e.currentTarget.elements.message,btn=e.currentTarget.querySelector('button');
      const value=input.value.trim();if(!value)return;
      btn.disabled=true;one('#myAgentCanvasStatus').textContent='Asking your Agent…';
      try{await send(value);if(canvas.querySelector('#myAgentCanvasForm'))await render({canvas,api,esc,send,conversationId,navigate});}
      catch(err){btn.disabled=false;one('#myAgentCanvasStatus').textContent='Could not send the message.';}
    };
    active={canvas,refresh:()=>render({canvas,api,esc,send,conversationId,navigate})};
  }
  window.SFCustomerAgentChat={render,async onReply(){
    if(active&&active.canvas.isConnected&&active.canvas.querySelector('#myAgentCanvasForm')){
      try{await active.refresh();}catch(e){/* preserve existing chat on a network failure */}
    }
  }};
})();
