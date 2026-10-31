import { Conversation } from '@elevenlabs/client';

// This is bundled into Stonefellow's existing same-origin static assets.
// Only the short-lived, account-issued conversation token enters the browser.
const base='api/speech-engine.php';
let current=null,starting=false,sessionEpoch=0;

async function request(url,options={}) {
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});
  const data=await response.json();
  if(!response.ok||data.ok===false)throw new Error(data.message||data.error||'Voice service unavailable.');
  return data;
}

async function post(action,fields={}) {
  const auth=await request('api/auth.php');
  return request(base,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':auth.csrf||''},body:JSON.stringify({action,...fields})});
}

async function bind(conversationId,bindToken) {
  let error;
  for(let attempt=0;attempt<7;attempt++){
    try{return await post('bind',{conversation_id:conversationId,bind_token:bindToken});}
    catch(e){error=e;if(attempt<6)await new Promise(resolve=>setTimeout(resolve,250*(attempt+1)));}
  }
  throw error||new Error('Voice connection could not be verified.');
}

const pilot={
  async isSelected(){
    try{const state=(await request(base)).speech_engine;return !!state?.pilot_available&&state?.selected_engine==='speech_engine';}
    catch{return false;}
  },
  get active(){return !!current||starting;},
  async takeAction(){
    const active=current;
    if(!active)return null;
    const conversationId=active.getId();
    if(!conversationId)return null;
    const result=await post('take_action',{conversation_id:conversationId});
    if(active!==current)return null;
    return result.action_envelope||null;
  },
  async start(handlers={}){
    if(current||starting)return;
    starting=true;
    const epoch=++sessionEpoch;
    let created=null,disconnected=false;
    const ensureCurrent=()=>{if(epoch!==sessionEpoch||disconnected)throw new Error('Voice session was stopped before it was ready.');};
    try{
      if(!navigator.mediaDevices?.getUserMedia)throw new Error('Microphone capture is not available in this browser.');
      const stream=await navigator.mediaDevices.getUserMedia({audio:true});
      // The SDK opens its own WebRTC microphone; release this permissions probe.
      stream.getTracks().forEach(track=>track.stop());
      ensureCurrent();
      const ticket=await post('start');ensureCurrent();let connectedId='';
      created=await Conversation.startSession({
        conversationToken:ticket.conversation_token,
        onConnect:({conversationId})=>{connectedId=conversationId;handlers.onStatus?.('connected');},
        onDisconnect:()=>{disconnected=true;current=null;handlers.onStatus?.('disconnected');},
        onStatusChange:({status})=>handlers.onStatus?.(status),
        onModeChange:({mode})=>handlers.onMode?.(mode),
        onMessage:payload=>handlers.onMessage?.(payload),
        onError:error=>handlers.onError?.(typeof error==='string'?error:'Voice connection error.')
      });
      ensureCurrent();
      const id=connectedId||created.getId();
      if(!id)throw new Error('ElevenLabs did not assign a conversation ID.');
      await bind(id,ticket.bind_token);
      ensureCurrent();
      current=created;handlers.onStatus?.('ready');
    }catch(error){if(created)try{await created.endSession();}catch{}if(epoch===sessionEpoch)handlers.onError?.(error.message||'Could not start voice session.');throw error;}
    finally{starting=false;}
  },
  async stop(){sessionEpoch++;const active=current;current=null;if(active)await active.endSession();}
};

window.StonefellowSpeechEngine=pilot;
