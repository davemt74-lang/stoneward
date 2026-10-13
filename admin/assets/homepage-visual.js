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
    if(key==='hero')return {primary_label:v('primary_label'),primary_view:v('primary_view'),secondary_label:v('secondary_label'),secondary_view:v('secondary_view'),sticky_chat_enabled:!!field(f,'sticky_chat_enabled')?.checked};
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
    const chat=$('#homepageWebChatToggle',sidebar),chatInput=$('[name="sticky_chat_enabled"]',forms().find(x=>x.dataset.homeSection==='hero'));
    if(chat&&chatInput){chat.textContent='Sticky Agent chat: '+(chatInput.checked?'On':'Off');chat.setAttribute('aria-pressed',String(chatInput.checked));}
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


  let previewTimer=null,previewRevision=0,previewBusy=false;
  const money=cents=>'$'+(Math.max(0,Number(cents)||0)/100).toFixed(2);
  function publicMarkup(sections){
    const render=window.SFArtistHomeRenderer?.renderSection;
    if(typeof render!=='function')throw new Error('Public homepage renderer is unavailable.');
    const helpers={
      esc,
      time(value){const n=Math.max(0,Math.floor(Number(value)||0));return Math.floor(n/60)+':'+String(n%60).padStart(2,'0');},
      homepageMoney:money,
      newsletterSignupHtml:()=>'<div class="newsletter-form"><input disabled type="email" placeholder="Email address"><button disabled type="button">Sign up</button></div>',
      homeTrackCard:item=>'<div class="home-track-card">'+esc(item?.title||'Track')+'</div>'
    };
    return (sections||[]).map(section=>render(section,helpers)
      .replace('<section ','<section data-home-key="'+esc(section.section_key)+'" ')).join('');
  }
  function bindCanonicalFrame(frame){
    const doc=frame.contentDocument;
    if(!doc||!doc.body)return;
    frame.style.height=Math.max(doc.documentElement.scrollHeight,doc.body.scrollHeight,540)+'px';
    doc.addEventListener('click',event=>{
      if(event.target.closest('[contenteditable]'))return;
      event.preventDefault();
      const section=event.target.closest('[data-home-key]');
      if(section)selected(section.dataset.homeKey);
    },true);
    $$('[data-home-key]',doc).forEach(el=>{
      const key=el.dataset.homeKey,f=forms().find(x=>x.dataset.homeSection===key);
      if(!f)return;
      el.tabIndex=0;el.draggable=true;
      el.ondragstart=e=>{e.dataTransfer.setData('text/plain',key);e.dataTransfer.effectAllowed='move';};
      el.ondragover=e=>{e.preventDefault();e.dataTransfer.dropEffect='move';};
      el.ondrop=e=>{e.preventDefault();moveCanvasSection(e.dataTransfer.getData('text/plain'),key);};
      el.onkeydown=e=>{if(e.altKey&&(e.key==='ArrowUp'||e.key==='ArrowDown')){
        e.preventDefault();
        const enabled=forms().filter(x=>x.elements.enabled?.checked&&x.dataset.homeSection!=='hero');
        const i=enabled.indexOf(f),dest=enabled[i+(e.key==='ArrowUp'?-1:1)];
        if(dest)moveCanvasSection(key,dest.dataset.homeSection);
      }};
      editable(el.querySelector(key==='hero'?'h1':'h2'),field(f,'heading'));
      editable(el.querySelector('.artist-home-copy'),field(f,'body_text'));
      if(key==='custom_blocks'){
        const enabled=$$('#homepageBlockList > [data-home-block-id]',sidebar).filter(x=>x.querySelector('[data-block-field="enabled"]')?.checked);
        $$('.artist-home-custom-card',el).forEach((card,i)=>{
          const block=enabled[i];if(!block)return;
          editable(card.querySelector('h3'),block.querySelector('[data-block-field="heading"]'));
          editable(card.querySelector('.artist-home-custom-copy > p:not(.artist-home-meta)'),block.querySelector('[data-block-field="body_text"]'));
        });
      }
      const button=doc.createElement('button');
      button.type='button';button.className='sf-visual-section-select';
      button.textContent='Edit section · drag to move';
      button.onclick=()=>selected(key);el.prepend(button);
    });
  }
  async function renderCanonical(){
    if(!root||previewBusy)return;
    previewBusy=true;
    const token=previewRevision,holder=$('#homepageBuilderPreview',root);
    try{
      const result=await ctx.api('homepage.php',{action:'preview_design_draft',sections:collect()});
      if(!root||token!==previewRevision)return;
      const html=publicMarkup(result.homepage?.sections||[]);
      const stickyChat=result.homepage?.chat_bar_enabled!==false;
      const frame=document.createElement('iframe');
      frame.title='Actual homepage design — private draft preview';
      frame.className='homepage-visual-public-frame';
      frame.setAttribute('sandbox','allow-same-origin');
      frame.referrerPolicy='same-origin';
      const styles='body{margin:0}.artist-homepage{max-width:none;width:100%}'+
        '.sf-visual-section-select{display:block;position:relative;z-index:20;margin:8px 12px;padding:5px 10px;cursor:pointer;background:#201b16;color:#f8e6d5;border:1px solid #bd956c;border-radius:3px;font-size:12px}'+
        '[contenteditable]:hover{outline:1px dashed #c68f5b;outline-offset:3px}[contenteditable]:focus{outline:2px solid #e8b079;outline-offset:3px}'+
        '[data-home-key]{position:relative;min-height:50px}'+
        '.sf-visual-chat-footer{position:sticky;bottom:0;z-index:60;padding:12px 18px;background:linear-gradient(180deg,transparent,#090807 32%)}'+
        '.sf-visual-chatbar{display:grid;grid-template-columns:35px 1fr 35px 35px;align-items:center;gap:6px;max-width:760px;margin:auto;border:1px solid #574637;border-radius:24px;background:#161310;color:#e6ddd4;padding:12px 14px;font:14px sans-serif}'+
        '.sf-visual-chatbar span:not(:nth-child(2)){text-align:center;font-size:20px}.sf-visual-chatbar span:nth-child(2){color:#b1a99d}';
      const chat=stickyChat?'<footer class="sf-visual-chat-footer"><div class="sf-visual-chatbar"><span>+</span><span>Ask Stonefellow anything…</span><span>◉</span><span>↑</span></div></footer>':'';
      frame.srcdoc='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
        '<link rel="stylesheet" href="../assets/css/site.css?v=1.3.52"><style>'+styles+'</style></head>'+
        '<body class="artist-home-active"><article class="artist-homepage">'+html+'</article>'+chat+'</body></html>';
      frame.addEventListener('load',()=>bindCanonicalFrame(frame),{once:true});
      holder.replaceChildren(frame);
    }catch(e){if(holder&&!holder.querySelector('iframe'))holder.textContent=e.message||'Draft preview unavailable';}
    finally{previewBusy=false;if(root&&token!==previewRevision)requestCanonical();}
  }
  function requestCanonical(){
    if(!root)return;
    previewRevision++;clearTimeout(previewTimer);
    previewTimer=setTimeout(()=>{if(previewBusy){requestCanonical();return;}void renderCanonical();},220);
  }
  function decorate(){ /* canonical renderer owns canvas; no duplicate mock renderer */ }
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
    requestCanonical();
    sidebar.addEventListener('input',markDirty);
    sidebar.addEventListener('change',markDirty);
    announce('Private draft. Edit text directly on canvas; save and publish are separate.');
  }
  function close(){observer?.disconnect();observer=null;clearTimeout(previewTimer);previewRevision++;root=sidebar=null;dirty=false;}
  window.SFHomepageVisual={open,close,saveDraft,active:()=>!!root,refresh:requestCanonical,isDirty:()=>dirty};
})();
