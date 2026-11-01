(() => {
  'use strict';
  const endpoint='api/speech-engine.php';
  const greetingKey='stonefellow.voice.greeting.v1';
  const engineKey='stonefellow.voice.engine.v1.'+String(window.STONEFELLOW_AUTH_BOOTSTRAP?.user?.id||'guest');
  let preferenceRequest=null;
  function rememberEngine(preference){
    const engine=preference?.pilot_available&&preference?.selected_engine==='speech_engine'?'speech_engine':'legacy';
    window.STONEFELLOW_VOICE_ENGINE_BOOTSTRAP=engine;
    localStorage.setItem(engineKey,engine);
    return engine;
  }
  let requestId=0;
  async function json(url,options={}) {
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.message||'Voice settings unavailable.');
    return data;
  }
  function loadEnginePreference(){
    if(!preferenceRequest)preferenceRequest=json(endpoint).then(data=>{rememberEngine(data.speech_engine);return data}).catch(error=>{preferenceRequest=null;throw error});
    return preferenceRequest;
  }
  async function save(engine,status,select) {
    select.disabled=true;
    try {
      const auth=await json('api/auth.php');
      const result=await json(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':auth.csrf||''},body:JSON.stringify({action:'set_preference',engine})});
      select.value=result.speech_engine.selected_engine;
      rememberEngine(result.speech_engine);
      preferenceRequest=Promise.resolve(result);
      status.textContent=engine==='legacy'?'Original voice engine selected.':'Speech Engine pilot selected. Click the microphone to connect; the separate gateway must be running.';
    }catch(error){status.textContent=error.message;select.value=select.dataset.previous||'legacy';}
    finally{select.disabled=false;}
  }
  async function mount() {
    const page=document.querySelector('#dynamicCanvas .auth-module');
    if(!page||!(page.querySelector('.account-header')||page.querySelector('#profileForm'))||page.querySelector('[data-account-speech-engine]'))return;
    const block=document.createElement('section');
    block.className='account-orders';block.dataset.accountSpeechEngine='';
    block.innerHTML='<div class="module-kicker">VOICE CONTROLS</div><p class="player-note">Select how your signed-in Stonefellow Agent will handle conversations. Existing ElevenLabs credentials remain in Admin; no key is stored here.</p><label class="field">Conversation engine <select data-voice-engine-choice disabled><option value="legacy">Original conversational voice (default)</option><option value="speech_engine">ElevenLabs Speech Engine pilot</option></select></label><label class="field"><input type="checkbox" data-voice-greeting-choice> Greet me when the microphone is ready</label><p class="player-note">The original voice engine greets once per page after microphone access succeeds, never on page load. Requires spoken replies to be on. The Speech Engine pilot manages its own greetings.</p><p class="player-note" role="status" aria-live="polite" data-voice-engine-status>Loading voice options…</p>';
    const before=page.querySelector('.fan-preferences')||page.querySelector('.notification-preferences');
    if(before)before.before(block);else page.append(block);
    const status=block.querySelector('[data-voice-engine-status]');
    const select=block.querySelector('[data-voice-engine-choice]');
    const greeting=block.querySelector('[data-voice-greeting-choice]');
    greeting.checked=localStorage.getItem(greetingKey)!=='off';
    greeting.addEventListener('change',()=>localStorage.setItem(greetingKey,greeting.checked?'on':'off'));
    const current=++requestId;
    try {
      const data=(await loadEnginePreference()).speech_engine;
      if(current!==requestId||!block.isConnected)return;
      select.value=data.selected_engine;
      select.dataset.previous=select.value;
      if(!data.pilot_available){select.querySelector('option[value="speech_engine"]').disabled=true;status.textContent='Speech Engine pilot is not yet enabled by Admin. Your original voice controls remain available.';}
      else status.textContent=data.selected_engine==='speech_engine'?'Speech Engine selected. The microphone starts a secure WebRTC session when clicked.':'Original conversational voice is active. Speech Engine is available as an opt-in pilot.';
      select.disabled=false;
      select.addEventListener('change',async()=>{const previous=select.dataset.previous;await save(select.value,status,select);select.dataset.previous=select.value||previous;});
    }catch(error){status.textContent=error.message;}
  }
  // Warm the preference before the first microphone tap. Never wait for this
  // network request inside the gesture-bound Safari capture path.
  if(window.STONEFELLOW_AUTH_BOOTSTRAP?.authenticated)void loadEnginePreference().catch(()=>{});
  document.addEventListener('stonefellow:navigation',event=>{if(event.detail?.view==='account')void mount();});
  // Account may re-render after saving preferences without a navigation event.
  const observer=new MutationObserver(()=>{if(document.querySelector('#dynamicCanvas .auth-module:not(:has([data-account-speech-engine]))'))void mount();});
  observer.observe(document.documentElement,{childList:true,subtree:true});
})();
