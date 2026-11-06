(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  const {app,api,canvas,head,say,esc,openView,openCatalogTrack,reloadCore}=ctx;
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const state={assets:[],category:'',query:'',aiOnly:false,trackId:'',trackLinks:[],publication:null,capabilities:{},completeness:{},dashboard:{},entityConfigs:new Map(),imageAnalysisRequests:new Map(),imageBatchSelection:new Set(),imageBatchSession:null,health:null,healthBusy:false,healthStatus:''};
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
  const healthStatusLabel={ready:'Ready',legacy_ready:'Legacy file found',no_audio:'No audio reference',reference_missing:'Missing media record',missing_file:'File missing',storage_unavailable:'Storage unavailable',unsafe_path:'Unsafe storage path',unreadable_file:'Empty or unreadable file',empty_file:'Empty file',size_mismatch:'File size mismatch',unpublished_link:'Public media link missing',legacy_missing:'Legacy file missing',external_unverified:'External URL · unverified',invalid_url:'Invalid audio URL',preview_shorter_than_master:'Public preview shorter than private original'};
  function mediaHealthHtml(){
    const report=state.health,summary=report?.summary||{},problem=report?.tracks?.filter(t=>t.public&&!['ready','legacy_ready'].includes(t.status))||[];
    const action='<button class="secondary" type="button" data-health-scan '+(state.healthBusy?'disabled':'')+'>'+(state.healthBusy?'Checking server files…':report?'Re-run audit':'Run media audit')+'</button>';
    return '<section class="panel media-health" id="mediaHealthPanel"><div class="panel-title"><div><div class="eyebrow">91B17H · STORAGE &amp; PLAYER</div><h2>Media Health</h2><p>Checks this server’s song references and managed media files. Nothing is repaired automatically.</p></div>'+action+'</div>'+
      (state.healthStatus?'<p class="media-health-message" role="status">'+esc(state.healthStatus)+'</p>':'')+
      (!report?'<div class="notice">Run a read-only audit to see missing audio, unpublished links, invalid paths and eligible recovery files.</div>':
      '<div class="media-health-stats"><span><strong>'+Number(summary.ready_public_tracks||0)+'</strong> ready public songs</span><span><strong>'+Number(summary.public_issues||0)+'</strong> audio issues</span><span><strong>'+Number(summary.broken_assets||0)+'</strong> broken managed files</span><span><strong>'+Number(summary.candidate_audio||0)+'</strong> available recovery assets</span></div>'+
      (summary.truncated?'<p class="notice">Audit limit reached. Only '+Number(summary.checked_tracks)+' songs and '+Number(summary.checked_assets)+' assets were checked; review the remainder separately.</p>':'')+
      '<p class="hint">Checked '+Number(summary.checked_tracks)+' song records and '+Number(summary.checked_assets)+' managed assets · '+esc(report.audited_at||'')+'. External URLs are not fetched or assumed broken.</p>'+
      (problem.length?'<div class="media-health-issues">'+problem.map(t=>'<div class="media-health-issue"><div><strong>'+esc(t.title)+'</strong><small>'+esc(t.id)+' · '+esc(healthStatusLabel[t.status]||t.status)+'</small><code title="Current audio reference">'+esc(t.audio||'No audio reference')+'</code></div>'+(t.can_relink?'<button type="button" class="secondary" data-health-relink="'+esc(t.id)+'" '+(!report.candidates?.length?'disabled':'')+'>Relink existing file</button>':'<span class="media-health-passive">Inspect external source</span>')+'</div>').join('')+'</div>':'<div class="media-health-ok">No public song reference issues detected in the checked catalog.</div>')+
      ((report.broken_assets||[]).length?'<details class="media-health-broken"><summary>Managed files needing attention ('+report.broken_assets.length+')</summary><div>'+report.broken_assets.slice(0,100).map(a=>'<p><strong>'+esc(a.name)+'</strong> · '+esc(a.category)+' · '+esc(healthStatusLabel[a.status]||a.status)+'<small>'+esc(a.stored_path)+'</small></p>').join('')+'</div></details>':''))+'</section>';
  }
  function bindMediaHealth(){
    const panel=$('#mediaHealthPanel',canvas);if(!panel)return;
    const scan=$('[data-health-scan]',panel);if(scan)scan.onclick=runMediaHealth;
    $$('[data-health-relink]',panel).forEach(button=>button.onclick=()=>{
      const row=state.health?.tracks?.find(t=>t.id===button.dataset.healthRelink);
      if(row&&row.can_relink)openMediaHealthRelink(row);
    });
  }
  function updateMediaHealth(){const panel=$('#mediaHealthPanel',canvas);if(panel){panel.outerHTML=mediaHealthHtml();bindMediaHealth()}}
  async function runMediaHealth(){
    if(state.healthBusy)return;
    state.healthBusy=true;state.healthStatus='';updateMediaHealth();
    try{state.health=await api('media-health.php')}
    catch(error){state.healthStatus='Media audit could not run: '+(error.message||'Unknown error')}
    finally{state.healthBusy=false;updateMediaHealth()}
  }
  function openMediaHealthRelink(track){
    const candidates=state.health?.candidates||[];
    if(!track.can_relink||!candidates.length)return;
    const overlay=document.createElement('div');overlay.className='media-modal media-health-modal';
    overlay.innerHTML='<div class="media-modal-card" role="dialog" aria-modal="true" aria-label="Relink song audio"><div class="panel-title"><div><div class="eyebrow">MEDIA HEALTH · MANUAL RECOVERY</div><h2>Relink '+esc(track.title)+'</h2><p>'+esc(healthStatusLabel[track.status]||track.status)+'</p></div><button class="secondary" type="button" data-health-close>✕ Close</button></div>'+
      '<p class="notice">This changes the PUBLIC listening audio for this published song. The song ID, catalog metadata, play history, existing assets and older attachments are preserved. Private source masters are not eligible.</p>'+
      '<form data-health-form><label class="field">Choose an existing, verified audio file<select name="asset_id" required><option value="">Select a server file…</option>'+candidates.map(a=>'<option value="'+Number(a.id)+'">'+esc(a.title)+' · '+esc(a.original_name)+' · '+fmtBytes(a.size_bytes)+'</option>').join('')+'</select></label>'+
      '<label class="check-field"><input type="checkbox" name="confirmed" required> I confirm this is the correct public audio for '+esc(track.title)+'.</label>'+
      '<div class="actions"><button class="primary" type="submit">Confirm relink</button><button class="secondary" type="button" data-health-close>Cancel</button></div><p class="status-line" role="status" data-health-result></p></form></div>';
    document.body.appendChild(overlay);
    $$('[data-health-close]',overlay).forEach(button=>button.onclick=()=>overlay.remove());
    const form=$('[data-health-form]',overlay);form.onsubmit=async event=>{
      event.preventDefault();const assetId=Number(form.elements.asset_id.value),submit=form.querySelector('[type="submit"]'),status=$('[data-health-result]',form);
      if(!form.elements.confirmed.checked||!candidates.some(a=>Number(a.id)===assetId))return;
      submit.disabled=true;status.textContent='Verifying and relinking the selected server file…';
      try{
        await api('media-health.php',{action:'relink_track_audio',track_id:track.id,asset_id:assetId,expected_audio:track.audio,confirmed:true});
        overlay.remove();await reloadCore();await runMediaHealth();
        state.healthStatus='Relinked '+track.title+'. The previous reference and media asset were preserved.';updateMediaHealth();
        say('Public song audio relinked. Existing song identity and media history preserved.');
      }catch(error){status.textContent=error.message||'Relink failed. No successful change was confirmed.';submit.disabled=false}
    };
  }
  function storeMediaHtml(products){
    if(!products?.length)return'';
    return '<section class="panel"><div class="panel-title"><div><h2>Store product media</h2><p>Attach reusable product imagery without editing store configuration files.</p></div></div><div class="media-store-grid">'+products.map(p=>'<button type="button" class="media-store-card" data-store-media="'+esc(p.id)+'" data-store-label="'+esc(p.label)+'"><strong>'+esc(p.label)+'</strong><span>'+esc((p.format||'product').toUpperCase())+'</span><small>Manage imagery</small></button>').join('')+'</div></section>';
  }
  async function renderMediaLibrary(){
    try{await loadAssets(state.category,state.query);state.dashboard=await api('media.php?mode=dashboard');state.completeness=state.dashboard.completeness||state.completeness}catch(e){canvas.innerHTML=head('MEDIA LIBRARY','Unavailable',e.message);return}
    const rows=state.aiOnly?state.assets.filter(a=>a.technical?.source==='openai_image_generation'):state.assets,counts={audio:0,image:0,video:0,document:0};rows.forEach(a=>{counts[a.category]=(counts[a.category]||0)+1});const d=state.completeness||{};
    canvas.innerHTML=head('ASSET MANAGEMENT','Media Library','One reusable asset system for songs, releases, shows, campaigns, store products and the public site.','<button class="secondary" id="mediaOpenImageStudio" type="button">Create with AI</button> <button class="primary" id="mediaUploadLibrary" type="button">Upload media</button>')+
      '<div class="stats media-stats"><div class="stat"><strong>'+Number(d.assets||rows.length)+'</strong><span>Total assets</span></div><div class="stat"><strong>'+fmtBytes(d.storage_bytes||0)+'</strong><span>Managed storage</span></div><div class="stat"><strong>'+Number(d.unused_assets||0)+'</strong><span>Unused</span></div><div class="stat"><strong>'+(state.capabilities.ffprobe?'FFprobe':'Built-in')+'</strong><span>Audio analyzer</span></div></div><div class="notice media-capability-note">Audio metadata: '+(state.capabilities.ffprobe?'FFprobe available; built-in MP3/WAV fallback also enabled.':'built-in MP3 frame/ID3 and WAV RIFF parser active.')+' Image derivatives: '+(state.capabilities.gd?'enabled via GD.':'original-only; GD is unavailable.')+' Effective upload ceiling: '+esc(state.capabilities.upload_max_label||'server-defined')+'.</div>'+
      completenessHtml()+mediaHealthHtml()+storeMediaHtml(state.dashboard.store_products||[])+'<section class="panel" id="videoHubPanel"><div class="empty compact">Loading Public Video Hub…</div></section><section class="panel" id="photoGalleriesPanel"><div class="empty compact">Loading Photo Galleries…</div></section>'+
      '<section class="panel"><div class="panel-title"><div><h2>Asset library</h2><p>Search, inspect usage, edit metadata and remove unused files. Generated images remain private until approved in Image Studio.</p></div></div><div class="toolbar media-toolbar"><input id="mediaSearch" type="search" placeholder="Search filename, title or caption" value="'+esc(state.query)+'"><div class="media-filters">'+['','audio','image','video','document'].map(k=>'<button type="button" data-media-category="'+k+'" class="'+(state.category===k?'active':'')+'">'+(k||'all')+'</button>').join('')+'<button type="button" id="mediaGeneratedOnly" class="'+(state.aiOnly?'active':'')+'">AI generated</button></div></div>'+imageBatchToolbar()+'<div id="mediaLibraryGrid" class="media-library-grid">'+(rows.length?rows.map(assetCard).join(''):'<div class="empty">No media assets match this filter.</div>')+'</div></section>';
    $('#mediaUploadLibrary').onclick=()=>pickUploadFiles('', '', '');bindMediaHealth();
    $('#mediaOpenImageStudio').onclick=()=>openView('image-generation');
    $('#mediaGeneratedOnly').onclick=()=>{state.aiOnly=!state.aiOnly;if(state.aiOnly)state.category='image';renderMediaLibrary()};
    $('#mediaSearch').onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();state.query=e.currentTarget.value.trim();renderMediaLibrary()}};
    $$('[data-media-category]',canvas).forEach(b=>b.onclick=()=>{state.category=b.dataset.mediaCategory;renderMediaLibrary()});
    bindImageBatchToolbar(canvas);
    bindReferenceGalleryControls(canvas,state.assets);
    $$('[data-media-analyze]',canvas).forEach(button=>button.onclick=()=>editAsset(Number(button.dataset.mediaAnalyze),{analyze:true}));
    $$('[data-media-edit]',canvas).forEach(b=>b.onclick=()=>editAsset(Number(b.dataset.mediaEdit)));
    $$('[data-media-add-catalog]',canvas).forEach(b=>b.onclick=()=>addCatalogAsset(Number(b.dataset.mediaAddCatalog)));
    $$('[data-media-open-catalog]',canvas).forEach(b=>b.onclick=()=>openCatalogTrack(b.dataset.mediaOpenCatalog));
    $$('[data-media-delete]',canvas).forEach(b=>b.onclick=()=>deleteAsset(Number(b.dataset.mediaDelete)));
    $$('[data-media-preview-ai]',canvas).forEach(b=>b.onclick=()=>window.SFImageGenerationAdmin?.previewByJobId?.(Number(b.dataset.mediaPreviewAi)));
    $$('[data-media-vary]',canvas).forEach(b=>b.onclick=async()=>{
      const a=state.assets.find(x=>Number(x.id)===Number(b.dataset.mediaVary));if(!a)return;
      try{
        const available=await api('image-generation.php');
        if(!(available.references||[]).some(x=>Number(x.id)===Number(a.id))){
          alert('This image is not currently eligible as a reference. Approve generated images in AI Image Studio first, or choose an eligible image from the reference picker.');
          return;
        }
      }catch(err){alert('Could not verify permission to reuse this reference image: '+err.message);return}
      window.SFImageGenerationAdmin?.openWithDraft?.({preset_slug:'artist_world',reference_asset_id:Number(a.id),
        concept:'Create a new Stonefellow image variation using '+(a.title||a.original_name)+' as an authorized visual reference. Preserve its recognizable visual identity while adjusting the scene, lighting and composition. No overlaid text.'});
    });
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
  function referenceGalleryControl(asset){
    if(asset.category!=='image'||!['image/png','image/jpeg','image/webp'].includes(asset.mime_type))return '';
    const id=Number(asset.asset_id||asset.id),included=asset.in_reference_gallery===true||Number(asset.in_reference_gallery)===1;
    return '<div class="media-reference-gallery"><label><input type="checkbox" data-media-reference="'+id+'" '+(included?'checked':'')+'> <span>Include in reference gallery</span></label><small>Available to all visitors in /images</small><small data-media-reference-status role="status"></small></div>';
  }
  function bindReferenceGalleryControls(root,assets){
    $$('[data-media-reference]',root).forEach(control=>control.onchange=async()=>{
      const id=Number(control.dataset.mediaReference),asset=assets.find(a=>Number(a.asset_id||a.id)===id);
      if(!asset)return;
      const previous=asset.in_reference_gallery===true||Number(asset.in_reference_gallery)===1,enabled=control.checked;
      const controls=$$('[data-media-reference="'+id+'"]');
      controls.forEach(node=>{
        node.disabled=true;
        const status=$('[data-media-reference-status]',node.closest('.media-reference-gallery'));
        if(status)status.textContent='Saving…';
      });
      try{
        const result=await api('media.php',{action:'set_reference_gallery',id,enabled});
        const included=result.asset?.in_reference_gallery===true||Number(result.asset?.in_reference_gallery)===1;
        for(const item of [...state.assets,...assets]){
          if(Number(item.asset_id||item.id)===id)item.in_reference_gallery=included;
        }
        controls.forEach(node=>{
          node.checked=included;
          const status=$('[data-media-reference-status]',node.closest('.media-reference-gallery'));
          if(status)status.textContent=included?'Included in the reference gallery.':'Removed from the reference gallery.';
        });
        say(included?'Image included in the reference gallery.':'Image removed from the reference gallery.');
      }catch(error){
        controls.forEach(node=>{
          node.checked=previous;
          const status=$('[data-media-reference-status]',node.closest('.media-reference-gallery'));
          if(status)status.textContent=error.message||'Reference gallery could not be updated.';
        });
      }finally{controls.forEach(node=>node.disabled=false);}
    });
  }
  function imageBatchToolbar(){
    return '<section class="media-image-batch-toolbar" aria-label="Batch image analysis"><div><strong>Batch image analysis</strong><p>Analyze selected images or images missing analysis across the managed library. Review the drafts before approving them.</p></div><div class="media-image-batch-actions"><label><input type="checkbox" data-media-batch-visible> Select visible images</label><span data-media-batch-count role="status"></span><button class="secondary" type="button" data-media-batch-clear>Clear selection</button><button class="primary" type="button" data-media-batch-selected>Analyze selected</button><button class="secondary" type="button" data-media-batch-missing>Analyze missing images</button><button class="secondary" type="button" data-media-batch-open>Open saved batch</button></div></section>';
  }
  function bindImageBatchToolbar(root){
    const selection=state.imageBatchSelection,visible=$$('[data-media-batch-image]',root);
    const sync=()=>{
      for(const checkbox of visible)checkbox.checked=selection.has(Number(checkbox.dataset.mediaBatchImage));
      const count=$('[data-media-batch-count]',root),all=$('[data-media-batch-visible]',root);
      if(count)count.textContent=selection.size+' selected';
      const selected=$('[data-media-batch-selected]',root),clear=$('[data-media-batch-clear]',root);
      if(selected)selected.disabled=selection.size===0;
      if(clear)clear.disabled=selection.size===0;
      if(all){
        const checked=visible.filter(checkbox=>checkbox.checked).length;
        all.checked=visible.length>0&&checked===visible.length;all.indeterminate=checked>0&&checked<visible.length;all.disabled=visible.length===0;
      }
    };
    for(const checkbox of visible)checkbox.onchange=()=>{
      const id=Number(checkbox.dataset.mediaBatchImage);
      if(checkbox.checked)selection.add(id);else selection.delete(id);
      sync();
    };
    const all=$('[data-media-batch-visible]',root);
    if(all)all.onchange=()=>{for(const checkbox of visible){const id=Number(checkbox.dataset.mediaBatchImage);if(all.checked)selection.add(id);else selection.delete(id);}sync();};
    const clear=$('[data-media-batch-clear]',root);
    if(clear)clear.onclick=()=>{selection.clear();sync();};
    const selected=$('[data-media-batch-selected]',root);
    if(selected)selected.onclick=()=>openImageAnalysisBatch({mode:'selected',ids:Array.from(selection)});
    const missing=$('[data-media-batch-missing]',root);
    if(missing)missing.onclick=()=>openImageAnalysisBatch({mode:'missing'});
    const saved=$('[data-media-batch-open]',root);
    if(saved)saved.onclick=()=>openImageAnalysisBatch();
    sync();
  }
  function imageBatchSelectionControl(asset){
    if(asset.category!=='image')return '';
    const id=Number(asset.id),title=String(asset.title||asset.original_name||'image');
    return '<label class="media-image-batch-select"><input type="checkbox" data-media-batch-image="'+id+'" aria-label="'+esc('Select '+title+' for batch analysis')+'" '+(state.imageBatchSelection.has(id)?'checked':'')+'> Select for batch analysis</label>';
  }
  async function openImageAnalysisBatch(options={}){
    if(state.imageBatchSession){state.imageBatchSession.dialog.focus();return;}
    const dialog=document.createElement('dialog');dialog.className='media-image-batch-dialog';dialog.setAttribute('aria-labelledby','mediaImageBatchTitle');
    dialog.innerHTML='<section class="media-image-batch-panel"><header><div><h2 id="mediaImageBatchTitle">Batch image analysis</h2><p>Progress is saved after each image. Closing pauses the batch.</p></div><button class="secondary" type="button" data-batch-close>Close</button></header><div class="media-image-batch-body"><div class="media-image-batch-controls"><label>Saved batches<select data-batch-jobs aria-label="Saved batches"><option value="">Choose a saved batch</option></select></label><button class="secondary" type="button" data-batch-refresh>Refresh progress</button><button class="secondary" type="button" data-batch-pause>Pause</button><button class="primary" type="button" data-batch-resume>Resume</button><button class="danger" type="button" data-batch-cancel>Cancel batch</button></div><p class="media-image-batch-message" data-batch-message role="status"></p><div data-batch-progress></div><div class="media-image-batch-review-actions"><button class="secondary" type="button" data-batch-select-drafts>Select all drafts</button><button class="primary" type="button" data-batch-review>Approve selected drafts</button><span data-batch-review-count></span></div><div class="media-image-batch-items" data-batch-items></div></div></section>';
    const session={dialog,job:null,jobs:[],active:true,running:false,busy:false,controlBusy:false,driving:false,stepPromise:null,review:new Map(),createId:options.mode?crypto.randomUUID().replaceAll('-',''):null,message:'Loading saved progress…'};
    state.imageBatchSession=session;
    const body=$('.media-image-batch-body',dialog),message=$('[data-batch-message]',dialog),progress=$('[data-batch-progress]',dialog),items=$('[data-batch-items]',dialog),jobs=$('[data-batch-jobs]',dialog);
    const pause=$('[data-batch-pause]',dialog),resume=$('[data-batch-resume]',dialog),refresh=$('[data-batch-refresh]',dialog),cancel=$('[data-batch-cancel]',dialog),review=$('[data-batch-review]',dialog),selectDrafts=$('[data-batch-select-drafts]',dialog);
    const fieldNames=['description','visible_text','objects','scene','style','colors','tags','uncertainties'];
    const active=()=>session.active&&state.imageBatchSession===session;
    const canReview=item=>item.status==='completed'&&item.analysis_status==='pending_review'&&typeof item.analysis_id==='string'&&Number.isInteger(item.version);
    const applyJob=job=>{
      if(!job||typeof job.id!=='string'||!Array.isArray(job.items))throw new Error('The server did not return saved batch progress.');
      session.job=job;
      for(const [id,selected] of session.review){
        const current=job.items.find(item=>String(item.image_id)===id);
        if(!current||!canReview(current)||current.analysis_id!==selected.analysis_id||current.version!==selected.version)session.review.delete(id);
      }
    };
    const render=()=>{
      if(!active())return;
      const job=session.job,counts=job?.counts||{},stopped=!session.running&&!session.busy&&!session.controlBusy;
      message.textContent=session.message;
      pause.disabled=!job||session.controlBusy||(!session.running&&!session.busy);
      pause.textContent=session.controlBusy?'Saving pause…':'Pause';
      resume.disabled=!job||!stopped||!['running','paused'].includes(job.status)||Number(counts.processing)>0||Number(counts.pending)===0;
      refresh.disabled=session.controlBusy||session.busy;
      cancel.disabled=!job||session.controlBusy||!['running','paused'].includes(job.status);
      jobs.disabled=session.busy||session.controlBusy||session.running;
      selectDrafts.disabled=!job||!stopped||!job.items.some(canReview);
      review.disabled=!stopped||session.review.size===0;
      $('[data-batch-review-count]',dialog).textContent=session.review.size+' selected for approval';
      progress.replaceChildren();items.replaceChildren();
      if(!job)return;
      const status=document.createElement('p');status.className='media-image-batch-status';
      status.textContent='Batch '+job.status+' · '+Number(counts.total||0)+' images';progress.append(status);
      const bar=document.createElement('progress'),finished=Number(counts.total||0)-Number(counts.pending||0)-Number(counts.processing||0);
      bar.max=Math.max(1,Number(counts.total||0));bar.value=Math.max(0,finished);bar.setAttribute('aria-label','Batch analysis progress');progress.append(bar);
      const tally=document.createElement('p');tally.textContent=finished+' finished · '+Number(counts.pending||0)+' pending · '+Number(counts.processing||0)+' processing · '+Number(counts.completed||0)+' drafts · '+Number(counts.reviewed||0)+' approved · '+Number(counts.failed||0)+' failed · '+Number(counts.skipped||0)+' skipped';progress.append(tally);
      const usage=document.createElement('p'),totals=job.usage||{};
      usage.className='media-image-batch-usage';usage.textContent='Input tokens '+Number(totals.input_tokens||0)+' · Output tokens '+Number(totals.output_tokens||0)+' · Active Tokens charged '+Number(totals.active_tokens_used||0);progress.append(usage);
      if(Number(job.remaining_count)>0){const remaining=document.createElement('p');remaining.textContent=Number(job.remaining_count)+' more images are missing analysis beyond this batch.';progress.append(remaining);}
      for(const item of job.items){
        const card=document.createElement('article');card.className='media-image-batch-item';
        const content=document.createElement('div'),title=document.createElement('h3'),stateLabel=document.createElement('p');
        title.textContent=item.title||'Image '+item.image_id;stateLabel.textContent=item.status;content.append(title,stateLabel);
        try{
          if(typeof item.image_url!=='string'||!item.image_url)throw new Error('Image preview unavailable.');
          const source=typeof item.image_url==='string'&&item.image_url.startsWith('api/')?'../'+item.image_url:item.image_url;
          const url=new URL(source,document.baseURI);
          if(['http:','https:'].includes(url.protocol)&&url.origin===window.location.origin&&!url.username&&!url.password){const image=document.createElement('img');image.alt='';image.loading='lazy';image.src=url.href;card.append(image);}
        }catch(_){}
        if(item.analysis?.description){const description=document.createElement('p');description.textContent=item.analysis.description;content.append(description);}
        if(item.analysis){
          const details=document.createElement('details'),summary=document.createElement('summary'),fields=document.createElement('dl');summary.textContent='Review image details';
          for(const key of fieldNames){
            const value=item.analysis[key];if(!value||(Array.isArray(value)&&!value.length))continue;
            const term=document.createElement('dt'),definition=document.createElement('dd');term.textContent=key.replaceAll('_',' ');definition.textContent=Array.isArray(value)?value.join(', '):String(value);fields.append(term,definition);
          }
          details.append(summary,fields);content.append(details);
        }
        if(item.error){const error=document.createElement('p');error.className='media-image-batch-error';error.textContent=item.error;content.append(error);}
        if(canReview(item)){
          const label=document.createElement('label'),checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.checked=session.review.has(String(item.image_id));checkbox.disabled=!stopped;
          checkbox.setAttribute('aria-label','Approve analysis for '+title.textContent);
          checkbox.onchange=()=>{const id=String(item.image_id);if(checkbox.checked)session.review.set(id,{analysis_id:item.analysis_id,version:item.version});else session.review.delete(id);review.disabled=session.review.size===0;$('[data-batch-review-count]',dialog).textContent=session.review.size+' selected for approval';};
          label.className='media-image-batch-review-choice';label.append(checkbox,document.createTextNode(' Select draft for approval'));content.append(label);
        }
        if(item.analysis_id){
          const edit=document.createElement('button');edit.type='button';edit.className='secondary';edit.textContent='Edit analysis';edit.disabled=!stopped;
          edit.onclick=async()=>{
            session.running=false;session.controlBusy=true;render();
            try{
              if(['running','paused'].includes(session.job.status)){const response=await api('image-analysis-batch.php',{action:'pause',id:session.job.id});applyJob(response.job);}
              if(!active())return;
              dialog.close();
              await editAsset(Number(item.image_id),{onClose:()=>{
                if(!active())return;
                dialog.showModal();void recover();
              }});
            }catch(error){session.message=error.message||'Image details could not be opened.';}
            finally{session.controlBusy=false;render();}
          };
          content.append(edit);
        }
        card.append(content);items.append(card);
      }
    };
    const updateJobs=()=>{
      const selected=session.job?.id||'';jobs.replaceChildren();
      const placeholder=document.createElement('option');placeholder.value='';placeholder.textContent='Choose a saved batch';jobs.append(placeholder);
      const summaries=session.jobs.slice();
      if(session.job&&!summaries.some(job=>job.id===session.job.id))summaries.unshift(session.job);
      for(const job of summaries){const option=document.createElement('option');option.value=job.id;option.textContent=(job.created_at||'Saved batch')+' · '+job.status+' · '+Number(job.counts?.total||0)+' images';jobs.append(option);}
      jobs.value=selected;
    };
    const recover=async()=>{
      if(!active())return;
      session.running=false;session.controlBusy=true;render();
      try{
        const id=session.job?.id||session.createId;
        const response=await api('image-analysis-batch.php'+(id?'?id='+encodeURIComponent(id):''));
        if(!active())return;
        if(Array.isArray(response.jobs))session.jobs=response.jobs;
        if(response.job)applyJob(response.job);
        session.message=response.job?'Saved progress loaded.': 'No saved batches yet.';updateJobs();
      }catch(error){if(active())session.message=error.message||'Saved progress could not be loaded.';}
      finally{session.controlBusy=false;render();}
    };
    const drive=async()=>{
      if(session.driving)return;
      session.driving=true;
      try{
        while(active()&&dialog.open&&document.visibilityState==='visible'&&session.running&&session.job?.status==='running'&&Number(session.job.counts?.pending)>0){
          session.busy=true;session.message='Analyzing the next image…';render();
          try{
            const next=session.job.items.find(item=>item.status==='pending');
            session.stepPromise=api('image-analysis-batch.php',{action:'step',id:session.job.id,request_id:next.request_id});
            const response=await session.stepPromise;applyJob(response.job);
            if(Number(session.job.counts?.processing)>0){session.running=false;session.message='An image is still processing. Refresh to check its saved progress.';}
            else if(session.job.items.find(item=>item.request_id===next.request_id)?.status==='pending'){
              session.running=false;session.message='The next image has not started. Refresh saved progress before resuming.';
            }
          }catch(error){
            session.running=false;session.message=error.message||'The connection was interrupted. Refresh saved progress before resuming.';
            if(active()){
              try{const response=await api('image-analysis-batch.php?id='+encodeURIComponent(session.job.id));if(active())applyJob(response.job);}catch(_){}
            }
          }finally{session.stepPromise=null;session.busy=false;render();}
        }
        if(session.running){session.running=false;session.message=session.job?.status==='completed'?'Analysis finished. Review the saved drafts.':'Batch progress is saved.';}
      }finally{session.driving=false;render();}
    };
    const pauseBatch=async()=>{
      session.running=false;session.controlBusy=true;render();
      const id=session.job?.id,pendingStep=session.stepPromise;
      try{
        if(id){const response=await api('image-analysis-batch.php',{action:'pause',id});if(active())applyJob(response.job);}
        if(active())session.message='Batch paused. Progress is saved.';
      }catch(error){if(active())session.message=error.message||'Progress is saved. Refresh to check the batch.';}
      finally{
        session.controlBusy=false;render();
        if(pendingStep)pendingStep.then(()=>{if(active())void recover();},()=>{if(active())void recover();});
      }
    };
    const close=()=>{
      if(!active())return;
      session.running=false;
      if(session.job&&['running','paused'].includes(session.job.status))void pauseBatch();
      session.active=false;state.imageBatchSession=null;document.removeEventListener('visibilitychange',visibilityChanged);
      if(dialog.open)dialog.close();dialog.remove();
      $('[data-media-batch-open]',canvas)?.focus();
    };
    const visibilityChanged=()=>{if(document.visibilityState!=='visible'&&session.running)void pauseBatch();};
    document.addEventListener('visibilitychange',visibilityChanged);
    $('[data-batch-close]',dialog).onclick=close;
    dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
    dialog.addEventListener('keydown',event=>event.stopPropagation());
    pause.onclick=()=>void pauseBatch();refresh.onclick=()=>void recover();
    resume.onclick=async()=>{
      if(!active()||session.busy||session.controlBusy||!session.job)return;
      session.controlBusy=true;render();
      try{const response=await api('image-analysis-batch.php',{action:'resume',id:session.job.id});if(!active())return;applyJob(response.job);session.running=true;session.message='Resuming batch…';}
      catch(error){session.message=error.message||'The batch could not be resumed.';}
      finally{session.controlBusy=false;render();}
      if(active()&&session.running)void drive();
    };
    cancel.onclick=async()=>{
      if(!session.job||session.controlBusy)return;
      session.running=false;session.controlBusy=true;render();
      const pendingStep=session.stepPromise;
      try{
        const response=await api('image-analysis-batch.php',{action:'cancel',id:session.job.id});if(active()){applyJob(response.job);session.message='Batch canceled. Completed drafts remain available for review.';}}
      catch(error){if(active())session.message=error.message||'The batch could not be canceled.';}
      finally{
        session.controlBusy=false;render();
        if(pendingStep)pendingStep.then(()=>{if(active())void recover();},()=>{if(active())void recover();});
      }
    };
    jobs.onchange=async()=>{
      if(!jobs.value||session.running||session.busy||session.controlBusy)return;
      session.controlBusy=true;render();
      try{const response=await api('image-analysis-batch.php?id='+encodeURIComponent(jobs.value));if(active()){applyJob(response.job);session.message='Saved batch loaded.';}}
      catch(error){if(active())session.message=error.message||'The saved batch could not be loaded.';}
      finally{session.controlBusy=false;render();}
    };
    selectDrafts.onclick=()=>{
      for(const item of session.job?.items||[])if(canReview(item))session.review.set(String(item.image_id),{analysis_id:item.analysis_id,version:item.version});render();
    };
    review.onclick=async()=>{
      if(session.running||session.busy||session.controlBusy||!session.review.size)return;
      const selected=session.job.items.filter(item=>{const choice=session.review.get(String(item.image_id));return canReview(item)&&choice?.analysis_id===item.analysis_id&&choice.version===item.version;});
      const payload=selected.map(item=>({image_id:item.image_id,analysis_id:item.analysis_id,version:item.version,analysis:Object.fromEntries(fieldNames.map(key=>[key,item.analysis[key]]))}));
      session.controlBusy=true;session.message='Approving selected drafts…';render();
      try{
        const response=await api('image-analysis-batch.php',{action:'review',id:session.job.id,items:payload});
        if(!active())return;
        applyJob(response.job);
        const rejected=(response.review_results||[]).filter(result=>!result.ok);
        session.message=rejected.length?rejected.length+' drafts changed or could not be approved. Review their latest details.':'Selected image analyses approved.';
      }catch(error){if(active())session.message=error.message||'Draft approval could not be confirmed. Refresh the saved batch.';}
      finally{session.controlBusy=false;render();}
    };
    document.body.append(dialog);dialog.showModal();render();
    if(options.mode){
      session.controlBusy=true;session.message='Saving batch…';render();
      try{
        const response=await api('image-analysis-batch.php',{action:'create',request_id:session.createId,mode:options.mode,ids:options.ids||[]});
        if(!active())return;
        applyJob(response.job);updateJobs();session.running=session.job.status==='running';session.message='Batch saved.';
      }catch(error){
        if(active())session.message=error.message||'The batch could not be created. Open saved progress before trying again.';
        try{
          const response=await api('image-analysis-batch.php?id='+encodeURIComponent(session.createId));
          if(active()){applyJob(response.job);updateJobs();session.message='Batch was saved. Resume when ready.';}
        }catch(_){}
      }
      finally{session.controlBusy=false;render();}
      if(active()&&session.running)void drive();
    }else await recover();
  }
  function mediaImageAnalysisSummary(asset){
    if(asset.category!=='image')return '';
    const analysis=asset.image_analysis;
    const text=analysis?.description?String(analysis.description).slice(0,320):'';
    return '<p class="media-image-analysis-summary" data-media-analysis-summary="'+Number(asset.asset_id||asset.id)+'">'+esc(text)+'</p>';
  }
  function mediaImageAnalysisPanel(asset){
    if(asset.category!=='image'||!['image/png','image/jpeg','image/webp'].includes(asset.mime_type))return '';
    const analysis=asset.image_analysis||{},editable=['pending_review','approved'].includes(analysis.status);
    const listValue=key=>Array.isArray(analysis[key])?analysis[key].join('\n'):'';
    const textField=(key,label,maxLength,rows=3)=>'<label class="field media-analysis-wide">'+esc(label)+'<textarea aria-label="'+esc(label)+'" name="'+key+'" maxlength="'+maxLength+'" rows="'+rows+'">'+esc(analysis[key]||'')+'</textarea></label>';
    const listField=(key,label,maxLength)=>'<label class="field">'+esc(label)+'<textarea aria-label="'+esc(label)+'" name="'+key+'" maxlength="'+maxLength+'" rows="3">'+esc(listValue(key))+'</textarea></label>';
    const metadata=[analysis.provider,analysis.model,analysis.analyzed_at?'Analyzed '+analysis.analyzed_at:'',analysis.reviewed_at?'Reviewed '+analysis.reviewed_at:''].filter(Boolean).map(esc).join(' · ');
    const status=analysis.status==='approved'?'Reviewed analysis saved.':analysis.status==='pending_review'?'Review this analysis before saving it for image search.':analysis.status==='running'?'Image analysis is running. Check its progress below.':analysis.status==='failed'?'Image analysis could not finish. You can try again.':'Analyze the image to describe its visible content and make it easier to find.';
    return '<section class="media-image-analysis" data-media-image-analysis><div class="panel-title"><div><h3>Image analysis</h3><p>'+esc(status)+'</p></div><button class="secondary" type="button" data-media-analysis-run>'+(analysis.status==='running'?'Check analysis':analysis.id?'Analyze image again':'Analyze image')+'</button></div>'+
      (metadata?'<p class="hint media-analysis-provider">'+metadata+'</p>':'')+
      '<form data-media-analysis-form><fieldset '+(editable?'':'disabled')+'><div class="media-analysis-fields">'+
      textField('description','Visible description',4000,4)+textField('visible_text','Visible text / OCR',12000,4)+
      textField('scene','Scene and setting',1000,2)+textField('style','Visual style',500,2)+
      listField('objects','Objects, one per line',3630)+listField('colors','Colors, one per line',1296)+listField('tags','Tags, one per line',2430)+listField('uncertainties','Uncertainties, one per line',2892)+
      '</div><div class="actions"><button class="primary" type="submit">Save reviewed analysis</button></div></fieldset><p class="status-line" data-media-analysis-status role="status"></p></form></section>';
  }
  function bindMediaImageAnalysis(root,asset,analyzeOnOpen=false){
    const section=$('[data-media-image-analysis]',root);
    if(!section)return;
    const run=$('[data-media-analysis-run]',section),form=$('[data-media-analysis-form]',section),status=$('[data-media-analysis-status]',section);
    let busy=false;
    const remember=analysis=>{
      asset.image_analysis=analysis;
      for(const item of state.assets)if(Number(item.id)===Number(asset.id))item.image_analysis=analysis;
      $$('[data-media-analysis-summary="'+Number(asset.id)+'"]').forEach(node=>node.textContent=String(analysis.description||'').slice(0,320));
    };
    const render=analysis=>{
      remember(analysis);
      if(!root.isConnected)return;
      section.outerHTML=mediaImageAnalysisPanel(asset);
      bindMediaImageAnalysis(root,asset);
    };
    run.onclick=async()=>{
      if(busy)return;
      busy=true;run.disabled=true;form.querySelector('fieldset').disabled=true;status.textContent='Analyzing image…';
      const key=Number(asset.id);
      let requestId=state.imageAnalysisRequests.get(key)||(asset.image_analysis?.status==='running'?asset.image_analysis.id:null);
      if(!requestId){requestId=crypto.randomUUID().replaceAll('-','');state.imageAnalysisRequests.set(key,requestId);}
      try{
        const response=await api('media.php',{action:'analyze_image',id:key,request_id:requestId});
        if(!response.analysis?.id)throw new Error('The server did not return image analysis.');
        if(response.analysis.status!=='running')state.imageAnalysisRequests.delete(key);
        render(response.analysis);
      }catch(error){
        status.textContent=error.message||'Image analysis could not be loaded.';
        run.textContent='Check analysis';run.disabled=false;
        form.querySelector('fieldset').disabled=!['pending_review','approved'].includes(asset.image_analysis?.status);
      }finally{busy=false;}
    };
    form.onsubmit=async event=>{
      event.preventDefault();
      const current=asset.image_analysis;
      if(busy||!current?.id||!['pending_review','approved'].includes(current.status))return;
      const fields=new FormData(form),analysis={};
      for(const key of ['description','visible_text','scene','style'])analysis[key]=String(fields.get(key)||'');
      for(const key of ['objects','colors','tags','uncertainties'])analysis[key]=String(fields.get(key)||'').split(/\r?\n/).map(item=>item.trim()).filter(Boolean);
      busy=true;run.disabled=true;form.querySelector('fieldset').disabled=true;status.textContent='Saving reviewed analysis…';
      try{
        const response=await api('media.php',{action:'save_image_analysis',id:Number(asset.id),analysis_id:current.id,expected_version:current.version,analysis});
        if(!response.analysis?.id)throw new Error('The server did not confirm the reviewed analysis.');
        render(response.analysis);say('Reviewed image analysis saved.');
      }catch(error){
        status.textContent=error.message||'The reviewed analysis could not be saved.';
        run.disabled=false;form.querySelector('fieldset').disabled=false;
      }finally{busy=false;}
    };
    if(analyzeOnOpen)void run.onclick();
  }
  function assetCard(a){
    const tags=a.technical&&a.technical.normalized_tags||{};
    const audio=a.category==='audio'&&['mp3','wav','wave'].includes(String(a.extension||'').toLowerCase());
    const existing=audio?(app.catalog||[]).find(t=>Number(t.media_primary_asset||0)===Number(a.id)||Number(t.media_private_master_asset||0)===Number(a.id)||(t.audio||'').includes('api/media.php?id='+a.uuid)):null;
    const catalogAction=audio?(existing?'<button class="secondary" type="button" data-media-open-catalog="'+esc(existing.id)+'">In Catalog</button>':
      '<button class="primary media-add-catalog-btn" type="button" data-media-add-catalog="'+a.id+'">Add to Catalog</button>'):'';
    return '<article class="media-asset-card" data-asset-id="'+a.id+'"><div class="media-asset-preview">'+assetPreview(a)+'</div><div class="media-asset-main"><div class="media-asset-kicker">'+esc(String(a.category||'media').toUpperCase())+' · '+esc(sourceLabel(a))+'</div>'+imageBatchSelectionControl(a)+'<strong>'+esc(a.title||a.original_name)+'</strong><small>'+esc(a.original_name)+'</small><p>'+esc(technicalLine(a))+'</p>'+(a.technical?.source==='openai_image_generation'?'<p class="hint">Generated image · approval managed in AI Image Studio · job #'+Number(a.technical.image_job_id||0)+'</p>':'')+(tags.artist||tags.album?'<p>'+esc([tags.artist,tags.album].filter(Boolean).join(' · '))+'</p>':'')+mediaImageAnalysisSummary(a)+referenceGalleryControl(a)+'<div class="media-asset-foot"><span>'+fmtBytes(a.size_bytes)+'</span><span>'+Number(a.link_count||0)+' use'+(Number(a.link_count||0)===1?'':'s')+'</span></div></div><div class="media-asset-actions">'+catalogAction+(a.category==='image'&&['image/png','image/jpeg','image/webp'].includes(a.mime_type)&&Number(a.size_bytes)<=10*1024*1024?'<button class="secondary" type="button" data-media-vary="'+Number(a.id)+'">Create variation</button>':'')+(a.technical?.source==='openai_image_generation'&&Number(a.technical.image_job_id||0)>0?'<button class="secondary" type="button" data-media-preview-ai="'+Number(a.technical.image_job_id)+'">Preview placements</button>':'')+(a.category==='image'&&['image/png','image/jpeg','image/webp'].includes(a.mime_type)?'<button class="secondary" type="button" data-media-analyze="'+Number(a.id)+'">Analyze image</button>':'')+'<button class="secondary" type="button" data-media-edit="'+a.id+'">Edit</button>'+(Number(a.link_count||0)===0?'<button class="danger" type="button" data-media-delete="'+a.id+'">Delete</button>':'')+'</div></article>';
  }
  async function addCatalogAsset(id){
    const asset=state.assets.find(a=>Number(a.id)===id);
    if(!asset||asset.category!=='audio')return;
    const templates=app.templates||[];
    const images=await api('media.php?category=image').then(r=>r.assets||[]).catch(()=>[]);
    const opts='<option value="">Quick song · no template needed</option>'+templates.map(t=>'<option value="'+esc(t.id)+'">'+esc(t.name)+' · v'+Number(t.version||1)+'</option>').join('');
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
      '<label class="check-field span3"><input type="checkbox" name="digital_purchase_enabled"> Enable paid digital downloads</label>'+
      '<label class="check-field span3"><input type="checkbox" name="pod_eligible"> Available for vinyl print (requires a distinct private master)</label>'+
      '<label class="check-field span3"><input type="checkbox" name="rights_confirmed"> I confirm I have authority to publish this recording and its metadata.</label>'+
      '<label class="check-field span3"><input type="checkbox" name="auto_publish"> Auto-publish to public listening Catalog now</label>'+
      '<p class="notice span3">Choose streaming, downloads and vinyl. Stonefellow will protect any private master and prepare a separate listening MP3 automatically when possible, including on shared hosting. WAV masters may require a public MP3 upload in Catalog review.</p>'+
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
        const input={action:'intake_asset',simple_upload:!d.get('template_id'),asset_id:id,request_key:key,template_id:d.get('template_id'),
          title:d.get('title'),release:d.get('release'),isrc:d.get('isrc'),artist_display:d.get('artist_display'),
          words_by:d.get('words_by'),music_by:d.get('music_by'),master_owner:d.get('master_owner'),
          composition_owner:d.get('composition_owner'),artwork_asset_id:d.get('artwork_asset_id'),
          digital_price:d.get('digital_price'),digital_purchase_enabled:form.elements.digital_purchase_enabled.checked,
          pod_eligible:form.elements.pod_eligible.checked,
          rights_confirmed:rights,auto_publish:auto,confirmed:auto};
        const result=await api('media-catalog.php',input);
        if(result.publication==='published'){
          status.textContent='Published to the public listening Catalog.';await reloadCore();modal.remove();
          openCatalogTrack(result.track_id);say('Song published to the public Catalog.');
        }else{
          status.textContent=result.publication==='needs_review'?'Saved privately. Publication needs review: '+result.publication_message:'Saved as a private Song Draft. Nothing is public.';
          await reloadCore();modal.remove();openView('catalog');
          say('Saved as a PRIVATE Catalog draft. Open its Review / Publish controls to make it public.');
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
  async function editAsset(id,options={}){
    let a=state.assets.find(x=>Number(x.id)===id),usage=[];try{const detail=await api('media.php?asset_id='+encodeURIComponent(id));a=detail.asset||a;usage=detail.usage||[]}catch(_){if(!a){await loadAssets();a=state.assets.find(x=>Number(x.id)===id)}}if(!a)return;
    const tags=a.technical&&a.technical.normalized_tags||{},usageHtml='<section class="media-usage-panel"><strong>Used by '+usage.length+' item'+(usage.length===1?'':'s')+'</strong>'+(usage.length?usage.map(u=>'<div><span>'+esc(String(u.entity_type).toUpperCase())+' · '+esc(u.role)+'</span><b>'+esc(u.entity_label||u.entity_id)+'</b><small>'+(Number(u.public_visible)?'public':'private')+(Number(u.download_allowed)?' · downloadable':'')+'</small></div>').join(''):'<p>This asset is currently unused and may be deleted.</p>')+'</section>';
    const overlay=document.createElement('div');overlay.className='media-modal';overlay.innerHTML='<div class="media-modal-card"><div class="panel-title"><div><div class="eyebrow">MEDIA ASSET</div><h2>'+esc(a.title||a.original_name)+'</h2><p>'+esc(technicalLine(a))+'</p></div><button type="button" class="secondary" data-media-close>Close</button></div>'+(a.category==='audio'?'<audio controls preload="metadata" src="'+esc(mediaUrl(a))+'"></audio>':a.category==='image'?'<img class="media-modal-image" src="'+esc(mediaUrl(a,a.variants&&a.variants.medium?'medium':'original'))+'" alt="">':a.category==='video'?'<video class="media-modal-image" controls preload="metadata" src="'+esc(mediaUrl(a))+'"></video>':'')+referenceGalleryControl(a)+'<form id="mediaAssetForm" class="form-grid"><label class="field span2">Title<input name="title" value="'+esc(a.title||'')+'"></label><label class="field span3">Caption<textarea name="caption">'+esc(a.caption||'')+'</textarea></label><label class="field span2">Alt text<input name="alt_text" value="'+esc(a.alt_text||'')+'"></label><label class="field">Credit<input name="credit" value="'+esc(a.credit||'')+'"></label><label class="field span3">Copyright / rights<input name="copyright_text" value="'+esc(a.copyright_text||'')+'"></label><div class="actions span3"><button class="primary" type="submit">Save metadata</button></div></form>'+mediaImageAnalysisPanel(a)+'<section class="media-tech-panel"><strong>Extracted metadata</strong><dl><div><dt>File</dt><dd>'+esc(a.original_name)+' · '+fmtBytes(a.size_bytes)+'</dd></div><div><dt>Technical</dt><dd>'+esc(technicalLine(a))+'</dd></div><div><dt>Analyzer</dt><dd>'+esc(sourceLabel(a))+'</dd></div>'+(tags.title?'<div><dt>Embedded title</dt><dd>'+esc(tags.title)+'</dd></div>':'')+(tags.artist?'<div><dt>Embedded artist</dt><dd>'+esc(tags.artist)+'</dd></div>':'')+(tags.album?'<div><dt>Embedded album</dt><dd>'+esc(tags.album)+'</dd></div>':'')+(a.technical&&a.technical.embedded_artwork?'<div><dt>Embedded artwork</dt><dd>Detected</dd></div>':'')+'</dl></section>'+usageHtml+'</div>';
    let closed=false;const closeAsset=()=>{if(closed)return;closed=true;overlay.remove();options.onClose?.();};
    document.body.appendChild(overlay);bindReferenceGalleryControls(overlay,[a]);bindMediaImageAnalysis(overlay,a,options.analyze===true);$('[data-media-close]',overlay).onclick=closeAsset;$('#mediaAssetForm',overlay).onsubmit=async e=>{e.preventDefault();const d=new FormData(e.currentTarget);try{await api('media.php',{action:'update_asset',id:a.id,asset:{title:d.get('title'),caption:d.get('caption'),alt_text:d.get('alt_text'),credit:d.get('credit'),copyright_text:d.get('copyright_text')}});closeAsset();say('Media metadata saved.');if(app.view==='media')renderMediaLibrary();else reloadCore()}catch(x){alert(x.message)}};
  }
  async function deleteAsset(id){if(!confirm('Permanently delete this unused media asset and its stored file?'))return;try{await api('media.php',{action:'delete_asset',id});state.imageBatchSelection.delete(id);say('Media asset deleted.');renderMediaLibrary()}catch(e){alert(e.message)}}

  function trackSection(){return '<div class="track-media-toolbar"><button class="primary" type="button" data-track-media-upload="audio_candidate">Upload audio</button><button class="secondary" type="button" id="trackReplaceSourceAudio">Replace source audio</button><button class="secondary" type="button" data-track-media-upload="artwork">Upload artwork</button><button class="secondary" type="button" id="trackCreateAiArtwork">Create artwork with AI</button><button class="secondary" type="button" data-track-media-upload="photo">Upload photos</button><button class="secondary" type="button" data-track-media-upload="video">Upload video</button><button class="secondary" type="button" data-track-media-upload="document">Upload documents</button><button class="secondary" type="button" data-track-media-upload="archive">Upload archive media</button><button class="secondary" type="button" id="trackMediaChoose">Choose from Library</button></div><div class="media-publishing-hint">Use separate Media Library files for the public preview and the private purchased original. Your existing uploader and media attachments are used.</div><div id="trackMasterHistory" class="track-master-history"></div><div id="trackPublicationStatus" class="media-publication-status" role="status"></div><div id="trackMediaStatus" class="status-line"></div><div id="trackMediaGroups" class="track-media-groups"><div class="empty compact">Loading attached media…</div></div>'}
  async function bindTrackMedia(trackId){
    state.trackId=trackId;$$('[data-track-media-upload]',canvas).forEach(b=>b.onclick=()=>pickUploadFiles('track',trackId,b.dataset.trackMediaUpload));const choose=$('#trackMediaChoose',canvas);if(choose)choose.onclick=()=>chooseExisting('track',trackId,'archive');const replace=$('#trackReplaceSourceAudio',canvas);if(replace)replace.onclick=()=>replaceSourceAudio(trackId);
    const ai=$('#trackCreateAiArtwork',canvas);if(ai)ai.onclick=()=>{const t=(app.catalog||[]).find(x=>String(x.id)===String(trackId));
      window.SFImageGenerationAdmin?.openWithDraft?.({preset_slug:'album_cover',concept:'Create a cinematic song artwork concept for '+(t?.title||'this track')+'. Preserve a cohesive Stonefellow artist-world visual identity. No typography or interface overlays.'});};
    await refreshTrackMedia(trackId);
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
    return '<div class="universal-media" data-universal-media="'+esc(key)+'"><div class="track-media-toolbar">'+roleList.map((role,i)=>'<button class="'+(i===0?'primary':'secondary')+'" type="button" data-entity-media-upload="'+esc(role)+'">Upload '+esc(roleLabel(role))+'</button>').join('')+'<button class="secondary" type="button" data-entity-media-choose>Choose from Library</button><button class="secondary" type="button" data-entity-media-ai>Create with AI</button></div><div class="status-line" data-entity-media-status></div><div class="track-media-groups" data-entity-media-host><div class="empty compact">Loading attached media…</div></div></div>';
  }
  async function bindEntityMedia(entityType,entityId,config={},root=canvas){
    if(!entityId)return;const key=entityType+':'+entityId;state.entityConfigs.set(key,{...config,root});
    $$('[data-universal-media="'+CSS.escape(key)+'"] [data-entity-media-upload]',root).forEach(b=>b.onclick=()=>pickUploadFiles(entityType,entityId,b.dataset.entityMediaUpload,()=>refreshEntityMedia(entityType,entityId)));
    const choose=$('[data-universal-media="'+CSS.escape(key)+'"] [data-entity-media-choose]',root);if(choose)choose.onclick=()=>chooseExisting(entityType,entityId,(config.roles||['archive'])[0],config.roles||null,()=>refreshEntityMedia(entityType,entityId));
    const ai=$('[data-universal-media="'+CSS.escape(key)+'"] [data-entity-media-ai]',root);if(ai)ai.onclick=()=>{
      ai.closest('.media-modal')?.remove();
      const preset=['site','campaign'].includes(entityType)?'site_hero':entityType==='story_entry'?'song_moment':entityType==='store'?'merch_art':'album_cover';
      window.SFImageGenerationAdmin?.openWithDraft?.({preset_slug:preset,concept:'Create an original visual concept for '+entityType.replaceAll('_',' ')+' '+entityId+'. Use a cinematic Stonefellow aesthetic. No typography unless explicitly requested.'});
    };
    await refreshEntityMedia(entityType,entityId);
  }
  async function refreshEntityMedia(entityType,entityId){
    const key=entityType+':'+entityId,cfg=state.entityConfigs.get(key)||{},root=cfg.root||canvas,box=$('[data-universal-media="'+CSS.escape(key)+'"]',root);if(!box)return;
    try{const j=await api('media.php?entity_type='+encodeURIComponent(entityType)+'&entity_id='+encodeURIComponent(entityId)),links=j.links||[],host=$('[data-entity-media-host]',box);state.capabilities=j.capabilities||state.capabilities;host.innerHTML=links.length?links.map(l=>genericLinkCard(l,cfg)).join(''):'<div class="empty compact">No media attached yet.</div>';bindGenericCards(entityType,entityId,links,cfg,box)}catch(e){const s=$('[data-entity-media-status]',box);if(s)s.textContent=e.message}
  }
  function genericLinkCard(l,cfg){
    const publishRoles=cfg.publishRoles||[],canPublish=l.category==='image'&&publishRoles.includes(l.role),preview=l.category==='image'?'<img src="'+esc(mediaUrl(l,l.variants&&l.variants.thumb?'thumb':'original'))+'" alt="">':l.category==='audio'?'<audio controls preload="metadata" src="'+esc(mediaUrl(l))+'"></audio>':'<div class="media-type-icon">'+esc(l.category==='video'?'▶':l.category==='document'?'DOC':'FILE')+'</div>';
    return '<article class="track-media-card universal-media-card" draggable="true" data-generic-media-link="'+l.id+'" data-media-role="'+esc(l.role)+'"><div class="track-media-preview">'+preview+'</div><div class="track-media-main"><strong>'+esc(l.title||l.original_name)+'</strong><small>'+esc(roleLabel(l.role))+' · '+esc(technicalLine(l))+'</small><span>'+esc(l.original_name)+' · '+fmtBytes(l.size_bytes)+'</span>'+mediaImageAnalysisSummary(l)+referenceGalleryControl(l)+'<div class="media-link-flags"><label><input type="checkbox" data-generic-public="'+l.id+'" '+(Number(l.public_visible)?'checked':'')+'> Public</label><label><input type="checkbox" data-generic-download="'+l.id+'" '+(Number(l.download_allowed)?'checked':'')+'> Downloadable</label><label><input type="checkbox" data-generic-featured="'+l.id+'" '+(Number(l.featured)?'checked':'')+'> Featured</label></div></div><div class="track-media-actions">'+(canPublish?'<button class="primary" type="button" data-generic-publish="'+l.id+'" data-publish-role="'+esc(l.role)+'">'+(Number(l.featured)&&Number(l.public_visible)?'Republish ':'Publish ')+esc(roleLabel(l.role))+'</button>':'')+(l.category==='image'&&['image/png','image/jpeg','image/webp'].includes(l.mime_type)?'<button class="secondary" type="button" data-media-analyze="'+Number(l.asset_id)+'">Analyze image</button>':'')+'<button class="secondary" type="button" data-generic-edit="'+l.asset_id+'">Edit</button><button class="danger" type="button" data-generic-detach="'+l.id+'">Detach</button></div></article>';
  }
  function bindGenericCards(entityType,entityId,links,cfg,box){
    bindReferenceGalleryControls(box,links);
    $$('[data-media-analyze]',box).forEach(button=>button.onclick=()=>editAsset(Number(button.dataset.mediaAnalyze),{analyze:true}));
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

  window.SFMediaAdmin={renderMediaLibrary,openAsset:editAsset,trackSection,bindTrackMedia,chooseExisting,pickUploadFiles,entitySection,bindEntityMedia,openEntityManager,refreshEntityMedia};
})();
