(() => {
  'use strict';
  // Section 54: declarative turntable slots. Visual effects are decorative, never audio processing.
  const SLOTS=['base','platter','arm','label','effect'];
  const LABELS={base:'Player base',platter:'Record platter',arm:'Tonearm',label:'Center label',effect:'Visual effects',pack:'Experience packs'};
  const DEFAULTS={base:'base-studio',platter:'platter-classic',arm:'arm-studio',label:'label-classic',effect:'effect-none'};
  // Always usable built-in catalog; paid choices and ownership remain server-authoritative.
  const BUILTIN_MODULES=[['base','base-studio','Studio Turntable','studio'],['platter','platter-classic','Classic Grooved Vinyl','classic'],['arm','arm-studio','Studio Tonearm','studio'],['label','label-classic','Classic Center Label','classic'],['effect','effect-none','Clean Playback','none']].map(([slot,id,name,visual])=>({slot,id,name,visual,brand:'Stonefellow',price_cents:0,includes:[],active:true}));
  let registry=null,loading=null,catalogLoaded=false,catalogLoading=false,catalogError='',ctx=null,host=null,preview=null,artState={side:'A'},version=0,editor=null,activeTab='base',returnFocus=null;
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money=c=>'$'+(Math.max(0,Number(c)||0)/100).toFixed(2);
  const getModules=()=>registry?.modules||BUILTIN_MODULES;
  const moduleById=id=>getModules().find(m=>m.id===id);
  const defaults=()=>registry?.defaults||DEFAULTS;
  const installed=()=>({...defaults(),...(ctx?.appState.builder.playerModules||{})});
  const selected=()=>({...installed(),...(preview||{})});
  const owned=id=>!!registry?.owned?.includes(id) || Object.values(defaults()).includes(id);
  const current=()=>ctx?.appState.builder||{};
  const apiUrl=()=>((window.STONEFELLOW_CONFIG||{}).apiBase||'api').replace(/\/$/,'')+'/turntable-modules.php';
  async function load(){
    if(catalogLoaded)return registry;
    if(loading)return loading;
    const generation=version;
    catalogLoading=true;catalogError='';
    const controller=new AbortController();
    const timeout=setTimeout(()=>controller.abort(),8000);
    loading=fetch(apiUrl(),{credentials:'same-origin',cache:'no-store',signal:controller.signal}).then(async response=>{
      let result;
      try{result=await response.json();}catch(_){throw new Error('The player catalog returned invalid JSON.');}
      if(!response.ok||result?.ok!==true)throw new Error(result?.message||'The player catalog request failed ('+response.status+').');
      if(!Array.isArray(result.modules)||!Array.isArray(result.owned)||!result.defaults||typeof result.defaults!=='object')throw new Error('Player catalog data is incomplete.');
      if(generation!==version)return null;
      registry=result;catalogLoaded=true;catalogError=typeof result.warning==='string'?result.warning:'';
      return registry;
    }).catch(error=>{
      if(generation===version)catalogError=error?.name==='AbortError'?'The player catalog timed out.':(error?.message||'The player catalog could not be loaded.');
      throw error;
    }).finally(()=>{
      clearTimeout(timeout);
      if(generation===version){loading=null;catalogLoading=false;renderPartList();drawTrack();}
    });
    return loading;
  }
  function invalidate(){registry=null;loading=null;catalogLoaded=false;catalogLoading=false;catalogError='';version++;}
  function retryCatalog(){invalidate();renderPartList();load().catch(()=>{});}
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
    const main=host?.querySelector('.turntable-stage-body');if(!main||!ctx)return;
    const stages=[main,editor?.querySelector('.turntable-editor-preview .turntable-stage-body')].filter(Boolean);
    stages.forEach(stage=>{
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
    });
  }
  function renderStage(){
    const stage=document.createElement('aside');stage.className='builder-turntable-stage';stage.setAttribute('aria-label','Digital modular record player');
    stage.innerHTML='<div class="turntable-stage-body" data-base="studio" data-platter="classic" data-arm="studio" data-label="classic" data-effect="none">'+
      '<div class="turntable-deck"><div class="turntable-deck-head"><b data-record-brand>STONEFELLOW</b><span>HIGH FIDELITY · MODULAR PLAYER</span></div>'+
      '<div class="turntable-platter"><div class="turntable-disc"><div class="turntable-disc-grooves"></div>'+
      '<div class="turntable-center-label"><img data-record-artwork alt="Current song artwork" hidden><span data-record-fallback>SF</span><strong data-record-track>STONEFELLOW</strong><em data-record-side>SIDE A</em></div></div></div>'+
      '<div class="turntable-tonearm"><i></i><b></b></div><div class="turntable-light"></div>'+
      '<div class="turntable-deck-foot"><div><span>33⅓ RPM</span><strong data-record-state>READY</strong></div><span>PLAY · PAUSE IN MUSIC PLAYER</span></div>'+
      '<div class="turntable-hardware"><i aria-hidden="true" class="turntable-hardware-screw a"></i><i aria-hidden="true" class="turntable-hardware-screw b"></i><i aria-hidden="true" class="turntable-hardware-screw c"></i><i aria-hidden="true" class="turntable-hardware-screw d"></i><span class="turntable-rpm-switch" aria-label="Speed marking">33 ⅓ &nbsp; / &nbsp; 45</span><button type="button" class="turntable-settings-trigger" data-player-editor-open aria-haspopup="dialog" aria-controls="recordPlayerEditor" aria-label="Edit record player settings" title="Edit Record Player"><span aria-hidden="true">⚙</span></button><span aria-hidden="true" class="turntable-power-switch"></span><span aria-hidden="true" class="turntable-deck-serial">SF • STUDIO / 001</span></div></div>'+
      '<div class="turntable-visual-effect" aria-hidden="true"></div></div>';
    return stage;
  }

  const TABS=[...SLOTS,'pack'];
  function closeEditor(){if(editor?.open)editor.close();}
  function setEditorTab(tab,focus=false){
    if(!editor||!TABS.includes(tab))return;
    activeTab=tab;
    editor.querySelectorAll('[data-player-tab]').forEach(button=>{
      const on=button.dataset.playerTab===tab;
      button.setAttribute('aria-selected',String(on));button.tabIndex=on?0:-1;
    });
    editor.querySelectorAll('[data-player-panel]').forEach(panel=>{panel.hidden=panel.dataset.playerPanel!==tab;});
    if(focus)editor.querySelector('[data-player-tab="'+tab+'"]')?.focus();
  }
  function openPlayerEditor(trigger){
    if(!editor||!editor.isConnected)return;
    returnFocus=trigger||document.activeElement;
    activeTab='base';
    renderPartList();
    if(!editor.open)editor.showModal();
    setEditorTab(activeTab,true);
    if(!catalogLoaded&&!catalogLoading)load().catch(()=>{});
  }
  function renderPartList(){
    const box=editor?.querySelector('[data-turntable-options]');
    if(!box)return;
    // Render built-in options immediately, even before the live catalog is ready.
    const installedParts=installed(),sel=selected();
    const tabs='<div class="turntable-editor-tabs" role="tablist" aria-label="Record player components">'+
      TABS.map(slot=>'<button type="button" role="tab" id="player-tab-'+slot+'" data-player-tab="'+slot+'" aria-controls="player-panel-'+slot+'" aria-selected="'+(slot===activeTab)+'" tabindex="'+(slot===activeTab?'0':'-1')+'">'+esc(slot==='pack'?'Packs':LABELS[slot])+'</button>').join('')+'</div>';
    const panels=TABS.map(slot=>{
      if(slot==='pack')return '<section role="tabpanel" class="turntable-editor-panel" id="player-panel-pack" data-player-panel="pack" aria-labelledby="player-tab-pack" '+(activeTab!=='pack'?'hidden':'')+'>'+
        '<h3>Upgrade packages</h3><p class="player-note">Build a complete look with matching components.</p>'+
        '<div class="turntable-pack-list">'+(!catalogLoaded?'<p class="player-note">Upgrade packages will appear here when the live catalog is available.</p>':'')+getModules().filter(m=>m.slot==='pack').map(m=>
          '<div class="turntable-pack"><span><b>'+esc(m.name)+'</b><small>'+esc((m.includes||[]).map(id=>moduleById(id)?.name||id).join(' · '))+'</small></span>'+
          (owned(m.id)?'<span class="turntable-owned">Owned</span>':'<button type="button" data-tt-buy="'+esc(m.id)+'">Get pack · '+money(m.price_cents)+'</button>')+'</div>').join('')+'</div>'+
        '<p class="player-note">Visual effect packs change the turntable appearance only; they do not alter the sound or mastering.</p></section>';
      const parts=getModules().filter(m=>m.slot===slot),requested=sel[slot]||installedParts[slot],active=parts.some(m=>m.id===requested)?requested:defaults()[slot],isLocked=catalogLoaded&&!owned(active);
      const chosen=moduleById(active);
      return '<section role="tabpanel" class="turntable-editor-panel" id="player-panel-'+slot+'" data-player-panel="'+slot+'" aria-labelledby="player-tab-'+slot+'" '+(activeTab!==slot?'hidden':'')+'>'+
        '<h3>'+esc(LABELS[slot])+'</h3><p class="player-note">'+
        esc(({base:'Choose the materials and finish of the turntable.',platter:'Customize the vinyl platter and groove appearance.',arm:'Choose your tonearm finish and precision styling.',label:'Choose the center-label appearance.',effect:'Add decorative light and atmosphere effects.'})[slot])+'</p>'+
        '<div class="turntable-part-row"><label for="tt-'+slot+'">Select '+esc(LABELS[slot].toLowerCase())+'</label><select id="tt-'+slot+'" data-tt-slot="'+slot+'">'+
        parts.map(m=>'<option value="'+esc(m.id)+'" '+(m.id===active?'selected':'')+'>'+esc(m.name)+(owned(m.id)?' · Owned':' · '+money(m.price_cents))+'</option>').join('')+'</select>'+
        (isLocked?'<button type="button" class="turntable-unlock" data-tt-buy="'+esc(active)+'">Unlock '+money(chosen?.price_cents)+'</button>':'<small>Owned · available in your library</small>')+'</div>'+
        '<p class="player-note">'+(isLocked?'Preview only — purchase the module to save this finish.':'Installed · this appearance is saved with your record design.')+'</p></section>';
    }).join('');
    const notice=!catalogLoaded?'<div class="turntable-catalog-notice" role="status"><div><strong>'+(catalogLoading?'Loading the complete player catalog…':'Built-in player options are ready')+'</strong><p>'+esc(catalogError||(catalogLoading?'The editor remains usable while more options load.':'The live catalog is unavailable. Retry to load owned and premium modules.'))+'</p></div>'+(!catalogLoading?'<button type="button" data-player-catalog-retry>Retry catalog</button>':'')+'</div>':(catalogError?'<div class="turntable-catalog-notice" role="status">'+esc(catalogError)+'</div>':'');
    box.innerHTML=notice+tabs+'<div class="turntable-editor-panels">'+panels+'</div>';
    box.querySelector('[data-player-catalog-retry]')?.addEventListener('click',retryCatalog);
    box.querySelectorAll('[data-player-tab]').forEach(button=>{
      button.onclick=()=>setEditorTab(button.dataset.playerTab);
      button.onkeydown=event=>{
        const i=TABS.indexOf(button.dataset.playerTab);
        const next=event.key==='ArrowRight'?TABS[(i+1)%TABS.length]:event.key==='ArrowLeft'?TABS[(i+TABS.length-1)%TABS.length]:event.key==='Home'?TABS[0]:event.key==='End'?TABS[TABS.length-1]:null;
        if(next){event.preventDefault();setEditorTab(next,true);}
      };
    });
    box.querySelectorAll('[data-tt-slot]').forEach(select=>select.onchange=()=>{
      const slot=select.dataset.ttSlot,id=select.value;
      if(!moduleById(id))return;
      if(owned(id)){ctx.appState.builder.playerModules={...installedParts,[slot]:id};preview=null;ctx.persist();}
      else preview={[slot]:id};
      renderPartList();drawTrack();
      editor?.querySelector('[data-tt-slot="'+slot+'"]')?.focus();
    });
    box.querySelectorAll('[data-tt-buy]').forEach(button=>button.onclick=()=>purchase(button.dataset.ttBuy));
    setEditorTab(activeTab);
  }
  function makePlayerEditor(){
    if(editor?.open)editor.close();editor?.remove?.();
    editor=document.createElement('dialog');editor.id='recordPlayerEditor';editor.className='turntable-editor-dialog';
    editor.setAttribute('aria-labelledby','playerEditorTitle');
    editor.innerHTML='<div class="turntable-editor-shell"><header class="turntable-editor-header"><div><span class="turntable-editor-kicker">STONEFELLOW · STUDIO</span><h2 id="playerEditorTitle">Edit Record Player</h2><p>Choose your player components. Owned finishes save to this record; other finishes can be previewed.</p></div><button type="button" class="turntable-editor-close" aria-label="Close player editor" data-player-editor-close>×</button></header>'+
      '<div class="turntable-editor-workspace"><div data-turntable-options class="turntable-editor-options"></div><section class="turntable-editor-preview" aria-label="Live record player preview"><div class="turntable-editor-preview-heading"><strong>Live player preview</strong><span>Visual only · changes do not alter audio</span></div><div data-player-preview class="turntable-editor-preview-canvas"></div></section></div><footer class="turntable-editor-footer"><span>Owned selections save automatically · unowned looks are previews only.</span><button type="button" data-player-editor-done>Done</button></footer></div>';
    const previewStage=renderStage();previewStage.querySelector('[data-player-editor-open]')?.remove();
    editor.querySelector('[data-player-preview]').appendChild(previewStage);
    editor.querySelector('[data-player-editor-close]').onclick=closeEditor;
    editor.querySelector('[data-player-editor-done]').onclick=closeEditor;
    editor.addEventListener('close',()=>{
      preview=null;drawTrack();
      if(returnFocus?.isConnected)returnFocus.focus();
      returnFocus=null;
    });
    document.body.appendChild(editor);
  }
  function purchase(id){
    const m=moduleById(id);if(!m||owned(id))return;
    if(!ctx.appState.auth.authenticated){ctx.say('Sign in to unlock '+m.name+'.',()=>ctx.navigate('login'));return;}
    if(ctx.appState.cart.some(x=>x.type==='turntable_module'&&x.module_id===id)){
      ctx.say('This upgrade is already in your cart.',()=>ctx.navigate('cart'));return;
    }
    ctx.appState.cart.push({type:'turntable_module',module_id:id});
    try{localStorage.setItem('stonefellow.cart.v03',JSON.stringify(ctx.appState.cart));}catch(_){}
    preview=null;closeEditor();ctx.say(m.name+' added to your cart. Purchases unlock after payment.',()=>ctx.navigate('cart'));
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
    makePlayerEditor();
    stage.querySelector('[data-player-editor-open]')?.addEventListener('click',event=>openPlayerEditor(event.currentTarget));
    const partBox=document.createElement('section');partBox.className='builder-parts-picker';
    partBox.innerHTML='<button type="button" class="builder-player-edit-button" data-player-editor-open aria-haspopup="dialog" aria-controls="recordPlayerEditor"><span aria-hidden="true">⚙</span> Edit Record Player</button><p class="player-note">Customize the base, platter, tonearm, center label, effects and upgrade packs.</p>';
    host.querySelector('[data-builder-player-options]')?.append(partBox);
    partBox.querySelector('[data-player-editor-open]')?.addEventListener('click',event=>openPlayerEditor(event.currentTarget));
    decorateCatalog();decorateArtwork();decorateNotes();renderPartList();drawTrack();
    if(!catalogLoaded&&!catalogLoading)load().catch(()=>{});
  }
  function sync(track,playing,builder){if(!host?.isConnected)return;drawTrack();}
  window.StonefellowTurntable={mount,sync,invalidate,sideForTrack,defaults,owned};
})();
