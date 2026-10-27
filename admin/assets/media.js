(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  const {app,api,canvas,head,say,esc,openView,openCatalogTrack,reloadCore}=ctx;
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const state={assets:[],category:'',query:'',trackId:'',trackLinks:[],publication:null,capabilities:{},completeness:{},dashboard:{},entityConfigs:new Map()};
  const roles={
    audio_candidate:{label:'Audio',category:'audio',accept:'.mp3,.wav,audio/mpeg,audio/wav',multiple:true},
    alternate_audio:{label:'Alternate audio',category:'audio',accept:'.mp3,.wav,audio/mpeg,audio/wav',multiple:true},
    master_audio:{label:'Master audio',category:'audio',accept:'.mp3,.wav,audio/mpeg,audio/wav',multiple:true},
    preview_audio:{label:'Preview audio',category:'audio',accept:'.mp3,.wav,audio/mpeg,audio/wav',multiple:true},
    download_audio:{label:'Download audio',category:'audio',accept:'.mp3,.wav,audio/mpeg,audio/wav',multiple:true},
    artwork:{label:'Artwork',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:true},
    photo:{label:'Photos',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:true},
    video:{label:'Video',category:'video',accept:'video/mp4,video/webm,video/quicktime',multiple:true},
    captions:{label:'Captions',category:'document',accept:'.vtt,text/vtt',multiple:true},
    document:{label:'Documents',category:'document',accept:'.pdf,.txt,.md,.rtf,.vtt,application/pdf,text/plain,text/vtt',multiple:true},
    archive:{label:'Archive',category:'',accept:'.mp3,.wav,.jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.mov,.pdf,.txt,.md,.rtf',multiple:true},
    cover:{label:'Front cover',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    back:{label:'Back cover',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    label_a:{label:'Label — Side A',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    label_b:{label:'Label — Side B',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    social_square:{label:'Social square',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    social_story:{label:'Social story',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    poster:{label:'Poster',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    hero:{label:'Hero artwork',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    background:{label:'Background',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    offer_artwork:{label:'Offer artwork',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    product_primary:{label:'Primary product image',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    product_gallery:{label:'Product gallery',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:true},
    logo:{label:'Logo',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    social_share:{label:'Social share artwork',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    app_icon:{label:'App icon',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:false},
    artist_photo:{label:'Artist photo',category:'image',accept:'image/jpeg,image/png,image/webp,image/gif',multiple:true}
  };
  const fmtBytes=n=>{n=Number(n)||0;if(n<1024)return n+' B';if(n<1048576)return(n/1024).toFixed(1)+' KB';if(n<1073741824)return(n/1048576).toFixed(1)+' MB';return(n/1073741824).toFixed(2)+' GB'};
  const fmtTime=s=>{s=Math.max(0,Math.round(Number(s)||0));return Math.floor(s/60)+':'+String(s%60).padStart(2,'0')};
  const mediaUrl=(a,v='original')=>'../api/media.php?id='+encodeURIComponent(a.uuid)+(v!=='original'?'&variant='+encodeURIComponent(v):'');
  function technicalLine(a){
    if(a.category==='audio')return [a.codec,Number(a.duration_seconds)>0?fmtTime(a.duration_seconds):'',a.bitrate_bps?Math.round(a.bitrate_bps/1000)+' kbps':'',a.sample_rate_hz?(a.sample_rate_hz/1000).toFixed(1)+' kHz':'',a.channels?(a.channels===1?'mono':a.channels===2?'stereo':a.channels+' ch'):'',a.bits_per_sample?a.bits_per_sample+'-bit':''].filter(Boolean).join(' · ');
    if(a.category==='image')return [a.width&&a.height?a.width+'×'+a.height:'',a.mime_type].filter(Boolean).join(' · ');
    if(a.category==='video')return [a.codec,a.width&&a.height?a.width+'×'+a.height:'',a.duration_seconds?fmtTime(a.duration_seconds):''].filter(Boolean).join(' · ');
    return a.mime_type||a.extension;
  }
  function sourceLabel(a){return a.technical&&a.technical.source?String(a.technical.source):'basic'}
  async function loadAssets(category='',q=''){
    const p=new URLSearchParams();if(category)p.set('category',category);if(q)p.set('q',q);
    const j=await api('media.php'+(p.toString()?'?'+p.toString():''));state.assets=j.assets||[];state.capabilities=j.capabilities||state.capabilities;state.completeness=j.completeness||state.completeness;return state.assets;
  }
  function missingCount(key){return Array.isArray(state.completeness?.missing?.[key])?state.completeness.missing[key].length:0}
  function completenessHtml(){
    const d=state.completeness||{},m=d.missing||{},problemTotal=Number(d.broken_assets||0)+Number(d.public_private_mismatch?.length||0)+missingCount('track_audio')+missingCount('track_artwork')+missingCount('release_cover')+missingCount('show_poster')+missingCount('campaign_hero')+missingCount('store_media');
    const issue=(label,rows,view)=>'<button class="media-readiness-row" type="button" data-media-jump="'+esc(view||'media')+'"><span>'+esc(label)+'</span><strong>'+Number(Array.isArray(rows)?rows.length:rows||0)+'</strong></button>';
    return '<section class="panel media-readiness"><div class="panel-title"><div><h2>Media completeness</h2><p>Missing, broken and unsafe references across the entire Stonefellow publishing surface.</p></div><span class="badge '+(problemTotal===0?'good':'')+'">'+(problemTotal===0?'READY':problemTotal+' NEED ATTENTION')+'</span></div><div class="media-readiness-grid">'+
      issue('Songs missing audio',m.track_audio,'catalog')+issue('Songs missing artwork',m.track_artwork,'catalog')+issue('Releases missing cover',m.release_cover,'releases')+issue('Shows missing poster',m.show_poster,'shows')+issue('Campaigns missing hero',m.campaign_hero,'campaigns')+issue('Store products missing media',m.store_media,'media')+issue('Broken stored assets',d.broken_assets,'media')+issue('Public/private mismatches',d.public_private_mismatch,'media')+
      '</div><div class="media-site-gaps"><strong>Site media still missing:</strong> '+((m.site_roles||[]).length?(m.site_roles||[]).map(x=>'<span>'+esc(String(x).replaceAll('_',' '))+'</span>').join(''):'<span>none</span>')+'</div></section>';
  }
  function storeMediaHtml(products){
    if(!products?.length)return'';
    return '<section class="panel"><div class="panel-title"><div><h2>Store product media</h2><p>Attach reusable product imagery without editing store configuration files.</p></div></div><div class="media-store-grid">'+products.map(p=>'<button type="button" class="media-store-card" data-store-media="'+esc(p.id)+'" data-store-label="'+esc(p.label)+'"><strong>'+esc(p.label)+'</strong><span>'+esc((p.format||'product').toUpperCase())+'</span><small>Manage imagery</small></button>').join('')+'</div></section>';
  }
  async function renderMediaLibrary(){
    try{await loadAssets(state.category,state.query);state.dashboard=await api('media.php?mode=dashboard');state.completeness=state.dashboard.completeness||state.completeness}catch(e){canvas.innerHTML=head('MEDIA LIBRARY','Unavailable',e.message);return}
    const rows=state.assets,counts={audio:0,image:0,video:0,document:0};rows.forEach(a=>{counts[a.category]=(counts[a.category]||0)+1});const d=state.completeness||{};
    canvas.innerHTML=head('ASSET MANAGEMENT','Media Library','One reusable asset system for songs, releases, shows, campaigns, store products and the public site.','<button class="primary" id="mediaUploadLibrary" type="button">Upload media</button>')+
      '<div class="stats media-stats"><div class="stat"><strong>'+Number(d.assets||rows.length)+'</strong><span>Total assets</span></div><div class="stat"><strong>'+fmtBytes(d.storage_bytes||0)+'</strong><span>Managed storage</span></div><div class="stat"><strong>'+Number(d.unused_assets||0)+'</strong><span>Unused</span></div><div class="stat"><strong>'+(state.capabilities.ffprobe?'FFprobe':'Built-in')+'</strong><span>Audio analyzer</span></div></div><div class="notice media-capability-note">Audio metadata: '+(state.capabilities.ffprobe?'FFprobe available; built-in MP3/WAV fallback also enabled.':'built-in MP3 frame/ID3 and WAV RIFF parser active.')+' Image derivatives: '+(state.capabilities.gd?'enabled via GD.':'original-only; GD is unavailable.')+' Effective upload ceiling: '+esc(state.capabilities.upload_max_label||'server-defined')+'.</div>'+
      completenessHtml()+storeMediaHtml(state.dashboard.store_products||[])+'<section class="panel" id="videoHubPanel"><div class="empty compact">Loading Public Video Hub…</div></section><section class="panel" id="photoGalleriesPanel"><div class="empty compact">Loading Photo Galleries…</div></section>'+
      '<section class="panel"><div class="panel-title"><div><h2>Asset library</h2><p>Search, inspect usage, edit metadata and remove unused files.</p></div></div><div class="toolbar media-toolbar"><input id="mediaSearch" type="search" placeholder="Search filename, title or caption" value="'+esc(state.query)+'"><div class="media-filters">'+['','audio','image','video','document'].map(k=>'<button type="button" data-media-category="'+k+'" class="'+(state.category===k?'active':'')+'">'+(k||'all')+'</button>').join('')+'</div></div><div id="mediaLibraryGrid" class="media-library-grid">'+(rows.length?rows.map(assetCard).join(''):'<div class="empty">No media assets match this filter.</div>')+'</div></section>';
    $('#mediaUploadLibrary').onclick=()=>pickUploadFiles('', '', '');
    $('#mediaSearch').onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();state.query=e.currentTarget.value.trim();renderMediaLibrary()}};
    $$('[data-media-category]',canvas).forEach(b=>b.onclick=()=>{state.category=b.dataset.mediaCategory;renderMediaLibrary()});
    $$('[data-media-edit]',canvas).forEach(b=>b.onclick=()=>editAsset(Number(b.dataset.mediaEdit)));
    $$('[data-media-add-catalog]',canvas).forEach(b=>b.onclick=()=>addCatalogAsset(Number(b.dataset.mediaAddCatalog)));
    $$('[data-media-open-catalog]',canvas).forEach(b=>b.onclick=()=>openCatalogTrack(b.dataset.mediaOpenCatalog));
    $$('[data-media-delete]',canvas).forEach(b=>b.onclick=()=>deleteAsset(Number(b.dataset.mediaDelete)));
    $$('[data-media-jump]',canvas).forEach(b=>b.onclick=()=>openView(b.dataset.mediaJump));
    $$('[data-store-media]',canvas).forEach(b=>b.onclick=()=>openEntityManager('store',b.dataset.storeMedia,b.dataset.storeLabel,{roles:['product_primary','product_gallery'],publishRoles:['product_primary']}));window.SFVideoAdmin?.renderInto?.('videoHubPanel');window.SFPhotoGalleriesAdmin?.renderInto?.('photoGalleriesPanel');
  }
  function assetPreview(a){
    if(a.category==='image')return '<img src="'+esc(mediaUrl(a,a.variants&&a.variants.thumb?'thumb':'original'))+'" alt="">';
    if(a.category==='audio')return '<div class="media-type-icon">♪</div>';
    if(a.category==='video')return '<div class="media-type-icon">▶</div>';
    if(a.category==='document')return '<div class="media-type-icon">DOC</div>';
    return '<div class="media-type-icon">FILE</div>';
  }
  function assetCard(a){
    const tags=a.technical&&a.technical.normalized_tags||{};
    const audio=a.category==='audio'&&['mp3','wav','wave'].includes(String(a.extension||'').toLowerCase());
    const existing=audio?(app.catalog||[]).find(t=>Number(t.media_primary_asset||0)===Number(a.id)||Number(t.media_private_master_asset||0)===Number(a.id)||(t.audio||'').includes('api/media.php?id='+a.uuid)):null;
    const catalogAction=audio?(existing?'<button class="secondary" type="button" data-media-open-catalog="'+esc(existing.id)+'">In Catalog</button>':
      '<button class="primary media-add-catalog-btn" type="button" data-media-add-catalog="'+a.id+'">Add to Catalog</button>'):'';
    return '<article class="media-asset-card" data-asset-id="'+a.id+'"><div class="media-asset-preview">'+assetPreview(a)+'</div><div class="media-asset-main"><div class="media-asset-kicker">'+esc(String(a.category||'media').toUpperCase())+' · '+esc(sourceLabel(a))+'</div><strong>'+esc(a.title||a.original_name)+'</strong><small>'+esc(a.original_name)+'</small><p>'+esc(technicalLine(a))+'</p>'+(tags.artist||tags.album?'<p>'+esc([tags.artist,tags.album].filter(Boolean).join(' · '))+'</p>':'')+'<div class="media-asset-foot"><span>'+fmtBytes(a.size_bytes)+'</span><span>'+Number(a.link_count||0)+' use'+(Number(a.link_count||0)===1?'':'s')+'</span></div></div><div class="media-asset-actions">'+catalogAction+'<button class="secondary" type="button" data-media-edit="'+a.id+'">Edit</button>'+(Number(a.link_count||0)===0?'<button class="danger" type="button" data-media-delete="'+a.id+'">Delete</button>':'')+'</div></article>';
  }
  async function addCatalogAsset(id){
    const asset=state.assets.find(a=>Number(a.id)===id);
    if(!asset||asset.category!=='audio')return;
    const templates=app.templates||[];
    if(!templates.length){say('Create a metadata template before adding a song to Catalog.');openView('templates');return;}
    const images=await api('media.php?category=image').then(r=>r.assets||[]).catch(()=>[]);
    const opts=templates.map(t=>'<option value="'+esc(t.id)+'">'+esc(t.name)+' · v'+Number(t.version||1)+'</option>').join('');
    const artOpts='<option value="">No artwork yet</option>'+images.map(a=>'<option value="'+Number(a.id)+'">'+esc(a.title||a.original_name)+'</option>').join('');
    const modal=document.createElement('div');modal.className='media-modal media-catalog-modal';
    modal.innerHTML='<div class="media-modal-card"><div class="panel-title"><div><div class="eyebrow">SONG CATALOG</div><h2>Add to Catalog</h2><p>'+esc(asset.original_name)+'</p></div><button class="secondary" type="button" data-catalog-close>Close</button></div>'+
      '<p class="hint">Assign a metadata template. Saving makes a private song draft. Auto-publish is optional and OFF by default.</p>'+
      '<form data-catalog-intake-form class="form-grid"><label class="field span2">Metadata template<select name="template_id" required>'+opts+'</select></label>'+
      '<label class="field span2">Song title<input name="title" required maxlength="180" value="'+esc(asset.title||asset.original_name.replace(/\.(mp3|wav|wave)$/i,''))+'"></label>'+
      '<label class="field">Release / album<input name="release" value="Unreleased"></label>'+
      '<label class="field">ISRC (optional)<input name="isrc" maxlength="16"></label>'+
      '<label class="field">Artist<input name="artist_display"></label><label class="field">Words by<input name="words_by"></label>'+
      '<label class="field">Music by<input name="music_by"></label><label class="field">Master recording owner<input name="master_owner"></label>'+
      '<label class="field">Composition owner<input name="composition_owner"></label>'+
      '<label class="field">Artwork (optional)<select name="artwork_asset_id">'+artOpts+'</select></label>'+
      '<label class="field">Digital download price ($)<input type="number" name="digital_price" min="0" max="1000" step="0.01" value="2.00" required></label>'+
      '<label class="check-field span3"><input type="checkbox" name="pod_eligible"> Available for vinyl print (requires a distinct private master)</label>'+
      '<label class="check-field span3"><input type="checkbox" name="rights_confirmed"> I confirm I have authority to publish this recording and its metadata.</label>'+
      '<label class="check-field span3"><input type="checkbox" name="auto_publish"> Auto-publish to public listening Catalog now</label>'+
      '<p class="notice span3">Publishing a lone MP3/WAV exposes it as the public listening copy. Paid downloads and vinyl printing stay disabled until a different private master is assigned.</p>'+
      '<div class="actions span3"><button class="primary" type="submit">Save song</button><button class="secondary" type="button" data-catalog-close>Cancel</button></div>'+
      '<p class="status-line span3" data-catalog-status role="status"></p></form></div>';
    document.body.appendChild(modal);
    $$('[data-catalog-close]',modal).forEach(b=>b.onclick=()=>modal.remove());
    const form=$('[data-catalog-intake-form]',modal),templateSelect=form.elements.template_id;
    const fillTemplate=()=>{const defaults=templates.find(t=>t.id===templateSelect.value)?.defaults||{};
      for(const field of ['artist_display','words_by','music_by','master_owner','composition_owner']){
        const key=field==='master_owner'?'master_copyright':field==='composition_owner'?'composition_copyright':field;
        form.elements[field].value=defaults[key]||(field==='artist_display'?defaults.performing_artist||'Stonefellow':field.includes('owner')?defaults.artist_legal||'':'');
      }
      form.elements.digital_price.value=Number(defaults.price??2).toFixed(2);
    };
    templateSelect.onchange=fillTemplate;fillTemplate();
    form.onsubmit=async e=>{
      e.preventDefault();const d=new FormData(form),auto=!!form.elements.auto_publish.checked,rights=!!form.elements.rights_confirmed.checked;
      if(auto&&!rights){$('[data-catalog-status]',form).textContent='Confirm publishing rights before enabling auto-publish.';return;}
      if(auto&&!window.confirm('Publish "'+d.get('title')+'" to the public listening player and Catalog now? If this is the only audio file, it becomes publicly streamable; paid downloads stay disabled.'))return;
      const submit=form.querySelector('[type="submit"]'),status=$('[data-catalog-status]',form);submit.disabled=true;status.textContent='Saving private song metadata…';
      const key=form.dataset.requestKey||(form.dataset.requestKey=Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join(''));
      try{
        const input={action:'intake_asset',asset_id:id,request_key:key,template_id:d.get('template_id'),
          title:d.get('title'),release:d.get('release'),isrc:d.get('isrc'),artist_display:d.get('artist_display'),
          words_by:d.get('words_by'),music_by:d.get('music_by'),master_owner:d.get('master_owner'),
          composition_owner:d.get('composition_owner'),artwork_asset_id:d.get('artwork_asset_id'),
          digital_price:d.get('digital_price'),pod_eligible:form.elements.pod_eligible.checked,
          rights_confirmed:rights,auto_publish:auto,confirmed:auto};
        const result=await api('media-catalog.php',input);
        if(result.publication==='published'){
          status.textContent='Published to the public listening Catalog.';await reloadCore();modal.remove();
          openCatalogTrack(result.track_id);say('Song published to the public Catalog.');
        }else{
          status.textContent=result.publication==='needs_review'?'Saved privately. Publication needs review: '+result.publication_message:'Saved as a private Song Draft. Nothing is public.';
          if(!form.querySelector('[data-open-saved-draft]')){
            const b=document.createElement('button');b.type='button';b.className='secondary';b.dataset.openSavedDraft='1';b.textContent='Review / Publish';
            b.onclick=()=>window.SFAdminAgentCanvas?.openSongDraft?.(result.draft.request_key);
            form.querySelector('.actions').appendChild(b);
          }
          submit.disabled=false;
        }
      }catch(error){status.textContent=error.message||'Could not save song.';submit.disabled=false;}
    };
  }
  function uploadOne(file,onProgress){
    return new Promise(async(resolve,reject)=>{
      if(!app.csrf){try{await ctx.reloadCore()}catch(_){}}
      const fd=new FormData();fd.append('file',file,file.name);const xhr=new XMLHttpRequest();xhr.open('POST','api/media-upload.php');xhr.withCredentials=true;if(app.csrf)xhr.setRequestHeader('X-CSRF-Token',app.csrf);
      xhr.upload.onprogress=e=>{if(e.lengthComputable)onProgress&&onProgress(e.loaded/e.total)};
      xhr.onerror=()=>reject(new Error('Upload failed for '+file.name+'.'));
      xhr.onload=()=>{let j={};try{j=JSON.parse(xhr.responseText||'{}')}catch(_){return reject(new Error('Invalid upload response for '+file.name+'.'))}if(xhr.status<200||xhr.status>=300||j.ok===false)return reject(new Error(j.message||'Upload failed.'));resolve(j.asset)};
      xhr.send(fd);
    });
  }
  function pickUploadFiles(entityType,entityId,role,onDone=null){
    const def=roles[role]||{accept:'.mp3,.wav,.jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.mov,.pdf,.txt,.md,.rtf',multiple:true};const inp=document.createElement('input');inp.type='file';inp.multiple=def.multiple!==false;inp.accept=def.accept;inp.onchange=async()=>{
      const files=[...(inp.files||[])];if(!files.length)return;const max=Number(state.capabilities.upload_max_bytes||0);if(max){const tooLarge=files.find(f=>f.size>max);if(tooLarge){alert(tooLarge.name+' exceeds the server upload limit of '+(state.capabilities.upload_max_label||fmtBytes(max))+'.');return}}let done=0;
      for(const file of files){try{say('Uploading '+file.name+'…');const asset=await uploadOne(file,p=>say('Uploading '+file.name+' · '+Math.round(p*100)+'%'));if(entityType&&entityId&&role)await api('media.php',{action:'attach',asset_id:asset.id,entity_type:entityType,entity_id:entityId,role:role,public_visible:false,download_allowed:false});done++}catch(e){alert(e.message);break}}
      say('Uploaded '+done+' of '+files.length+' media file'+(files.length===1?'':'s')+'.');if(onDone){await onDone()}else if(entityType==='track')await refreshTrackMedia(entityId);else if(entityType&&entityId&&state.entityConfigs.has(entityType+':'+entityId))await refreshEntityMedia(entityType,entityId);else renderMediaLibrary();
    };inp.click();
  }
  async function editAsset(id){
    let a=state.assets.find(x=>Number(x.id)===id),usage=[];try{const detail=await api('media.php?asset_id='+encodeURIComponent(id));a=detail.asset||a;usage=detail.usage||[]}catch(_){if(!a){await loadAssets();a=state.assets.find(x=>Number(x.id)===id)}}if(!a)return;
    const tags=a.technical&&a.technical.normalized_tags||{},usageHtml='<section class="media-usage-panel"><strong>Used by '+usage.length+' item'+(usage.length===1?'':'s')+'</strong>'+(usage.length?usage.map(u=>'<div><span>'+esc(String(u.entity_type).toUpperCase())+' · '+esc(u.role)+'</span><b>'+esc(u.entity_label||u.entity_id)+'</b><small>'+(Number(u.public_visible)?'public':'private')+(Number(u.download_allowed)?' · downloadable':'')+'</small></div>').join(''):'<p>This asset is currently unused and may be deleted.</p>')+'</section>';
    const overlay=document.createElement('div');overlay.className='media-modal';overlay.innerHTML='<div class="media-modal-card"><div class="panel-title"><div><div class="eyebrow">MEDIA ASSET</div><h2>'+esc(a.title||a.original_name)+'</h2><p>'+esc(technicalLine(a))+'</p></div><button type="button" class="secondary" data-media-close>Close</button></div>'+(a.category==='audio'?'<audio controls preload="metadata" src="'+esc(mediaUrl(a))+'"></audio>':a.category==='image'?'<img class="media-modal-image" src="'+esc(mediaUrl(a,a.variants&&a.variants.medium?'medium':'original'))+'" alt="">':a.category==='video'?'<video class="media-modal-image" controls preload="metadata" src="'+esc(mediaUrl(a))+'"></video>':'')+'<form id="mediaAssetForm" class="form-grid"><label class="field span2">Title<input name="title" value="'+esc(a.title||'')+'"></label><label class="field span3">Caption<textarea name="caption">'+esc(a.caption||'')+'</textarea></label><label class="field span2">Alt text<input name="alt_text" value="'+esc(a.alt_text||'')+'"></label><label class="field">Credit<input name="credit" value="'+esc(a.credit||'')+'"></label><label class="field span3">Copyright / rights<input name="copyright_text" value="'+esc(a.copyright_text||'')+'"></label><div class="actions span3"><button class="primary" type="submit">Save metadata</button></div></form><section class="media-tech-panel"><strong>Extracted metadata</strong><dl><div><dt>File</dt><dd>'+esc(a.original_name)+' · '+fmtBytes(a.size_bytes)+'</dd></div><div><dt>Technical</dt><dd>'+esc(technicalLine(a))+'</dd></div><div><dt>Analyzer</dt><dd>'+esc(sourceLabel(a))+'</dd></div>'+(tags.title?'<div><dt>Embedded title</dt><dd>'+esc(tags.title)+'</dd></div>':'')+(tags.artist?'<div><dt>Embedded artist</dt><dd>'+esc(tags.artist)+'</dd></div>':'')+(tags.album?'<div><dt>Embedded album</dt><dd>'+esc(tags.album)+'</dd></div>':'')+(a.technical&&a.technical.embedded_artwork?'<div><dt>Embedded artwork</dt><dd>Detected</dd></div>':'')+'</dl></section>'+usageHtml+'</div>';
    document.body.appendChild(overlay);$('[data-media-close]',overlay).onclick=()=>overlay.remove();$('#mediaAssetForm',overlay).onsubmit=async e=>{e.preventDefault();const d=new FormData(e.currentTarget);try{await api('media.php',{action:'update_asset',id:a.id,asset:{title:d.get('title'),caption:d.get('caption'),alt_text:d.get('alt_text'),credit:d.get('credit'),copyright_text:d.get('copyright_text')}});overlay.remove();say('Media metadata saved.');if(app.view==='media')renderMediaLibrary();else reloadCore()}catch(x){alert(x.message)}};
  }
  async function deleteAsset(id){if(!confirm('Permanently delete this unused media asset and its stored file?'))return;try{await api('media.php',{action:'delete_asset',id});say('Media asset deleted.');renderMediaLibrary()}catch(e){alert(e.message)}}

  function trackSection(){return '<div class="track-media-toolbar"><button class="primary" type="button" data-track-media-upload="audio_candidate">Upload audio</button><button class="secondary" type="button" id="trackReplaceSourceAudio">Replace source audio</button><button class="secondary" type="button" data-track-media-upload="artwork">Upload artwork</button><button class="secondary" type="button" data-track-media-upload="photo">Upload photos</button><button class="secondary" type="button" data-track-media-upload="video">Upload video</button><button class="secondary" type="button" data-track-media-upload="document">Upload documents</button><button class="secondary" type="button" data-track-media-upload="archive">Upload archive media</button><button class="secondary" type="button" id="trackMediaChoose">Choose from Library</button></div><div class="media-publishing-hint">Use separate Media Library files for the public preview and the private purchased original. Your existing uploader and media attachments are used.</div><div id="trackMasterHistory" class="track-master-history"></div><div id="trackPublicationStatus" class="media-publication-status" role="status"></div><div id="trackMediaStatus" class="status-line"></div><div id="trackMediaGroups" class="track-media-groups"><div class="empty compact">Loading attached media…</div></div>'}
  async function bindTrackMedia(trackId){
    state.trackId=trackId;$$('[data-track-media-upload]',canvas).forEach(b=>b.onclick=()=>pickUploadFiles('track',trackId,b.dataset.trackMediaUpload));const choose=$('#trackMediaChoose',canvas);if(choose)choose.onclick=()=>chooseExisting('track',trackId,'archive');const replace=$('#trackReplaceSourceAudio',canvas);if(replace)replace.onclick=()=>replaceSourceAudio(trackId);await refreshTrackMedia(trackId);
  }
  async function refreshTrackMedia(trackId){
    try{const j=await api('media.php?entity_type=track&entity_id='+encodeURIComponent(trackId));state.trackLinks=j.links||[];state.publication=j.publication||{};state.capabilities=j.capabilities||state.capabilities;renderTrackGroups(trackId)}catch(e){const s=$('#trackMediaStatus',canvas);if(s)s.textContent=e.message}
  }
  function renderTrackGroups(trackId){
    const pub=state.publication||{},status=$('#trackPublicationStatus',canvas);
    if(status)status.innerHTML='<strong>Audio publishing</strong><span>Public preview: '+(pub.public_preview?'Published':'Missing')+'</span><span>Private original: '+(pub.private_master?'Assigned':'Missing')+'</span><span>Paid download: '+(pub.download_ready?'Ready':'Unavailable')+'</span><small>'+esc(pub.hint||'Upload or choose audio for this song.')+'</small>';
    const host=$('#trackMediaGroups',canvas);if(!host)return;const groups=[
      ['primary_audio','Primary audio'],['master_audio','Masters'],['preview_audio','Previews'],['download_audio','Downloads'],['alternate_audio','Alternate audio'],['audio_candidate','Audio candidates'],['artwork','Artwork'],['primary_artwork','Primary artwork'],['photo','Photos'],['video','Video'],['document','Documents'],['archive','Archive']
    ];const seen=new Set();host.innerHTML=groups.map(([role,label])=>{const rows=state.trackLinks.filter(x=>x.role===role);if(!rows.length)return'';seen.add(role);return '<section class="track-media-group" data-media-role-group="'+role+'"><div class="panel-title"><h4>'+label+'</h4><span>'+rows.length+'</span></div><div class="track-media-list">'+rows.map(linkCard).join('')+'</div></section>'}).join('')||'<div class="empty compact">No media attached yet. Upload a file or choose one from the Media Library.</div>';
    bindTrackCards(trackId);renderMasterHistory(trackId);
  }
  function renderMasterHistory(trackId){
    const host=$('#trackMasterHistory',canvas);if(!host)return;
    const masters=state.trackLinks.filter(x=>x.role==='master_audio')
      .sort((a,b)=>Number(a.id)-Number(b.id));
    const current=Number(state.publication?.master_asset_id||0);
    const ref=String(state.publication?.master_reference||'');
    host.innerHTML='<h4>Source audio revision history</h4><p class="hint">Every previous private master is retained. Changing the current master keeps this song’s Catalog ID, listening counts, purchases and store history.</p>'+
      (masters.length?'<ol class="track-master-revisions">'+masters.map((m,i)=>{
        const active=current?current===Number(m.asset_id):ref.includes('id='+m.uuid);
        const note=String(m.link?.revision_note||'');
        return '<li><div><strong>Revision '+(i+1)+(active?' · Current source':' · Retained master')+'</strong>'+
          '<span>'+esc(m.original_name)+' · '+esc((m.created_at||'').slice(0,19).replace('T',' '))+'</span>'+
          (note?'<small>'+esc(note)+'</small>':'')+'</div>'+
          (!active?'<button class="secondary" data-master-restore="'+Number(m.id)+'" type="button">Restore this revision</button>':'<span class="badge good">Current</span>')+'</li>';
      }).join('')+'</ol>':'<p class="hint">No source masters assigned yet. Upload an original or replace the source to create the first revision.</p>');
    $$('[data-master-restore]',host).forEach(b=>b.onclick=()=>publishPrivateMaster(Number(b.dataset.masterRestore),trackId));
  }
  function replaceSourceAudio(trackId){
    const overlay=document.createElement('div');overlay.className='media-modal';
    overlay.innerHTML='<div class="media-modal-card"><div class="panel-title"><div><div class="eyebrow">SOURCE REVISION</div><h2>Replace source audio</h2></div><button class="secondary" type="button" data-replace-close>Close</button></div>'+
      '<p>Upload an MP3 or WAV as the new <strong>private master</strong>. The public listening preview will not change, and older masters will stay in revision history. Play and store statistics remain on the same song.</p>'+
      '<form data-source-revision-form><label class="field">Replacement source audio<input type="file" accept=".mp3,.wav,.wave,audio/mpeg,audio/wav" required name="master"></label>'+
      '<label class="field">Revision notes (optional)<textarea name="revision_note" maxlength="500" placeholder="For example: New mix, remastered vocal, corrected ending"></textarea></label>'+
      '<label class="check-field"><input type="checkbox" required name="confirmed"> Set this as the current private master while retaining the earlier revisions</label>'+
      '<div class="actions"><button class="primary" type="submit">Upload and confirm replacement</button><button type="button" class="secondary" data-replace-close>Cancel</button></div><p class="status-line" role="status" data-source-revision-status></p></form></div>';
    document.body.appendChild(overlay);
    $$('[data-replace-close]',overlay).forEach(b=>b.onclick=()=>overlay.remove());
    $('[data-source-revision-form]',overlay).onsubmit=async event=>{
      event.preventDefault();const form=event.currentTarget,files=form.elements.master.files,file=files?.[0];
      if(!file||!(/\.(mp3|wav|wave)$/i.test(file.name))||file.size<1)return;
      const note=String(new FormData(form).get('revision_note')||'').trim();
      const submit=form.querySelector('[type="submit"]'),status=$('[data-source-revision-status]',form);
      submit.disabled=true;
      try{
        status.textContent='Uploading private source '+file.name+'…';
        const asset=await uploadOne(file,p=>status.textContent='Uploading private source · '+Math.round(p*100)+'%');
        if(asset.category!=='audio')throw new Error('A valid MP3/WAV source is required.');
        const attached=await api('media.php',{action:'attach',entity_type:'track',entity_id:trackId,
          role:'audio_candidate',asset_id:Number(asset.id),public_visible:false,download_allowed:false,
          revision_note:note});
        const linked=(attached.links||[]).find(x=>Number(x.asset_id)===Number(asset.id)&&x.role==='audio_candidate');
        if(!linked)throw new Error('Source was stored privately but the revision attachment was not verified. Review media before retrying.');
        status.textContent='Verifying replacement and keeping previous masters…';
        await api('media.php',{action:'publish_track_master',track_id:trackId,link_id:Number(linked.id),confirmed:true});
        overlay.remove();await reloadCore();await refreshTrackMedia(trackId);
        say('Private source revision saved. Existing song statistics and earlier masters are retained.');
      }catch(error){status.textContent=error.message||'Replacement did not finish. Uploaded candidates remain private.';submit.disabled=false;}
    };
  }
  function linkCard(l){
    const preview=l.category==='image'?'<img src="'+esc(mediaUrl(l,l.variants&&l.variants.thumb?'thumb':'original'))+'" alt="">':l.category==='audio'&&l.role!=='master_audio'?'<audio controls preload="metadata" src="'+esc(mediaUrl(l))+'"></audio>':'<div class="media-type-icon">'+esc(l.category==='video'?'▶':l.category==='document'?'DOC':'FILE')+'</div>';
    return '<article class="track-media-card" draggable="true" data-media-link="'+l.id+'" data-media-role="'+esc(l.role)+'"><div class="track-media-preview">'+preview+'</div><div class="track-media-main"><strong>'+esc(l.title||l.original_name)+'</strong><small>'+esc(technicalLine(l))+'</small><span>'+esc(l.original_name)+' · '+fmtBytes(l.size_bytes)+'</span><div class="media-link-flags">'+(l.category==='audio'&&l.role!=='primary_audio'&&l.role!=='master_audio'?'<label>Role <select data-link-role="'+l.id+'">'+[['audio_candidate','Candidate'],['master_audio','Master'],['preview_audio','Preview'],['download_audio','Download'],['alternate_audio','Alternate']].map(x=>'<option value="'+x[0]+'" '+(l.role===x[0]?'selected':'')+'>'+x[1]+'</option>').join('')+'</select></label>':'')+(l.role==='master_audio'?'':'<label><input type="checkbox" data-link-public="'+l.id+'" '+(Number(l.public_visible)?'checked':'')+'> Public</label>')+(l.category==='audio'&&l.role!=='master_audio'?'<label><input type="checkbox" data-link-download="'+l.id+'" '+(Number(l.download_allowed)?'checked':'')+'> Downloadable</label>':'')+'<label><input type="checkbox" data-link-featured="'+l.id+'" '+(Number(l.featured)?'checked':'')+'> Featured</label></div></div><div class="track-media-actions">'+(l.category==='audio'&&['preview_audio','audio_candidate','alternate_audio','primary_audio'].includes(l.role)?'<button class="primary" type="button" data-media-primary-audio="'+l.id+'">Publish public preview</button>':'')+(l.category==='audio'&&['master_audio','download_audio','audio_candidate'].includes(l.role)?'<button class="secondary" type="button" data-media-private-master="'+l.id+'">Publish private original</button>':'')+(l.category==='image'&&l.role!=='primary_artwork'?'<button class="secondary" type="button" data-media-primary-art="'+l.id+'">Make primary artwork</button>':'')+'<button class="secondary" type="button" data-media-edit-inline="'+l.id+'">Edit</button><button class="danger" type="button" data-media-detach="'+l.id+'">Detach</button></div></article>';
  }
  function bindTrackCards(trackId){
    $$('[data-link-role]',canvas).forEach(x=>x.onchange=async()=>{try{await api('media.php',{action:'update_link',id:Number(x.dataset.linkRole),link:{role:x.value}});say('Audio role updated.');refreshTrackMedia(trackId)}catch(e){alert(e.message)}});
    $$('[data-link-public]',canvas).forEach(x=>x.onchange=()=>updateLink(Number(x.dataset.linkPublic),{public_visible:x.checked}));
    $$('[data-link-download]',canvas).forEach(x=>x.onchange=()=>updateLink(Number(x.dataset.linkDownload),{download_allowed:x.checked,public_visible:x.checked||undefined}));
    $$('[data-link-featured]',canvas).forEach(x=>x.onchange=()=>updateLink(Number(x.dataset.linkFeatured),{featured:x.checked}));
    $$('[data-media-detach]',canvas).forEach(b=>b.onclick=()=>detachLink(Number(b.dataset.mediaDetach),trackId));
    $$('[data-media-primary-audio]',canvas).forEach(b=>b.onclick=()=>publishPrimaryAudio(Number(b.dataset.mediaPrimaryAudio),trackId));
    $$('[data-media-private-master]',canvas).forEach(b=>b.onclick=()=>publishPrivateMaster(Number(b.dataset.mediaPrivateMaster),trackId));
    $$('[data-media-primary-art]',canvas).forEach(b=>b.onclick=()=>publishPrimaryArtwork(Number(b.dataset.mediaPrimaryArt),trackId));
    $$('[data-media-edit-inline]',canvas).forEach(b=>{const link=state.trackLinks.find(x=>Number(x.id)===Number(b.dataset.mediaEditInline));b.onclick=()=>link&&editTrackAsset(link)});
    $$('.track-media-card',canvas).forEach(card=>{card.ondragstart=e=>e.dataTransfer.setData('media/link',card.dataset.mediaLink);card.ondragover=e=>e.preventDefault();card.ondrop=e=>reorderDrop(e,trackId,card)});
  }
  async function updateLink(id,patch,confirmed=false){try{await api('media.php',{action:'update_link',id,link:patch,confirmed});say('Media attachment updated.')}catch(e){alert(e.message)}}
  async function detachLink(id,trackId){if(!confirm('Detach this media item from the song? The underlying Media Library asset will be kept.'))return;try{await api('media.php',{action:'detach',link_id:id});say('Media detached.');refreshTrackMedia(trackId)}catch(e){alert(e.message)}}
  async function publishPrimaryAudio(linkId,trackId){if(!confirm('Publish this file as the PUBLIC preview that anyone can hear? Paid downloads require a separate private original.'))return;try{await api('media.php',{action:'publish_track_audio',track_id:trackId,link_id:linkId,confirmed:true});await reloadCore();say('Public preview published; paid master stays private.');ctx.openView('catalog');setTimeout(()=>{const row=document.querySelector('[data-track-id="'+CSS.escape(trackId)+'"]');if(row)row.click()},50)}catch(e){alert(e.message)}}
  async function publishPrivateMaster(linkId,trackId){
    if(!confirm('Publish this as the PRIVATE original? It must be a different asset from the public preview, and it will be available only to verified owners.'))return;
    try{await api('media.php',{action:'publish_track_master',track_id:trackId,link_id:linkId,confirmed:true});await reloadCore();
      say('Private original verified and assigned to purchased music.');
      ctx.openView('catalog');setTimeout(()=>{const row=document.querySelector('[data-track-id="'+CSS.escape(trackId)+'"]');if(row)row.click()},50)
    }catch(e){alert(e.message)}
  }
  async function publishPrimaryArtwork(linkId,trackId){if(!confirm('Make this image the song’s primary public artwork?'))return;try{await api('media.php',{action:'publish_track_artwork',track_id:trackId,link_id:linkId});await reloadCore();say('Primary song artwork updated.');ctx.openView('catalog');setTimeout(()=>{const row=document.querySelector('[data-track-id="'+CSS.escape(trackId)+'"]');if(row)row.click()},50)}catch(e){alert(e.message)}}
  async function editTrackAsset(link){await loadAssets();const a=state.assets.find(x=>Number(x.id)===Number(link.asset_id));if(a)editAsset(a.id)}
  async function reorderDrop(e,trackId,target){e.preventDefault();const sourceId=Number(e.dataTransfer.getData('media/link')||0),targetId=Number(target.dataset.mediaLink||0),role=target.dataset.mediaRole;if(!sourceId||!targetId||sourceId===targetId)return;const rows=state.trackLinks.filter(x=>x.role===role),from=rows.findIndex(x=>Number(x.id)===sourceId),to=rows.findIndex(x=>Number(x.id)===targetId);if(from<0||to<0)return;const [moved]=rows.splice(from,1);rows.splice(to,0,moved);for(let i=0;i<rows.length;i++)await api('media.php',{action:'update_link',id:Number(rows[i].id),link:{sort_order:i}});say('Media order updated.');refreshTrackMedia(trackId)}
  async function chooseExisting(entityType,entityId,defaultRole,roleChoices=null,onDone=null){
    try{await loadAssets()}catch(e){return alert(e.message)}
    const overlay=document.createElement('div');overlay.className='media-modal';overlay.innerHTML='<div class="media-modal-card media-picker"><div class="panel-title"><div><div class="eyebrow">MEDIA LIBRARY</div><h2>Choose existing media</h2></div><button class="secondary" type="button" data-media-close>Close</button></div><div class="media-picker-controls"><select id="mediaPickerRole">'+Object.entries(roles).map(([k,v])=>'<option value="'+k+'" '+(k===defaultRole?'selected':'')+'>'+esc(v.label)+'</option>').join('')+'</select><input id="mediaPickerSearch" type="search" placeholder="Filter assets"></div><div id="mediaPickerGrid" class="media-picker-grid">'+state.assets.map(a=>'<button type="button" data-media-pick="'+a.id+'"><span>'+assetPreview(a)+'</span><strong>'+esc(a.title||a.original_name)+'</strong><small>'+esc(technicalLine(a))+'</small></button>').join('')+'</div></div>';document.body.appendChild(overlay);$('[data-media-close]',overlay).onclick=()=>overlay.remove();$('#mediaPickerSearch',overlay).oninput=e=>{const q=e.currentTarget.value.toLowerCase();$$('[data-media-pick]',overlay).forEach(b=>b.hidden=q&&!b.textContent.toLowerCase().includes(q))};$$('[data-media-pick]',overlay).forEach(b=>b.onclick=async()=>{const role=$('#mediaPickerRole',overlay).value;try{await api('media.php',{action:'attach',asset_id:Number(b.dataset.mediaPick),entity_type:entityType,entity_id:entityId,role});overlay.remove();say('Media attached.');if(onDone)onDone();else if(entityType==='track')refreshTrackMedia(entityId);else if(state.entityConfigs.has(entityType+':'+entityId))refreshEntityMedia(entityType,entityId)}catch(e){alert(e.message)}})
  }


  function roleLabel(role){return roles[role]?.label||String(role||'media').replaceAll('_',' ')}
  function entitySection(entityType,entityId,config={}){
    if(!entityId)return '<div class="notice">Save this '+esc(entityType)+' first, then its reusable Media Library controls will appear here.</div>';
    const roleList=config.roles||['photo','video','document','archive'];const key=entityType+':'+entityId;
    return '<div class="universal-media" data-universal-media="'+esc(key)+'"><div class="track-media-toolbar">'+roleList.map((role,i)=>'<button class="'+(i===0?'primary':'secondary')+'" type="button" data-entity-media-upload="'+esc(role)+'">Upload '+esc(roleLabel(role))+'</button>').join('')+'<button class="secondary" type="button" data-entity-media-choose>Choose from Library</button></div><div class="status-line" data-entity-media-status></div><div class="track-media-groups" data-entity-media-host><div class="empty compact">Loading attached media…</div></div></div>';
  }
  async function bindEntityMedia(entityType,entityId,config={},root=canvas){
    if(!entityId)return;const key=entityType+':'+entityId;state.entityConfigs.set(key,{...config,root});
    $$('[data-universal-media="'+CSS.escape(key)+'"] [data-entity-media-upload]',root).forEach(b=>b.onclick=()=>pickUploadFiles(entityType,entityId,b.dataset.entityMediaUpload,()=>refreshEntityMedia(entityType,entityId)));
    const choose=$('[data-universal-media="'+CSS.escape(key)+'"] [data-entity-media-choose]',root);if(choose)choose.onclick=()=>chooseExisting(entityType,entityId,(config.roles||['archive'])[0],config.roles||null,()=>refreshEntityMedia(entityType,entityId));
    await refreshEntityMedia(entityType,entityId);
  }
  async function refreshEntityMedia(entityType,entityId){
    const key=entityType+':'+entityId,cfg=state.entityConfigs.get(key)||{},root=cfg.root||canvas,box=$('[data-universal-media="'+CSS.escape(key)+'"]',root);if(!box)return;
    try{const j=await api('media.php?entity_type='+encodeURIComponent(entityType)+'&entity_id='+encodeURIComponent(entityId)),links=j.links||[],host=$('[data-entity-media-host]',box);state.capabilities=j.capabilities||state.capabilities;host.innerHTML=links.length?links.map(l=>genericLinkCard(l,cfg)).join(''):'<div class="empty compact">No media attached yet.</div>';bindGenericCards(entityType,entityId,links,cfg,box)}catch(e){const s=$('[data-entity-media-status]',box);if(s)s.textContent=e.message}
  }
  function genericLinkCard(l,cfg){
    const publishRoles=cfg.publishRoles||[],canPublish=l.category==='image'&&publishRoles.includes(l.role),preview=l.category==='image'?'<img src="'+esc(mediaUrl(l,l.variants&&l.variants.thumb?'thumb':'original'))+'" alt="">':l.category==='audio'?'<audio controls preload="metadata" src="'+esc(mediaUrl(l))+'"></audio>':'<div class="media-type-icon">'+esc(l.category==='video'?'▶':l.category==='document'?'DOC':'FILE')+'</div>';
    return '<article class="track-media-card universal-media-card" draggable="true" data-generic-media-link="'+l.id+'" data-media-role="'+esc(l.role)+'"><div class="track-media-preview">'+preview+'</div><div class="track-media-main"><strong>'+esc(l.title||l.original_name)+'</strong><small>'+esc(roleLabel(l.role))+' · '+esc(technicalLine(l))+'</small><span>'+esc(l.original_name)+' · '+fmtBytes(l.size_bytes)+'</span><div class="media-link-flags"><label><input type="checkbox" data-generic-public="'+l.id+'" '+(Number(l.public_visible)?'checked':'')+'> Public</label><label><input type="checkbox" data-generic-download="'+l.id+'" '+(Number(l.download_allowed)?'checked':'')+'> Downloadable</label><label><input type="checkbox" data-generic-featured="'+l.id+'" '+(Number(l.featured)?'checked':'')+'> Featured</label></div></div><div class="track-media-actions">'+(canPublish?'<button class="primary" type="button" data-generic-publish="'+l.id+'" data-publish-role="'+esc(l.role)+'">'+(Number(l.featured)&&Number(l.public_visible)?'Republish ':'Publish ')+esc(roleLabel(l.role))+'</button>':'')+'<button class="secondary" type="button" data-generic-edit="'+l.asset_id+'">Edit</button><button class="danger" type="button" data-generic-detach="'+l.id+'">Detach</button></div></article>';
  }
  function bindGenericCards(entityType,entityId,links,cfg,box){
    $$('[data-generic-public]',box).forEach(x=>x.onchange=()=>{const videoGoverned=entityType==='video_feature',governed=['video_feature','photo_gallery'].includes(entityType),label=videoGoverned?'Video Hub':entityType==='photo_gallery'?'Photo Gallery':'governed media';if(governed&&!confirm('Change this '+label+' media item to '+(x.checked?'Public':'Private')+'?')){x.checked=!x.checked;return}updateLink(Number(x.dataset.genericPublic),{public_visible:x.checked},governed)});
    $$('[data-generic-download]',box).forEach(x=>x.onchange=()=>{const videoGoverned=entityType==='video_feature',governed=['video_feature','photo_gallery'].includes(entityType),label=videoGoverned?'Video Hub':entityType==='photo_gallery'?'Photo Gallery':'governed media';if(governed&&!confirm('Change download permission for this '+label+' media item?')){x.checked=!x.checked;return}updateLink(Number(x.dataset.genericDownload),{download_allowed:x.checked,public_visible:x.checked||undefined},governed)});
    $$('[data-generic-featured]',box).forEach(x=>x.onchange=()=>updateLink(Number(x.dataset.genericFeatured),{featured:x.checked}));
    $$('[data-generic-edit]',box).forEach(b=>b.onclick=()=>editAsset(Number(b.dataset.genericEdit)));
    $$('[data-generic-detach]',box).forEach(b=>b.onclick=async()=>{if(!confirm('Detach this media item? The reusable library asset will be preserved.'))return;try{await api('media.php',{action:'detach',link_id:Number(b.dataset.genericDetach)});say('Media detached.');refreshEntityMedia(entityType,entityId)}catch(e){alert(e.message)}});
    $$('[data-generic-publish]',box).forEach(b=>b.onclick=async()=>{const role=b.dataset.publishRole;if(!confirm('Publish this asset as '+roleLabel(role)+' for this '+entityType+'?'))return;try{await api('media.php',{action:'publish_entity_image',entity_type:entityType,entity_id:entityId,link_id:Number(b.dataset.genericPublish),role,confirmed:true});await reloadCore();say(roleLabel(role)+' published.');refreshEntityMedia(entityType,entityId)}catch(e){alert(e.message)}});
    $$('.universal-media-card',box).forEach(card=>{card.ondragstart=e=>e.dataTransfer.setData('media/link',card.dataset.genericMediaLink);card.ondragover=e=>e.preventDefault();card.ondrop=async e=>{e.preventDefault();const sourceId=Number(e.dataTransfer.getData('media/link')||0),targetId=Number(card.dataset.genericMediaLink||0),role=card.dataset.mediaRole;if(!sourceId||!targetId||sourceId===targetId)return;const rows=links.filter(x=>x.role===role),from=rows.findIndex(x=>Number(x.id)===sourceId),to=rows.findIndex(x=>Number(x.id)===targetId);if(from<0||to<0)return;const [moved]=rows.splice(from,1);rows.splice(to,0,moved);for(let i=0;i<rows.length;i++)await api('media.php',{action:'update_link',id:Number(rows[i].id),link:{sort_order:i}});say('Media order updated.');refreshEntityMedia(entityType,entityId)}});
  }
  async function openEntityManager(entityType,entityId,label,config={}){
    const overlay=document.createElement('div');overlay.className='media-modal';const key=entityType+':'+entityId;overlay.innerHTML='<div class="media-modal-card media-entity-manager"><div class="panel-title"><div><div class="eyebrow">'+esc(entityType.toUpperCase())+' MEDIA</div><h2>'+esc(label||entityId)+'</h2><p>Upload, reuse, order and publish media through the central library.</p></div><button class="secondary" type="button" data-media-close>Close</button></div>'+entitySection(entityType,entityId,config)+'</div>';document.body.appendChild(overlay);$('[data-media-close]',overlay).onclick=()=>{state.entityConfigs.delete(key);overlay.remove()};await bindEntityMedia(entityType,entityId,config,overlay);
  }

  window.SFMediaAdmin={renderMediaLibrary,trackSection,bindTrackMedia,chooseExisting,pickUploadFiles,entitySection,bindEntityMedia,openEntityManager,refreshEntityMedia};
})();
