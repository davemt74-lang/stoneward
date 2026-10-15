(() => {
'use strict';
/* First-party Stonefellow rights wallet. The API is authoritative: this UI
   only renders safe metadata and never passes rights or tokens to requests. */
function render(c,owned,query=''){
  const esc=c.esc;
  if(owned.status==='loading')return '<section data-library-owned-root class="library-owned-status" role="status">Checking your Stonefellow purchases and vinyl print rights…</section>';
  if(owned.status==='error')return `<section data-library-owned-root class="library-owned-status" role="alert">Purchased music is unavailable: ${esc(owned.error||'Please try again.')} <button type="button" class="text-control compact" data-owned-retry>Retry</button></section>`;
  const all=Array.isArray(owned.tracks)?owned.tracks:[];
  const rows=all.filter(t=>!query||String(t.title||'').toLowerCase().includes(query.toLowerCase())).sort((a,b)=>String(a.title||'').localeCompare(String(b.title||'')));
  const totalPrint=all.reduce((n,t)=>n+Math.max(0,Number(t.vinyl_print_rights?.available||0)),0);
  const cards=rows.map(t=>{
    const id=String(t.id||''), rights=t.vinyl_print_rights||{};
    const granted=Math.max(0,Number(rights.granted||0)),reserved=Math.max(0,Number(rights.reserved_or_used||0)),available=Math.max(0,Number(rights.available||0));
    const pkg=Array.isArray(rights.packages)?rights.packages:[];
    const track=c.trackById(id);
    const play=track?.audio&&t.can_play?`<button class="buy-primary compact" type="button" data-owned-play="${esc(id)}">Play</button>`:'';
    const download=t.download_ready?`<a class="text-control compact" href="api/fan-owned-download.php?id=${esc(encodeURIComponent(id))}" download>Download master</a>`:'<small class="library-owned-unavailable">Original master not published</small>';
    const art=t.artwork?`<img src="${esc(t.artwork)}" alt="">`:'<span>SF</span>';
    return `<article class="my-library-card library-owned-card">
      <button class="my-library-art" type="button" data-library-open="track" data-library-key="${esc(id)}">${art}</button>
      <div class="my-library-card-main">
        <span class="my-library-card-kind">PURCHASED DIGITAL MUSIC</span>
        <button class="my-library-title" type="button" data-library-open="track" data-library-key="${esc(id)}">${esc(t.title||'Purchased song')}</button>
        <small>Owned streaming access · ${t.download_ready?'Private master published':'Private master pending'}</small>
        <div class="library-owned-rights"><strong>${available} vinyl print license${available===1?'':'s'} available</strong><span>${granted} granted · ${reserved} reserved/used</span></div>
        <small>One print license per song per record. Streaming and downloads never consume licenses.</small>
      </div>
      <div class="my-library-card-actions">${play}${download}${pkg.length?`<button type="button" class="text-control compact" data-owned-licenses="${esc(id)}">More print licenses</button>`:''}</div>
    </article>`;
  }).join('');
  return `<section data-library-owned-root class="library-owned-workspace">
    <div class="library-owned-overview">
      <div><span>PAID DIGITAL MUSIC</span><strong>${all.length} owned song${all.length===1?'':'s'}</strong><small>Verified against paid Stonefellow purchases</small></div>
      <div><span>VINYL PRINT RIGHTS</span><strong>${totalPrint} available</strong><small>Print licenses for these owned songs</small></div>
    </div>
    <p class="player-note library-owned-note">Owning digital music means you can listen without consuming vinyl rights. Each vinyl pressing needs a separate license for every song. Downloads are offered only when an original master has been privately published.</p>
    <div class="my-library-list">${cards||'<div class="library-empty">No matching purchased songs. Saved songs and favorites are available in your Tracks tab.</div>'}</div>
  </section>`;
}
function bind(c,onRetry){
  c.$$('[data-owned-play]',c.canvas).forEach(b=>b.onclick=()=>{
    const track=c.trackById(b.dataset.ownedPlay);
    if(track)c.play(track,0,'owned');
  });
  c.$$('[data-owned-licenses]',c.canvas).forEach(b=>b.onclick=()=>c.navigate('track',{id:b.dataset.ownedLicenses}));
  c.$$('[data-owned-retry]',c.canvas).forEach(b=>b.onclick=onRetry);
}
window.StonefellowOwnedMusic={render,bind};
})();
