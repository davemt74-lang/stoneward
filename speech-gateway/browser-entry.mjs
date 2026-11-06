import { Conversation } from '@elevenlabs/client';

// This is bundled into Stonefellow's existing same-origin static assets.
// Only the short-lived, account-issued conversation token enters the browser.
const base='api/speech-engine.php';
let current=null,teardown=null;

async function request(url,options={}) {
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});
  const data=await response.json();
  if(!response.ok||data.ok===false)throw new Error(data.message||data.error||'Voice service unavailable.');
  return data;
}

async function post(action,fields={},signal) {
  const auth=await request('api/auth.php',{signal});
  return request(base,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':auth.csrf||''},body:JSON.stringify({action,...fields}),signal});
}

async function bind(conversationId,bindToken,signal) {
  let error;
  for(let attempt=0;attempt<7&&!signal.aborted;attempt++){
    try{return await post('bind',{conversation_id:conversationId,bind_token:bindToken},signal);}
    catch(e){error=e;if(attempt<6&&!signal.aborted)await new Promise(resolve=>setTimeout(resolve,250*(attempt+1)));}
  }
  throw error||new Error('Voice connection could not be verified.');
}

function release(session) {
  current=null;
  session.abort.abort();
  clearTimeout(session.inputUnmuteTimer);
}

function close(session) {
  release(session);
  if(!session.connecting)return teardown;
  // The SDK may still be acquiring the microphone. Let it finish and close
  // before another start can open a competing microphone session.
  const closing=session.connecting.then(conversation=>conversation?.endSession(),()=>{});
  const settled=closing.catch(error=>console.warn('[Stonefellow] Speech Engine cleanup failed',error));
  teardown=settled;
  void settled.then(()=>{if(teardown===settled)teardown=null;});
  return settled;
}

function applyOutputMute(session) {
  const {conversation,outputMuted}=session;
  if(!conversation)return;
  conversation.setVolume({volume:outputMuted?0:1});
  // The pinned WebRTC adapter sets element.volume, which iPhone can ignore.
  // Use its own remote elements for the binary mute and leave song audio alone.
  for(const audio of conversation.connection?.audioAdapter?.audioElements||[])audio.muted=outputMuted;
}

const pilot={
  get active(){return !!current;},
  async takeAction(){
    const session=current;
    if(!session?.ready)return null;
    const conversationId=session.conversation.getId();
    if(!conversationId)return null;
    try{
      const result=await post('take_action',{conversation_id:conversationId},session.abort.signal);
      return current===session?result.action_envelope||null:null;
    }catch(error){if(current===session)throw error;return null;}
  },
  setInputMuted(muted){
    const session=current;
    if(!session||session.inputMuted===Boolean(muted))return;
    session.inputMuted=Boolean(muted);
    clearTimeout(session.inputUnmuteTimer);
    session.inputUnmuteTimer=null;
    if(!session.conversation)return;
    if(session.inputMuted){session.conversation.setMicMuted(true);return;}
    session.inputUnmuteTimer=setTimeout(()=>{
      session.inputUnmuteTimer=null;
      if(current===session)session.conversation.setMicMuted(false);
    },500);
  },
  setOutputMuted(muted){
    const session=current;
    if(!session||session.outputMuted===Boolean(muted))return;
    session.outputMuted=Boolean(muted);
    applyOutputMute(session);
  },
  interrupt(){
    const conversation=current?.conversation;
    if(!conversation)return false;
    try{
      conversation.output?.interrupt?.();
      conversation.sendUserActivity?.();
      conversation.updateMode?.('listening');
      return true;
    }catch(error){console.warn('[Stonefellow] Speech Engine interrupt unavailable',error);return false;}
  },
  async start(handlers={}){
    if(current)return;
    const abort=new AbortController();
    const session={abort,connecting:null,conversation:null,ready:false,inputMuted:false,
      inputUnmuteTimer:null,outputMuted:Boolean(handlers.outputMuted)};
    current=session;
    const isCurrent=()=>current===session;
    const emit=(event,value)=>{if(isCurrent())handlers[event]?.(value);};
    const attachConversation=conversation=>{
      if(!isCurrent()||session.conversation===conversation)return;
      session.conversation=conversation;
      if(session.outputMuted)applyOutputMute(session);
      if(session.inputMuted)conversation.setMicMuted(true);
    };
    try{
      if(!navigator.mediaDevices?.getUserMedia)throw new Error('Microphone capture is not available in this browser.');
      if(teardown)await teardown;
      if(!isCurrent())return;
      const ticket=await post('start',{},abort.signal);
      if(!isCurrent())return;
      let connectedId='';
      // Register the pending connection before the SDK emits synchronous events.
      session.connecting=Promise.resolve().then(()=>{
        if(!isCurrent())return null;
        return Conversation.startSession({
          conversationToken:ticket.conversation_token,
          onConversationCreated:attachConversation,
          onConnect:({conversationId})=>{connectedId=conversationId;emit('onStatus','connected');},
          onDisconnect:()=>{
            if(!isCurrent())return;
            release(session);
            handlers.onStatus?.('disconnected');
          },
          onStatusChange:({status})=>{
            if(status!=='connected'&&status!=='disconnected')emit('onStatus',status);
          },
          onModeChange:({mode})=>emit('onMode',mode),
          onMessage:payload=>emit('onMessage',payload),
          onError:error=>emit('onError',typeof error==='string'?error:'Voice connection error.'),
          onDebug:({type})=>{
            if(type==='audio_element_ready'&&isCurrent()&&session.outputMuted)applyOutputMute(session);
          }
        });
      });
      const conversation=await session.connecting;
      if(!isCurrent())return;
      attachConversation(conversation);
      const id=connectedId||conversation.getId();
      if(!id)throw new Error('ElevenLabs did not assign a conversation ID.');
      await bind(id,ticket.bind_token,abort.signal);
      if(!isCurrent())return;
      session.ready=true;
      emit('onStatus','ready');
    }catch(error){
      if(!isCurrent())return;
      await close(session);
      throw error;
    }
  },
  async stop(){await (current?close(current):teardown);}
};

window.StonefellowSpeechEngine=pilot;
