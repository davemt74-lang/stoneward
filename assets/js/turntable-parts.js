(() => {
  'use strict';
  // Section 54: declarative turntable slots. Visual effects are decorative, never audio processing.
  const SLOTS=['base','platter','arm','label','effect'];
  const LABELS={base:'Player base',platter:'Record platter',arm:'Tonearm',label:'Center label',effect:'Visual effects',pack:'Experience packs'};
  const DEFAULTS={base:'base-studio',platter:'platter-classic',arm:'arm-studio',label:'label-classic',effect:'effect-none'};
  let registry=null,loading=null,ctx=null,host=null,preview=null,artState={side:'A'},version=0;
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money=c=>'$'+(Math.max(0,Number(c)||0)/100).toFixed(2);
  const getModules=()=>registry?.modules||[];
  const moduleById=id=>getModules().find(m=>m.id===id);
  const defaults=()=>registry?.defaults||DEFAULTS;
  const installed=()=>({...defaults(),...(ctx?.appState.builder.playerModules||{})});
  const selected=()=>({...installed(),...(preview||{})});
  const owned=id=>!!registry?.owned?.includes(id) || Object.values(defaults()).includes(id);
  const current=()=>ctx?.appState.builder||{};
  const apiUrl=()=>((window.STONEFELLOW_CONFIG||{}).apiBase||'api')+'/turntable-modules.php';
  async function load(){
    if(registry)return registry;
    if(!loading)loading=fetch(apiUrl(),{credentials:'same-origin',cache:'no-store'}).then(async r=>{
      const data=await r.json();if(!r.ok||!data.ok)throw new Error('Player options unavailable.');
      registry=data;return data;
    }).finally(()=>{loading=null;});
    return loading;
  }
  function invalidate(){registry=null;loading=null;version++;}
  function sideForTrack(track,builder){
    if(!track)return 'A';
    const id=String(track.id);
    if((builder?.B||[]).some(x=>String(x)===id))return 'B';
    if((builder?.A||[]).some(x=>String(x)===id))return 'A';
    return '';
  }
  function shownTrack(){
    const {appState,trackById}=ctx||{};
    const b=appState?.builder||{};
    return appState?.track||trackById?.(b[artState.side]?.[0])||trackById?.(b.A?.[0])||trackById?.(b.B?.[0])||null;
  }
  function drawTrack(){
    const stage=host?.querySelector('.turntable-stage-body');if(!stage||!ctx)return;
    const t=shownTrack(),side=sideForTrack(t,current()),sel=selected(),label=moduleById(sel.label);
    const title=stage.querySelector('[data-record-track]'),sideText=stage.querySelector('[data-record-side]'),
          image=stage.querySelector('[data-record-artwork]'),fallback=stage.querySelector('[data-record-fallback]');
    title.textContent=t?.title||current().title||'Stonefellow';
    sideText.textContent=side?'SIDE '+side:(t?'CATALOG PREVIEW':'SIDE A');
    const url=label?.image|| (t?ctx.trackArtwork(t):'');
    if(url){image.src=url;image.hidden=false;fallback.hidden=true;}
    else{image.removeAttribute('src');image.hidden=true;fallback.hidden=false;}
    stage.classList.toggle('is-playing',!!ctx.appState.playing&&!!ctx.appState.track);
    stage.dataset.base=moduleById(sel.base)?.visual||'studio';
    stage.dataset.platter=moduleById(sel.platter)?.visual||'classic';
    stage.dataset.arm=moduleById(sel.arm)?.visual||'studio';
    stage.dataset.label=moduleById(sel.label)?.visual||'classic';
    stage.dataset.effect=moduleById(sel.effect)?.visual||'none';
    const accent=moduleById(sel.base)?.accent;
    if(/^#[a-f0-9]{6}$/i.test(accent||''))stage.style.setProperty('--deck-accent',accent);
    stage.querySelector('[data-record-brand]').textContent=moduleById(sel.base)?.brand||'Stonefellow';
    const status=stage.querySelector('[data-record-state]'),isPreview=!!preview&&Object.keys(preview).length>0;
    status.textContent=isPreview?'PREVIEW · UNOWNED MODULE':(ctx.appState.playing?'PLAYING':'READY');
  }
  function renderStage(){
    const stage=document.createElement('aside');stage.className='builder-turntable-stage';stage.setAttribute('aria-label','Digital modular record player');
    stage.innerHTML='<div class="turntable-stage-body" data-base="studio" data-platter="classic" data-arm="studio" data-label="classic" data-effect="none">'+
      '<div class="turntable-deck"><div class="turntable-deck-head"><b data-record-brand>STONEFELLOW</b><span>HIGH FIDELITY · MODULAR PLAYER</span></div>'+
      '<div class="turntable-platter"><div class="turntable-disc"><div class="turntable-disc-grooves"></div>'+
      '<div class="turntable-center-label"><img data-record-artwork alt="Current song artwork" hidden><span data-record-fallback>SF</span><strong data-record-track>STONEFELLOW</strong><em data-record-side>SIDE A</em></div></div></div>'+
      '<div class="turntable-tonearm"><i></i><b></b></div><div class="turntable-light"></div>'+
      '<div class="turntable-deck-foot"><div><span>33⅓ RPM</span><strong data-record-state>READY</strong></div><span>PLAY · PAUSE IN MUSIC PLAYER</span></div>'+
      '<div class="turntable-hardware" aria-hidden="true"><i class="turntable-hardware-screw a"></i><i class="turntable-hardware-screw b"></i><i class="turntable-hardware-screw c"></i><i class="turntable-hardware-screw d"></i><span class="turntable-rpm-switch">33 ⅓ &nbsp; / &nbsp; 45</span><span class="turntable-power-switch"></span><span class="turntable-deck-serial">SF • STUDIO / 001</span></div></div>'+
      '<div class="turntable-visual-effect" aria-hidden="true"></div></div>';
    return stage;
  }
  function renderPartList(){
    const box=host.querySelector('[data-turntable-options]');if(!box)return;
    if(!registry){box.innerHTML='<p class="player-note">Loading available player parts…</p>';return;}
    const installedParts=installed(),sel=selected();
    box.innerHTML='<div class="turntable-part-heading"><strong>Customize your digital player</strong><span>Try any look. Purchase a module to keep it.</span></div>'+
      SLOTS.map(slot=>{
        const parts=getModules().filter(m=>m.slot===slot),active=sel[slot]||installedParts[slot],isLocked=!owned(active);
        return '<div class="turntable-part-row"><label for="tt-'+slot+'">'+LABELS[slot]+'</label><select id="tt-'+slot+'" data-tt-slot="'+slot+'">'+
          parts.map(m=>'<option value="'+esc(m.id)+'" '+(m.id===active?'selected':'')+'>'+esc(m.name)+(owned(m.id)?' · Owned':' · '+money(m.price_cents))+'</option>').join('')+
          '</select>'+(isLocked?'<button type="button" class="turntable-unlock" data-tt-buy="'+esc(active)+'">Unlock '+money(moduleById(active)?.price_cents)+'</button>':'<small>Installed · available in your library</small>')+'</div>';
      }).join('')+
      '<div class="turntable-pack-list"><strong>Upgrade packages</strong>'+getModules().filter(m=>m.slot==='pack').map(m=>
        '<div class="turntable-pack"><span><b>'+esc(m.name)+'</b><small>'+esc(m.includes.map(id=>moduleById(id)?.name||id).join(' · '))+'</small></span>'+
        (owned(m.id)?'<span class="turntable-owned">Owned</span>':'<button type="button" data-tt-buy="'+esc(m.id)+'">Get pack · '+money(m.price_cents)+'</button>')+'</div>').join('')+'</div>'+
      '<p class="player-note">Visual effect packs change the turntable appearance only; they do not alter the sound or mastering.</p>';
    box.querySelectorAll('[data-tt-slot]').forEach(select=>select.onchange=()=>{
      const slot=select.dataset.ttSlot,id=select.value;
      if(!moduleById(id))return;
      if(owned(id)){ctx.appState.builder.playerModules={...installedParts,[slot]:id};preview=null;ctx.persist();}
      else preview={[slot]:id};
      renderPartList();drawTrack();
    });
    box.querySelectorAll('[data-tt-buy]').forEach(button=>button.onclick=()=>purchase(button.dataset.ttBuy));
  }
  function purchase(id){
    const m=moduleById(id);if(!m||owned(id))return;
    if(!ctx.appState.auth.authenticated){ctx.say('Sign in to unlock '+m.name+'.',()=>ctx.navigate('login'));return;}
    if(ctx.appState.cart.some(x=>x.type==='turntable_module'&&x.module_id===id)){
      ctx.say('This upgrade is already in your cart.',()=>ctx.navigate('cart'));return;
    }
    ctx.appState.cart.push({type:'turntable_module',module_id:id});
    try{localStorage.setItem('stonefellow.cart.v03',JSON.stringify(ctx.appState.cart));}catch(_){}
    preview=null;ctx.say(m.name+' added to your cart. Purchases unlock after payment.',()=>ctx.navigate('cart'));
  }
  function decorateCatalog(){
    const list=host.querySelector('.builder-song-list'),catalog=ctx.catalog;
    if(!list)return;
    const all=Array.from(list.querySelectorAll('.builder-song'));
    const cats=[...new Set(catalog.flatMap(t=>(t.mood||[]).map(x=>String(x).trim()).filter(Boolean)))].sort();
    const releases=[...new Set(catalog.map(t=>t.release||'Stonefellow'))].sort();
    const filters=document.createElement('div');filters.className='builder-catalog-filters';
    filters.innerHTML='<input type="search" data-builder-search placeholder="Search songs…" aria-label="Search catalog">'+
      '<select data-builder-category aria-label="Filter by category"><option value="">All categories</option>'+cats.map(x=>'<option>'+esc(x)+'</option>').join('')+'</select>'+
      '<select data-builder-release aria-label="Filter by release"><option value="">All releases</option>'+releases.map(x=>'<option>'+esc(x)+'</option>').join('')+'</select>';
    list.before(filters);
    const tracks=catalog;
    all.forEach((b,i)=>{
      const t=tracks[i];if(!t)return;b.dataset.search=(t.title+' '+(t.mood||[]).join(' ')+' '+(t.release||'')).toLowerCase();
      b.dataset.category=(t.mood||[]).join('|');b.dataset.release=t.release||'Stonefellow';
      const art=ctx.trackArtwork(t);if(art){const img=document.createElement('img');img.className='builder-song-art';img.src=art;img.alt='';img.loading='lazy';b.prepend(img);}
      const listen=document.createElement('button'); // Standalone sibling avoids nested interactive buttons.
      listen.type='button';listen.className='builder-song-listen';listen.textContent='▶';listen.title='Preview '+t.title;listen.setAttribute('aria-label','Play '+t.title);
      listen.onclick=()=>{ctx.play(t,0,'record_builder');drawTrack();};
      b.after(listen);
      const wrapper=document.createElement('div');wrapper.className='builder-catalog-row';b.parentNode.insertBefore(wrapper,b);wrapper.append(b,listen);
    });
    const apply=()=>{
      const query=(filters.querySelector('[data-builder-search]').value||'').trim().toLowerCase(),
            cat=filters.querySelector('[data-builder-category]').value,rel=filters.querySelector('[data-builder-release]').value;
      list.querySelectorAll('.builder-catalog-row').forEach(row=>{
        const b=row.querySelector('.builder-song');row.hidden=!!((query&&!b.dataset.search.includes(query))||
          (cat&&!b.dataset.category.split('|').includes(cat))||(rel&&b.dataset.release!==rel));
      });
    };
    filters.querySelectorAll('input,select').forEach(el=>el.addEventListener('input',apply));
  }
  function decorateArtwork(){
    const container=host.querySelector('.builder-artwork-layout .builder-object');if(!container)return;
    const currentTrack=shownTrack(),art=currentTrack?ctx.trackArtwork(currentTrack):'';
    const templates=[{id:'stonefellow',name:'Stonefellow Original'},{id:'desert',name:'Desert Edition'},{id:'midnight',name:'Midnight Edition'},{id:'analog',name:'Analog Edition'}];
    const extra=document.createElement('div');extra.className='builder-art-editor';
    const front=current().coverFront||'stonefellow',back=current().coverBack||'stonefellow';
    extra.innerHTML='<div class="module-kicker">COVER DESIGN</div><div class="builder-art-previews">'+
      '<div class="builder-cover-face" data-cover-front="'+esc(front)+'">'+(art?'<img src="'+esc(art)+'" alt="Album cover artwork">':'<strong>STONEFELLOW</strong>')+'<small>FRONT</small></div>'+
      '<div class="builder-cover-face back" data-cover-back="'+esc(back)+'"><strong>'+esc(current().title||'Custom Edition')+'</strong><small>BACK</small><p>'+esc(current().customNote||'Your custom record story')+'</p></div></div>'+
      '<div class="builder-art-fields"><label>Front artwork<select data-cover-select="coverFront">'+templates.map(x=>'<option value="'+x.id+'" '+(x.id===front?'selected':'')+'>'+x.name+'</option>').join('')+'</select></label>'+
      '<label>Back artwork<select data-cover-select="coverBack">'+templates.map(x=>'<option value="'+x.id+'" '+(x.id===back?'selected':'')+'>'+x.name+'</option>').join('')+'</select></label>'+
      '</div>';
    container.appendChild(extra);
    extra.querySelectorAll('[data-cover-select]').forEach(s=>s.onchange=()=>{current()[s.dataset.coverSelect]=s.value;ctx.persist();ctx.renderBuilder();});
  }
  function decorateNotes(){
    const section=host.querySelector('.builder-personal-note');if(!section)return;
    const label=document.createElement('label');label.className='builder-note';label.htmlFor='builderPersonalNote';label.textContent='Your message';
    const input=document.createElement('textarea');input.id='builderPersonalNote';input.dataset.recordNote='';
    input.rows=6;input.maxLength=280;
    input.placeholder='Write a personal dedication for the back cover…';input.value=current().customNote||'';
    const count=document.createElement('small');count.className='builder-note-count';count.textContent=input.value.length+' / 280';
    input.addEventListener('input',()=>{
      current().customNote=input.value.slice(0,280);
      count.textContent=current().customNote.length+' / 280';
      const back=host.querySelector('.builder-cover-face.back p');
      if(back)back.textContent=current().customNote||'Your custom record story';
      ctx.persist();
    });
    label.append(input);section.append(label,count);
  }
  function mount(context){
    ctx=context;host=context.canvas.querySelector('.builder-module');if(!host)return;
    preview=null;
    const stage=renderStage();host.appendChild(stage);
    const partBox=document.createElement('section');partBox.className='builder-parts-picker';partBox.dataset.turntableOptions='';
    host.querySelector('[data-builder-player-options]')?.append(partBox);
    decorateCatalog();decorateArtwork();decorateNotes();renderPartList();drawTrack();
    if(!registry){const expected=host;load().then(()=>{
      if(host===expected&&expected.isConnected){renderPartList();drawTrack();}
    }).catch(()=>{const box=expected.querySelector('[data-turntable-options]');if(box)box.innerHTML='<p class="player-note">Player catalog unavailable. The default player and record builder still work.</p>';});}
  }
  function sync(track,playing,builder){if(!host?.isConnected)return;drawTrack();}
  window.StonefellowTurntable={mount,sync,invalidate,sideForTrack,defaults,owned};
})();
