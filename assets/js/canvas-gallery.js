(() => {
  'use strict';
  let sequence=0;
  const statuses=Object.freeze({pending:'Awaiting review',approved:'Published',returned:'Returned for changes',declined:'Declined',rejected:'Changes requested',withdrawn:'Withdrawn'});
  const text=value=>typeof value==='string'?value.trim():'';
  const element=(tag,className='',value)=>{
    const node=document.createElement(tag);
    if(className)node.className=className;
    if(value!==undefined)node.textContent=value;
    return node;
  };
  const button=(value,className='')=>{const node=element('button',className,value);node.type='button';return node;};
  const requestId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');
  const publicId=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  const sourceId=value=>['number','string'].includes(typeof value)&&Number.isSafeInteger(Number(value))&&Number(value)>0?Number(value):null;
  function imageUrl(value){
    if(typeof value!=='string'||!value)return '';
    try{
      const url=new URL(value,document.baseURI);
      return ['http:','https:'].includes(url.protocol)&&url.origin===location.origin&&!url.username&&!url.password?url.href:'';
    }catch(_){return '';}
  }
  function attribution(host,value){
    const rows=Array.isArray(value)?value:value?.source?[value.source,...(Array.isArray(value.ancestors)?value.ancestors:[])]:value&&typeof value==='object'?[value]:[];
    const seen=new Set();
    for(const row of rows){
      const title=text(row.title),creator=text(row.creator_label);
      const key=text(row.id)||title+'\n'+creator;
      if(seen.has(key))continue;seen.add(key);
      if(title||creator)host.append(element('p','sf-gallery-attribution','Remixed from '+(title||'a shared canvas')+(creator?' by '+creator:'')));
    }
  }

  function create(options={}){
    const api=options.api||{};
    const requests=new Set();
    const prefix='sfCanvasGallery-'+(++sequence);
    let root=null,content=null,status=null,navigation=null,view='public',epoch=0;
    let owner=String(options.getOwner?.()??options.owner??'');
    let submissionReceipt=null;
    const installedKeys=new Set();
    const getOwner=()=>String(options.getOwner?.()??options.owner??'');
    const active=captured=>!!root?.isConnected&&captured===epoch;
    function abortRequests(){for(const request of requests)request.abort();requests.clear();}
    function message(value,error=false){
      if(!status)return;
      status.textContent=value;
      status.setAttribute('role',error?'alert':'status');
    }
    function setOwner(){
      const next=getOwner();
      if(next===owner)return;
      owner=next;epoch++;abortRequests();submissionReceipt=null;
      if(root)void show(view);
    }
    function ownsView(){
      if(owner!==getOwner()){setOwner();return false;}
      return !!owner;
    }
    async function call(callback,args=[],privateRead=false){
      if(typeof callback!=='function')throw new Error('This gallery action is unavailable.');
      if(privateRead&&!ownsView())throw new DOMException('The gallery account changed.','AbortError');
      const captured=epoch,capturedOwner=owner,request=new AbortController();requests.add(request);
      try{
        const result=await callback(...args,request.signal);
        if(request.signal.aborted||!active(captured)||(privateRead&&(capturedOwner!==getOwner()||!ownsView())))
          throw new DOMException('The gallery view changed.','AbortError');
        if(result?.ok===false)throw new Error(text(result.message)||'The gallery request could not be completed.');
        return result;
      }finally{requests.delete(request);}
    }
    function signIn(){
      if(typeof options.onSignIn==='function')options.onSignIn();
      else message('Sign in from the account menu to continue.');
    }
    function accountNotice(){
      content.append(element('p','sf-gallery-empty',view==='installed'?'Sign in to see your installed canvas apps.':'Sign in to manage and submit your canvases.'));
      const action=button('Sign in','sf-gallery-primary');action.onclick=signIn;content.append(action);
    }
    function rememberInstall(app){
      if(!publicId(app?.id)||app.installed!==true)return;
      installedKeys.add(app.id);
      if(publicId(app.gallery_id))installedKeys.add(app.gallery_id);
    }
    function syncInstallButton(control){
      const installed=installedKeys.has(control.dataset.galleryInstall)||installedKeys.has(control.dataset.appId);
      const label=installed?'Installed':'Install';
      control.textContent=label;
      control.setAttribute('aria-label',label+' '+control.dataset.appTitle);
      control.disabled=installed||control.dataset.installing==='true';
    }
    async function loadInstallState(){
      if(!owner||typeof api.installed!=='function')return;
      try{
        const result=await call(api.installed,[],true);
        if(!Array.isArray(result?.items))return;
        result.items.forEach(rememberInstall);
        root.querySelectorAll('[data-gallery-install]').forEach(syncInstallButton);
      }catch(_){/* The public catalog stays available when this private read fails. */}
    }
    function installButton(item,title,state){
      const control=button('Install');
      control.dataset.galleryInstall=item.id;
      control.dataset.appId=publicId(item.app_id)?item.app_id:'';
      control.dataset.appTitle=title;
      syncInstallButton(control);
      control.onclick=async event=>{
        if(!event.isTrusted)return;
        if(!ownsView()){signIn();return;}
        control.dataset.installing='true';syncInstallButton(control);state.textContent='Installing app…';
        try{
          const result=await call(api.install,[item.id],true);
          if(!publicId(result?.app?.id)||result.app.installed!==true||
            (publicId(item.app_id)&&result.app.id!==item.app_id))throw new Error('The installation could not be verified.');
          rememberInstall(result.app);installedKeys.add(item.id);
          root.querySelectorAll('[data-gallery-install]').forEach(syncInstallButton);
          state.textContent=result.app.enabled===true?'Installed. App notifications are on.':'Installed. App notifications are off.';
        }catch(error){if(error.name!=='AbortError')state.textContent=error.message||'The app could not be installed.';}
        finally{if(control.isConnected){delete control.dataset.installing;syncInstallButton(control);}}
      };
      return control;
    }
    function preview(item){
      const frame=element('div','sf-gallery-preview');
      const fallback=element('span','sf-gallery-preview-fallback','Canvas preview');
      frame.append(fallback);
      const url=imageUrl(item.preview_url);
      if(url){
        const image=element('img');image.src=url;image.alt='Preview of '+(text(item.title)||'shared canvas');
        image.loading='lazy';image.decoding='async';image.referrerPolicy='same-origin';
        image.addEventListener('load',()=>{fallback.hidden=true;},{once:true});
        image.addEventListener('error',()=>{image.remove();fallback.hidden=false;fallback.textContent='Preview unavailable';},{once:true});
        frame.append(image);
      }
      return frame;
    }
    async function openCard(item,action,state,buttons,installed=false){
      const privateRead=action==='remix'||installed;
      if(privateRead&&!ownsView()){signIn();return;}
      const callback=action==='remix'?options.onRemix:options.onOpen;
      if(typeof callback!=='function'){state.textContent='This action is unavailable.';return;}
      buttons.forEach(node=>{node.disabled=true;});state.textContent=action==='remix'?'Preparing your remix…':'Opening canvas…';
      try{
        await call(callback,[item],privateRead);
        state.textContent=action==='remix'?'Remix opened in your canvas.':'Canvas opened.';
      }catch(error){if(error.name!=='AbortError')state.textContent=error.message||'The canvas could not be opened.';}
      finally{if(state.isConnected)buttons.forEach(node=>{node.disabled=false;});}
    }
    function card(item,mine=false){
      const node=element('article','sf-gallery-card');node.dataset.galleryId=item.id;
      node.append(preview(item));
      const copy=element('div','sf-gallery-card-copy');
      const heading=element('h3','',text(item.title)||'Untitled canvas');
      copy.append(heading,element('p','sf-gallery-creator','By '+(text(item.creator_label)||'a community creator')));
      if(text(item.description))copy.append(element('p','sf-gallery-description',item.description));
      attribution(copy,item.attribution);
      if(Array.isArray(item.uses)){
        const uses=element('details','sf-gallery-uses'),summary=element('summary','','What this canvas uses'),list=element('ul');uses.append(summary,list);
        for(const permission of item.uses){
          if(typeof permission?.label!=='string'||typeof permission.personal!=='boolean'||typeof permission.enabled!=='boolean')continue;
          list.append(element('li','',permission.label+' · '+(permission.library?'Local script library':permission.device_consent?'Asks to check your device location':permission.personal?'Your data; permission required':'Public artist data')+(!permission.enabled?' · Disabled by administrator':'')));
        }
        if(!list.children.length)list.append(element('li','','No registered tools or data permissions requested.'));
        copy.append(uses);
      }
      const state=element('p','sf-gallery-card-state');state.setAttribute('role','status');
      const actions=element('div','sf-gallery-card-actions');
      if(mine){
        const value=Object.hasOwn(statuses,item.status)?item.status:'';
        const badge=element('span','sf-gallery-badge',statuses[value]||'Status unavailable');badge.dataset.status=value;
        copy.append(badge);
        if(sourceId(item.source_scene_id))copy.append(element('small','sf-gallery-version','Saved version #'+item.source_scene_id));
        const feedback=text(item.feedback);
        if(feedback){const note=element('section','sf-gallery-feedback');note.append(element('strong','','Review feedback'),element('p','',feedback));copy.append(note);}
        if(['pending','approved'].includes(value)){
          const withdraw=button('Withdraw');withdraw.setAttribute('aria-label','Withdraw '+heading.textContent);
          withdraw.onclick=async()=>{
            if(!ownsView())return;
            withdraw.disabled=true;state.textContent='Withdrawing submission…';
            try{
              const result=await call(api.withdraw,[item.id],true);
              const next=result?.item;
              if(!next||!publicId(next.id))throw new Error('The withdrawal response was incomplete. Refresh your submissions.');
              node.replaceWith(card(next,true));message('Submission withdrawn.');
            }catch(error){if(error.name!=='AbortError'){state.textContent=error.message||'The submission could not be withdrawn.';withdraw.disabled=false;}}
          };
          actions.append(withdraw);
        }
      }else{
        const open=button('Open','sf-gallery-primary'),remix=button('Remix');
        open.setAttribute('aria-label','Open '+heading.textContent);remix.setAttribute('aria-label','Remix '+heading.textContent);
        open.onclick=()=>void openCard(item,'open',state,[open,remix]);
        remix.onclick=()=>void openCard(item,'remix',state,[open,remix]);
        actions.append(open);
        if(typeof api.install==='function')actions.append(installButton(item,heading.textContent,state));
        actions.append(remix);
        if(typeof options.getItemUrl==='function'){
          const share=button('Copy link');share.setAttribute('aria-label','Copy link to '+heading.textContent);
          const label=element('label','sf-gallery-share');label.hidden=true;label.append(element('span','sf-gallery-label','Share link'));
          const link=element('input');link.type='url';link.readOnly=true;label.append(link);copy.append(label);
          share.onclick=async()=>{
            const captured=epoch;let url='';
            try{url=imageUrl(options.getItemUrl(item));}catch(_){}
            if(!url){state.textContent='This canvas link is unavailable.';return;}
            share.disabled=true;
            try{
              if(typeof navigator.clipboard?.writeText!=='function')throw new Error('Clipboard unavailable.');
              await navigator.clipboard.writeText(url);
              if(active(captured)){label.hidden=true;state.textContent='Link copied.';}
            }catch(_){
              if(active(captured)){link.value=url;label.hidden=false;state.textContent='Copy the link below.';link.focus({preventScroll:true});link.select();}
            }finally{if(share.isConnected)share.disabled=false;}
          };
          actions.append(share);
        }
      }
      copy.append(actions,state);node.append(copy);return node;
    }
    function installedCard(app,grid){
      const captured=epoch;
      const node=element('article','sf-gallery-card sf-gallery-installed-card');
      const copy=element('div','sf-gallery-card-copy');
      const heading=element('h3');
      const availability=element('p','sf-gallery-installed-state');
      const actions=element('div','sf-gallery-card-actions');
      const state=element('p','sf-gallery-card-state');state.setAttribute('role','status');
      const open=button('Open','sf-gallery-primary');
      const alerts=button('Notification settings');
      const remove=button('Uninstall');
      const current=()=>active(captured)&&node.isConnected&&owner===getOwner();
      let hasAlertSettings=false;
      const update=next=>{
        app=next;
        const available=publicId(app.gallery_id),enabled=app.enabled===true,supportsAlerts=app.supports_alerts===true;
        const title=text(app.title)||'Canvas app';
        heading.textContent=title;
        open.setAttribute('aria-label','Open '+title);
        alerts.setAttribute('aria-label','Notification settings for '+title);
        remove.setAttribute('aria-label','Uninstall '+title);
        open.hidden=!available;
        hasAlertSettings ||= supportsAlerts||enabled;
        alerts.hidden=typeof options.onAppNotifications!=='function'||!hasAlertSettings;
        const alertStatus=enabled?(supportsAlerts?'App notifications on.':'App notifications are paused.'):
          supportsAlerts?'App notifications off.':'This app has no notifications available.';
        availability.textContent=available?alertStatus:'This app is unavailable right now.'+(enabled?' '+alertStatus:'');
      };
      open.onclick=()=>{
        if(current()&&publicId(app.gallery_id))void openCard({id:app.gallery_id,title:heading.textContent},'open',state,[open],true);
      };
      alerts.onclick=()=>{
        if(current()&&ownsView())options.onAppNotifications?.(app,next=>{
          if(current()&&next?.id===app.id)update(next);
        },current);
      };
      remove.onclick=async event=>{
        if(!event.isTrusted||!current()||!ownsView())return;
        const controls=[open,alerts,remove];
        controls.forEach(control=>{control.disabled=true;});
        state.textContent='Uninstalling app…';
        try{
          const result=await call(api.uninstall,[app.id],true);
          if(result?.app?.id!==app.id||result.app.installed!==false||result.app.enabled!==false)
            throw new Error('The uninstall could not be verified. Refresh Installed apps to check.');
          const next=node.nextElementSibling||node.previousElementSibling;
          node.remove();
          if(!grid.children.length)grid.append(element('p','sf-gallery-empty','You have no installed canvas apps.'));
          message('App uninstalled. Its notifications are off.');
          (next?.querySelector('button:not([hidden]):not([disabled])')||navigation.querySelector('[data-view=installed]'))?.focus({preventScroll:true});
        }catch(error){if(error.name!=='AbortError')state.textContent=error.message||'The app could not be uninstalled.';}
        finally{if(node.isConnected)controls.forEach(control=>{control.disabled=false;});}
      };
      remove.hidden=typeof api.uninstall!=='function';
      actions.append(open,alerts,remove);
      copy.append(heading,availability,actions,state);node.append(copy);
      update(app);
      return node;
    }
    async function installedListing(){
      const grid=element('div','sf-gallery-grid');grid.setAttribute('aria-label','Installed canvas apps');
      const retry=button('Try again');retry.hidden=true;retry.onclick=()=>void show('installed');
      content.append(element('p','sf-gallery-installed-intro','Uninstalling an app turns off its notifications.'),grid,retry);
      message('Loading your installed apps…');
      try{
        const result=await call(api.installed,[],true);
        if(!Array.isArray(result?.items))throw new Error('Your installed apps could not be read.');
        const seen=new Set();
        for(const app of result.items){
          if(!publicId(app?.id)||app.installed!==true||seen.has(app.id))continue;
          seen.add(app.id);grid.append(installedCard(app,grid));
        }
        if(!seen.size)grid.append(element('p','sf-gallery-empty','You have no installed canvas apps.'));
        message('');
      }catch(error){if(error.name!=='AbortError'){message(error.message||'Your installed apps could not load.',true);retry.hidden=false;}}
    }
    async function detail(id){
      message('Loading shared canvas…');
      const retry=button('Try again');retry.hidden=true;retry.onclick=()=>void show('public',{itemId:id});content.append(retry);
      try{
        if(!publicId(id))throw new Error('Choose a published canvas.');
        const response=await call(api.detail,[id]);
        if(!publicId(response?.item?.id))throw new Error('This canvas is unavailable.');
        const grid=element('div','sf-gallery-grid sf-gallery-detail');grid.append(card(response.item));content.append(grid);message('');
      }catch(error){if(error.name!=='AbortError'){message(error.message||'This shared canvas could not load.',true);retry.hidden=false;}}
    }
    async function listing(mine){
      const captured=epoch;
      const searchForm=element('form','sf-gallery-search');
      const label=element('label');label.append(element('span','sf-gallery-label',mine?'Search your submissions':'Search shared canvases'));
      const search=element('input');search.type='search';search.name='q';search.maxLength=200;label.append(search);
      const searchButton=button('Search');searchButton.type='submit';searchForm.append(label,searchButton);
      const grid=element('div','sf-gallery-grid');grid.setAttribute('aria-label',mine?'Your canvas submissions':'Published canvases');
      const controls=element('div','sf-gallery-pagination');
      const more=button('More canvases'),retry=button('Try again');more.hidden=retry.hidden=true;controls.append(more,retry);
      content.append(searchForm,grid,controls);
      const seen=new Set();let page=0,query='',busy=false,failedPage=1;
      async function load(next,append=false){
        if(busy||!active(captured))return;
        busy=true;failedPage=next;more.disabled=searchButton.disabled=true;retry.hidden=true;grid.setAttribute('aria-busy','true');
        if(!append){grid.replaceChildren();seen.clear();}
        message(mine?'Loading your submissions…':'Loading shared canvases…');
        try{
          const result=await call(mine?api.mine:api.list,[{page:next,limit:24,q:query}],mine);
          if(!Array.isArray(result?.items))throw new Error('The gallery returned an unreadable list.');
          for(const item of result.items){if(!publicId(item?.id)||seen.has(item.id))continue;seen.add(item.id);grid.append(card(item,mine));}
          page=next;more.hidden=result.has_more!==true;
          if(!seen.size)grid.append(element('p','sf-gallery-empty',query?'No canvases match your search.':mine?'You have no canvas submissions yet.':'No canvases have been published yet.'));
          const total=Number.isSafeInteger(result.total)?result.total:null;
          message(submissionReceipt&&mine?submissionReceipt:seen.size+(total!==null?' of '+total:'')+' '+(mine?'submissions.':'shared canvases.'));
          if(mine)submissionReceipt=null;
        }catch(error){if(error.name!=='AbortError'){message(error.message||'The gallery could not load.',true);retry.hidden=false;}}
        finally{busy=false;if(active(captured)){grid.setAttribute('aria-busy','false');more.disabled=searchButton.disabled=false;}}
      }
      searchForm.onsubmit=event=>{event.preventDefault();if(busy)return;query=search.value.trim();void load(1);};
      more.onclick=()=>void load(page+1,true);retry.onclick=()=>void load(failedPage,failedPage>1);
      await load(1);
    }
    async function submission(selectedId){
      const captured=epoch;
      message('Loading your saved canvas versions…');
      const retry=button('Try again');retry.hidden=true;retry.onclick=()=>void show('submit',{sceneId:selectedId});content.append(retry);
      let scenes;
      try{
        const result=await call(api.sources,[],true);
        const rows=result?.items??result?.scenes??result?.sources??result?.history;
        if(!Array.isArray(rows))throw new Error('Your saved canvases could not be read.');
        scenes=rows.filter(row=>sourceId(row.id));
      }catch(error){if(error.name!=='AbortError'){message(error.message||'Your saved canvases could not load.',true);retry.hidden=false;}return;}
      if(!active(captured))return;
      if(selectedId&&!scenes.some(row=>sourceId(row.id)===sourceId(selectedId))&&typeof api.source==='function'){
        try{const response=await call(api.source,[sourceId(selectedId)],true);if(response?.scene&&sourceId(response.scene.id))scenes.unshift(response.scene);}
        catch(error){if(error.name!=='AbortError')message('The selected saved version is unavailable. Choose another canvas.',true);}
      }
      if(!scenes.length){content.append(element('p','sf-gallery-empty','Save a canvas before submitting it to the gallery.'));message('No saved versions are available.');return;}
      const form=element('form','sf-gallery-submit');form.setAttribute('aria-label','Submit a saved canvas for review');
      const field=(caption,name,tag='input')=>{
        const label=element('label','sf-gallery-field');label.append(element('span','sf-gallery-label',caption));
        const input=element(tag);input.name=name;input.id=prefix+'-'+name;label.append(input);form.append(label);return input;
      };
      const select=field('Saved canvas version','scene_id','select');select.required=true;
      for(const scene of scenes){const option=element('option','',(text(scene.title)||'Untitled canvas')+' · #'+scene.id);option.value=String(scene.id);select.append(option);}
      if(scenes.some(row=>sourceId(row.id)===sourceId(selectedId)))select.value=String(selectedId);
      const version=element('p','sf-gallery-version');form.append(version);
      const title=field('Public title','title');title.required=true;title.maxLength=240;
      const description=field('Public description','description','textarea');description.rows=4;description.required=true;description.maxLength=2000;
      const creator=field('Creator name','creator_label');creator.required=true;creator.maxLength=120;creator.value=text(options.getCreatorLabel?.());
      const previewSelect=field('Preview image (optional)','preview_asset_id','select');
      const previewState=element('small','sf-gallery-version');form.append(previewState);
      const previewImage=element('img','sf-gallery-submit-preview');previewImage.alt='Selected gallery preview';previewImage.hidden=true;form.append(previewImage);
      const attributionHost=element('div');form.append(attributionHost);
      const publication=element('p','sf-gallery-publication','After approval, your title, description, creator name, code and canvas image assets will be public and available to remix. Personal account records are supplied separately for each viewer.');
      form.append(publication);
      const consentLabel=element('label','sf-gallery-consent');
      const consent=element('input');consent.type='checkbox';consent.required=true;consent.name='publication_consent';
      consentLabel.append(consent,element('span','','I agree to publish this saved version’s code and image assets, including the selected preview.'));form.append(consentLabel);
      const submit=button('Submit for review','sf-gallery-primary');submit.type='submit';form.append(submit);
      const state=element('p','sf-gallery-form-state');state.setAttribute('role','status');form.append(state);content.append(form);
      let titleEdited=false,busy=false,receiptKey='',receiptId='';
      title.addEventListener('input',()=>{titleEdited=true;});
      function previewChanged(){
        const id=previewSelect.value;let url='';
        if(id&&typeof options.getPreviewUrl==='function')url=imageUrl(options.getPreviewUrl(id));
        previewImage.hidden=!url;if(url)previewImage.src=url;else previewImage.removeAttribute('src');
      }
      previewSelect.onchange=previewChanged;
      previewImage.addEventListener('error',()=>{previewImage.hidden=true;previewState.textContent='The preview could not be displayed. Choose another image or the gallery placeholder.';});
      async function sourceChanged(){
        const id=sourceId(select.value),scene=scenes.find(row=>sourceId(row.id)===id);
        if(!scene)return;
        version.textContent='Sharing saved version #'+id+'.';
        if(!titleEdited)title.value=text(scene.title);
        attributionHost.replaceChildren();attribution(attributionHost,scene.attribution??scene.gallery_attribution);
        previewSelect.replaceChildren();const none=element('option','','Use a gallery placeholder');none.value='';previewSelect.append(none);previewChanged();
        previewState.textContent='Loading available preview images…';previewSelect.disabled=true;
        try{
          const response=typeof api.source==='function'?await call(api.source,[id],true):{scene};
          if(!active(captured)||sourceId(select.value)!==id)return;
          const source=response?.scene??scene;
          attributionHost.replaceChildren();attribution(attributionHost,source.attribution??source.gallery_attribution);
          const assets=Array.isArray(source.image_assets)?source.image_assets:[];
          for(const asset of assets){
            if(typeof asset?.id!=='string'||!/^(?:ref-)?[a-f0-9]{32}$/.test(asset.id))continue;
            const option=element('option','',text(asset.title)||'Canvas image');option.value=asset.id;previewSelect.append(option);
          }
          previewState.textContent=previewSelect.options.length>1?'Choose an image already used by this saved version.':'This saved version can use the gallery placeholder.';
        }catch(error){if(error.name!=='AbortError'&&active(captured)&&sourceId(select.value)===id)previewState.textContent='Preview images could not load. The gallery placeholder is available.';}
        finally{if(active(captured)&&sourceId(select.value)===id)previewSelect.disabled=busy;}
      }
      select.onchange=()=>{void sourceChanged();};void sourceChanged();
      form.onsubmit=async event=>{
        event.preventDefault();if(busy||!ownsView()||!form.reportValidity())return;
        const values={title:title.value.trim(),description:description.value.trim(),creator_label:creator.value.trim()};
        for(const [name,limit] of [['title',240],['description',2000],['creator_label',120]]){
          if(!values[name]||new TextEncoder().encode(values[name]).length>limit){state.textContent='Please shorten '+(name==='creator_label'?'the creator name':'the '+name)+'.';form.elements.namedItem(name)?.focus();return;}
        }
        const payload={scene_id:sourceId(select.value),...values,publication_consent:consent.checked};
        if(previewSelect.value)payload.preview_asset_id=previewSelect.value;
        const key=JSON.stringify(payload);if(key!==receiptKey){receiptKey=key;receiptId=requestId();}
        busy=true;state.textContent='Submitting your saved version…';
        const controls=[...form.elements];controls.forEach(control=>{control.disabled=true;});
        try{
          const result=await call(api.submit,[{...payload,request_id:receiptId}],true);
          if(!result?.item||!publicId(result.item.id))throw new Error('The submission response was incomplete. Check My submissions before retrying.');
          submissionReceipt='Submitted for review.';receiptKey=receiptId='';
          try{options.onSubmitted?.(result.item);}catch(_){}
          if(active(captured))await show('mine');
        }catch(error){if(error.name!=='AbortError')state.textContent=error.message||'The submission could not be saved. Try again.';}
        finally{busy=false;if(active(captured))controls.forEach(control=>{control.disabled=false;});}
      };
      message('Choose a saved version and review its public details.');
    }
    async function show(next,parameters={}){
      if(!root||!['public','mine','submit','installed'].includes(next)||(next==='installed'&&typeof api.installed!=='function'))return;
      const nextOwner=getOwner();if(nextOwner!==owner){owner=nextOwner;submissionReceipt=null;}
      epoch++;abortRequests();installedKeys.clear();view=next;content.replaceChildren();message('');
      for(const item of navigation.children)item.setAttribute('aria-current',item.dataset.view===view?'page':'false');
      if(view!=='public'&&!owner){accountNotice();return;}
      if(view==='public')void loadInstallState();
      if(view==='submit')await submission(parameters.sceneId);
      else if(view==='installed')await installedListing();
      else if(view==='public'&&parameters.itemId)await detail(parameters.itemId);
      else await listing(view==='mine');
    }
    function mount(host,parameters={}){
      destroy();owner=getOwner();submissionReceipt=null;
      root=element('section','sf-canvas-gallery');root.setAttribute('aria-labelledby',prefix+'-heading');
      const header=element('header','sf-gallery-header');header.append(element('small','sf-gallery-eyebrow','STONEFELLOW / COMMUNITY'));
      const heading=element('h1','','Canvas Gallery');heading.id=prefix+'-heading';
      header.append(heading,element('p','','Explore shared canvases, open an experience, or make a remix of your own.'));
      navigation=element('nav','sf-gallery-nav');navigation.setAttribute('aria-label','Canvas gallery views');
      const views=[['public','Gallery']];
      if(typeof api.installed==='function')views.push(['installed','Installed apps']);
      views.push(['mine','My submissions'],['submit','Submit a canvas']);
      for(const [key,label] of views){
        const action=button(label);action.dataset.view=key;action.onclick=()=>void show(key);navigation.append(action);
      }
      status=element('p','sf-gallery-state');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
      content=element('div','sf-gallery-content');header.append(navigation);root.append(header,status,content);host.replaceChildren(root);
      window.addEventListener('pagehide',destroy,{once:true});void show(parameters.view||'public',parameters);return root;
    }
    function destroy(){
      epoch++;abortRequests();window.removeEventListener('pagehide',destroy);root?.remove();root=content=status=navigation=null;
    }
    return Object.freeze({mount,show,setOwner,destroy});
  }
  window.SFCanvasGallery=Object.freeze({create});
})();
