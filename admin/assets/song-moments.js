/* Section 91B18 — one artist-owned timeline and one governed fan moderation queue. */
(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;if(!ctx)return;
  const {api,canvas,head,say,esc}=ctx;
  const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const state={data:null,track:'',tab:'timeline',editing:0,busy:false};
  const clock=ms=>{const s=Math.max(0,Math.floor(Number(ms||0)/1000));return Math.floor(s/60)+':'+String(s%60).padStart(2,'0')};
  const opt=(v,label,selected)=>'<option value="'+esc(v)+'"'+(String(v)===String(selected)?' selected':'')+'>'+esc(label)+'</option>';
  const refTitle=(id)=>state.data?.references?.tracks?.find(t=>String(t.id)===String(id))?.title||String(id);
  async function load(){state.data=await api('song-moments.php');return state.data;}
  function metrics(){const m=state.data?.metrics||{};return '<div class="sf-moment-admin-metrics">'+[
    ['PENDING APPROVAL',m.pending_comments||0],['PUBLIC COMMENTS',m.published_comments||0],
    ['PUBLISHED MOMENTS',m.published_artist_moments||0],['ARTIST DRAFTS',m.draft_artist_moments||0]
  ].map(([label,value])=>'<div><strong>'+Number(value)+'</strong><small>'+label+'</small></div>').join('')+'</div>';}
  function editor(){const entries=state.data?.entries||[],e=entries.find(x=>Number(x.id)===state.editing)||{id:0,track_id:state.track||state.data?.references?.tracks?.[0]?.id||'',at_ms:0,kind:'note',title:'',body:'',media_uuid:'',story_id:0,status:'draft'};
    const refs=state.data?.references||{},kind=e.kind||'note';
    return '<form id="sfMomentAdminForm" class="sf-moment-admin-editor" aria-label="Artist song moment editor">'+
      '<div class="sf-moment-admin-editor-title"><strong>'+(e.id?'Edit artist moment #'+Number(e.id):'New artist moment')+'</strong><button type="button" id="sfMomentReset">New moment +</button></div>'+
      '<div class="sf-moment-admin-fields"><label>SONG<select name="track_id" required>'+((refs.tracks||[]).map(x=>opt(x.id,x.title,x.id===e.track_id)).join(''))+'</select></label>'+
      '<label>AT (MM:SS)<input name="position" inputmode="numeric" required pattern="[0-9]{1,3}:[0-5][0-9]" value="'+esc(clock(e.at_ms))+'" placeholder="1:24"></label>'+
      '<label>TYPE<select name="kind">'+[['note','Artist note'],['photo','Photo'],['story','Existing story']].map(x=>opt(x[0],x[1],kind)).join('')+'</select></label>'+
      '<label>STATUS<select name="status">'+[['draft','Draft / not public'],['published','Published / visible']].map(x=>opt(x[0],x[1],e.status||'draft')).join('')+'</select></label>'+
      '<label class="wide">TITLE<input name="title" maxlength="180" value="'+esc(e.title||'')+'" placeholder="What happens here?"></label>'+
      '<label class="wide">AGENT DESCRIPTION<textarea name="body" maxlength="1600" rows="3" placeholder="A brief context to surface while the song plays">'+esc(e.body||'')+'</textarea></label>'+
      '<label class="wide" data-moment-photo>PUBLIC MEDIA LIBRARY PHOTO<select name="media_uuid">'+opt('','Choose a public image',e.media_uuid||'')+(refs.photos||[]).map(x=>opt(x.uuid,x.title+' · '+x.uuid.slice(0,8),e.media_uuid)).join('')+'</select><small>Use Media Library to upload and publish photos first.</small></label>'+
      '<label class="wide" data-moment-story>LINK TO A PUBLISHED STORY<select name="story_id">'+opt('','Choose a published story',e.story_id||0)+(refs.stories||[]).map(x=>opt(x.id,x.title+(x.entity_id?' · '+refTitle(x.entity_id):''),e.story_id)).join('')+'</select><small>Reuses the original story; changes in Stories automatically carry through.</small></label></div>'+
      '<div class="sf-moment-admin-actions"><button type="submit" class="primary">'+(e.id?'Save moment':'Add moment')+'</button><span id="sfMomentFormStatus" role="status"></span></div></form>';
  }
  function entryCard(e){const comment=e.kind==='comment',kind=comment?'FAN COMMENT':e.kind.toUpperCase();
    const info=comment?'<small>'+esc(e.display_name||'Listener')+' · '+esc(String(e.created_at||'').slice(0,16))+'</small>':'';
    const commands=comment&&e.status==='pending'?'<button data-moment-approve="'+e.id+'" class="primary">Approve</button><button data-moment-reject="'+e.id+'">Reject</button>':
      !comment&&e.status!=='removed'?'<button data-moment-edit="'+e.id+'">Edit</button>':'';
    const remove=e.status!=='removed'?'<button data-moment-remove="'+e.id+'">Remove</button>':'';
    return '<article class="sf-moment-admin-row"><div class="sf-moment-admin-time">'+clock(e.at_ms)+'</div><div class="sf-moment-admin-row-body"><div class="sf-moment-admin-label">'+kind+' · '+esc(refTitle(e.track_id))+'</div><strong>'+esc(e.title||e.body?.slice(0,100)||'Untitled')+'</strong>'+info+'<p>'+esc(e.body||'')+'</p><small class="sf-moment-admin-state status-'+esc(e.status)+'">'+esc(String(e.status||'').toUpperCase())+'</small></div><div class="sf-moment-admin-row-actions">'+commands+remove+'</div></article>';
  }
  function render(){const d=state.data||{entries:[],metrics:{},references:{tracks:[]}},all=d.entries||[],pending=all.filter(e=>e.kind==='comment'&&e.status==='pending'),rows=state.tab==='moderation'?all.filter(e=>e.kind==='comment'):
    all.filter(e=>(!state.track||String(e.track_id)===String(state.track))&&e.kind!=='comment').sort((a,b)=>Number(a.at_ms)-Number(b.at_ms));
    canvas.innerHTML=head('MUSIC / FAN EXPERIENCE','Song Moments','Publish timecoded photos, notes, and existing stories around the agent orb. Review every public listener comment before it appears.')+
      metrics()+'<div class="sf-moment-admin-tabs"><button data-moment-tab="timeline" class="'+(state.tab==='timeline'?'active':'')+'">Song Timeline Editor</button><button data-moment-tab="moderation" class="'+(state.tab==='moderation'?'active':'')+'">Comment Approval <b>'+pending.length+'</b></button></div>'+
      (state.tab==='timeline'?'<section class="panel">'+editor()+'<div class="sf-moment-admin-list-title"><h2>Artist timeline</h2><select id="sfMomentFilterSong" aria-label="Filter moments by song">'+opt('','All songs',state.track)+(d.references?.tracks||[]).map(x=>opt(x.id,x.title,state.track)).join('')+'</select></div><div class="sf-moment-admin-list">'+(rows.length?rows.map(entryCard).join(''):'<p class="empty">No artist moments for this song yet. Add a photo, story or note above.</p>')+'</div></section>':
      '<section class="panel"><div class="panel-title"><div><h2>Public comment moderation</h2><p>Fan submissions are pending by default. Only Approved comments appear in the public orb and song timeline.</p></div></div><div class="sf-moment-admin-list">'+(rows.length?rows.map(entryCard).join(''):'<p class="empty">No fan comments awaiting or in review history.</p>')+'</div></section>');
    $$('[data-moment-tab]',canvas).forEach(button=>button.onclick=()=>{state.tab=button.dataset.momentTab;state.editing=0;render()});
    const filter=$('#sfMomentFilterSong',canvas);if(filter)filter.onchange=()=>{state.track=filter.value;state.editing=0;render()};
    const form=$('#sfMomentAdminForm',canvas);
    if(form){
      function showFields(){const kind=form.elements.kind.value;const photo=$('[data-moment-photo]',form),story=$('[data-moment-story]',form);photo.hidden=kind!=='photo';story.hidden=kind!=='story';photo.querySelector('select').disabled=kind!=='photo';story.querySelector('select').disabled=kind!=='story';}
      showFields();form.elements.kind.onchange=showFields;
      $('#sfMomentReset',form).onclick=()=>{state.editing=0;render()};
      form.onsubmit=async ev=>{ev.preventDefault();if(state.busy)return;const f=new FormData(form),stamp=String(f.get('position')||'').match(/^(\d{1,3}):([0-5]\d)$/);const status=$('#sfMomentFormStatus',form);
        if(!stamp){status.textContent='Enter a time like 1:24.';return;}
        const publish=f.get('status')==='published';if(publish&&!confirm('Publish this artist moment to everyone listening to this song?'))return;
        const entry={id:state.editing,track_id:f.get('track_id'),at_ms:(Number(stamp[1])*60+Number(stamp[2]))*1000,kind:f.get('kind'),status:f.get('status'),title:f.get('title'),body:f.get('body'),media_uuid:f.get('media_uuid'),story_id:Number(f.get('story_id')||0),confirmed:publish};
        state.busy=true;status.textContent='Saving…';try{await api('song-moments.php',{action:'save',entry});state.editing=0;await load();say('Song moment saved.');render()}catch(e){status.textContent=e.message}finally{state.busy=false;}
      };
    }
    $$('[data-moment-edit]',canvas).forEach(b=>b.onclick=()=>{state.editing=Number(b.dataset.momentEdit);state.track=all.find(e=>e.id===state.editing)?.track_id||'';state.tab='timeline';render();$('#sfMomentAdminForm',canvas)?.scrollIntoView({block:'start',behavior:'smooth'})});
    for(const action of ['approve','reject','remove'])$$('[data-moment-'+action+']',canvas).forEach(b=>b.onclick=async()=>{
      if(state.busy)return;const id=Number(b.dataset['moment'+action[0].toUpperCase()+action.slice(1)]);if(!confirm((action==='approve'?'Make this fan comment public?':action==='reject'?'Reject this comment?':'Remove this moment from public display?')+' This decision is logged.'))return;
      state.busy=true;try{await api('song-moments.php',{action,id,confirmed:true});await load();say('Moment '+action+'d.');render()}catch(e){say(e.message)}finally{state.busy=false;}
    });
  }
  async function open(tab='timeline'){state.tab=tab;canvas.innerHTML=head('MUSIC / FAN EXPERIENCE','Song Moments','Loading the song timeline and approval queue…');try{await load();render()}catch(e){canvas.innerHTML='<section class="panel"><p role="alert">'+esc(e.message)+'</p></section>';}}
  async function dashboard(){const target=$('#sfSongMomentsDashboard',canvas);if(!target)return;
    try{const d=await api('song-moments.php'),m=d.metrics||{};if(!target.isConnected)return;
      target.innerHTML='<div class="panel-title"><h2>Song Moments &amp; Fan Approval</h2><button data-view="song-moments" class="panel-link">Open editor →</button></div><div class="dashboard-kpi-stack"><button class="dashboard-kpi" data-view="song-moderation"><small>AWAITING APPROVAL</small><strong>'+Number(m.pending_comments||0)+'</strong><span>fan comments not public</span></button><button class="dashboard-kpi" data-view="song-moments"><small>LIVE TIMELINE</small><strong>'+Number(m.published_artist_moments||0)+'</strong><span>published photos, stories and notes</span></button></div>';
      target.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>ctx.openView(b.dataset.view));
    }catch(e){if(target.isConnected)target.innerHTML='<div class="panel-title"><h2>Song Moments</h2><button data-view="song-moments" class="panel-link">Open editor →</button></div><p class="empty">Moment totals are unavailable.</p>';target.querySelector('button')?.addEventListener('click',()=>ctx.openView('song-moments'));}
  }
  window.SFSongMomentsAdmin=Object.freeze({open,dashboard});
})();
