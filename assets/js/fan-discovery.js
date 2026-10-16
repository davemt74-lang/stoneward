(() => {
 'use strict';
 const esc=s=>String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 function view(data,authenticated){
  const paths=data.paths||[],items=data.items||[];
  return '<section class="fan-guided-discovery" aria-label="Guided music discovery">'+
   '<div class="fan-guided-head"><div><small>GUIDED LISTENING</small><h3>Find your Stonefellow sound</h3>'+
   '<p>Choose a direction. Every suggestion comes from the released catalog.</p></div>'+
   '<button type="button" data-fan-start '+(!items.length?'disabled':'')+'>'+
   (authenticated?'Play this path':'Play the first song')+'</button></div>'+
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
   '</div></article>').join(''):'<p>No released songs match this path yet.</p>')+'</div>'+
   '<p class="fan-guided-privacy">'+esc(data.privacy||'')+'</p>'+
   '<p class="fan-guided-status" data-fan-status role="status" aria-live="polite"></p></section>';
 }
 async function mount(element,host){
  if(!element)return;
  let active='start_here',request=0,stopped=false;
  const notify=message=>{const node=element.querySelector('[data-fan-status]');if(node)node.textContent=message;};
  async function refresh(path){
   const revision=++request;active=path;
   try{
    const r=await fetch((host.apiBase||'api')+'/fan-discovery.php?path='+encodeURIComponent(path),
      {credentials:'same-origin',cache:'no-store'});
    const j=await r.json();
    if(!r.ok||j.ok===false)throw Error(j.message||'Discovery unavailable');
    if(stopped||revision!==request||!element.isConnected)return;
    const data=j.discovery;element.innerHTML=view(data,!!host.authenticated);
    bind(data);
   }catch(e){if(!stopped&&revision===request)element.textContent='Discovery unavailable: '+e.message;}
  }
  function bind(data){
   element.querySelectorAll('[data-fan-path]').forEach(b=>b.onclick=()=>refresh(b.dataset.fanPath));
   element.querySelectorAll('[data-fan-play]').forEach(b=>b.onclick=()=>host.play(b.dataset.fanPlay));
   element.querySelectorAll('[data-fan-open]').forEach(b=>b.onclick=()=>host.open(b.dataset.fanOpen));
   element.querySelectorAll('[data-fan-queue]').forEach(b=>b.onclick=()=>host.queue(b.dataset.fanQueue));
   element.querySelectorAll('[data-fan-dislike]').forEach(b=>b.onclick=async()=>{
    b.disabled=true;
    try{await host.feedback(b.dataset.fanDislike,'dislike',active);await refresh(active);}
    catch(e){b.disabled=false;notify(e.message);}
   });
   const start=element.querySelector('[data-fan-start]');
   if(start)start.onclick=async()=>{
    const ids=(data.items||[]).map(x=>x.track_id);
    if(!ids.length)return;
    start.disabled=true;
    try{
     host.play(ids[0]);
     if(host.authenticated)for(const id of ids.slice(1))await host.queue(id);
     notify(host.authenticated?'The rest of this path was added to Up Next.':'Playing the first Stonefellow song.');
    }catch(e){notify('Could not queue every track: '+e.message);}
    finally{start.disabled=false;}
   };
  }
  await refresh(active);
  return ()=>{stopped=true;request++;};
 }
 window.STONEFELLOW_FAN_DISCOVERY={view,mount};
})();