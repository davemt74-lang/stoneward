(() => {
  'use strict';
  let revision=0;
  async function json(path,options={}){
    const response=await fetch('api/'+path,{credentials:'same-origin',cache:'no-store',...options});
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.message||'Speech Engine configuration unavailable.');
    return data;
  }
  async function mount(){
    const canvas=document.querySelector('#adminCanvas');
    const anchor=canvas?.querySelector('#aiRoutingForm');
    if(!anchor||canvas.querySelector('[data-admin-speech-engine]'))return;
    const block=document.createElement('section');block.className='panel';block.dataset.adminSpeechEngine='';
    block.innerHTML='<div class="panel-title"><div><h2>ElevenLabs Speech Engine pilot</h2><p>Reuses the encrypted ElevenLabs API key above. The current voice runtime remains the default.</p></div></div><form data-speech-pilot-form class="form-grid"><label class="field span2">Speech Engine ID<input name="engine_id" placeholder="seng_…" maxlength="101" autocomplete="off"></label><label class="check-field span2"><input name="enabled" type="checkbox">Allow account-level pilot opt-in</label><div class="actions span3"><button type="submit" class="secondary">Save pilot settings</button></div><p class="hint span3" data-pilot-status role="status" aria-live="polite">Loading…</p></form>';
    anchor.closest('section')?.after(block);
    const form=block.querySelector('form'),status=block.querySelector('[data-pilot-status]');const current=++revision;
    try{
      const data=await json('ai-providers.php');if(current!==revision||!block.isConnected)return;
      const config=data.speech_engine||{};form.elements.engine_id.value=config.engine_id||'';form.elements.enabled.checked=!!config.pilot_enabled;
      status.textContent=config.ready?'Pilot configured. Confirm your separate gateway is online before testing WebRTC sessions.':'Pilot disabled or missing its existing ElevenLabs provider credentials / Engine ID.';
    }catch(error){status.textContent=error.message;}
    form.addEventListener('submit',async event=>{
      event.preventDefault();const button=form.querySelector('[type="submit"]');button.disabled=true;status.textContent='Saving…';
      try{
        const auth=await json('auth.php');
        const data=await json('ai-providers.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':auth.csrf||''},body:JSON.stringify({action:'save_speech_engine',engine_id:form.elements.engine_id.value,enabled:form.elements.enabled.checked})});
        status.textContent=data.speech_engine?.ready?'Pilot enabled. Account members may connect with WebRTC after the gateway is deployed.':'Pilot saved, currently unavailable.';
      }catch(error){status.textContent=error.message;}finally{button.disabled=false;}
    });
  }
  const observer=new MutationObserver(()=>{const canvas=document.querySelector('#adminCanvas');if(canvas?.querySelector('#aiRoutingForm')&&!canvas.querySelector('[data-admin-speech-engine]'))void mount();});
  observer.observe(document.documentElement,{childList:true,subtree:true});
})();
