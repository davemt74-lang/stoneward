(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;if(!ctx)return;
  const {api,app,canvas,head,esc,say,openView}=ctx;
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  let state={settings:{},openai:{},jobs:[],models:[],presets:[],styles:[],dashboard:{}};
  let pendingDraft=null,busy=false,incomingBrief=null,composerCache=null,referenceAssets=[];
  let placementOverlay=null,compareOverlay=null,galleryQuery='',galleryFilter='all',referenceQuery='';
  const publishablePlacements=new Set(['song_cover','release','story','campaign','site']);
  const placementSpecs={
    song_cover:{label:'Song cover',type:'track',role:'artwork',shape:'square',note:'Stages alternate song artwork. Publish primary artwork separately in the Catalog editor.'},
    story:{label:'Story hero',type:'story_entry',role:'hero',shape:'portrait',note:'Stages a private Story hero. Open the Story editor to approve its publication.'},
    song_moment:{label:'Song Moment photo',type:'track',role:'photo',shape:'moment',note:'Stages a private song photo. Make it public in Media Library, then select it at a timestamp in Song Moments.'},
    release:{label:'Release cover',type:'release',role:'cover',shape:'square',note:'Stages a release cover. Publish it separately in the Release editor.'},
    campaign:{label:'Campaign hero',type:'campaign',role:'hero',shape:'wide',note:'Stages campaign artwork. Nothing is sent to fans or published.'},
    site:{label:'Site hero',type:'site',role:'hero',shape:'wide',note:'Stages site artwork. The live homepage will not change.'},
    canvas:{label:'Site canvas background',type:'site',role:'background',shape:'wide',note:'Stages a canvas background in Media Library. The public template is unchanged until its own publishing flow is approved.'}
  };
  const idempotencyKey=()=>crypto.randomUUID();
  const fmt=n=>Number(n||0).toLocaleString();
  const dailyCapLabel=cap=>cap===null?'Unlimited':fmt(cap);
  const usd=cents=>'$'+(Number(cents||0)/100).toFixed(2);
  const imageUrl=uuid=>uuid?'../api/media.php?id='+encodeURIComponent(uuid):'';
  function installIconActions(job){
    if(job.status!=='ready'||!job.asset_approved_by||!job.media_asset_id)return '';
    return `<div data-imagegen-icon-actions><div class="actions"><button type="button" class="secondary" data-imagegen-icon="${Number(job.id)}">Use as install icon</button><button type="button" class="secondary" data-imagegen-open-splash hidden>Open PWA &amp; Splash</button></div><p role="status" data-imagegen-icon-status></p></div>`;
  }
  function bindInstallIconActions(root,close){
    for(const actions of $$('[data-imagegen-icon-actions]',root)){
      const stage=$('[data-imagegen-icon]',actions),open=$('[data-imagegen-open-splash]',actions),status=$('[data-imagegen-icon-status]',actions);
      open.onclick=()=>{if(close)close();openView('pwa-splash')};
      stage.onclick=async()=>{
        if(busy)return;
        busy=true;stage.disabled=true;open.hidden=true;status.textContent='Preparing a square install icon…';
        try{
          const {draft}=await api('pwa-splash.php');
          await api('pwa-icon-stage.php',{jobId:Number(stage.dataset.imagegenIcon),revision:draft.revision});
          status.textContent='512 × 512 PNG staged in the PWA draft. Open PWA & Splash to review and publish.';
          open.hidden=false;
        }catch(error){status.textContent=error.message;}
        finally{busy=false;stage.disabled=false;}
      };
    }
  }
  async function refresh(){const r=await api('image-generation.php');state={settings:r.settings||{},openai:r.openai||{},jobs:r.jobs||[],models:r.models||[],presets:r.presets||[],styles:r.styles||[],dashboard:r.dashboard||{}};referenceAssets=r.references||[];}
  function galleryHtml(){
    const withAssets=state.jobs.filter(j=>j.asset_uuid);
    if(!withAssets.length)return '<p class="empty">Generated artwork will appear here after the first approved paid request.</p>';
    const jobs=new Map(state.jobs.map(j=>[Number(j.id),j])),groups=new Map();
    const rootFor=j=>{let n=j,root=Number(j.id),seen=new Set([root]);
      while(n.parent_job_id){const parent=Number(n.parent_job_id);root=parent;if(seen.has(parent))break;seen.add(parent);n=jobs.get(parent);if(!n)break;}return root;};
    for(const j of withAssets){const root=rootFor(j);if(!groups.has(root))groups.set(root,[]);groups.get(root).push(j)}
    return [...groups.entries()].map(([root,items])=>'<section class="imagegen-gallery-group" data-imagegen-gallery-group><h3>Artwork family #'+root+' <small>· '+items.length+' result'+(items.length===1?'':'s')+'</small></h3><div class="imagegen-gallery-grid">'+
      items.map(j=>'<button type="button" class="imagegen-gallery-card" data-imagegen-gallery-card data-imagegen-compare="'+Number(j.id)+'" data-gallery-status="'+esc(j.status)+'" data-gallery-source="'+(j.source_asset_id?'variation':'original')+'" data-gallery-text="'+esc((j.prompt+' '+j.model+' '+j.id+' '+(j.reference_title||'')).toLowerCase())+'"><img src="'+imageUrl(j.asset_uuid)+'" loading="lazy" alt="Generated artwork #'+Number(j.id)+'"><span><strong>#'+Number(j.id)+'</strong> · '+(j.source_asset_id?'Variation':'Original')+'</span><small>'+esc(j.status.replaceAll('_',' '))+'</small></button>').join('')+'</div></section>').join('');
  }
  function compareInGallery(id){
    const job=state.jobs.find(j=>Number(j.id)===Number(id));if(!job?.asset_uuid)return;
    if(compareOverlay)compareOverlay.remove();if(placementOverlay)placementOverlay.remove();
    const before=imageUrl(job.reference_uuid),after=imageUrl(job.asset_uuid),overlay=document.createElement('div');
    overlay.className='imagegen-compare-backdrop';compareOverlay=overlay;
    overlay.innerHTML='<section class="imagegen-compare-shell" role="dialog" aria-modal="true" aria-labelledby="imagegenCompareHeading"><header><div><small>91B20E · PRIVATE VARIATION REVIEW</small><h2 id="imagegenCompareHeading">Artwork #'+Number(job.id)+' · '+(before?'Reference vs. variation':'Original generation')+'</h2><p>'+esc(job.model+' · '+job.size+' · '+job.quality+' · '+(job.background||'auto')+' background')+'</p></div><button type="button" class="secondary" data-compare-close>Close ×</button></header>'+
      '<div class="imagegen-compare-frame '+(before?'':'single')+'" style="--reveal:50%">'+(before?'<img class="imagegen-compare-before" src="'+before+'" alt="Original reference image">':'')+'<img class="imagegen-compare-after" src="'+after+'" alt="Generated artwork variation">'+(before?'<span class="imagegen-compare-handle" aria-hidden="true"></span>':'')+'</div>'+
      (before?'<label class="imagegen-compare-control">Reveal generated variation <input type="range" min="0" max="100" value="50" data-compare-range aria-label="Percentage of generated variation shown"></label><div class="imagegen-compare-labels"><span>Reference image</span><span>Generated result</span></div>':'<p class="hint">No source image for this original generation.</p>')+
      '<p class="imagegen-compare-prompt">'+esc(job.prompt)+'</p><div class="actions">'+(job.status==='ready'&&job.media_asset_id?'<button type="button" class="primary" data-compare-vary="'+Number(job.media_asset_id)+'">Use approved result as reference</button>':'<span class="hint">Approve this image before it can become a new reference.</span>')+(job.status==='ready'?'<button type="button" class="secondary" data-compare-preview="'+Number(job.id)+'">Preview placements</button>':'')+'</div>'+installIconActions(job)+'</section>';
    document.body.appendChild(overlay);document.body.classList.add('imagegen-placement-open');
    const close=()=>{overlay.remove();if(compareOverlay===overlay)compareOverlay=null;document.body.classList.remove('imagegen-placement-open')};
    $('[data-compare-close]',overlay).onclick=close;
    overlay.onclick=e=>{if(e.target===overlay)close()};
    overlay.onkeydown=e=>{if(e.key==='Escape'){e.preventDefault();close()}if(e.key==='Tab'){const nodes=Array.from(overlay.querySelectorAll('button,input')).filter(n=>n.getClientRects().length);if(!nodes.length)return;if(e.shiftKey&&document.activeElement===nodes[0]){e.preventDefault();nodes[nodes.length-1].focus()}else if(!e.shiftKey&&document.activeElement===nodes[nodes.length-1]){e.preventDefault();nodes[0].focus()}}};
    if(before)$('[data-compare-range]',overlay).oninput=e=>$('.imagegen-compare-frame',overlay).style.setProperty('--reveal',Number(e.target.value)+'%');
    const vary=$('[data-compare-vary]',overlay);if(vary)vary.onclick=()=>{const assetId=Number(vary.dataset.compareVary);close();selectVariationAsset(assetId)};
    const preview=$('[data-compare-preview]',overlay);if(preview)preview.onclick=()=>{close();previewPlacement(Number(preview.dataset.comparePreview))};
    bindInstallIconActions(overlay,close);
    $('[data-compare-close]',overlay).focus();
  }
  function selectVariationAsset(assetId){
    const asset=referenceAssets.find(x=>Number(x.id)===Number(assetId));
    if(!asset){alert('This approved image is not eligible as a reference or is outside the current Media Library results.');return}
    $('#imagegenReferenceSelect').value=String(asset.id);
    $('#imagegenReferenceSelect').dispatchEvent(new Event('change'));
    $('#imagegenConcept').value='Make a new variation of this approved Stonefellow artwork while preserving its artistic identity. Adjust lighting, atmosphere and composition as directed. No overlaid text.';
    $('#imagegenDraftForm [name="prompt"]').value=$('#imagegenConcept').value;
    $('#imagegenDraftForm [name="mask_asset_id"]').value='';
    pendingDraft=null;rememberCurrentComposer();$('#imagegenDraftForm').scrollIntoView({behavior:'smooth',block:'start'});
    say('Approved image selected as a new reference. Confirm image-use permission before saving.');
  }
  let rememberCurrentComposer=()=>{};
  function jobCard(j){
    const approvePrompt=j.status==='draft',generate=j.status==='approved',approveAsset=j.status==='needs_review';
    const statusText={draft:'Prompt draft',approved:'Prompt approved',generating:'Processing · do not retry',needs_review:'Private preview · approval required',ready:'Approved · available in Media Library',failed:'Failed / outcome uncertain'}[j.status]||j.status;
    const view=j.asset_uuid?'../api/media.php?id='+encodeURIComponent(j.asset_uuid):'';
    const ref=j.reference_uuid?'../api/media.php?id='+encodeURIComponent(j.reference_uuid):'';
    const usage=j.usage||{};
    return `<section class="panel imagegen-job" data-imagegen-job="${Number(j.id)}">
      <div class="panel-title"><div><h3>#${Number(j.id)} · ${esc(statusText)}</h3><p>${esc(j.model)} · ${esc(j.size)} · ${esc(j.quality)} · ${esc(j.created_at||'')}</p></div><span class="badge ${j.status==='ready'?'good':j.status==='failed'?'warn':''}">${esc(j.status)}</span></div>
      <div class="imagegen-job-body">${ref?`<div class="imagegen-variation-pair"><figure><img src="${ref}" alt="Source image reference" loading="lazy"><figcaption>Reference ${j.parent_job_id?'· job #'+Number(j.parent_job_id):''}</figcaption></figure>${view?`<figure><img src="${view}" alt="Generated variation" loading="lazy"><figcaption>Variation · job #${Number(j.id)}</figcaption></figure>`:''}</div>`:view?`<a href="${view}" target="_blank" rel="noopener" aria-label="Open full private image"><img src="${view}" alt="Private generated image preview" loading="lazy"></a>`:''}
      <div><p class="imagegen-prompt">${esc(j.prompt)}</p><small>Provider: OpenAI · ${esc(j.background||'auto')} background ${j.mask_asset_id?'· targeted mask #'+Number(j.mask_asset_id):''} · Original prompt and approvals retained. ${j.prompt_approved_by?'Approved by Admin #'+Number(j.prompt_approved_by):''}</small>
      ${j.asset_uuid?`<p><small>Media asset: ${esc(j.asset_uuid)} · ${j.asset_approved_by?'approved':'not approved for use'}</small></p>`:''}
      ${(usage.input_tokens||usage.output_tokens)?`<p><small>Provider-reported input ${fmt(usage.input_tokens)}, output ${fmt(usage.output_tokens)} tokens. Dollar billing is external and is not inferred here.</small></p>`:''}
      ${j.error_code?`<p class="notice">${esc(j.error_code)}. Check provider billing before creating a replacement draft.</p>`:''}
      ${j.started_at&&['ready','needs_review','failed'].includes(j.status)?`<p class="hint">${j.actual_cost_cents===null?'Cost not reconciled with provider billing.':'Admin-entered invoice cost: '+usd(j.actual_cost_cents)+' · '+esc(j.invoice_reference||'')}</p>`:''}
      <div class="actions">${approvePrompt?`<button class="secondary" data-imagegen-approve-prompt="${j.id}" type="button">Approve exact prompt</button>`:''}
      ${generate?`<button class="primary" data-imagegen-generate="${j.id}" type="button">Generate · paid API request</button>`:''}
      ${approveAsset?`<button class="primary" data-imagegen-approve-asset="${j.id}" type="button">Approve image for Media Library use</button>`:''}
      ${view?`<a class="secondary" href="${view}" target="_blank" rel="noopener">View full image</a>`:''}
      ${j.status==='ready'&&j.media_asset_id?`<button type="button" class="secondary" data-imagegen-vary="${j.media_asset_id}">Create a variation</button>`:''}
      ${view?`<button type="button" class="secondary" data-imagegen-compare="${j.id}">Compare versions</button>`:''}
      ${j.started_at&&['ready','needs_review','failed'].includes(j.status)?`<button type="button" class="secondary" data-imagegen-reconcile="${j.id}">Record invoice cost</button>`:''}
      ${j.status==='ready'&&view?`<button type="button" class="secondary" data-imagegen-preview="${j.id}">Preview placements</button>`:''}</div>${installIconActions(j)}</div></div>
    </section>`;
  }
  async function previewPlacement(jobId){
    const job=state.jobs.find(j=>Number(j.id)===Number(jobId));
    if(!job||job.status!=='ready'||!job.asset_uuid)return;
    if(placementOverlay)placementOverlay.remove();
    const imageUrl='../api/media.php?id='+encodeURIComponent(job.asset_uuid);
    const sources={
      track:(app.catalog||[]).map(x=>({id:String(x.id||''),title:String(x.title||x.id||'')})),
      release:(app.releases||[]).map(x=>({id:String(x.id||''),title:String(x.title||x.id||'')})),
      site:[{id:'public',title:'Stonefellow public site'}],story_entry:[],campaign:[]
    };
    const overlay=document.createElement('div');overlay.className='imagegen-placement-backdrop';placementOverlay=overlay;
    overlay.innerHTML='<div class="imagegen-placement-dialog" role="dialog" aria-modal="true" aria-labelledby="imagegenPlacementTitle">'+
      '<header class="imagegen-placement-header"><div><small>91B20G · REVIEW & APPLY</small><h2 id="imagegenPlacementTitle">Preview image placements</h2><p>Preview and private staging do not publish or charge. No publishing or provider request is performed here without separate, explicit Apply authorization. Managed prior images can be restored.</p></div><button type="button" class="secondary" data-placement-close>Close ×</button></header>'+
      '<div class="imagegen-placement-controls"><label class="field">Where to preview<select data-placement-kind>'+Object.entries(placementSpecs).map(([id,s])=>'<option value="'+id+'">'+esc(s.label)+'</option>').join('')+'</select></label>'+
      '<label class="field">Existing content<select data-placement-target></select></label><label class="field">Device<select data-placement-device><option value="desktop">Desktop</option><option value="mobile">Mobile</option></select></label></div>'+
      '<p class="imagegen-placement-note" data-placement-note></p><div class="imagegen-placement-compare" data-placement-compare></div>'+
      '<div class="imagegen-placement-footer"><label class="check-field"><input type="checkbox" data-placement-confirm> I approve adding this image as a private Media Library attachment; do not publish or replace any current public image.</label><div class="actions"><button class="primary" type="button" data-placement-stage disabled>Stage private attachment</button><button class="secondary" type="button" data-placement-media>Open Media Library</button><button class="secondary" type="button" data-placement-editor>Open content editor</button></div><p role="status" data-placement-status></p></div>'+      '<section class="imagegen-placement-publish" data-placement-publish hidden><h3>Public artwork · reviewed apply</h3><p>Applying changes live artwork for this target. Previous managed attachments remain available for restoration.</p>'+      '<label class="check-field"><input type="checkbox" data-placement-publish-confirm> I reviewed the current and proposed artwork and explicitly authorize replacing the public image.</label>'+      '<div class="actions"><button type="button" class="primary" data-placement-apply disabled>Apply approved artwork</button><button type="button" class="secondary" data-placement-restore disabled>Restore previous artwork</button></div>'+      '<div class="imagegen-placement-history" data-placement-history aria-live="polite"></div></section></div>';
    document.body.appendChild(overlay);document.body.classList.add('imagegen-placement-open');
    const kind=$('[data-placement-kind]',overlay),target=$('[data-placement-target]',overlay),device=$('[data-placement-device]',overlay),note=$('[data-placement-note]',overlay),compare=$('[data-placement-compare]',overlay),confirmed=$('[data-placement-confirm]',overlay),stage=$('[data-placement-stage]',overlay),status=$('[data-placement-status]',overlay),publicArea=$('[data-placement-publish]',overlay),publicConsent=$('[data-placement-publish-confirm]',overlay),apply=$('[data-placement-apply]',overlay),restore=$('[data-placement-restore]',overlay),history=$('[data-placement-history]',overlay);
    const dismiss=()=>{overlay.remove();document.body.classList.remove('imagegen-placement-open');if(placementOverlay===overlay)placementOverlay=null};
    $('[data-placement-close]',overlay).onclick=dismiss;
    overlay.addEventListener('click',e=>{if(e.target===overlay)dismiss()});
    overlay.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();dismiss();return}if(e.key!=='Tab')return;
      const items=Array.from(overlay.querySelectorAll('button:not([disabled]),select:not([disabled]),input:not([disabled])')).filter(n=>n.getClientRects().length);
      if(!items.length)return;if(e.shiftKey&&document.activeElement===items[0]){e.preventDefault();items[items.length-1].focus()}
      else if(!e.shiftKey&&document.activeElement===items[items.length-1]){e.preventDefault();items[0].focus()}
    });
    let refreshNonce=0,staging=false,applying=false,currentLinkId=0,candidateLinkId=0,restoreReceipt=0;
    const currentView=(title,url,shape,proposed)=>'<article class="imagegen-placement-example"><h3>'+esc(title)+'</h3><div class="imagegen-placement-frame '+esc(shape)+' '+(device.value==='mobile'?'phone':'desktop')+'">'+
      (shape==='moment'?'<div class="imagegen-placement-orb" aria-hidden="true">✧</div>':'')+
      (url?'<img src="'+esc(url)+'" alt="'+esc(title)+' artwork" loading="lazy">':'<div class="imagegen-placement-empty">No current artwork</div>')+
      (shape==='moment'?'<span class="imagegen-placement-time">♪ · 1:24</span>':'')+'</div><small>'+(proposed?'Approved image #'+Number(job.id)+' · private preview':'Current Media Library selection')+'</small></article>';
    const syncPublish=()=>{
      const enabled=publishablePlacements.has(kind.value)&&!!target.value&&!applying&&!staging;
      publicArea.hidden=!publishablePlacements.has(kind.value);
      apply.disabled=!enabled||!candidateLinkId||candidateLinkId===currentLinkId||!publicConsent.checked;
      restore.disabled=!enabled||!restoreReceipt;
    };
    const updateTargets=()=>{
      const s=placementSpecs[kind.value],rows=sources[s.type]||[];
      target.innerHTML=rows.filter(x=>x.id).map(x=>'<option value="'+esc(x.id)+'">'+esc(x.title)+'</option>').join('')||'<option value="">No existing content found</option>';
      confirmed.checked=false;stage.disabled=true;publicConsent.checked=false;currentLinkId=0;candidateLinkId=0;restoreReceipt=0;history.innerHTML='';
      syncPublish();updatePreview();
    };
    const updatePreview=async()=>{
      const seq=++refreshNonce,s=placementSpecs[kind.value],entityId=target.value,canApply=publishablePlacements.has(kind.value);
      stage.disabled=true;confirmed.checked=false;publicConsent.checked=false;currentLinkId=0;candidateLinkId=0;restoreReceipt=0;
      note.textContent=s.note;history.innerHTML='';status.textContent='Reading existing Media Library attachments…';syncPublish();
      if(!entityId){compare.innerHTML='<p class="notice">Choose an existing '+s.label.toLowerCase()+' target first.</p>';status.textContent='';return}
      try{
        const params='entity_type='+encodeURIComponent(s.type)+'&entity_id='+encodeURIComponent(entityId);
        const [r,h]=await Promise.all([
          api('media.php?entity_type='+encodeURIComponent(s.type)+'&entity_id='+encodeURIComponent(entityId)),
          canApply?api('media.php?mode=image_placement_history&'+params+'&role='+encodeURIComponent(s.role)):Promise.resolve({current_link_id:0,entries:[]})
        ]);
        if(seq!==refreshNonce||!overlay.isConnected)return;
        const links=(r.links||[]).filter(x=>x.category==='image');
        const matching=links.filter(x=>x.role===s.role||(s.type==='track'&&s.role==='artwork'&&x.role==='primary_artwork'));
        const old=matching.find(x=>Number(x.public_visible)===1&&Number(x.featured)===1)||matching.find(x=>Number(x.public_visible)===1)||null;
        const oldUrl=old?.uuid?'../api/media.php?id='+encodeURIComponent(old.uuid):'';
        compare.innerHTML=currentView('Current',oldUrl,s.shape,false)+currentView('Proposed',imageUrl,s.shape,true);
        currentLinkId=canApply?Number(h.current_link_id||0):0;
        const candidate=links.find(x=>Number(x.asset_id)===Number(job.media_asset_id)&&x.role===s.role);
        candidateLinkId=candidate?Number(candidate.id):0;
        const entries=Array.isArray(h.entries)?h.entries:[];
        const activeReceipt=entries.find(e=>Number(e.new_link_id)===currentLinkId&&Number(e.previous_link_id)>0);
        restoreReceipt=activeReceipt?Number(activeReceipt.id):0;
        history.innerHTML=canApply?(entries.length?'<h4>Recent reviewed artwork changes</h4>'+entries.slice(0,5).map(e=>
          '<div class="imagegen-placement-receipt"><span>'+esc(e.created_at||'')+' · Admin #'+Number(e.actor_id||0)+'</span><strong>'+esc(e.new_title||'Artwork #'+e.new_link_id)+'</strong><small>'+(e.action==='image_studio_placement_restored'?'Restored':'Applied')+' · receipt #'+Number(e.id)+'</small></div>').join(''):
          '<p class="hint">No reviewed apply history for this target yet. Unmanaged existing artwork cannot be restored here.</p>'):'';
        status.textContent=canApply?(candidateLinkId?'Image attached. Review and authorize Apply before changing public artwork.':'Preview only. Stage this approved image privately before applying.'):'Preview and private staging only. Open the specialized editor to publish this kind of artwork.';
        stage.disabled=!confirmed.checked;syncPublish();
      }catch(e){if(seq===refreshNonce){compare.innerHTML='<p role="alert">'+esc(e.message)+'</p>';status.textContent='Could not verify artwork. Publishing is disabled.';candidateLinkId=0;restoreReceipt=0;syncPublish()}}
    };
    kind.onchange=updateTargets;target.onchange=updatePreview;device.onchange=updatePreview;
    confirmed.onchange=()=>{stage.disabled=!confirmed.checked||!target.value||!compare.querySelector('.imagegen-placement-example')||staging};
    publicConsent.onchange=syncPublish;
    apply.onclick=async()=>{
      if(applying||staging||!publicConsent.checked||!candidateLinkId||candidateLinkId===currentLinkId||!publishablePlacements.has(kind.value))return;
      const spec=placementSpecs[kind.value],entityId=target.value,label=target.selectedOptions[0]?.textContent||entityId;
      if(!confirm('PUBLISH approved image #'+job.id+' as the primary '+spec.role+' for '+label+'? This changes live artwork. No AI request will be made.'))return;
      applying=true;syncPublish();status.textContent='Applying reviewed artwork to the existing content editor…';
      try{
        await api('media.php',{action:'image_studio_apply',entity_type:spec.type,entity_id:entityId,role:spec.role,
          link_id:candidateLinkId,job_id:Number(job.id),expected_current_link_id:currentLinkId,confirmed:true});
        await updatePreview();status.textContent='Published approved artwork. Previous managed versions remain available for restoration.';
        say('Approved image applied to '+label+'. No additional image generation was requested.');
      }catch(error){status.textContent=error.message||'Could not confirm artwork publication. Refresh before retrying.';publicConsent.checked=false}
      finally{applying=false;syncPublish()}
    };
    restore.onclick=async()=>{
      if(applying||staging||!restoreReceipt||!publishablePlacements.has(kind.value))return;
      const spec=placementSpecs[kind.value],entityId=target.value,label=target.selectedOptions[0]?.textContent||entityId;
      if(!confirm('RESTORE the previous managed '+spec.role+' for '+label+'? This changes live artwork.'))return;
      applying=true;syncPublish();status.textContent='Restoring the previous reviewed Media Library image…';
      try{
        await api('media.php',{action:'image_studio_restore',entity_type:spec.type,entity_id:entityId,role:spec.role,
          receipt_id:restoreReceipt,expected_current_link_id:currentLinkId,confirmed:true});
        await updatePreview();status.textContent='Previous managed artwork restored. The new artwork is still retained privately.';
        say('Previous artwork restored for '+label+'.');
      }catch(error){status.textContent=error.message||'Restore could not be confirmed. Refresh the artwork list.'}
      finally{applying=false;syncPublish()}
    };
    stage.onclick=async()=>{
      if(staging||!confirmed.checked||!target.value)return;
      const s=placementSpecs[kind.value],label=target.selectedOptions[0]?.textContent||target.value;
      if(!confirm('Stage approved image #'+job.id+' privately on '+label+' as '+s.role+'? Existing published artwork will remain unchanged.'))return;
      staging=true;stage.disabled=true;status.textContent='Attaching approved image privately…';
      try{await api('image-generation.php',{action:'stage_placement',id:Number(job.id),entity_type:s.type,entity_id:target.value,role:s.role,confirmed:true});
        confirmed.checked=false;status.textContent='Private attachment saved. Open the relevant content editor to review and approve publication separately.';say('Approved image staged privately. No public artwork changed.');
        await updatePreview();status.textContent='Private attachment saved. No public artwork changed.';
      }catch(e){status.textContent=e.message||'Private attachment could not be confirmed.'}
      finally{staging=false;stage.disabled=!confirmed.checked}
    };
    $('[data-placement-media]',overlay).onclick=()=>{dismiss();openView('media')};
    $('[data-placement-editor]',overlay).onclick=()=>{const s=placementSpecs[kind.value],view=s.type==='track'?(kind.value==='song_moment'?'song-moments':'catalog'):s.type==='story_entry'?'settings':s.type==='release'?'releases':s.type==='campaign'?'campaigns':'settings';dismiss();openView(view)};
    updateTargets();
    Promise.allSettled([api('stories.php'),api('campaigns.php')]).then(results=>{
      if(!overlay.isConnected)return;
      if(results[0].status==='fulfilled')sources.story_entry=(results[0].value.stories||[]).map(x=>({id:String(x.id),title:String(x.title||x.id)}));
      if(results[1].status==='fulfilled')sources.campaign=(results[1].value.campaigns||[]).map(x=>({id:String(x.id),title:String(x.name||x.title||x.id)}));
      if(['story_entry','campaign'].includes(placementSpecs[kind.value].type))updateTargets();
    });
    kind.focus();
  }
  function render(){
    const s=state.settings,o=state.openai,d=state.dashboard||{},c=d.counts||{},providerReady=!!(o.enabled&&o.key_configured);
    const presets=state.presets||[],styles=state.styles||[];
    const accountDailyCap=d.daily_cap===undefined?s.admin_daily_cap:d.daily_cap;
    let accountAvailability=fmt(d.today_remaining)+' paid requests remaining for your account today across Image Studio and canvases.';
    if(accountDailyCap===0)accountAvailability='Paid image generation is disabled for your account.';
    if(accountDailyCap===null)accountAvailability='Your account has unlimited image generation across Image Studio and canvases.';
    canvas.innerHTML=head('91B20I · IMAGE STUDIO','AI Image Studio','Visual Style Library, variations, transparent backgrounds, targeted edits and invoice reconciliation. Every paid request and publication remains separately approved.',
      '<button class="secondary" type="button" id="imagegenBackMedia">Media Library</button>')+
      '<div class="stats imagegen-stats">'+
      '<div class="stat"><strong>'+fmt(d.total)+'</strong><span>Image concepts</span></div>'+
      '<div class="stat"><strong>'+fmt(c.needs_review)+'</strong><span>Awaiting image review</span></div>'+
      '<div class="stat"><strong>'+fmt(c.ready)+'</strong><span>Approved images</span></div>'+
      '<div class="stat"><strong>'+fmt(d.reference_variations)+'</strong><span>Reference-based variations</span></div>'+
      '<div class="stat"><strong>'+fmt(d.today_attempts)+' / '+dailyCapLabel(accountDailyCap)+'</strong><span>Your paid attempts today · UTC</span></div>'+
      '<div class="stat"><strong>'+fmt(c.failed)+'</strong><span>Failed / uncertain</span></div></div>'+
      '<section class="panel imagegen-summary"><div class="panel-title"><div><h2>Generation overview</h2><p>'+accountAvailability+' '+fmt(c.draft)+' drafts · '+fmt(c.approved)+' approved prompts · '+fmt(c.generating)+' in progress.</p></div><button type="button" class="secondary" id="imagegenRefresh">Refresh history</button></div>'+
      '<div class="imagegen-model-activity"><strong>Model activity · last 30 days</strong><p>'+((d.model_activity_30d||[]).map(m=>esc(m.model)+' · '+fmt(m.total)+' request(s)').join('  ·  ')||'No paid image requests yet.')+' · '+fmt(d.variation_attempts_30d)+' variation attempts.</p>'+
      '<p>Image API reported: '+fmt(d.usage_30d?.['image-generation']?.input_tokens)+' input / '+fmt(d.usage_30d?.['image-generation']?.output_tokens)+' output tokens. Agent text prompt refinement: '+fmt(d.usage_30d?.['image-prompt-composer']?.input_tokens)+' input / '+fmt(d.usage_30d?.['image-prompt-composer']?.output_tokens)+' output tokens.</p></div><p class="hint">The dashboard counts provider-reported tokens, not fabricated dollar amounts. Provider billing is separate from customer Active Tokens. '+fmt(d.cost_reconciled_jobs)+' requests manually reconciled · '+fmt(d.cost_unreconciled_jobs)+' pending · '+usd(d.cost_recorded_cents)+' recorded from invoices (not a provider-sourced total).</p><div class="actions"><button type="button" class="secondary" id="imagegenExportAudit">Download recent usage and invoice audit CSV (up to 150 jobs)</button></div></section>'+
      '<section class="panel imagegen-gallery"><div class="panel-title"><div><h2>Image variation gallery</h2><p>Browse original imagery and all saved variations grouped by artwork family. Compare versions without making an API request.</p></div></div><div class="imagegen-gallery-toolbar"><label class="field">Search generated images<input type="search" id="imagegenGallerySearch" placeholder="Prompt, model, job ID or reference title" value="'+esc(galleryQuery)+'"></label><label class="field">Show<select id="imagegenGalleryFilter"><option value="all">All generated images</option><option value="variation">Variations only</option><option value="ready">Approved images</option><option value="needs_review">Awaiting approval</option></select></label></div><div id="imagegenGalleryGroups">'+galleryHtml()+'</div></section>'+
      '<section class="panel imagegen-style-library"><div class="panel-title"><div><h2>Visual Style Library</h2><p>Reusable identity, mood, and color palettes. Pick a style for this image concept, then preview and edit the prompt. Style thumbnails remain private.</p></div><span class="badge good">91B20H · NO GENERATION CHARGE</span></div>'+
      '<div class="imagegen-style-cards">'+styles.map(s=>'<div class="imagegen-style-tile"><button type="button" class="imagegen-style-card" data-imagegen-style="'+Number(s.id)+'">'+
        (s.inspiration_uuid?'<img class="imagegen-style-inspiration" src="'+imageUrl(s.inspiration_uuid)+'" alt="Private style reference" loading="lazy">':'')+
        '<span class="imagegen-style-swatches"><i style="background:'+esc(s.primary_hex)+'"></i><i style="background:'+esc(s.secondary_hex)+'"></i><i style="background:'+esc(s.accent_hex)+'"></i></span>'+
        '<strong>'+esc(s.name)+'</strong><small>'+esc(s.mood)+'</small><span>'+esc(s.guidance).slice(0,135)+'</span></button>'+
        (!s.is_builtin?'<button type="button" class="secondary imagegen-style-archive" data-imagegen-archive-style="'+Number(s.id)+'">Archive custom style</button>':'')+'</div>').join('')+'</div>'+
      '<details class="imagegen-custom-preset"><summary>Add a custom visual style</summary><form id="imagegenCustomStyle" class="form-grid">'+
      '<label class="field">Style name<input name="name" maxlength="100" required placeholder="My signature artist world"></label>'+
      '<label class="field">Mood / theme<input name="mood" maxlength="80" required placeholder="Nocturnal / dreamy"></label>'+
      '<label class="field span3">Art direction<textarea name="guidance" minlength="30" maxlength="1200" required rows="3" placeholder="Lighting, atmosphere, camera, texture, recurring motifs and visual boundaries..."></textarea></label>'+
      '<label class="field">Primary color<input type="color" name="primary_hex" value="#12243b" aria-label="Primary palette color"></label>'+
      '<label class="field">Secondary color<input type="color" name="secondary_hex" value="#657c94" aria-label="Secondary palette color"></label>'+
      '<label class="field">Accent color<input type="color" name="accent_hex" value="#83d5e3" aria-label="Accent palette color"></label>'+
      '<label class="field span3">Inspiration thumbnail (optional)<select name="inspiration_asset_id"><option value="0">No thumbnail</option>'+referenceAssets.map(a=>'<option value="'+Number(a.id)+'">'+esc(a.title||a.original_name)+'</option>').join('')+'</select></label>'+
      '<p class="hint span3">The thumbnail is for private visual review only. Selecting a style never sends thumbnail bytes to a provider, and never publishes or generates an image.</p>'+
      '<div class="actions"><button type="submit" class="secondary">Save style to library</button></div></form></details></section>'+
      '<section class="panel"><div class="panel-title"><div><h2>Visual presets</h2><p>Start from a reusable production style, then edit the resulting prompt. Presets do not trigger API charges.</p></div></div>'+
      '<div class="imagegen-presets">'+presets.map(p=>'<button type="button" class="imagegen-preset" data-imagegen-preset="'+esc(p.slug)+'"><small>'+esc(p.category)+'</small><strong>'+esc(p.name)+'</strong><span>'+esc(p.guidance).slice(0,155)+'</span><small>'+esc(p.default_size)+' · '+esc(p.default_quality)+'</small></button>').join('')+'</div>'+
      '<details class="imagegen-custom-preset"><summary>Create a custom visual preset</summary><form id="imagegenCustomPreset" class="form-grid">'+
      '<label class="field">Preset name<input name="name" maxlength="100" required placeholder="My cinematic world"></label>'+
      '<label class="field">Category<input name="category" maxlength="55" value="Custom"></label>'+
      '<label class="field span3">Visual guidance<textarea name="guidance" minlength="30" maxlength="1600" required rows="3" placeholder="Visual style, camera, lighting, palette, composition and exclusions..."></textarea></label>'+
      '<label class="field">Default size<select name="default_size"><option value="1536x1024">Landscape</option><option value="1024x1024">Square</option><option value="1024x1536">Portrait</option></select></label>'+
      '<label class="field">Default quality<select name="default_quality"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select></label>'+
      '<div class="actions"><button type="submit" class="secondary">Save custom preset</button></div></form>'+
      (presets.some(p=>!p.is_builtin)?'<div class="imagegen-custom-list"><strong>Your custom presets</strong>'+presets.filter(p=>!p.is_builtin).map(p=>'<p>'+esc(p.name)+' <button type="button" class="secondary" data-imagegen-archive-preset="'+Number(p.id)+'">Archive</button></p>').join('')+'</div>':'')+
      '</details></section>'+
      `<section class="panel"><div class="panel-title"><div><h2>OpenAI Image API</h2><p>Reuses the existing encrypted OpenAI key in Admin → AI Providers. Generation is disabled by default.</p></div><span class="badge ${providerReady&&s.enabled?'good':'warn'}">${providerReady&&s.enabled?'CONFIGURED':'CONFIGURE'}</span></div>
      <form id="imagegenSettings" class="form-grid">
      <label class="check-field span3"><input type="checkbox" name="enabled" ${s.enabled?'checked':''}> Enable paid image generation in Image Studio and administrator canvases</label>
      <label class="check-field span3"><input type="checkbox" name="canvas_listeners_enabled" ${s.canvas_listeners_enabled?'checked':''}> Allow signed-in listeners to generate private canvas images with Active Tokens</label>
      <label class="field">Image model<select name="model">${state.models.map(m=>`<option value="${esc(m)}" ${m===s.model?'selected':''}>${esc(m)}</option>`).join('')}</select></label>
      <label class="field">Default listener image limit / UTC day<input name="daily_cap" type="number" min="1" max="50" step="1" required value="${Number(s.daily_cap??5)}"></label>
      <label class="field">Default admin image limit / UTC day<input name="admin_daily_cap" type="number" min="1" max="50" step="1" value="${esc(s.admin_daily_cap??'')}" placeholder="Unlimited"></label>
      <div class="actions span3"><button type="submit" class="secondary">Save settings</button></div>
      <p class="hint span3">OpenAI API charges go to the developer organization. ${providerReady?'OpenAI key configured.':'Enable your existing OpenAI provider and its API key first.'} Leave the admin limit blank for unlimited requests. Set account overrides in Manage Users. Image Studio and canvas requests count toward the same account limit, which resets at midnight UTC.</p></form></section>
      <section class="panel"><div class="panel-title"><div><h2>Compose an image</h2><p>Write a visual concept, select a preset, and ask Stonefellow to draft or refine the prompt. Generation is a later, separately approved step.</p></div></div>
      <div class="imagegen-brief"><label class="field">Starting preset<select id="imagegenPresetSelect">${presets.map(p=>`<option value="${esc(p.slug)}">${esc(p.category+' · '+p.name)}</option>`).join('')}</select></label>
      <label class="field">Visual identity style<select id="imagegenStyleSelect"><option value="0">Use preset direction only</option>${styles.map(s=>'<option value="'+Number(s.id)+'">'+esc(s.mood+' · '+s.name)+'</option>').join('')}</select></label>
      <label class="field">Scene / concept<textarea id="imagegenConcept" rows="3" maxlength="1800" placeholder="Describe the artist, song, place, mood, framing and things to exclude..."></textarea></label>
      <div class="actions"><button class="secondary" type="button" id="imagegenApplyPreset">Apply preset locally · free</button><button class="secondary" type="button" id="imagegenAgentRefine">Ask Stonefellow Agent to refine · text API</button></div>
      <p class="hint">Agent refinement sends your entered concept, selected preset, and selected style text/colors to the configured text-model provider. Style thumbnail bytes remain private. It may incur a text-API charge; it does not generate an image, save a draft or publish anything.</p></div>
      <form id="imagegenDraftForm" class="form-grid"><label class="field span3">Editable generation prompt<textarea name="prompt" rows="6" maxlength="3000" minlength="16" required placeholder="Apply a preset or write a detailed image-generation prompt. Review every word before saving."></textarea></label>
      <label class="field">Aspect / size<select name="size"><option value="1536x1024">Landscape · 1536×1024</option><option value="1024x1024">Square · 1024×1024</option><option value="1024x1536">Portrait · 1024×1536</option></select></label>
      <label class="field">Quality<select name="quality"><option value="low">Low · draft</option><option value="medium" selected>Medium · preview</option><option value="high">High · final concept</option></select></label>
      <label class="field">Output background<select name="background"><option value="auto">Automatic</option><option value="opaque">Opaque</option><option value="transparent">Transparent · PNG/WebP output</option></select></label>
      <div class="imagegen-reference span3"><div><label class="field">Reference image (optional)<select name="reference_asset_id" id="imagegenReferenceSelect"><option value="">Generate from text only</option>${referenceAssets.map(a=>'<option value="'+Number(a.id)+'">'+esc(a.title||a.original_name)+' · '+esc(String(a.uuid).slice(0,9))+'</option>').join('')}</select></label><label class="field">Search reference library<input type="search" id="imagegenReferenceSearch" value="${esc(referenceQuery)}" placeholder="Search title or filename"></label><p class="hint">PNG, WebP or JPEG, up to 10 MB. Select an approved image or a library original. Variations make one separately approved paid Image Edits API call.</p><label class="check-field"><input type="checkbox" name="reference_consent" id="imagegenReferenceConsent"> I own or have permission to send this reference image to OpenAI for this variation.</label></div><div class="imagegen-reference-preview" id="imagegenReferencePreview"><span>No image reference selected</span></div></div>
      <div class="imagegen-reference-browser span3" id="imagegenReferenceBrowser" aria-label="Searchable reference image choices"></div>
      <div class="imagegen-mask span3"><div><label class="field">Targeted edit mask (optional)<select name="mask_asset_id" id="imagegenMaskSelect"><option value="">No mask · edit the entire source</option></select></label><p class="hint">Choose a PNG with transparent edit areas that matches the selected reference dimensions. Upload masks through Media Library first, or draw one with the private Visual Mask Editor. The source and mask remain saved as separate originals.</p><button type="button" class="secondary imagegen-mask-launch" id="imagegenDrawMask" disabled>Open Visual Mask Editor · brush / crop</button><label class="check-field"><input type="checkbox" name="mask_consent" id="imagegenMaskConsent"> I have permission to send this mask image to OpenAI for this edit.</label></div><div id="imagegenMaskPreview" class="imagegen-reference-preview"><span>No edit mask selected</span></div></div>
      <div class="actions"><button class="primary" type="submit">Save prompt draft</button></div>
      <p class="status-line span3" id="imagegenStatus" role="status"></p></form></section>
      <div class="imagegen-list-head"><div><h2>Generation history & review queue</h2><p>${state.jobs.length} recent jobs · latest first · saved originals stay immutable</p></div><label class="field imagegen-history-filter">Status<select id="imagegenStatusFilter"><option value="">All statuses</option>${['draft','approved','generating','needs_review','ready','failed'].map(x=>`<option value="${x}">${x.replaceAll('_',' ')}</option>`).join('')}</select></label></div>
      <div class="imagegen-jobs">${state.jobs.map(jobCard).join('')||'<div class="empty">No prompts yet. Start with a saved image concept.</div>'}</div>`;
    // Repainting the history and settings must never erase a partially written
    // creative brief or editable prompt.
    if(composerCache){
      if(presets.some(p=>p.slug===composerCache.preset_slug))$('#imagegenPresetSelect').value=composerCache.preset_slug;
      if(styles.some(s=>Number(s.id)===Number(composerCache.style_id)))$('#imagegenStyleSelect').value=String(composerCache.style_id);
      $('#imagegenConcept').value=composerCache.concept;
      $('#imagegenDraftForm [name="prompt"]').value=composerCache.prompt;
      $('#imagegenDraftForm [name="size"]').value=composerCache.size;
      $('#imagegenDraftForm [name="quality"]').value=composerCache.quality;
      $('#imagegenDraftForm [name="background"]').value=composerCache.background||'auto';
      $('#imagegenReferenceSelect').value=String(composerCache.reference_asset_id||'');
      $('#imagegenReferenceConsent').checked=!!composerCache.reference_consent;
      referenceQuery=String(composerCache.reference_query||referenceQuery);
    }
    function populateMasks(){
      const refId=Number($('#imagegenReferenceSelect').value||0),ref=referenceAssets.find(x=>Number(x.id)===refId),el=$('#imagegenMaskSelect');
      const prior=String(composerCache?.mask_asset_id||el.value||'');
      const masks=ref?referenceAssets.filter(a=>a.category==='image'&&a.mime_type==='image/png'&&Number(a.width)===Number(ref.width)&&Number(a.height)===Number(ref.height)&&Number(a.id)!==refId):[];
      el.innerHTML='<option value="">No mask · edit the entire source</option>'+masks.map(a=>'<option value="'+Number(a.id)+'">'+esc(a.title||a.original_name)+'</option>').join('');
      el.disabled=!ref;
      if(masks.some(a=>String(a.id)===prior))el.value=prior;
      const mask=masks.find(a=>Number(a.id)===Number(el.value));
      const holder=$('#imagegenMaskPreview');
      holder.innerHTML=mask?'<img src="'+imageUrl(mask.uuid)+'" alt="Selected transparent edit mask"><small>'+esc(mask.title||mask.original_name)+'</small>':'<span>No edit mask selected</span>';
      $('#imagegenMaskConsent').disabled=!mask;
      if(!mask)$('#imagegenMaskConsent').checked=false;
    }
    function updateReference(){
      const id=Number($('#imagegenReferenceSelect').value||0),asset=referenceAssets.find(x=>Number(x.id)===id),holder=$('#imagegenReferencePreview');
      $('#imagegenReferenceConsent').disabled=!asset;
      if(!asset){$('#imagegenReferenceConsent').checked=false;holder.innerHTML='<span>No image reference selected</span>'}
      else holder.innerHTML='<img src="../api/media.php?id='+encodeURIComponent(asset.uuid)+'" alt="Selected private reference image"><small>'+esc(asset.title||asset.original_name)+' · '+Number(asset.width||0)+'×'+Number(asset.height||0)+'</small>';
      const launch=$('#imagegenDrawMask');if(launch){launch.disabled=!asset||Number(asset.width)<1||Number(asset.height)<1;launch.title=asset?'Paint a private PNG mask using this source image.':'Select a reference image first.';}
      populateMasks();
    }
    function renderReferenceBrowser(){
      const q=referenceQuery.trim().toLowerCase(),matches=referenceAssets.filter(a=>(a.title+' '+a.original_name+' '+a.id).toLowerCase().includes(q)),holder=$('#imagegenReferenceBrowser');
      holder.innerHTML='<small>'+fmt(matches.length)+' matching references · showing first '+Math.min(18,matches.length)+'</small><div class="imagegen-reference-tiles">'+matches.slice(0,18).map(a=>'<button class="imagegen-reference-tile" type="button" data-reference-choice="'+Number(a.id)+'"><img src="'+imageUrl(a.uuid)+'" loading="lazy" alt=""><span>'+esc(a.title||a.original_name)+'</span></button>').join('')+'</div>';
      $$('[data-reference-choice]',holder).forEach(button=>button.onclick=()=>{
        $('#imagegenReferenceSelect').value=String(button.dataset.referenceChoice);
        $('#imagegenReferenceSelect').dispatchEvent(new Event('change'));
      });
    }
    updateReference();
    if(composerCache){$('#imagegenMaskConsent').checked=!!composerCache.mask_consent;if(composerCache.mask_asset_id)$('#imagegenMaskSelect').value=String(composerCache.mask_asset_id);populateMasks()}
    renderReferenceBrowser();
    const rememberComposer=()=>{composerCache={
      preset_slug:$('#imagegenPresetSelect').value,style_id:Number($('#imagegenStyleSelect').value||0),concept:$('#imagegenConcept').value,
      prompt:$('#imagegenDraftForm [name="prompt"]').value,
      size:$('#imagegenDraftForm [name="size"]').value,
      quality:$('#imagegenDraftForm [name="quality"]').value,
      background:$('#imagegenDraftForm [name="background"]').value,
      reference_asset_id:Number($('#imagegenReferenceSelect').value||0),
      reference_consent:$('#imagegenReferenceConsent').checked,
      mask_asset_id:Number($('#imagegenMaskSelect').value||0),mask_consent:$('#imagegenMaskConsent').checked,
      reference_query:referenceQuery
    }};
    rememberCurrentComposer=rememberComposer;
    for(const field of [$('#imagegenPresetSelect'),$('#imagegenStyleSelect'),$('#imagegenConcept'),$('#imagegenDraftForm [name="prompt"]'),
      $('#imagegenDraftForm [name="size"]'),$('#imagegenDraftForm [name="quality"]'),$('#imagegenDraftForm [name="background"]'),
      $('#imagegenReferenceSelect'),$('#imagegenReferenceConsent'),$('#imagegenMaskSelect'),$('#imagegenMaskConsent')]){
      field.addEventListener('input',rememberComposer);field.addEventListener('change',rememberComposer);
    }
    $('#imagegenReferenceSelect').addEventListener('change',()=>{
      // Consent to upload one image never silently transfers to another.
      $('#imagegenReferenceConsent').checked=false;
      $('#imagegenMaskConsent').checked=false;
      composerCache={...composerCache,mask_asset_id:0};
      $('#imagegenMaskSelect').value='';
      updateReference();pendingDraft=null;rememberComposer();
    });
    $('#imagegenMaskSelect').addEventListener('change',()=>{
      $('#imagegenMaskConsent').checked=false;composerCache={...composerCache,mask_asset_id:Number($('#imagegenMaskSelect').value||0)};
      populateMasks();pendingDraft=null;rememberComposer();
    });
    $('#imagegenReferenceSearch').oninput=e=>{referenceQuery=e.target.value;renderReferenceBrowser();rememberComposer()};
    $('#imagegenDrawMask').onclick=()=>{
      const refId=Number($('#imagegenReferenceSelect').value||0),reference=referenceAssets.find(x=>Number(x.id)===refId);
      if(!reference){say('Select a Media Library reference image before opening the mask editor.');return;}
      if(!window.SFImageMaskEditor?.open){say('Visual Mask Editor is unavailable. Reload Admin and try again.');return;}
      rememberComposer();
      window.SFImageMaskEditor.open({reference,getCsrf:()=>app.csrf,onSaved:async maskAsset=>{
        // The mask is uploaded as a private Media Library original; generation is never automatic.
        composerCache={...composerCache,mask_asset_id:Number(maskAsset.id),mask_consent:false};
        pendingDraft=null;
        await refresh();render();
        const selected=$('#imagegenMaskSelect');
        if(selected&&Array.from(selected.options).some(option=>Number(option.value)===Number(maskAsset.id))){
          selected.value=String(maskAsset.id);$('#imagegenMaskConsent').checked=false;populateMasks();rememberCurrentComposer?.();
          say('Private mask uploaded. Confirm separate mask permission before saving your prompt.');
        }else{say('Mask uploaded to Media Library, but it is outside the current reference list. Open Media Library to review it.');}
      }});
    };
    $('#imagegenBackMedia').onclick=()=>openView('media');
    $('#imagegenRefresh').onclick=async()=>{try{await refresh();render();say('Image history refreshed.')}catch(err){alert(err.message)}};
    $('#imagegenGalleryFilter').value=galleryFilter;
    const applyGalleryFilter=()=>{
      galleryQuery=$('#imagegenGallerySearch').value.toLowerCase().trim();galleryFilter=$('#imagegenGalleryFilter').value;
      $$('[data-imagegen-gallery-card]',canvas).forEach(card=>{
        card.hidden=!!galleryQuery&&!card.dataset.galleryText.includes(galleryQuery);
        if(galleryFilter==='variation'&&card.dataset.gallerySource!=='variation')card.hidden=true;
        if(['ready','needs_review'].includes(galleryFilter)&&card.dataset.galleryStatus!==galleryFilter)card.hidden=true;
      });
      $$('[data-imagegen-gallery-group]',canvas).forEach(group=>{group.hidden=!group.querySelector('[data-imagegen-gallery-card]:not([hidden])')});
    };
    $('#imagegenGallerySearch').oninput=applyGalleryFilter;
    $('#imagegenGalleryFilter').onchange=applyGalleryFilter;
    $$('[data-imagegen-compare]',canvas).forEach(btn=>btn.onclick=()=>compareInGallery(Number(btn.dataset.imagegenCompare)));
    applyGalleryFilter();
    $('#imagegenExportAudit').onclick=()=>{
      const columns=['job_id','created_at','started_at','status','model','size','quality','background','source_asset_id','parent_job_id','mask_asset_id','provider_request_id','input_tokens','output_tokens','invoice_usd_manual','invoice_reference','cost_reviewed_at'];
      const safe=v=>{const s=String(v??'');return '"'+(/^[\s]*[=+\-@]/.test(s)?"'":'')+s.replaceAll('"','""')+'"'};
      const rows=state.jobs.map(j=>[j.id,j.created_at,j.started_at,j.status,j.model,j.size,j.quality,j.background,j.source_asset_id,j.parent_job_id,j.mask_asset_id,j.provider_request_id,j.usage?.input_tokens,j.usage?.output_tokens,j.actual_cost_cents===null?'':usd(j.actual_cost_cents),j.invoice_reference,j.cost_verified_at]);
      const csv=[columns.map(safe).join(','),...rows.map(row=>row.map(safe).join(','))].join('\r\n');
      const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
      const link=document.createElement('a');link.href=url;link.download='stonefellow-image-generation-audit.csv';document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
    };
    $$('[data-imagegen-style]',canvas).forEach(btn=>btn.onclick=()=>{
      $('#imagegenStyleSelect').value=String(btn.dataset.imagegenStyle);
      rememberComposer();
      $('#imagegenConcept').focus();
      say('Visual style selected. Apply preset locally to preview editable guidance.');
    });
    $('#imagegenCustomStyle').onsubmit=async e=>{e.preventDefault();if(busy)return;busy=true;const f=new FormData(e.currentTarget);
      try{
        const result=await api('image-generation.php',{action:'save_style',name:f.get('name'),mood:f.get('mood'),
          guidance:f.get('guidance'),primary_hex:f.get('primary_hex'),secondary_hex:f.get('secondary_hex'),
          accent_hex:f.get('accent_hex'),inspiration_asset_id:Number(f.get('inspiration_asset_id')||0)});
        rememberComposer();composerCache.style_id=Number(result.style.id);
        await refresh();render();say('Style saved and selected. No image request made.');
      }catch(err){alert(err.message)}finally{busy=false}
    };
    $$('[data-imagegen-archive-style]',canvas).forEach(btn=>btn.onclick=async()=>{
      if(busy||!confirm('Archive this custom style? Existing prompt drafts and generated artwork will be preserved.'))return;
      busy=true;
      try{rememberComposer();const removed=Number(btn.dataset.imagegenArchiveStyle);
        await api('image-generation.php',{action:'archive_style',style_id:removed,confirmed:true});
        if(Number(composerCache.style_id)===removed)composerCache.style_id=0;
        await refresh();render();say('Custom visual style archived.');
      }catch(err){alert(err.message)}finally{busy=false}
    });
    $$('[data-imagegen-preset]',canvas).forEach(btn=>btn.onclick=()=>{
      $('#imagegenPresetSelect').value=btn.dataset.imagegenPreset;
      const p=presets.find(x=>x.slug===btn.dataset.imagegenPreset);
      if(p){$('#imagegenDraftForm [name="size"]').value=p.default_size;$('#imagegenDraftForm [name="quality"]').value=p.default_quality;}
      rememberComposer();
      $('#imagegenConcept').focus();
    });
    $('#imagegenApplyPreset').onclick=async()=>{if(busy)return;busy=true;
      const status=$('#imagegenStatus');status.textContent='Preparing local prompt…';
      try{const r=await api('image-generation.php',{action:'local_prompt',concept:$('#imagegenConcept').value,preset_slug:$('#imagegenPresetSelect').value,style_id:Number($('#imagegenStyleSelect').value||0)});
        $('#imagegenDraftForm [name="prompt"]').value=r.suggestion.prompt;
        $('#imagegenDraftForm [name="size"]').value=r.suggestion.preset.default_size;
        $('#imagegenDraftForm [name="quality"]').value=r.suggestion.preset.default_quality;
        pendingDraft=null;rememberComposer();status.textContent='Local prompt prepared. Review and edit it before saving.';
      }catch(err){status.textContent=err.message}finally{busy=false}
    };
    $('#imagegenAgentRefine').onclick=async()=>{if(busy)return;
      const concept=$('#imagegenConcept').value,preset_slug=$('#imagegenPresetSelect').value,style_id=Number($('#imagegenStyleSelect').value||0);
      if(!confirm('Send this concept, visual preset and selected style text/colors to your configured text-model provider? This may incur a separate text-API charge; no image will be generated.'))return;
      busy=true;const status=$('#imagegenStatus');status.textContent='Agent is refining the image prompt…';
      try{const r=await api('image-generation.php',{action:'compose_prompt',confirmed:true,concept,preset_slug,style_id});
        $('#imagegenDraftForm [name="prompt"]').value=r.suggestion.prompt;
        $('#imagegenDraftForm [name="size"]').value=r.suggestion.preset.default_size;
        $('#imagegenDraftForm [name="quality"]').value=r.suggestion.preset.default_quality;
        pendingDraft=null;rememberComposer();status.textContent='Agent prompt prepared (text API only). Review and edit before saving.';
      }catch(err){status.textContent=err.message}finally{busy=false}
    };
    $('#imagegenStatusFilter').onchange=e=>$$('[data-imagegen-job]',canvas).forEach(card=>{
      card.hidden=!!e.currentTarget.value&&!card.querySelector('.badge')?.textContent?.includes(e.currentTarget.value);
    });
    $('#imagegenCustomPreset').onsubmit=async e=>{e.preventDefault();if(busy)return;busy=true;const f=new FormData(e.currentTarget);
      try{await api('image-generation.php',{action:'save_preset',name:f.get('name'),category:f.get('category'),guidance:f.get('guidance'),default_size:f.get('default_size'),default_quality:f.get('default_quality')});
        await refresh();render();say('Custom visual preset saved.');}
      catch(err){alert(err.message)}finally{busy=false}
    };
    $$('[data-imagegen-archive-preset]',canvas).forEach(btn=>btn.onclick=async()=>{
      if(busy||!confirm('Archive this custom preset? Existing saved prompts and image assets will remain unchanged.'))return;
      busy=true;try{await api('image-generation.php',{action:'archive_preset',preset_id:Number(btn.dataset.imagegenArchivePreset),confirmed:true});
        await refresh();render();say('Custom preset archived.');}
      catch(err){alert(err.message)}finally{busy=false}
    });
    $('#imagegenSettings').onsubmit=async e=>{e.preventDefault();if(busy)return;busy=true;const f=new FormData(e.currentTarget),adminDailyCap=f.get('admin_daily_cap');try{
      await api('image-generation.php',{action:'save_settings',enabled:f.get('enabled')==='on',
        canvas_listeners_enabled:f.get('canvas_listeners_enabled')==='on',model:f.get('model'),
        daily_cap:Number(f.get('daily_cap')),admin_daily_cap:adminDailyCap===''?null:Number(adminDailyCap)});await refresh();render();say('Image-generation settings saved.');
    }catch(err){alert(err.message)}finally{busy=false}};
    $('#imagegenDraftForm').onsubmit=async e=>{e.preventDefault();if(busy)return;const f=new FormData(e.currentTarget),prompt=String(f.get('prompt')||''),size=String(f.get('size')),quality=String(f.get('quality'));
      const reference_asset_id=Number(f.get('reference_asset_id')||0),reference_consent=f.get('reference_consent')==='on';
      const background=String(f.get('background')||'auto'),mask_asset_id=Number(f.get('mask_asset_id')||0),mask_consent=f.get('mask_consent')==='on';
      if(reference_asset_id&&!reference_consent){$('#imagegenStatus').textContent='Confirm rights and permission to use the selected reference image before saving.';return}
      if(mask_asset_id&&!mask_consent){$('#imagegenStatus').textContent='Confirm you have permission to send the chosen transparent edit mask to OpenAI.';return}
      if(!pendingDraft||pendingDraft.prompt!==prompt||pendingDraft.size!==size||pendingDraft.quality!==quality||pendingDraft.reference_asset_id!==reference_asset_id||pendingDraft.reference_consent!==reference_consent||pendingDraft.background!==background||pendingDraft.mask_asset_id!==mask_asset_id||pendingDraft.mask_consent!==mask_consent)
        pendingDraft={request_key:idempotencyKey(),prompt,size,quality,background,reference_asset_id,reference_consent,mask_asset_id,mask_consent};
      busy=true;const status=$('#imagegenStatus');status.textContent='Saving draft. No provider request is being made…';
      try{await api('image-generation.php',{action:'save_draft',...pendingDraft});pendingDraft=null;composerCache=null;await refresh();render();say('Image prompt saved for approval.');}
      catch(err){status.textContent=err.message+' · Retry preserves the same draft request.'}finally{busy=false}
    };
    $$('[data-imagegen-approve-prompt]',canvas).forEach(btn=>btn.onclick=async()=>{
      const job=state.jobs.find(x=>Number(x.id)===Number(btn.dataset.imagegenApprovePrompt));
      if(busy||!confirm('Approve this exact prompt, model, background, quality, size'+(job?.source_asset_id?' and reference image':'')+(job?.mask_asset_id?' and targeted edit mask':'')+' for a future paid image request?'))return;
      await act('approve_prompt',Number(btn.dataset.imagegenApprovePrompt),'Prompt approved.');
    });
    $$('[data-imagegen-generate]',canvas).forEach(btn=>btn.onclick=async()=>{
      if(busy||!providerReady||!s.enabled){alert('Enable image generation and your OpenAI provider before spending API credits.');return}
      const job=state.jobs.find(x=>Number(x.id)===Number(btn.dataset.imagegenGenerate));
      const quotaNotice=d.today_remaining>0?' This uses one of your '+fmt(d.today_remaining)+' remaining daily request slots.':'';
      if(!confirm('Start ONE paid OpenAI '+(job?.source_asset_id?'image edit using the approved private reference':'image generation')+' now? Output background: '+(job?.background||'auto')+(job?.mask_asset_id?' · Targeted edit mask included':'')+'.'+quotaNotice+' Dollar cost depends on the provider; do not refresh or retry.'))return;
      await act('generate',Number(btn.dataset.imagegenGenerate),'Generation complete. Review private image before approving.');
    });
    $$('[data-imagegen-approve-asset]',canvas).forEach(btn=>btn.onclick=async()=>{
      if(busy||!confirm('You inspected this image and approve it for use in the private Media Library? This does not publish it.'))return;
      await act('approve_asset',Number(btn.dataset.imagegenApproveAsset),'Image approved. Attach it from Media Library when ready.');
    });
    $$('[data-imagegen-preview]',canvas).forEach(btn=>btn.onclick=()=>previewPlacement(Number(btn.dataset.imagegenPreview)));
    bindInstallIconActions(canvas);
    $$('[data-imagegen-vary]',canvas).forEach(btn=>btn.onclick=()=>selectVariationAsset(Number(btn.dataset.imagegenVary)));
    $$('[data-imagegen-reconcile]',canvas).forEach(btn=>btn.onclick=async()=>{
      if(busy)return;const job=state.jobs.find(j=>Number(j.id)===Number(btn.dataset.imagegenReconcile));if(!job)return;
      const amount=prompt('Enter actual USD cost for job #'+job.id+' from your OpenAI invoice (not a token estimate):',job.actual_cost_cents===null?'':(job.actual_cost_cents/100).toFixed(2));
      if(amount===null)return;
      const invoice=prompt('Invoice ID or a note identifying the verified billing record:',job.invoice_reference||'');
      if(invoice===null||!confirm('I verified the OpenAI billing record for job #'+job.id+' and want to record $'+amount+' as an administrative reconciliation. This does not make a payment or issue tokens.'))return;
      busy=true;try{await api('image-generation.php',{action:'reconcile_cost',id:job.id,amount_usd:amount,invoice_reference:invoice,confirmed:true});await refresh();render();say('Invoice reconciliation recorded.');}
      catch(err){alert(err.message)}finally{busy=false}
    });
    window.SFImageMoodBoards?.mount({canvas,styles,references:referenceAssets,api,isBusy:()=>busy,
      onUseReference:assetId=>{
        if(busy||!referenceAssets.some(a=>Number(a.id)===assetId))return false;
        $('#imagegenReferenceSelect').value=String(assetId);
        $('#imagegenReferenceSelect').dispatchEvent(new Event('change'));
        $('#imagegenDraftForm').scrollIntoView({behavior:'smooth',block:'start'});
        say('Mood-board reference selected. Review separate image-use permission before saving.');
        return true;
      }});
  }
  async function act(action,id,success){
    busy=true;const button=$(`[data-imagegen-${action==='approve_prompt'?'approve-prompt':action==='approve_asset'?'approve-asset':'generate'}="${id}"]`,canvas);
    if(button){button.disabled=true;button.textContent=action==='generate'?'Generating… this can take two minutes':'Saving approval…'}
    try{await api('image-generation.php',{action,id,confirmed:true});await refresh();render();say(success)}
    catch(err){alert(err.message+' This request was not automatically retried.');try{await refresh();render()}catch{if(button)button.disabled=false}}
    finally{busy=false}
  }
  async function open(){
    canvas.innerHTML=head('IMAGE GENERATION','Loading','Checking the saved OpenAI settings and private image queue…');
    try{
      await refresh();
      render();
      if(incomingBrief){
        const b=incomingBrief;incomingBrief=null;
        if(state.presets.some(p=>p.slug===b.preset_slug))$('#imagegenPresetSelect').value=b.preset_slug;
        $('#imagegenConcept').value=String(b.concept||'').slice(0,1800);
        const selected=state.presets.find(p=>p.slug===$('#imagegenPresetSelect').value);
        if(selected){
          $('#imagegenDraftForm [name="size"]').value=selected.default_size;
          $('#imagegenDraftForm [name="quality"]').value=selected.default_quality;
        }
        composerCache={preset_slug:$('#imagegenPresetSelect').value,concept:$('#imagegenConcept').value,
          prompt:'',size:$('#imagegenDraftForm [name="size"]').value,
          quality:$('#imagegenDraftForm [name="quality"]').value,
          reference_asset_id:Number(b.reference_asset_id||0),reference_consent:false};
        if(b.reference_asset_id&&referenceAssets.some(x=>Number(x.id)===Number(b.reference_asset_id))){
          $('#imagegenReferenceSelect').value=String(b.reference_asset_id);
          $('#imagegenReferenceSelect').dispatchEvent(new Event('change'));
        }
        $('#imagegenConcept').focus();
        const referenceAvailable=b.reference_asset_id&&referenceAssets.some(x=>Number(x.id)===Number(b.reference_asset_id));
        $('#imagegenStatus').textContent=b.reference_asset_id?(referenceAvailable?'Media Library reference received. Review the creative brief and confirm image-use permission before saving.':'This reference is not eligible or was removed. Select an approved image in the reference picker before saving.'):'Admin Agent handoff received. Apply a preset or refine it, then review the prompt before saving.';
      }
    }catch(err){canvas.innerHTML=head('IMAGE GENERATION','Unavailable',err.message)}
  }
  function openWithDraft(draft){
    if(!draft||typeof draft.concept!=='string')return;
    incomingBrief={concept:draft.concept,preset_slug:String(draft.preset_slug||'album_cover'),reference_asset_id:Number(draft.reference_asset_id||0)};
    composerCache=null;pendingDraft=null;
    openView('image-generation');
  }
  async function previewByJobId(id){
    try{
      await refresh();
      let job=state.jobs.find(j=>Number(j.id)===Number(id));
      if(!job){const result=await api('image-generation.php?job_id='+encodeURIComponent(id));job=result.job;if(job)state.jobs.push(job)}
      if(!job||job.status!=='ready'||!job.asset_uuid){alert('Approve this generated image in AI Image Studio before previewing placements.');return}
      await previewPlacement(Number(id));
    }catch(e){alert(e.message||'Could not load the approved generated image.')}
  }
  async function dashboard(){
    const host=document.getElementById('sfImageStudioDashboard');if(!host)return;
    try{
      const r=await api('image-generation.php'),d=r.dashboard||{},c=d.counts||{};
      if(!host.isConnected)return;
      host.innerHTML='<div class="panel-title"><div><h2>AI Image Studio</h2><p>Generated artwork and visual concept approvals</p></div><button type="button" class="secondary" id="imagegenDashboardOpen">Open Image Studio</button></div>'+
        '<div class="imagegen-home-stats"><span><strong>'+fmt(c.needs_review)+'</strong> images to review</span><span><strong>'+fmt(c.approved)+'</strong> prompts approved</span><span><strong>'+fmt(c.ready)+'</strong> images ready</span><span><strong>'+fmt(d.today_attempts)+' / '+dailyCapLabel(d.daily_cap)+'</strong> your paid requests today (UTC)</span></div>';
      host.querySelector('#imagegenDashboardOpen').onclick=()=>openView('image-generation');
    }catch(_){if(host.isConnected)host.innerHTML='<div class="panel-title"><h2>AI Image Studio</h2><button class="secondary" type="button" id="imagegenDashboardOpen">Open Image Studio</button></div><p class="hint">Image summary is temporarily unavailable.</p>';host.querySelector('#imagegenDashboardOpen').onclick=()=>openView('image-generation')}
  }
  window.SFImageGenerationAdmin={open,openWithDraft,previewByJobId,dashboard};
})();
