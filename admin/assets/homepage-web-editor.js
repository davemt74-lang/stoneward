(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  const shell=document.querySelector('.admin-app'),sidebar=document.getElementById('sidebar');
  let stage=null,tools=null,source=null,dirty=false,opening=false,saving=false,returnFocus=null;
  const $=(selector,root=document)=>root.querySelector(selector);
  function status(message){
    const el=$('#homepageWebStatus',stage);if(el)el.textContent=message;
  }
  function forms(){return Array.from(tools?.querySelectorAll('[data-home-section]')||[])}
  function selectSection(key){
    const form=forms().find(el=>el.dataset.homeSection===key);
    if(!form)return;
    forms().forEach(f=>f.classList.toggle('homepage-web-selected',f===form));
    const details=$('details',form);if(details)details.open=true;
    form.scrollIntoView({block:'nearest',behavior:'smooth'});
    tools?.querySelectorAll('[data-web-section]').forEach(button=>
      button.setAttribute('aria-current',String(button.dataset.webSection===key)));
    if(window.matchMedia('(max-width: 800px)').matches)shell.classList.add('homepage-editor-tools-open');
  }
  function renderSectionNav(){
    const host=$('#homepageWebSectionNav',tools);if(!host)return;
    host.innerHTML=forms().map(f=>{
      const id=f.dataset.homeSection;
      const title=$('.homepage-section-key',f)?.textContent||id;
      const enabled=!!f.elements.enabled?.checked;
      return '<button type="button" class="homepage-web-section-tab" data-web-section="'+ctx.esc(id)+'">'+
        '<span>'+ctx.esc(title)+'</span><small>'+(enabled?'Visible':'Hidden')+'</small></button>';
    }).join('');
    host.querySelectorAll('[data-web-section]').forEach(button=>
      button.onclick=()=>selectSection(button.dataset.webSection));
  }
  async function saveAll(){
    if(saving)return false;
    saving=true;
    const button=$('#homepageWebSave',stage);
    if(button)button.disabled=true;
    status('Saving homepage sections…');
    try{
      const result=await window.SFArtistHomeAdmin?.saveAll?.(tools);
      if(!result)throw new Error('Some sections could not be saved; check the highlighted editor fields.');
      dirty=false;
      status('All homepage sections saved. Published site updates when its homepage plugin is enabled.');
      renderSectionNav();
      return true;
    }catch(e){status(e.message||'Save failed. Unsaved edits remain.');return false;}
    finally{saving=false;if(button)button.disabled=false;}
  }
  function exit(force=false){
    if(!stage)return true;
    if(saving)return false;
    if(!force&&(dirty||window.SFHomepageVisual?.isDirty?.())&&!confirm('Discard unsaved homepage changes and exit the designer?'))return false;
    window.removeEventListener('keydown',onKeyDown,true);window.SFHomepageVisual?.close?.();
    window.SFHomepageBuilder?.relocate?.(null);
    stage.remove();tools.remove();stage=tools=source=null;
    shell.classList.remove('homepage-web-design-mode','homepage-editor-tools-open');
    dirty=false;
    if(returnFocus?.isConnected)returnFocus.focus({preventScroll:true});
    returnFocus=null;
    // Reload Settings from the persisted source of truth, without replacing
    // unrelated Admin tabs or mutating database configuration.
    const destination=document.getElementById('artistHomepagePanel');
    if(destination?.isConnected)void window.SFArtistHomeAdmin?.renderInto?.(destination);
    return true;
  }
  async function switchToSettings(tab){
    if(!exit())return;
    try{await window.SFSettingsTabs.open(tab);}catch(e){ctx.say(e.message||'Settings tab unavailable');}
  }
  function onKeyDown(event){
    if(!stage)return;
    if(event.key==='Escape'){
      event.preventDefault();
      if(window.matchMedia('(max-width: 800px)').matches&&shell.classList.contains('homepage-editor-tools-open')){
        shell.classList.remove('homepage-editor-tools-open');return;
      }
      exit();
    }
    if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='s'){
      event.preventDefault();void (window.SFHomepageVisual?.saveDraft?.()||saveAll());
    }
  }
  async function open(){
    if(stage||opening)return;
    opening=true;returnFocus=document.activeElement;
    try{
      if(!window.SFArtistHomeAdmin?.renderInto||!window.SFHomepageBuilder?.mount)
        throw new Error('Homepage web editor scripts have not loaded.');
      tools=document.createElement('div');tools.id='homepageWebSidebar';
      tools.className='homepage-web-sidebar';
      tools.innerHTML='<header class="homepage-web-sidebar-head">'+
        '<small>STONEFELLOW · DESIGN</small><h2>Homepage Builder</h2>'+
        '<p>One artist · one published homepage</p></header>'+
        '<div class="homepage-web-sidebar-tools">'+
        '<button type="button" class="primary" id="homepageWebAddBlock">+ Add Block</button>'+
        '<button type="button" class="secondary" id="homepageWebHero">Hero image</button>'+
        '<button type="button" class="secondary" id="homepageWebStory">Artist story</button>'+
        '<button type="button" class="secondary" id="homepageWebTheme">Theme</button></div>'+
        '<h3 class="homepage-web-sidebar-subhead">Page sections</h3>'+
        '<nav id="homepageWebSectionNav" class="homepage-web-section-nav" aria-label="Homepage sections"></nav>'+
        '<div id="homepageWebSectionForms" class="homepage-web-section-forms" aria-label="Selected section editor"></div>'+
        '<footer class="homepage-web-sidebar-foot"><button type="button" class="secondary" id="homepageWebExitSide">← Back to Admin</button></footer>';
      stage=document.createElement('div');
      stage.id='homepageWebStage';stage.className='homepage-web-stage';
      stage.innerHTML='<header class="homepage-web-toolbar">'+
        '<div class="homepage-web-toolbar-title"><small>WEBSITE DESIGNER</small><strong>Stonefellow / Homepage</strong></div>'+
        '<div class="homepage-web-devices" aria-label="Preview device width" role="group">'+
        '<button type="button" data-device="desktop" aria-pressed="true">Desktop</button>'+
        '<button type="button" data-device="tablet" aria-pressed="false">Tablet</button>'+
        '<button type="button" data-device="phone" aria-pressed="false">Mobile</button></div>'+
        '<div class="homepage-web-toolbar-actions"><button type="button" class="secondary" id="homepageWebTools">Sections</button>'+
        '<a href="../?view=home" target="_blank" rel="noopener" class="secondary">Public preview ↗</a>'+
        '<button type="button" class="primary" id="homepageWebSave">Save all</button>'+
        '<button type="button" class="secondary" id="homepageWebExit">Exit</button></div></header>'+
        '<div class="homepage-web-content"><div id="homepageWebCanvas" class="homepage-web-canvas" data-device="desktop">'+
        '<div class="homepage-web-canvas-loading" role="status">Loading homepage sections and artwork…</div></div></div>'+
        '<footer class="homepage-web-statusbar"><span id="homepageWebStatus" role="status" aria-live="polite">Preview is a local draft. Nothing publishes until saved.</span><small>Ctrl/⌘ + S to save</small></footer>'+
        '<div id="homepageWebSource" class="homepage-web-source" hidden></div>';
      sidebar.append(tools);shell.append(stage);
      shell.classList.add('homepage-web-design-mode');
      source=$('#homepageWebSource',stage);
      // Use the exact same section renderer, forms and API contracts as
      // Settings. The builder relocates the live forms into the tool sidebar.
      await window.SFArtistHomeAdmin.renderInto(source);
      const formsEl=$('.homepage-section-list',source);
      const preview=$('.homepage-builder-visual',source);
      if(!formsEl||!preview)throw new Error('Homepage editor did not provide its section forms and preview.');
      $('#homepageWebSectionForms',tools).append(formsEl);
      $('#homepageWebCanvas',stage).replaceChildren(preview);
      window.SFHomepageBuilder.relocate({formsRoot:tools,previewRoot:stage});
      renderSectionNav();
      window.SFHomepageVisual?.open?.(window.SFArtistHomeAdmin.getData());
      selectSection('hero');
      formsEl.addEventListener('input',()=>{dirty=true;status('Unsaved changes.');});
      formsEl.addEventListener('change',()=>{dirty=true;renderSectionNav();status('Unsaved changes.');});
      formsEl.addEventListener('click',event=>{
        if(event.target.closest('#homepageBlockAdd,[data-block-remove],[data-block-move]')){
          dirty=true;status('Unsaved block changes.');
        }
      });
      $('#homepageWebSave',stage).onclick=()=>void (window.SFHomepageVisual?.saveDraft?.()||saveAll());
      $('#homepageWebExit',stage).onclick=()=>exit();
      $('#homepageWebExitSide',tools).onclick=()=>exit();
      $('#homepageWebTools',stage).onclick=()=>shell.classList.toggle('homepage-editor-tools-open');
      $('#homepageWebAddBlock',tools).onclick=()=>{
        selectSection('custom_blocks');
        const add=$('#homepageBlockAdd',tools);
        if(add){add.click();dirty=true;status('New block added to draft.');}
      };
      $('#homepageWebHero',tools).onclick=()=>void switchToSettings('media');
      $('#homepageWebStory',tools).onclick=()=>void switchToSettings('stories');
      $('#homepageWebTheme',tools).onclick=()=>void switchToSettings('theme');
      stage.querySelectorAll('[data-device]').forEach(button=>button.onclick=()=>{
        $('#homepageWebCanvas',stage).dataset.device=button.dataset.device;
        stage.querySelectorAll('[data-device]').forEach(el=>
          el.setAttribute('aria-pressed',String(el===button)));
      });
      window.addEventListener('keydown',onKeyDown,true);
      $('#homepageWebSave',stage).focus({preventScroll:true});
    }catch(e){
      window.SFHomepageBuilder?.relocate?.(null);
      if(stage){stage.remove();stage=null;}
      if(tools){tools.remove();tools=null;}
      source=null;
      shell.classList.remove('homepage-web-design-mode','homepage-editor-tools-open');
      throw e;
    }finally{opening=false;}
  }
  window.SFHomepageWebEditor={open,exit,isOpen:()=>!!stage};
})();