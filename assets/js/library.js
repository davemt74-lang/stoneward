(() => {
'use strict';

const validTabs=new Set(['all','tracks','releases','playlists','purchases','builds','history','collections']);
const tabLabel={all:'All',tracks:'Tracks',releases:'Releases',playlists:'Playlists',purchases:'Purchases',builds:'Builds',history:'History',collections:'Collections'};
const kindLabel={track:'Track',release:'Release',playlist:'Playlist',purchase:'Purchase',build:'Saved build'};

function when(v){return String(v||'').replace('T',' ').slice(0,16)}
function itemSearchText(x){return [x.title,x.subtitle,x.source,x.kind,x.item_type,x.source_order_id].filter(Boolean).join(' ').toLowerCase()}
function sorted(rows,mode){
  const out=[...(rows||[])];
  if(mode==='title')out.sort((a,b)=>String(a.title||'').localeCompare(String(b.title||'')));
  else if(mode==='type')out.sort((a,b)=>String(a.kind||a.item_type||'').localeCompare(String(b.kind||b.item_type||''))||String(a.title||'').localeCompare(String(b.title||'')));
  else out.sort((a,b)=>String(b.saved_at||b.updated_at||b.acquired_at||b.added_at||'').localeCompare(String(a.saved_at||a.updated_at||a.acquired_at||a.added_at||'')));
  return out;
}
function art(c,x){
  const src=x.artwork||'';
  return src?`<img src="${c.esc(src)}" alt="">`:'<span>SF</span>';
}
function collectionPicker(c,lib,type,key){
  const rows=lib.collections||[];
  if(!rows.length)return '<button class="text-control compact" type="button" data-library-new-collection-for="1">New collection</button>';
  return `<details class="library-collect-menu"><summary>Collect</summary><div>${rows.map(x=>`<button type="button" data-library-collection-add="${x.id}" data-library-item-type="${c.esc(type)}" data-library-item-key="${c.esc(key)}">${c.esc(x.name)}</button>`).join('')}<button type="button" data-library-new-collection-for="1">+ New collection</button></div></details>`;
}
function itemCard(c,lib,x){
  const type=x.kind||x.item_type||'';
  const key=String(x.id??x.item_key??'');
  const subtitle=x.subtitle||'';
  const meta=x.saved_at?`Saved ${when(x.saved_at)}`:(x.acquired_at?`Purchased ${when(x.acquired_at)}`:'');
  let primary='';
  if(type==='track')primary=`<button class="buy-primary compact" type="button" data-library-play-track="${c.esc(key)}">Play</button>`;
  else if(type==='release')primary=`<button class="text-control compact" type="button" data-library-open-release="${c.esc(key)}">Open</button>`;
  else if(type==='playlist')primary=`<button class="buy-primary compact" type="button" data-library-play-playlist="${c.esc(key)}">Play</button><button class="text-control compact" type="button" data-library-open-playlist="${c.esc(key)}">Open</button>`;
  else if(type==='purchase')primary=`${x.track_id?`<button class="buy-primary compact" type="button" data-library-play-track="${c.esc(x.track_id)}">Play</button>`:''}${x.source_order_id?`<button class="text-control compact" type="button" data-library-open-order="${c.esc(x.source_order_id)}">Order</button>`:''}`;
  else if(type==='build')primary=`<button class="text-control compact" type="button" data-library-open-build="${c.esc(key)}">Open build</button>`;
  const favorite=(type==='track'||type==='release')?c.favoriteButton(type,key,'Favorite'):'';
  return `<article class="my-library-card" data-library-card data-library-search="${c.esc(itemSearchText(x))}" data-library-kind="${c.esc(type)}">
    <button class="my-library-art" type="button" data-library-open="${c.esc(type)}" data-library-key="${c.esc(key)}">${art(c,x)}</button>
    <div class="my-library-card-main">
      <span class="my-library-card-kind">${c.esc(kindLabel[type]||type||'Saved')}</span>
      <button class="my-library-title" type="button" data-library-open="${c.esc(type)}" data-library-key="${c.esc(key)}">${c.esc(x.title||'Saved item')}</button>
      <small>${c.esc(subtitle)}${meta?' · '+c.esc(meta):''}</small>
    </div>
    <div class="my-library-card-actions">${primary}${favorite}${collectionPicker(c,lib,type,key)}</div>
  </article>`;
}
function historyRows(c,lib,query=''){
  const rows=(lib.history||[]).filter(x=>!query||itemSearchText({title:x.title,subtitle:x.release,kind:x.event_type}).includes(query.toLowerCase()));
  return rows.length?rows.map(x=>`<article class="library-history-row" data-library-card data-library-search="${c.esc(itemSearchText({title:x.title,subtitle:x.release,kind:x.event_type}))}">
    <button class="library-history-play" type="button" data-library-play-track="${c.esc(x.track_id)}">▶</button>
    <div><strong>${c.esc(x.title)}</strong><small>${c.esc(x.release||'')} · ${c.esc(String(x.event_type||'listen').replaceAll('_',' '))} · ${c.time(x.position_seconds||0)}</small></div>
    <time>${c.esc(when(x.created_at))}</time>
  </article>`).join(''):'<div class="library-empty">No listening history yet.</div>';
}
function continueRail(c,lib){
  const rows=lib.continue_listening||[];
  if(!rows.length)return '';
  return `<section class="library-continue"><div class="library-section-head"><div><span>CONTINUE LISTENING</span><h3>Pick up where you left off</h3></div></div><div class="library-continue-grid">${rows.slice(0,8).map(x=>`<button type="button" class="library-continue-card" data-library-resume="${c.esc(x.track_id)}" data-library-position="${Number(x.position_seconds||0)}"><div class="library-continue-art">${x.artwork?`<img src="${c.esc(x.artwork)}" alt="">`:'SF'}</div><strong>${c.esc(x.title)}</strong><small>${c.esc(x.release||'')}</small><span class="library-progress"><i style="width:${Math.max(0,Math.min(100,Number(x.progress_percent||0)))}%"></i></span><em>${c.time(x.position_seconds||0)} / ${c.time(x.duration_seconds||0)}</em></button>`).join('')}</div></section>`;
}
function collectionList(c,lib){
  const rows=lib.collections||[];
  return `<section class="library-collections-overview"><div class="library-section-head"><div><span>COLLECTIONS</span><h3>Organize anything you save</h3></div><button class="text-control" type="button" data-library-create-collection>New collection</button></div>${rows.length?`<div class="library-collection-grid">${rows.map(x=>`<button type="button" class="library-collection-card" data-library-open-collection="${x.id}"><span>${Number(x.item_count||0)} item${Number(x.item_count||0)===1?'':'s'}</span><strong>${c.esc(x.name)}</strong><small>${c.esc(x.description||'Mixed Stonefellow collection')}</small></button>`).join('')}</div>`:'<div class="library-empty compact">Create a collection for favorite listening, releases you want to revisit, purchases, playlists, or saved custom-media projects.</div>'}</section>`;
}
function collectionDetail(c,lib,col){
  const rows=col?.items||[];
  return `<section class="library-collection-detail">
    <div class="library-collection-detail-head"><div><button class="text-control compact" type="button" data-library-back-collections>← Collections</button><span>COLLECTION</span><h2>${c.esc(col.name)}</h2><p>${c.esc(col.description||'')}</p></div><div class="library-collection-detail-actions"><button class="text-control" type="button" data-library-edit-collection="${col.id}">Edit</button><button class="text-control danger" type="button" data-library-delete-collection="${col.id}">Delete</button></div></div>
    <div class="library-collection-items">${rows.length?rows.map((x,i)=>`<article class="my-library-card collection-item-card"><div class="my-library-art">${art(c,x)}</div><div class="my-library-card-main"><span class="my-library-card-kind">${c.esc(kindLabel[x.item_type]||x.item_type)}</span><button class="my-library-title" type="button" data-library-open="${c.esc(x.item_type)}" data-library-key="${c.esc(x.item_key)}">${c.esc(x.title)}</button><small>${c.esc(x.subtitle||'')} · Added ${c.esc(when(x.added_at))}</small></div><div class="my-library-card-actions"><button class="text-control compact" type="button" data-library-move-item="${x.collection_item_id}" data-dir="-1" ${i===0?'disabled':''}>↑</button><button class="text-control compact" type="button" data-library-move-item="${x.collection_item_id}" data-dir="1" ${i===rows.length-1?'disabled':''}>↓</button><button class="text-control compact danger" type="button" data-library-remove-item="${x.collection_item_id}">Remove</button></div></article>`).join(''):'<div class="library-empty">This collection is empty. Use <strong>Collect</strong> on saved items to add something here.</div>'}</div>
  </section>`;
}
function tabs(c,lib,tab){
  const s=lib.summary||{};
  const counts={all:s.all||0,tracks:s.tracks||0,releases:s.releases||0,playlists:s.playlists||0,purchases:s.purchases||0,builds:s.builds||0,history:s.history||0,collections:s.collections||0};
  return `<div class="my-library-tabs" role="tablist">${Object.keys(tabLabel).map(k=>`<button type="button" class="${tab===k?'active':''}" data-library-tab="${k}"><span>${tabLabel[k]}</span><em>${Number(counts[k]||0)}</em></button>`).join('')}</div>`;
}
function renderGrid(c,lib,tab,query,sort){
  if(tab==='history')return `<div class="library-history-list">${historyRows(c,lib,query)}</div>`;
  const rows=sorted(lib[tab]||[],sort).filter(x=>!query||itemSearchText(x).includes(query.toLowerCase()));
  return rows.length?`<div class="my-library-list">${rows.map(x=>itemCard(c,lib,x)).join('')}</div>`:'<div class="library-empty">Nothing in this part of your library yet.</div>';
}
async function post(c,payload){const j=await c.securePost('library.php',payload);if(j.csrf)c.state.auth.csrf=j.csrf;return j}
function createCollectionPrompt(c,after){
  const name=prompt('Collection name');if(name===null)return;
  const clean=name.trim();if(!clean)return c.say('Collection name is required.');
  const description=prompt('Optional collection description','')??'';
  post(c,{action:'create_collection',name:clean,description}).then(j=>{c.say('Collection created.');after?.(j.library,j.collection)}).catch(e=>c.say(e.message));
}
function editCollectionPrompt(c,col,after){
  const name=prompt('Collection name',col.name||'');if(name===null)return;
  const clean=name.trim();if(!clean)return c.say('Collection name is required.');
  const description=prompt('Collection description',col.description||'');if(description===null)return;
  post(c,{action:'update_collection',collection_id:col.id,name:clean,description}).then(j=>{c.say('Collection updated.');after?.(j.library,j.collection)}).catch(e=>c.say(e.message));
}
function bindCommon(c,lib,rerender){
  c.bindFavoriteButtons(c.canvas);
  c.bindQueueTrackButtons(c.canvas);
  c.$$(' [data-library-play-track]'.trim(),c.canvas).forEach(b=>b.onclick=()=>{const t=c.trackById(b.dataset.libraryPlayTrack);if(t)c.play(t,0,'library')});
  c.$$(' [data-library-open-track]'.trim(),c.canvas).forEach(b=>b.onclick=()=>c.navigate('track',{id:b.dataset.libraryOpenTrack}));
  c.$$(' [data-library-open-release]'.trim(),c.canvas).forEach(b=>b.onclick=()=>c.navigate('release',{id:b.dataset.libraryOpenRelease}));
  c.$$(' [data-library-open-playlist]'.trim(),c.canvas).forEach(b=>b.onclick=()=>c.navigate('playlist',{id:b.dataset.libraryOpenPlaylist}));
  c.$$(' [data-library-open-order]'.trim(),c.canvas).forEach(b=>b.onclick=()=>c.navigate('order',{id:b.dataset.libraryOpenOrder}));
  c.$$(' [data-library-play-playlist]'.trim(),c.canvas).forEach(b=>b.onclick=()=>{const p=(lib.playlists||[]).find(x=>String(x.id)===String(b.dataset.libraryPlayPlaylist));if(p)c.playPlaylist(p,0)});
  c.$$(' [data-library-open-build]'.trim(),c.canvas).forEach(b=>b.onclick=()=>c.loadBuild(Number(b.dataset.libraryOpenBuild),lib.builds||[]));
  c.$$(' [data-library-open]'.trim(),c.canvas).forEach(b=>b.onclick=()=>{const type=b.dataset.libraryOpen,key=b.dataset.libraryKey;if(type==='track')c.navigate('track',{id:key});else if(type==='release')c.navigate('release',{id:key});else if(type==='playlist')c.navigate('playlist',{id:key});else if(type==='purchase'){const p=(lib.purchases||[]).find(x=>String(x.id)===String(key));if(p?.source_order_id)c.navigate('order',{id:p.source_order_id})}else if(type==='build')c.loadBuild(Number(key),lib.builds||[])});
  c.$$(' [data-library-resume]'.trim(),c.canvas).forEach(b=>b.onclick=()=>c.resumeTrack(b.dataset.libraryResume,Number(b.dataset.libraryPosition||0)));
  c.$$(' [data-library-collection-add]'.trim(),c.canvas).forEach(b=>b.onclick=async()=>{try{const j=await post(c,{action:'add_to_collection',collection_id:Number(b.dataset.libraryCollectionAdd),item_type:b.dataset.libraryItemType,item_key:b.dataset.libraryItemKey});c.say('Saved to collection.');rerender(j.library)}catch(e){c.say(e.message)}});
  c.$$(' [data-library-new-collection-for]'.trim(),c.canvas).forEach(b=>b.onclick=()=>createCollectionPrompt(c,(next)=>rerender(next)));
}
async function render(c,params={}){
  const {canvas,esc,$,$$}=c;
  let j;
  try{j=await c.api('library.php')}catch(e){if(e.payload?.error==='authentication_required'){c.navigate('login');return}canvas.innerHTML=`<article class="module"><div class="module-kicker">MY LIBRARY</div><h2>Library unavailable</h2><p class="player-note">${esc(e.message)}</p></article>`;return}
  if(j.csrf)c.state.auth.csrf=j.csrf;
  let lib=j.library||{},tab=validTabs.has(params.tab)?params.tab:'all',collectionId=Number(params.collection||0),query='',sort='recent';
  const paint=(nextLib=lib)=>{
    lib=nextLib||lib;
    const collection=collectionId?(lib.collections||[]).find(x=>Number(x.id)===collectionId):null;
    canvas.innerHTML=`<article class="module my-library-module">
      <div class="module-kicker">MY STONEFELLOW</div>
      <div class="my-library-head"><div><h2>My Library</h2><p class="player-note">Everything you’ve saved, bought, built, played, or organized — in one place.</p></div><div class="my-library-head-actions"><button class="text-control" type="button" data-view="music">Discover music</button><button class="text-control" type="button" data-library-create-collection>New collection</button></div></div>
      <div class="library-summary"><div><strong>${Number(lib.summary?.tracks||0)}</strong><span>Saved tracks</span></div><div><strong>${Number(lib.summary?.releases||0)}</strong><span>Saved releases</span></div><div><strong>${Number(lib.summary?.playlists||0)}</strong><span>Playlists</span></div><div><strong>${Number(lib.summary?.purchases||0)}</strong><span>Purchases</span></div><div><strong>${Number(lib.summary?.collections||0)}</strong><span>Collections</span></div></div>
      ${collection?collectionDetail(c,lib,collection):`${tabs(c,lib,tab)}${(tab==='all'||tab==='history')?continueRail(c,lib):''}${tab==='collections'?collectionList(c,lib):`<div class="my-library-toolbar"><input id="librarySearch" type="search" placeholder="Search this library…" value="${esc(query)}"><select id="librarySort"><option value="recent" ${sort==='recent'?'selected':''}>Recently saved</option><option value="title" ${sort==='title'?'selected':''}>Title A–Z</option><option value="type" ${sort==='type'?'selected':''}>Type</option></select></div><div id="myLibraryContent">${renderGrid(c,lib,tab,query,sort)}</div>`}`}
    </article>`;
    c.bind(canvas);
    bindCommon(c,lib,paint);
    $$('[data-library-tab]',canvas).forEach(b=>b.onclick=()=>c.navigate('library',{tab:b.dataset.libraryTab}));
    $$('[data-library-open-collection]',canvas).forEach(b=>b.onclick=()=>c.navigate('library',{tab:'collections',collection:b.dataset.libraryOpenCollection}));
    $$('[data-library-create-collection]',canvas).forEach(b=>b.onclick=()=>createCollectionPrompt(c,(next,col)=>{lib=next;c.navigate('library',{tab:'collections',collection:col?.id||''})}));
    const search=$('#librarySearch',canvas),sortEl=$('#librarySort',canvas),content=$('#myLibraryContent',canvas);
    if(search&&content)search.oninput=()=>{query=search.value||'';content.innerHTML=renderGrid(c,lib,tab,query,sort);bindCommon(c,lib,paint)};
    if(sortEl&&content)sortEl.onchange=()=>{sort=sortEl.value||'recent';content.innerHTML=renderGrid(c,lib,tab,query,sort);bindCommon(c,lib,paint)};
    if(collection){
      $$('[data-library-back-collections]',canvas).forEach(b=>b.onclick=()=>c.navigate('library',{tab:'collections'}));
      $$('[data-library-edit-collection]',canvas).forEach(b=>b.onclick=()=>editCollectionPrompt(c,collection,(next,col)=>{lib=next;collectionId=Number(col?.id||collectionId);paint(lib)}));
      $$('[data-library-delete-collection]',canvas).forEach(b=>b.onclick=async()=>{if(!confirm('Delete this collection? The saved items themselves will not be deleted.'))return;try{const x=await post(c,{action:'delete_collection',collection_id:collection.id});lib=x.library;collectionId=0;c.navigate('library',{tab:'collections'})}catch(e){c.say(e.message)}});
      $$('[data-library-remove-item]',canvas).forEach(b=>b.onclick=async()=>{try{const x=await post(c,{action:'remove_from_collection',collection_id:collection.id,collection_item_id:Number(b.dataset.libraryRemoveItem)});lib=x.library;paint(lib)}catch(e){c.say(e.message)}});
      $$('[data-library-move-item]',canvas).forEach(b=>b.onclick=async()=>{try{const x=await post(c,{action:'move_collection_item',collection_id:collection.id,collection_item_id:Number(b.dataset.libraryMoveItem),direction:Number(b.dataset.dir||1)});lib=x.library;paint(lib)}catch(e){c.say(e.message)}});
    }
  };
  paint(lib);
}
window.StonefellowLibrary={render};
})();
