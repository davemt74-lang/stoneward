(() => {
  'use strict';
  // Keep Settings as a single route. Panels stay mounted when tabs switch so
  // unfinished forms are not silently erased. Heavy editors load on demand.
  const sections=[
    ['site','Public Site'],
    ['theme','Visual Theme'],
    ['homepage','Artist Homepage'],
    ['stories','Stories'],
    ['media','Site & Artist Media'],
    ['folders','Folder Access'],
    ['uploads','Upload Limits'],
    ['password','Password'],
    ['database','Database Upgrade'],
    ['session','Admin Session']
  ];
  let selected='site',currentActivator=null,mountedRoot=null;

  async function mount(root){
    if(!root)return;
    mountedRoot=root;
    const panels=Array.from(root.children).filter(el=>el.matches('section.panel'));
    if(panels.length!==sections.length){
      // Preserve the original sections if a future release changes the markup.
      throw new Error('Settings sections have changed; tab layout needs review.');
    }
    const actions=document.createElement('div');
    actions.className='settings-workspace-header';
    actions.innerHTML='<div><div class="eyebrow">SITE DESIGN &amp; ADMIN</div>'+
      '<h2>Settings workspace</h2><p>Edit one section at a time. Your unsaved fields stay in place while switching tabs.</p></div>'+
      '<button type="button" class="primary settings-new-story" id="settingsNewStory">+ New Story</button>';
    root.insertBefore(actions,panels[0]);
    const nav=document.createElement('div');
    nav.className='settings-tabs';
    nav.setAttribute('role','tablist');
    nav.setAttribute('aria-label','Settings sections');
    nav.innerHTML=sections.map(([key,label])=>
      '<button type="button" role="tab" id="settings-tab-'+key+'" aria-controls="settings-panel-'+key+
      '" aria-selected="false" tabindex="-1" data-settings-tab="'+key+'">'+label+'</button>'
    ).join('');
    root.insertBefore(nav,panels[0]);
    const byKey=new Map();
    panels.forEach((panel,i)=>{
      const key=sections[i][0];
      panel.id='settings-panel-'+key;
      panel.classList.add('settings-tab-panel');
      panel.setAttribute('role','tabpanel');
      panel.setAttribute('aria-labelledby','settings-tab-'+key);
      panel.tabIndex=0;
      panel.hidden=true;
      byKey.set(key,panel);
    });
    const loaded=new Set(),loading=new Map();
    async function load(key){
      if(loaded.has(key))return;
      if(loading.has(key))return loading.get(key);
      const task=(async()=>{
        if(key==='theme')await window.SFThemeAdmin?.renderInto?.('themePanel');
        if(key==='homepage')await window.SFArtistHomeAdmin?.renderInto?.('artistHomepagePanel');
        if(key==='stories')await window.SFStoriesAdmin?.renderInto?.('storytellingPanel');
        if(key==='media')await window.SFMediaAdmin?.bindEntityMedia?.('site','public',
          {roles:['logo','hero','artist_photo','social_share','app_icon'],
            publishRoles:['logo','hero','artist_photo','social_share','app_icon']});
        loaded.add(key);
      })();
      loading.set(key,task);
      try {await task;}
      finally {loading.delete(key);}
    }
    async function activate(key,focus=false){
      if(!byKey.has(key))return;
      selected=key;
      for(const [other,panel] of byKey){
        const active=other===key;
        panel.hidden=!active;
        const button=nav.querySelector('[data-settings-tab="'+other+'"]');
        button.setAttribute('aria-selected',String(active));
        button.tabIndex=active?0:-1;
        if(active && focus)button.focus();
      }
      await load(key);
    }
    nav.querySelectorAll('[data-settings-tab]').forEach((button,i)=>{
      button.addEventListener('click',()=>{void activate(button.dataset.settingsTab);});
      button.addEventListener('keydown',e=>{
        const keys=['ArrowRight','ArrowLeft','Home','End'];
        if(!keys.includes(e.key))return;
        e.preventDefault();
        const next=e.key==='Home'?0:e.key==='End'?sections.length-1:
          (i+(e.key==='ArrowRight'?1:-1)+sections.length)%sections.length;
        void activate(sections[next][0],true);
      });
    });
    actions.querySelector('#settingsNewStory').addEventListener('click',async()=>{
      const button=actions.querySelector('#settingsNewStory');
      button.disabled=true;
      try{
        await activate('stories');
        if(typeof window.SFStoriesAdmin?.openNew!=='function')
          throw new Error('Story Editor is not available.');
        await window.SFStoriesAdmin.openNew();
      }catch(err){
        const panel=byKey.get('stories');
        panel.insertAdjacentHTML('afterbegin','<p class="form-error" role="alert">Story editor unavailable. Please try again.</p>');
      }finally{button.disabled=false;}
    });
    currentActivator=activate;
    await activate(sections.some(([key])=>key===selected)?selected:'site');
  }
  async function open(key){
    if(!sections.some(([id])=>id===key))throw new Error('Unknown Settings section');
    if(!currentActivator||!mountedRoot?.isConnected)throw new Error('Settings workspace is not open');
    await currentActivator(key,true);
  }
  window.SFSettingsTabs={mount,open};
})();