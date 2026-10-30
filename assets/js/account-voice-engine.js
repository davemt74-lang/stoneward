(() => {
  'use strict';
  const endpoint='api/speech-engine.php';
  let requestId=0;
  async function json(url,options={}) {
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.message||'Voice settings unavailable.');
    return data;
  }
  async function save(engine,status,select) {
    select.disabled=true;
    try {
      const auth=await json('api/auth.php');
      const result=await json(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':auth.csrf||''},body:JSON.stringify({action:'set_preference',engine})});
      select.value=result.speech_engine.selected_engine;
      status.textContent=engine==='legacy'?'Original voice engine selected.':'Speech Engine pilot selected. Click the microphone to connect; the separate gateway must be running.';
    }catch(error){status.textContent=error.message;select.value=select.dataset.previous||'legacy';}
    finally{select.disabled=false;}
  }
  async function mount() {
    const page=document.querySelector('#dynamicCanvas .auth-module');
    if(!page||!(page.querySelector('.account-header')||page.querySelector('#profileForm'))||page.querySelector('[data-account-speech-engine]'))return;
    const block=document.createElement('section');
    block.className='account-orders';block.dataset.accountSpeechEngine='';
    block.innerHTML='<div class="module-kicker">VOICE CONTROLS</div><p class="player-note">Select how your signed-in Stonefellow Agent will handle conversations. Existing ElevenLabs credentials remain in Admin; no key is stored here.</p><label class="field">Conversation engine <select data-voice-engine-choice disabled><option value="legacy">Original conversational voice (default)</option><option value="speech_engine">ElevenLabs Speech Engine pilot</option></select></label><p class="player-note" role="status" aria-live="polite" data-voice-engine-status>Loading voice options…</p>';
    const before=page.querySelector('.fan-preferences')||page.querySelector('.notification-preferences');
    if(before)before.before(block);else page.append(block);
    const status=block.querySelector('[data-voice-engine-status]');
    const select=block.querySelector('[data-voice-engine-choice]');
    const current=++requestId;
    try {
      const data=(await json(endpoint)).speech_engine;
      if(current!==requestId||!block.isConnected)return;
      select.value=data.selected_engine;
      select.dataset.previous=select.value;
      if(!data.pilot_available){select.querySelector('option[value="speech_engine"]').disabled=true;status.textContent='Speech Engine pilot is not yet enabled by Admin. Your original voice controls remain available.';}
      else status.textContent=data.selected_engine==='speech_engine'?'Speech Engine selected. The microphone starts a secure WebRTC session when clicked.':'Original conversational voice is active. Speech Engine is available as an opt-in pilot.';
      select.disabled=false;
      select.addEventListener('change',async()=>{const previous=select.dataset.previous;await save(select.value,status,select);select.dataset.previous=select.value||previous;});
    }catch(error){status.textContent=error.message;}
  }
  document.addEventListener('stonefellow:navigation',event=>{if(event.detail?.view==='account')void mount();});
  // Account may re-render after saving preferences without a navigation event.
  const observer=new MutationObserver(()=>{if(document.querySelector('#dynamicCanvas .auth-module:not(:has([data-account-speech-engine]))'))void mount();});
  observer.observe(document.documentElement,{childList:true,subtree:true});
})();
