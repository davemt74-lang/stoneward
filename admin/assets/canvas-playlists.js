/* Explicit publication of a reviewed playlist revision; no membership edits. */
(() => {
  'use strict';
  const validHash=value=>typeof value==='string'&&/^[a-f0-9]{64}$/.test(value);
  const node=(tag,text,className)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=String(text);if(className)el.className=className;return el;};
  const button=text=>{const el=node('button',text,'secondary');el.type='button';return el;};
  const duration=value=>{const seconds=Math.max(0,Math.floor(Number(value)||0));return Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');};
  let active=null,sequence=0;

  function renderInto(target='adminCanvas',options={}){
    const host=typeof target==='string'?document.getElementById(target):target;
    const context=window.STONEFELLOW_ADMIN_CONTEXT;
    const api=options.api||context?.api;
    if(!host||typeof api!=='function')return null;
    active?.destroy();
    const root=node('section',undefined,'sf-admin-playlists'),prefix='sfArtistPlaylists-'+(++sequence);
    const heading=node('div',undefined,'canvas-head'),copy=node('div');
    copy.append(node('div','MUSIC CATALOG','eyebrow'),node('h1','Artist playlists'),
      node('p','Review a public playlist and publish its exact revision to the artist catalog. Ordinary public sharing alone does not add a playlist to this catalog.'));
    const refresh=button('Refresh playlists');heading.append(copy,refresh);
    const toolbar=node('form',undefined,'sf-playlist-admin-toolbar'),searchLabel=node('label','Search playlists','field'),search=node('input');
    search.type='search';search.maxLength=200;search.id=prefix+'-search';searchLabel.htmlFor=search.id;searchLabel.append(search);
    const filterLabel=node('label','Publication status','field'),filter=node('select');filter.id=prefix+'-status';filterLabel.htmlFor=filter.id;
    for(const [value,label]of [['all','All playlists'],['published','Published'],['stale','Needs review'],['unpublished','Unpublished'],['withdrawn','Withdrawn']]){const option=node('option',label);option.value=value;filter.append(option);}
    filterLabel.append(filter);toolbar.append(searchLabel,filterLabel);
    const status=node('p','Loading artist playlists…','sf-playlist-admin-status');status.setAttribute('role','status');
    const layout=node('div',undefined,'sf-playlist-admin-layout'),index=node('section'),list=node('ul',undefined,'sf-playlist-admin-list');index.setAttribute('aria-label','Eligible playlists');index.append(list);
    const detail=node('section',undefined,'sf-playlist-admin-detail');detail.setAttribute('aria-label','Playlist publication review');
    detail.append(node('p','Choose a playlist to review its title, description and complete ordered track list.'));
    layout.append(index,detail);root.append(heading,toolbar,status,layout);host.replaceChildren(root);
    const controller=new AbortController(),getOwner=options.getOwner||(()=>String(window.STONEFELLOW_ADMIN_CONTEXT?.app?.csrf||'')),owner=getOwner();
    let destroyed=false,epoch=0,items=[],selected=0,busy=false,mutating=false;
    const alive=()=>!destroyed&&!controller.signal.aborted&&root.isConnected&&owner===getOwner()&&!options.signal?.aborted;
    const state=item=>item.published?'published':item.publication_status==='published'?'stale':item.publication_status;
    const labels={published:'Published',stale:'Needs review',unpublished:'Unpublished',withdrawn:'Withdrawn'};
    const notice=(text,error=false)=>{status.textContent=text;status.setAttribute('role',error?'alert':'status');};
    function checked(data){
      if(data?.ok!==true||!Array.isArray(data.items))throw Error('The playlist service returned an unreadable response.');
      const ids=new Set();
      for(const item of data.items){
        if(!item||!Number.isSafeInteger(item.playlist_id)||item.playlist_id<1||!validHash(item.revision_hash)||
          !['unpublished','published','withdrawn'].includes(item.publication_status)||typeof item.published!=='boolean'||
          typeof item.can_publish!=='boolean'||typeof item.title!=='string'||typeof item.description!=='string'||!Array.isArray(item.items)||
          !Number.isSafeInteger(item.track_count)||item.track_count<0||!Number.isSafeInteger(item.available_count)||item.available_count<0||item.available_count>item.track_count)
          throw Error('The playlist service returned incomplete review data. Refresh before publishing.');
        if(ids.has(item.playlist_id)||item.items.length!==item.track_count||item.items.some(entry=>!entry||typeof entry.available!=='boolean'||typeof entry.title!=='string'))
          throw Error('The playlist service returned incomplete ordered entries. Refresh before publishing.');
        ids.add(item.playlist_id);
      }
      return data.items;
    }
    function lock(){
      for(const control of root.querySelectorAll('button,input,select'))control.disabled=busy||mutating;
    }
    function drawList(){
      list.replaceChildren();const query=search.value.trim().toLowerCase();
      const visible=items.filter(item=>(filter.value==='all'||state(item)===filter.value)&&(!query||[item.title,item.description].join(' ').toLowerCase().includes(query)));
      for(const item of visible){const row=node('li'),open=button('');open.className='sf-playlist-admin-item';open.dataset.playlistId=String(item.playlist_id);open.setAttribute('aria-pressed',String(selected===item.playlist_id));
        open.append(node('strong',item.title||'Untitled playlist'),node('span',labels[state(item)]+' · '+item.track_count+' track'+(item.track_count===1?'':'s')));
        open.addEventListener('click',()=>openDetail(item.playlist_id),{signal:controller.signal});row.append(open);list.append(row);}
      if(!visible.length)list.append(node('li',items.length?'No playlists match this search.':'No public playlists are available for artist publication.'));
      if(!busy&&!mutating)notice(visible.length+' playlist'+(visible.length===1?'':'s')+' shown.');lock();
    }
    function openDetail(id,focus=true){
      if(!alive()||mutating)return;selected=id;const item=items.find(value=>value.playlist_id===id);detail.replaceChildren();
      if(!item){selected=0;root.classList.remove('has-playlist-detail');detail.append(node('p','Choose a playlist to review.'));return;}
      root.classList.add('has-playlist-detail');
      const back=button('Back to playlists');back.className='secondary sf-playlist-admin-back';back.addEventListener('click',()=>{selected=0;root.classList.remove('has-playlist-detail');detail.replaceChildren(node('p','Choose a playlist to review.'));drawList();(list.querySelector('[data-playlist-id="'+id+'"]')||search).focus();},{signal:controller.signal});
      const title=node('h2',item.title||'Untitled playlist');title.tabIndex=-1;
      detail.append(back,title,node('p',labels[state(item)],'sf-playlist-admin-state'),node('p',item.description||'No description supplied.'));
      if(item.source_available===false)detail.append(node('p','The source is unavailable for publication. An existing publication can still be withdrawn.'));
      else if(state(item)==='stale')detail.append(node('p','The source changed after publication. Review this revision before publishing it again.'));
      detail.append(node('p',item.available_count+' of '+item.track_count+' ordered entries have available public audio. Repeated songs keep their original positions.'));
      const revision=node('details'),revisionLabel=node('summary','Reviewed revision'),hash=node('code',item.revision_hash);revision.append(revisionLabel,hash);detail.append(revision);
      const tracks=node('ol',undefined,'sf-playlist-admin-tracks');tracks.setAttribute('aria-label','Ordered playlist entries');
      for(const entry of item.items){const row=node('li'),text=node('span',entry.available?(entry.title||'Untitled song'):'Unavailable song');
        row.append(text,node('small',entry.available?duration(entry.duration_seconds):'Public audio unavailable'));tracks.append(row);}
      if(item.items.length)detail.append(tracks);else detail.append(node('p',item.source_available===false?'Source entries are not available for review.':'This playlist has no tracks.'));
      const actions=node('div',undefined,'actions');
      if(item.can_publish&&!item.published){const publish=button('Publish this revision');publish.className='buy-primary';publish.addEventListener('click',()=>void mutate('publish',item),{signal:controller.signal});actions.append(publish);}
      if(item.publication_status==='published'){const withdraw=button('Withdraw artist playlist');withdraw.addEventListener('click',()=>void mutate('withdraw',item),{signal:controller.signal});actions.append(withdraw);}
      if(!actions.children.length)actions.append(node('p',item.published?'This reviewed revision is available in the public artist playlist catalog.':'This source cannot currently be published.'));
      detail.append(actions,node('p','Publishing and withdrawal change catalog availability only; they do not edit the saved playlist.','sf-playlist-admin-note'));
      drawList();lock();if(focus)title.focus({preventScroll:true});
    }
    async function load(){
      if(!alive()||busy||mutating)return;const captured=++epoch;busy=true;lock();notice('Loading artist playlists…');
      try{const data=await api('canvas-playlists.php');if(!alive()||captured!==epoch)return;items=checked(data);busy=false;drawList();if(selected)openDetail(selected,false);}
      catch(error){if(alive()&&captured===epoch)notice(error.message||'Artist playlists could not load. Use Refresh playlists to retry.',true);}
      finally{if(alive()&&captured===epoch){busy=false;lock();}}
    }
    async function mutate(action,item){
      if(!alive()||busy||mutating||selected!==item.playlist_id)return;const captured=++epoch;mutating=true;lock();notice(action==='publish'?'Publishing reviewed revision…':'Withdrawing artist playlist…');
      try{const data=await api('canvas-playlists.php',{action,playlist_id:item.playlist_id,revision_hash:item.revision_hash});if(!alive()||captured!==epoch)return;items=checked(data);mutating=false;drawList();openDetail(item.playlist_id,false);notice(action==='publish'?'Artist playlist published.':'Artist playlist withdrawn.');}
      catch(error){if(alive()&&captured===epoch)notice(error.message||'Publication could not be saved. Refresh and review the current revision before retrying.',true);}
      finally{if(alive()&&captured===epoch){mutating=false;lock();}}
    }
    toolbar.addEventListener('submit',event=>event.preventDefault(),{signal:controller.signal});
    search.addEventListener('input',drawList,{signal:controller.signal});filter.addEventListener('change',drawList,{signal:controller.signal});refresh.addEventListener('click',()=>void load(),{signal:controller.signal});
    options.signal?.addEventListener('abort',destroy,{once:true});
    function destroy(){if(destroyed)return;destroyed=true;epoch++;controller.abort();options.signal?.removeEventListener('abort',destroy);}
    const instance={root,ready:load(),destroy};active=instance;return instance;
  }
  window.SFCanvasPlaylistsAdmin=Object.freeze({renderInto,destroy(){active?.destroy();active=null;}});
})();
