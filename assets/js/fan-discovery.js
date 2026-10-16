(() => {
 'use strict';
 const esc=s=>String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 function view(data,authenticated){
  const paths=data.paths||[],items=data.items||[];
  return '<section class="fan-guided-discovery" aria-label="Guided music discovery">'+
   '<div class="fan-guided-head"><div><small>GUIDED LISTENING</small><h3>Find your Stonefellow sound</h3>'+
   '<p>Choose a direction. Every suggestion comes from the released catalog.</p></div>'+
   '<button type="button" data-fan-start '+(!items.length?'disabled':'')+'>'+
   (authenticated?'Play this path':'Play the first song')+'</button>'+
   (authenticated?'<button type="button" data-fan-agent>Guide me with Agent Brain</button>':'')+'</div>'+
   '<div class="fan-guided-tabs" role="group" aria-label="Listening moods">'+
   paths.map(p=>'<button type="button" data-fan-path="'+esc(p.id)+'" aria-pressed="'+(p.id===data.path?'true':'false')+'">'+esc(p.title)+'</button>').join('')+
   '</div><p class="fan-guided-summary">'+esc(data.summary||'')+'</p>'+
   '<div class="fan-guided-tracks">'+(items.length?items.map((t,i)=>'<article class="fan-guided-track">'+
   '<span class="fan-guided-number">'+(i+1)+'</span>'+
   '<div><strong>'+esc(t.title)+'</strong><small>'+esc(t.release||'Stonefellow')+'</small>'+
   '<p>'+esc(t.reason||'')+'</p></div>'+
   '<div class="fan-guided-actions"><button type="button" data-fan-play="'+esc(t.track_id)+'">Play</button>'+
   '<button type="button" data-fan-open="'+esc(t.track_id)+'">Details</button>'+
   (authenticated?'<button type="button" data-fan-queue="'+esc(t.track_id)+'">Up Next</button>':'')+
   (data.allow_feedback?'<button type="button" data-fan-dislike="'+esc(t.track_id)+'">Not for me</button>':'')+
   (t.save_available?'<button type="button" data-fan-save="'+esc(t.track_id)+'" data-fan-saved="'+(t.saved?'1':'0')+'">'+(t.saved?'Saved for later':'Save song')+'</button>':'')+
   (t.digital?.action==='open_library'?'<button type="button" data-fan-library="'+esc(t.track_id)+'">Already owned · My Library</button>':'')+
   (t.digital?.action==='add_to_cart'?'<button type="button" data-fan-cart="'+esc(t.track_id)+'">Review digital purchase</button>':'')+
   (t.vinyl?.eligible?'<button type="button" data-fan-vinyl="'+esc(t.track_id)+'">Explore custom vinyl</button>':'')+
   (authenticated&&t.digital?'<button type="button" data-fan-buy-help="'+esc(t.track_id)+'">Ask Agent about owning this</button>':'')+
   '</div></article>').join(''):'<p>No released songs match this path yet.</p>')+'</div>'+
   (data.commercial_terms?'<p class="fan-guided-terms">'+esc(data.commercial_terms)+'</p>':'')+
   '<p class="fan-guided-privacy">'+esc(data.privacy||'')+'</p>'+
   '<p class="fan-guided-status" data-fan-status role="status" aria-live="polite"></p></section>';
 }
 async function playPath(items,host){
  const ids=(items||[]).map(x=>x.track_id).filter(Boolean);
  if(!ids.length)return {started:null,queued:0};
  host.play(ids[0]);let queued=0;
  if(host.authenticated)for(const id of ids.slice(1)){await host.queue(id);queued++;}
  return {started:ids[0],queued};
 }
 async function mount(element,host){
  if(!element)return;
  let active='start_here',request=0,stopped=false;
  const notify=message=>{const node=element.querySelector('[data-fan-status]');if(node)node.textContent=message;};
  async function refresh(path){
   const revision=++request;active=path;
   try{
    const r=await fetch((host.apiBase||'api')+'/'+(host.endpoint||'fan-discovery.php')+'?path='+encodeURIComponent(path),
      {credentials:'same-origin',cache:'no-store'});
    const j=await r.json();
    if(!r.ok||j.ok===false)throw Error(j.message||'Discovery unavailable');
    if(stopped||revision!==request||!element.isConnected)return;
    const data=j.journey||j.discovery;element.innerHTML=view(data,!!host.authenticated);
    bind(data);
   }catch(e){if(!stopped&&revision===request)element.textContent='Discovery unavailable: '+e.message;}
  }
  function bind(data){
   element.querySelectorAll('[data-fan-path]').forEach(b=>b.onclick=()=>refresh(b.dataset.fanPath));
   const agent=element.querySelector('[data-fan-agent]');
   if(agent)agent.onclick=()=>host.ask('Play me a '+data.paths.find(p=>p.id===active)?.title+' listening session');
   element.querySelectorAll('[data-fan-play]').forEach(b=>b.onclick=()=>host.play(b.dataset.fanPlay));
   element.querySelectorAll('[data-fan-open]').forEach(b=>b.onclick=()=>host.open(b.dataset.fanOpen));
   element.querySelectorAll('[data-fan-queue]').forEach(b=>b.onclick=()=>host.queue(b.dataset.fanQueue));
   element.querySelectorAll('[data-fan-dislike]').forEach(b=>b.onclick=async()=>{
    b.disabled=true;
    try{await host.feedback(b.dataset.fanDislike,'dislike',active);await refresh(active);}
    catch(e){b.disabled=false;notify(e.message);}
   });
   element.querySelectorAll('[data-fan-save]').forEach(b=>b.onclick=async()=>{
    b.disabled=true;
    try{
      await host.save(b.dataset.fanSave,b.dataset.fanSaved!=='1');
      notify('Song preference saved. A favorite is not a purchase.');
      await refresh(active);
    }catch(e){b.disabled=false;notify('Could not change saved song: '+e.message);}
   });
   element.querySelectorAll('[data-fan-cart]').forEach(b=>b.onclick=()=>{
    try{host.cart(b.dataset.fanCart);}catch(e){notify('Cart unavailable: '+e.message);}
   });
   element.querySelectorAll('[data-fan-library]').forEach(b=>b.onclick=()=>host.library(b.dataset.fanLibrary));
   element.querySelectorAll('[data-fan-vinyl]').forEach(b=>b.onclick=()=>host.vinyl(b.dataset.fanVinyl));
   element.querySelectorAll('[data-fan-buy-help]').forEach(b=>b.onclick=()=>{
    const id=b.dataset.fanBuyHelp;
    const song=(data.items||[]).find(t=>t.track_id===id);
    if(song)host.ask('Can I buy the Stonefellow song "'+song.title+'", or is it already in my purchased library? Explain without charging me.');
   });
   const start=element.querySelector('[data-fan-start]');
   if(start)start.onclick=async()=>{
    if(!data.items?.length)return;
    start.disabled=true;
    try{
     const outcome=await playPath(data.items,host);
     notify(host.authenticated?outcome.queued+' following songs added to Up Next.':'Playing the first Stonefellow song.');
    }catch(e){notify('Could not queue every track: '+e.message);}
    finally{start.disabled=false;}
   };
  }
  await refresh(active);
  return ()=>{stopped=true;request++;};
 }
 window.STONEFELLOW_FAN_DISCOVERY={view,mount,playPath};
})();