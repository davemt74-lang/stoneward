(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  let root=null,sidebar=null,draft=null,versions=[],saving=false,dirty=false,observer=null;
  const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const esc=v=>ctx.esc(String(v??''));
  const forms=()=>$$('[data-home-section]',sidebar||document.createElement('div'));
  const field=(form,name)=>form?.elements?.namedItem(name);
  function collect(){
    return forms().map(f=>({
      section_key:f.dataset.homeSection,enabled:!!field(f,'enabled')?.checked,
      position:Number(field(f,'position')?.value||0),
      eyebrow:field(f,'eyebrow')?.value||'',heading:field(f,'heading')?.value||'',
      body_text:field(f,'body_text')?.value||'',
      config:f.dataset.homeSection==='custom_blocks'?window.SFHomepageBuilder.getConfig(f):extractConfig(f)
    }));
  }
  function extractConfig(f){
    const key=f.dataset.homeSection,v=n=>field(f,n)?.value||'';
    if(key==='hero')return {primary_label:v('primary_label'),primary_view:v('primary_view'),secondary_label:v('secondary_label'),secondary_view:v('secondary_view')};
    if(key==='featured_release')return {release_id:v('release_id')};
    if(key==='featured_track')return {track_id:v('track_id')};
    if(key==='latest_releases'||key==='personalized')return {limit:Number(v('limit'))};
    if(key==='featured_video')return {video_slug:v('video_slug')};
    if(key==='upcoming_show')return {show_id:v('show_id')};
    if(key==='merch')return {product_id:v('product_id')};
    if(key==='artist_story')return {button_label:v('button_label'),button_view:v('button_view')};
    if(key==='newsletter')return {compact:!!field(f,'compact')?.checked};
    return {};
  }
  function announce(s){const el=$('#homepageWebStatus',root);if(el)el.textContent=s;}
  function versionList(){
    const sel=$('#homepageVisualVersions',root);if(!sel)return;
    sel.innerHTML='<option value="">Version history…</option>'+versions.map(v=>'<option value="'+Number(v.id)+'">#'+Number(v.id)+' · '+esc(v.created_at)+'</option>').join('');
  }
  function markDirty(){dirty=true;announce('Unsaved draft edits. Save draft before publishing.');}
  function hydrate(sections){
    const list=$('.homepage-section-list',sidebar);
    if(!list)return;
    const ids=sections.map(s=>s.section_key);
    sections.forEach(s=>{
      const f=forms().find(x=>x.dataset.homeSection===s.section_key);
      if(!f)return;
      for(const n of ['enabled','position','eyebrow','heading','body_text']){
        const input=field(f,n);if(!input)continue;
        if(input.type==='checkbox')input.checked=!!s[n];else input.value=s[n]??'';
      }
      const c=s.config||{};
      for(const [n,value] of Object.entries(c)){
        const input=field(f,n);
        if(input){if(input.type==='checkbox')input.checked=!!value;else input.value=value;}
      }
    });
    ids.forEach(key=>{const f=forms().find(x=>x.dataset.homeSection===key);if(f)list.append(f);});
    // The existing builder already owns the block list: rebuild it only when
    // draft differs, using the same safe editor field controls.
    const blocks=sections.find(s=>s.section_key==='custom_blocks')?.config?.blocks||[];
    const listBlocks=$('#homepageBlockList',sidebar);
    if(listBlocks){
      // Existing builder exposes block markup through mounted controls;
      // draw from saved draft by its public-safe editor factory.
      const html=window.SFHomepageBuilder?.blockHtml;
      if(typeof html==='function'){
        listBlocks.innerHTML=blocks.map(html).join('');
        window.SFHomepageBuilder.reconnect?.();
      }
    }
    window.SFHomepageBuilder?.preview?.();
  }
  async function saveDraft(){
    if(saving)return false;
    saving=true;
    try{
      const result=await ctx.api('homepage.php',{action:'save_design_draft',
        sections:collect(),expected_revision:draft.revision,base_hash:draft.base_hash});
      draft=result.draft;dirty=false;window.SFHomepageWebEditor?.markClean?.();announce('Draft saved privately · revision '+draft.revision+'. Public homepage is unchanged.');
      return true;
    }catch(e){announce(e.message||'Draft save failed');return false;}
    finally{saving=false;}
  }
  async function publish(){
    if(dirty){announce('Save the draft before publishing.');return;}
    if(!draft?.revision){announce('Save a draft before publishing.');return;}
    if(draft.base_hash!==draft.published_hash){announce('Live homepage changed. Reopen the designer and review conflicts.');return;}
    if(!confirm('Publish this entire homepage design to the live site?'))return;
    try{
      const result=await ctx.api('homepage.php',{action:'publish_design_draft',
        revision:draft.revision,published_hash:draft.published_hash,confirmed:true});
      draft=result.draft;versions=result.versions||[];versionList();
      announce('Homepage published atomically as version #'+result.version+'.');
    }catch(e){announce(e.message||'Publish failed; public homepage is unchanged.');}
  }
  async function reloadLive(){
    if(!confirm('Discard this homepage draft and load the currently published homepage?'))return;
    try{
      const result=await ctx.api('homepage.php',{action:'reset_design_draft',confirmed:true,
        expected_revision:draft.revision,base_hash:draft.base_hash});
      draft=result.draft;dirty=false;window.SFHomepageWebEditor?.markClean?.();
      hydrate(draft.sections);announce('Draft replaced with current published homepage. Nothing was published.');
    }catch(e){announce(e.message||'Could not reset draft.');}
  }
  async function restore(){
    const v=Number($('#homepageVisualVersions',root)?.value||0);if(!v)return;
    if(dirty&&!confirm('Discard unsaved edits and stage the selected older version?'))return;
    try{
      const result=await ctx.api('homepage.php',{action:'restore_design_version',version:v,
        expected_revision:draft.revision,base_hash:draft.base_hash});
      draft=result.draft;dirty=false;window.SFHomepageWebEditor?.markClean?.();hydrate(draft.sections);
      announce('Version #'+v+' restored to your private draft. Review, then publish to go live.');
    }catch(e){announce(e.message||'Version restore failed.');}
  }
  function moveCanvasSection(fromKey,toKey){
    if(!fromKey||!toKey||fromKey===toKey)return;
    const list=$('.homepage-section-list',sidebar);
    const from=forms().find(f=>f.dataset.homeSection===fromKey),to=forms().find(f=>f.dataset.homeSection===toKey);
    if(!list||!from||!to)return;
    const before=Array.from(list.children).indexOf(from)<Array.from(list.children).indexOf(to);
    list.insertBefore(from,before?to.nextSibling:to);
    Array.from(list.children).forEach((f,n)=>{if(f.elements.position)f.elements.position.value=(n+1)*10;});
    list.dispatchEvent(new Event('change',{bubbles:true}));
    window.SFHomepageBuilder?.preview?.();markDirty();
  }
  function selected(key){
    const f=forms().find(x=>x.dataset.homeSection===key);if(!f)return;
    sidebar.querySelector('[data-web-section="'+key+'"]')?.click();
  }
  function editable(element,input){
    if(!input||element.dataset.visualBound)return;
    element.dataset.visualBound='1';element.contentEditable='plaintext-only';
    element.setAttribute('role','textbox');element.setAttribute('aria-label','Edit homepage '+input.name);
    element.setAttribute('spellcheck','true');
    element.addEventListener('focus',()=>{element.dataset.editing='1';});
    element.addEventListener('blur',()=>{
      delete element.dataset.editing;
      const text=element.innerText.trim().slice(0,Number(input.maxLength)||6000);
      if(input.value!==text){input.value=text;input.dispatchEvent(new Event('input',{bubbles:true}));markDirty();}
    });
    element.addEventListener('keydown',e=>{
      if(e.key==='Escape'){e.preventDefault();element.textContent=input.value;element.blur();}
      if(e.key==='Enter'&&input.tagName!=='TEXTAREA'){e.preventDefault();element.blur();}
    });
  }
  function decorate(){
    const canvas=$('#homepageBuilderPreview',root);if(!canvas)return;
    const visualSections=$$('.homepage-preview-row,.homepage-preview-block-group',canvas);
    const others=forms().filter(f=>f.dataset.homeSection!=='hero'&&f.elements.enabled?.checked)
      .sort((a,b)=>Number(a.elements.position.value)-Number(b.elements.position.value));
    const hero=forms().find(f=>f.dataset.homeSection==='hero');
    const heroHeading=$('.homepage-preview-hero-copy h3',canvas),heroBody=$('.homepage-preview-hero-copy p',canvas);
    if(hero){editable(heroHeading,field(hero,'heading'));editable(heroBody,field(hero,'body_text'));}
    visualSections.forEach((el,i)=>{
      const f=others[i];if(!f)return;
      el.dataset.visualSection=f.dataset.homeSection;
      el.tabIndex=0;
      el.draggable=true;
      el.ondragstart=e=>{e.dataTransfer.setData('text/plain',f.dataset.homeSection);e.dataTransfer.effectAllowed='move';};
      el.ondragover=e=>{e.preventDefault();e.dataTransfer.dropEffect='move';};
      el.ondrop=e=>{e.preventDefault();moveCanvasSection(e.dataTransfer.getData('text/plain'),f.dataset.homeSection);};
      el.onkeydown=e=>{if(e.altKey&&(e.key==='ArrowUp'||e.key==='ArrowDown')){
        e.preventDefault();const enabled=forms().filter(x=>x.elements.enabled?.checked&&x.dataset.homeSection!=='hero');
        const i=enabled.indexOf(f),dest=enabled[i+(e.key==='ArrowUp'?-1:1)];if(dest)moveCanvasSection(f.dataset.homeSection,dest.dataset.homeSection);
      }};
      if(!el.querySelector('.homepage-visual-select')){
        const b=document.createElement('button');b.type='button';b.className='homepage-visual-select';
        b.textContent='Edit '+f.dataset.homeSection.replaceAll('_',' ');
        b.onclick=()=>selected(f.dataset.homeSection);
        el.prepend(b);
      }
      const h=el.querySelector(':scope > strong');
      editable(h,field(f,'heading'));
    });
    const blockForms=$$('#homepageBlockList > [data-home-block-id]',sidebar);
    $$('.homepage-preview-tile',canvas).forEach((el,i)=>{
      const enabled=blockForms.filter(f=>f.querySelector('[data-block-field="enabled"]')?.checked);
      const f=enabled[i];if(!f)return;
      const h=$('strong',el),body=$('p',el);
      editable(h,f.querySelector('[data-block-field="heading"]'));
      editable(body,f.querySelector('[data-block-field="body_text"]'));
      el.onclick=e=>{if(e.target.closest('[contenteditable]'))return;selected('custom_blocks');f.scrollIntoView({block:'nearest'});};
    });
  }
  function open(data){
    root=$('#homepageWebStage');sidebar=$('#homepageWebSidebar');
    if(!root||!sidebar)return;
    draft=data.draft;versions=data.versions||[];
    // Rehydrate the draft after the existing homepage forms have mounted.
    hydrate(draft.sections||data.sections||[]);
    const actions=$('.homepage-web-toolbar-actions',root);
    const original=$('#homepageWebSave',root);
    if(original){original.textContent='Save draft';original.id='homepageVisualSave';original.onclick=()=>void saveDraft();}
    actions.insertAdjacentHTML('beforeend','<button type="button" class="primary" id="homepageVisualPublish">Publish live</button><select id="homepageVisualVersions" aria-label="Homepage versions"></select><button type="button" class="secondary" id="homepageVisualRestore">Restore to draft</button><button type="button" class="secondary" id="homepageVisualReload">Reload live</button>');
    $('#homepageVisualPublish',root).onclick=()=>void publish();
    $('#homepageVisualRestore',root).onclick=()=>void restore();
    $('#homepageVisualReload',root).onclick=()=>void reloadLive();versionList();
    const preview=$('#homepageBuilderPreview',root);
    observer=new MutationObserver(()=>{if(observer){observer.disconnect();decorate();observer.observe(preview,{childList:true});}});
    observer.observe(preview,{childList:true});
    decorate();
    sidebar.addEventListener('input',markDirty);
    sidebar.addEventListener('change',markDirty);
    announce('Private draft. Edit text directly on canvas; save and publish are separate.');
  }
  function close(){observer?.disconnect();observer=null;root=sidebar=null;dirty=false;}
  window.SFHomepageVisual={open,close,saveDraft,isDirty:()=>dirty};
})();
