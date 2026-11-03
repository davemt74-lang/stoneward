/* Section 91B18 — one artist-owned timeline and one governed fan moderation queue. */
(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;if(!ctx)return;
  const {api,canvas,head,say,esc}=ctx;
  const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const state={data:null,track:'',tab:'timeline',editing:0,busy:false,editorDirty:false,view:null,workerEnabled:false,workerPaused:false,workerPauseVersion:0,workerBusy:false,pollTimer:null};
  const automaticImports=new Set(),checkingPublishedTracks=new Set();
  const importsByTrack=new Map();
  let catalogPanel=null;
  const clock=ms=>{const s=Math.max(0,Math.floor(Number(ms||0)/1000));return Math.floor(s/60)+':'+String(s%60).padStart(2,'0')};
  const preciseClock=ms=>clock(ms)+'.'+String(Math.max(0,Math.round(Number(ms)||0))%1000).padStart(3,'0');
  const parsePosition=value=>{const m=String(value||'').trim().match(/^(\d{1,3}):([0-5]\d)(?:\.(\d{1,3}))?$/);return m?(Number(m[1])*60+Number(m[2]))*1000+Number((m[3]||'').padEnd(3,'0')):null;};
  const artistEntries=()=>((state.data?.entries||[]).filter(e=>Number(e.source_annotation_id||0)===0&&e.status!=='private'));
  const opt=(v,label,selected)=>'<option value="'+esc(v)+'"'+(String(v)===String(selected)?' selected':'')+'>'+esc(label)+'</option>';
  const refTitle=(id)=>state.data?.references?.tracks?.find(t=>String(t.id)===String(id))?.title||String(id);
  const currentView=view=>view&&view===state.view&&(!ctx.app||ctx.app.view===view.route);
  async function load(view){const data=await api('song-moments.php');if(!currentView(view))return false;state.data=data;return true;}
  const validTrackId=id=>typeof id==='string'&&id.length>0&&id.length<=180;
  function importState(track){
    if(!importsByTrack.has(track))importsByTrack.set(track,{job:null,loaded:false,loading:false,busy:false,needsRefresh:false,notice:'',error:''});
    return importsByTrack.get(track);
  }
  function activePanel(){if(catalogPanel&&!catalogPanel.target.isConnected)catalogPanel=null;return catalogPanel;}
  const canAdvanceImport=job=>job?.status==='queued'&&job.can_advance!==false&&!job.pause_requested;
  const inWorkerScope=job=>activePanel()?.track===String(job.track_id)||automaticImports.has(Number(job.id));
  const canAdvanceHere=item=>item.job&&inWorkerScope(item.job)&&canAdvanceImport(item.job)&&!item.loading&&!item.busy&&!item.needsRefresh;
  const processingHere=item=>item.job&&inWorkerScope(item.job)&&item.job.status==='processing'&&!item.needsRefresh;
  const matchingImports=predicate=>Array.from(importsByTrack.values()).filter(predicate);
  function rememberImportJob(track,job){
    const item=importState(track),id=Number(job?.id);
    if(!Number.isInteger(id)||id<1||String(job.track_id)!==track)return false;
    const previous=Number(item.job?.id||0);
    if(previous>id)return false;
    if(previous&&previous!==id)automaticImports.delete(previous);
    item.job=job;item.loaded=true;
    if(!['queued','processing'].includes(job.status))automaticImports.delete(id);
    return true;
  }
  async function readLatestImport(track){
    const data=await api('song-lyrics-import.php?track_id='+encodeURIComponent(track));
    return (Array.isArray(data.jobs)?data.jobs:[]).filter(j=>String(j.track_id)===track).sort((a,b)=>Number(b.id)-Number(a.id))[0]||null;
  }
  function refreshImport(track){
    const item=importState(track);
    if(item.reading){renderImport();return item.reading;}
    const previous=item.job;
    item.loading=true;renderImport();
    item.reading=readLatestImport(track).then(job=>{
      // A slower status read must not replace a more recent worker or control response.
      if(item.job===previous){
        if(job){if(!rememberImportJob(track,job))throw new Error('Song lyric import status is unavailable.');}
        else if(previous)throw new Error('Song lyric import status is unavailable.');
        else item.loaded=true;
        item.needsRefresh=false;item.error='';item.notice='';
      }
      return true;
    }).catch(e=>{item.error=e.message||'Song lyric import status is unavailable.';item.needsRefresh=true;return false;})
      .finally(()=>{item.loading=false;item.reading=null;renderImport();});
    return item.reading;
  }
  function pauseWorker(){
    state.workerPaused=true;state.workerEnabled=false;state.workerPauseVersion++;
    if(state.pollTimer){clearTimeout(state.pollTimer);state.pollTimer=null;}
  }
  /** Successful publication has already queued this specific listening copy on the server. */
  async function resumePublishedTrack(trackId){
    if(!validTrackId(trackId)||checkingPublishedTracks.has(trackId))return false;
    checkingPublishedTracks.add(trackId);
    const item=importState(trackId),previous=item.job;
    try{
      const result=await readLatestImport(trackId);
      if(result&&(!item.job||Number(result.id)>Number(item.job.id)||item.job===previous))rememberImportJob(trackId,result);
      const job=item.job;
      if(!result||!job||Number(result.id)!==Number(job.id)||!(canAdvanceImport(job)||job.status==='processing'))return false;
      automaticImports.add(Number(job.id));
      if(!state.workerPaused){state.workerEnabled=true;renderImport();void pumpImports();}
      return true;
    }catch(_){return false;}
    finally{checkingPublishedTracks.delete(trackId);}
  }
  function scheduleImportPoll(){
    if(state.pollTimer||!state.workerEnabled||!matchingImports(processingHere).length)return;
    state.pollTimer=setTimeout(async()=>{
      state.pollTimer=null;if(!state.workerEnabled)return;
      const results=await Promise.all(matchingImports(processingHere).map(item=>refreshImport(String(item.job.track_id))));
      if(results.includes(false)){pauseWorker();renderImport();return;}
      if(state.workerEnabled)void pumpImports();
    },2000);
  }
  function importContent(item){
    const {job}=item,status=String(job?.status||'');
    const completed=Number(job?.completed_chunks||0),total=Number(job?.total_chunks||0);
    const descriptions={
      ready:'Import saved. Open Song Moments to review, approve and publish the unverified lyric segments.',
      unknown:'Provider outcome is unknown. Review the provider request and token ledger manually.',
      failed:'Import failed before transcription started. Check the provider and source configuration, then retry the import.',
      processing:'A transcription request is in progress. Pause import takes effect after the current block.',
      stale:'The source audio changed. Start an import for the current published listening copy.',
      paused:'Import is paused across workers. Resume it when ready.',
      queued:'Queued for one request at a time. Pause import saves a pause for this song across workers.'
    };
    const description=job?.pause_requested&&status==='processing'?'Pause saved. The current block will finish before the import pauses.':descriptions[status]||'Import the authorized listening copy once. Listeners reuse its saved lyrics during playback.';
    const disabled=item.busy||item.loading||!item.loaded||item.needsRefresh||['queued','running','processing','ready','unknown'].includes(status);
    const importLabel=status==='paused'?'Resume import':status==='failed'?'Retry import':'Import song lyrics';
    const pauseButton=['queued','processing'].includes(status)&&!job?.pause_requested
      ?'<button type="button" id="sfLyricsImportPause"'+(item.busy||item.loading?' disabled':'')+'>Pause import</button>':'';
    const progress=total>0?completed+' / '+total+' blocks saved':'';
    return '<div class="sf-moment-admin-import-title"><div><small>SONG LYRICS IMPORT</small><h2>Transcribe this song once</h2></div><span class="sf-moment-admin-import-badge">APPROXIMATE BLOCK TIMING</span></div><p>The published listening copy is transcribed in roughly 60-second blocks. Lyrics are saved as unverified drafts for review.</p>'+
      '<div class="sf-moment-admin-import-controls"><button type="button" id="sfLyricsImportEnqueue"'+(disabled?' disabled':'')+'>'+importLabel+'</button>'+pauseButton+'<button type="button" id="sfLyricsImportWorker"'+(!state.workerEnabled&&!matchingImports(canAdvanceHere).length&&!matchingImports(processingHere).length?' disabled':'')+'>'+(state.workerEnabled?'Stop worker in this browser':'Resume worker in this browser')+'</button><button type="button" id="sfLyricsImportRefresh"'+(item.loading||item.busy?' disabled':'')+'>Refresh status</button></div>'+
      '<div class="sf-moment-admin-import-status" role="status" aria-live="polite"><strong>'+esc(status?status.toUpperCase():item.loading?'CHECKING STATUS':item.loaded?'NOT IMPORTED':'STATUS UNAVAILABLE')+(progress?' · '+esc(progress):'')+'</strong><span>'+esc(description)+'</span>'+(job?.error_code?'<span>'+esc(String(job.error_code))+'</span>':'')+(item.notice?'<span>'+esc(item.notice)+'</span>':'')+(item.error?'<span class="sf-moment-admin-import-error">'+esc(item.error)+'</span>':'')+'</div>'+
      (total>0?'<progress max="'+total+'" value="'+Math.min(total,Math.max(0,completed))+'" aria-label="Lyric import blocks saved"></progress>':'')+
      (status==='ready'?'<button type="button" id="sfLyricsImportReview">Review lyric segments</button>':'');
  }
  function renderImport(){
    const panel=activePanel();if(!panel)return;
    const {target,track}=panel,item=importState(track);
    target.innerHTML=importContent(item);
    $('#sfLyricsImportEnqueue',target).onclick=async()=>{
      if(activePanel()!==panel||item.busy||item.loading||!item.loaded||item.needsRefresh)return;
      const current=item.job;
      let request={action:'enqueue',track_id:track};
      if(current?.status==='paused')request={action:'resume',job_id:Number(current.id)};
      else if(current?.status==='failed')request={action:'retry',job_id:Number(current.id)};
      else if(current&&current.status!=='stale')return;
      const pauseVersion=state.workerPauseVersion;
      item.busy=true;item.error='';item.notice='Queueing song lyric import…';renderImport();
      try{
        const data=await api('song-lyrics-import.php',request);
        if(!rememberImportJob(track,data.job))throw new Error('Import status was missing from the response.');
        item.notice=data.job.status==='queued'?'Song queued for its one-time import.':'Import status refreshed.';
        if(activePanel()===panel&&state.workerPauseVersion===pauseVersion){state.workerPaused=false;state.workerEnabled=true;}
      }catch(e){item.error=e.message||'Could not queue lyric import.';item.notice='';}
      finally{item.busy=false;renderImport();if(state.workerEnabled)void pumpImports();}
    };
    const pause=$('#sfLyricsImportPause',target);
    if(pause)pause.onclick=async()=>{
      const current=item.job;
      if(activePanel()!==panel||item.busy||item.loading||!current||!['queued','processing'].includes(current.status)||current.pause_requested)return;
      const id=Number(current.id);
      item.busy=true;
      item.error='';
      item.notice='Saving import pause…';
      renderImport();
      try{
        const data=await api('song-lyrics-import.php',{action:'pause',job_id:id});
        if(Number(data?.job?.id)!==id||String(data.job.track_id)!==track)throw new Error('Pause status was missing from the response.');
        if(Number(item.job?.completed_chunks)<=Number(data.job?.completed_chunks))rememberImportJob(track,data.job);
        item.notice='';
      }catch(e){item.error=(e.message||'Could not pause the import.')+' Refresh status before resuming.';item.notice='';item.needsRefresh=true;}
      finally{item.busy=false;renderImport();if(state.workerEnabled)void pumpImports();}
    };
    $('#sfLyricsImportWorker',target).onclick=()=>{
      if(activePanel()!==panel||(!state.workerEnabled&&!matchingImports(canAdvanceHere).length&&!matchingImports(processingHere).length))return;
      if(state.workerEnabled)pauseWorker();
      else{state.workerEnabled=true;state.workerPaused=false;}
      item.notice=state.workerEnabled?'Worker resumed in this browser.':'Worker stopped in this browser. The current request will finish.';
      renderImport();if(state.workerEnabled)void pumpImports();
    };
    $('#sfLyricsImportRefresh',target).onclick=async()=>{if(activePanel()===panel&&!item.busy){await refreshImport(track);if(state.workerEnabled)void pumpImports();}};
    const review=$('#sfLyricsImportReview',target);if(review)review.onclick=()=>{if(activePanel()===panel)return ctx.openSongTimeline?.(track);};
  }
  async function mountCatalogImport(target,trackId){
    if(!target?.isConnected||!validTrackId(trackId))return false;
    const panel=catalogPanel={target,track:trackId};
    await refreshImport(trackId);
    if(activePanel()!==panel)return false;
    if(!state.workerPaused&&matchingImports(item=>canAdvanceHere(item)||processingHere(item)).length){state.workerEnabled=true;void pumpImports();}
    return true;
  }
  async function pumpImports(){
    if(state.workerBusy||!state.workerEnabled)return;
    state.workerBusy=true;
    try{
      while(state.workerEnabled){
        const item=matchingImports(canAdvanceHere)[0];if(!item)break;
        const job=item.job,track=String(job.track_id);
        item.notice='Transcribing the next approximate audio block…';renderImport();
        try{
          const data=await api('song-lyrics-import.php',{action:'advance',job_id:Number(job.id)});
          const result=data?.job;
          if(!result||Number(result.id)!==Number(job.id))throw new Error('Import status was missing from the response.');
          if(Number(item.job.id)>Number(result.id))continue;
          if(item.job.pause_requested&&result.status==='queued'&&Number(item.job.completed_chunks)>=Number(result.completed_chunks))continue;
          if(!rememberImportJob(track,result))throw new Error('Import status did not match the selected song.');
          if(result.status==='queued'&&(data.advanced===false||Number(result.completed_chunks)<=Number(job.completed_chunks))){
            item.notice='No block was saved. Refresh status before resuming.';item.needsRefresh=true;pauseWorker();break;
          }
          if(result.status==='ready')item.notice='Lyrics saved as unverified timeline drafts.';
          else if(['unknown','failed'].includes(result.status))item.notice='Import paused for administrator review.';
          else if(state.workerEnabled)item.notice='';
        }catch(e){
          item.error=(e.message||'Import request interrupted.')+' Refresh status before resuming; this request will not be retried automatically.';
          item.notice='';item.needsRefresh=true;pauseWorker();break;
        }
        renderImport();
      }
    }finally{
      state.workerBusy=false;
      if(!matchingImports(item=>canAdvanceHere(item)||processingHere(item)).length)state.workerEnabled=false;
      renderImport();scheduleImportPoll();
    }
  }
  function metrics(){const m=state.data?.metrics||{};return '<div class="sf-moment-admin-metrics">'+[
    ['PENDING APPROVAL',m.pending_comments||0],['PUBLIC COMMENTS',m.published_comments||0],
    ['PUBLISHED MOMENTS',m.published_artist_moments||0],['ARTIST DRAFTS',m.draft_artist_moments||0]
  ].map(([label,value])=>'<div><strong>'+Number(value)+'</strong><small>'+label+'</small></div>').join('')+'</div>';}
  function editor(){const entries=artistEntries(),e=entries.find(x=>Number(x.id)===state.editing)||{id:0,track_id:state.track||state.data?.references?.tracks?.[0]?.id||'',at_ms:0,kind:'note',title:'',body:'',media_uuid:'',story_id:0,status:'draft',end_ms:1000,lyric_state:'unverified',confidence:null,timing_source:'manual',revision:0};
    const refs=state.data?.references||{},kind=e.kind||'note';
    const timingLabels={catalog_chunk_estimate:'Imported audio block / approximate timing',provider:'Provider timestamped segment',whole_clip_estimate:'Selected audio section / estimated alignment'};
    const timingOptions=[['manual','Manually timed range']];if(e.id&&Object.hasOwn(timingLabels,e.timing_source))timingOptions.push([e.timing_source,timingLabels[e.timing_source]]);
    return '<form id="sfMomentAdminForm" class="sf-moment-admin-editor" aria-label="Artist song moment editor">'+
      '<div class="sf-moment-admin-editor-title"><strong>'+(e.id?'Edit artist moment #'+Number(e.id):'New artist moment')+'</strong><button type="button" id="sfMomentReset">New moment +</button></div>'+
      '<div class="sf-moment-admin-fields"><label>SONG<select name="track_id" required>'+((refs.tracks||[]).map(x=>opt(x.id,x.title,e.track_id)).join(''))+'</select></label>'+
      '<label>START (MM:SS.MMM)<input name="position" inputmode="decimal" required pattern="[0-9]{1,3}:[0-5][0-9]([.][0-9]{1,3})?" value="'+esc(preciseClock(e.at_ms))+'" placeholder="1:24.000"></label>'+
      '<label>TYPE<select name="kind">'+[['note','Artist note'],['photo','Photo'],['story','Existing story'],['lyric','Lyric segment']].map(x=>opt(x[0],x[1],kind)).join('')+'</select></label>'+
      '<label>STATUS<select name="status">'+[['draft','Draft / not public'],['published','Published / visible']].map(x=>opt(x[0],x[1],e.status||'draft')).join('')+'</select></label>'+
      '<label class="wide">TITLE<input name="title" maxlength="180" value="'+esc(e.title||'')+'" placeholder="What happens here?"></label>'+
      '<label class="wide"><span data-moment-body-label>AGENT DESCRIPTION</span><textarea name="body" maxlength="1600" rows="3" placeholder="A brief context to surface while the song plays">'+esc(e.body||'')+'</textarea></label>'+
      '<label data-moment-lyric>END (MM:SS.MMM)<input name="end_position" inputmode="decimal" pattern="[0-9]{1,3}:[0-5][0-9]([.][0-9]{1,3})?" value="'+esc(preciseClock(e.end_ms||Number(e.at_ms)+1000))+'" placeholder="1:35.000"></label>'+
      '<label data-moment-lyric>LYRIC REVIEW<select name="lyric_state">'+[['unverified','Unverified draft'],['corrected','Corrected / awaiting approval'],['approved','Approved by administrator']].map(x=>opt(x[0],x[1],e.lyric_state||'unverified')).join('')+'</select></label>'+
      '<label data-moment-lyric>TIMING SOURCE<select name="timing_source">'+timingOptions.map(x=>opt(x[0],x[1],e.timing_source||'manual')).join('')+'</select></label>'+
      '<label data-moment-lyric>CONFIDENCE (OPTIONAL)<input name="confidence" type="number" min="0" max="1" step="any" value="'+esc(e.confidence==null?'':String(e.confidence))+'" placeholder="Unknown"><small>Leave blank when confidence is unknown.</small></label>'+
      '<p class="wide sf-moment-admin-lyric-help" data-moment-lyric>Each segment has its own start and end. A selected audio section is an estimated range, not word alignment. Publish only after checking the text and choosing administrator approval. New moment + adds another segment.</p>'+
      '<input name="revision" type="hidden" value="'+Number(e.revision||0)+'">'+
      '<label class="wide" data-moment-photo>PUBLIC MEDIA LIBRARY PHOTO<select name="media_uuid">'+opt('','Choose a public image',e.media_uuid||'')+(refs.photos||[]).map(x=>opt(x.uuid,x.title+' · '+x.uuid.slice(0,8),e.media_uuid)).join('')+'</select><small>Use Media Library to upload and publish photos first.</small></label>'+
      '<label class="wide" data-moment-story>LINK TO A PUBLISHED STORY<select name="story_id">'+opt('','Choose a published story',e.story_id||0)+(refs.stories||[]).map(x=>opt(x.id,x.title+(x.entity_id?' · '+refTitle(x.entity_id):''),e.story_id)).join('')+'</select><small>Reuses the original story; changes in Stories automatically carry through.</small></label></div>'+
      '<div class="sf-moment-admin-actions"><button type="submit" class="primary">'+(e.id?'Save moment':'Add moment')+'</button><span id="sfMomentFormStatus" role="status"></span></div></form>';
  }
  function entryCard(e){const comment=e.kind==='comment',lyric=e.kind==='lyric',kind=comment?'FAN COMMENT':e.kind.toUpperCase();
    const info=comment?'<small>'+esc(e.display_name||'Listener')+' · '+esc(String(e.created_at||'').slice(0,16))+'</small>':'';
    const commands=comment&&e.status==='pending'?'<button data-moment-approve="'+e.id+'" class="primary">Approve</button><button data-moment-reject="'+e.id+'">Reject</button>':
      !comment&&e.status!=='removed'?'<button data-moment-edit="'+e.id+'">Edit</button>':'';
    const remove=e.status!=='removed'?'<button data-moment-remove="'+e.id+'">Remove</button>':'';
    const range=lyric?preciseClock(e.at_ms)+'–'+preciseClock(e.end_ms):clock(e.at_ms);
    const review=lyric?'<small class="sf-moment-admin-lyric-state">'+esc(String(e.lyric_state||'unverified').toUpperCase())+' · '+(['whole_clip_estimate','catalog_chunk_estimate'].includes(e.timing_source)?'APPROXIMATE RANGE':e.timing_source==='provider'?'PROVIDER TIMING':'MANUAL TIMING')+' · '+(e.confidence==null?'CONFIDENCE UNKNOWN':'CONFIDENCE '+Math.round(Number(e.confidence)*100)+'%')+'</small>':'';
    return '<article class="sf-moment-admin-row'+(lyric?' sf-moment-admin-row-lyric':'')+'"><div class="sf-moment-admin-time">'+range+'</div><div class="sf-moment-admin-row-body"><div class="sf-moment-admin-label">'+kind+' · '+esc(refTitle(e.track_id))+'</div><strong>'+esc(e.title||e.body?.slice(0,100)||'Untitled')+'</strong>'+info+'<p>'+esc(e.body||'')+'</p><small class="sf-moment-admin-state status-'+esc(e.status)+'">'+esc(String(e.status||'').toUpperCase())+'</small>'+review+'</div><div class="sf-moment-admin-row-actions">'+commands+remove+'</div></article>';
  }
  function render(){const view=state.view;if(!currentView(view))return;const d=state.data||{entries:[],metrics:{},references:{tracks:[]}},all=artistEntries(),pending=all.filter(e=>e.kind==='comment'&&e.status==='pending'),rows=state.tab==='moderation'?all.filter(e=>e.kind==='comment'):
    all.filter(e=>(!state.track||String(e.track_id)===String(state.track))&&e.kind!=='comment').sort((a,b)=>Number(a.at_ms)-Number(b.at_ms));
    canvas.innerHTML=head('MUSIC / FAN EXPERIENCE','Song Moments','Publish timed lyrics, photos, notes, and stories around the agent orb. Review lyric text and public listener comments before publication.')+
      metrics()+'<div class="sf-moment-admin-tabs"><button data-moment-tab="timeline" class="'+(state.tab==='timeline'?'active':'')+'">Song Timeline Editor</button><button data-moment-tab="moderation" class="'+(state.tab==='moderation'?'active':'')+'">Comment Approval <b>'+pending.length+'</b></button></div>'+
      (state.tab==='timeline'?'<section class="panel">'+editor()+'<div class="sf-moment-admin-list-title"><h2>Artist timeline</h2><select id="sfMomentFilterSong" aria-label="Filter moments by song">'+opt('','All songs',state.track)+(d.references?.tracks||[]).map(x=>opt(x.id,x.title,state.track)).join('')+'</select></div><div class="sf-moment-admin-list">'+(rows.length?rows.map(entryCard).join(''):'<p class="empty">No artist moments for this song yet. Add lyrics, a photo, story or note above.</p>')+'</div><div class="sf-moment-admin-actions"><button type="button" id="sfLyricsApplySong"'+(!state.track||view.applyBusy?' disabled':'')+'>Save approved lyrics to song</button><span id="sfLyricsApplyStatus" role="status">'+(!state.track?'Filter to a song to save its approved lyrics.':'')+'</span></div></section>':
      '<section class="panel"><div class="panel-title"><div><h2>Public comment moderation</h2><p>Fan submissions are pending by default. Only Approved comments appear in the public orb and song timeline.</p></div></div><div class="sf-moment-admin-list">'+(rows.length?rows.map(entryCard).join(''):'<p class="empty">No fan comments awaiting or in review history.</p>')+'</div></section>');
    $$('[data-moment-tab]',canvas).forEach(button=>button.onclick=()=>{if(state.editorDirty&&!confirm('Discard unsaved timeline changes?'))return;state.tab=button.dataset.momentTab;state.editing=0;state.editorDirty=false;render()});
    const filter=$('#sfMomentFilterSong',canvas);if(filter)filter.onchange=()=>{if(state.editorDirty&&!confirm('Discard unsaved timeline changes?')){filter.value=state.track;return;}state.track=filter.value;state.editing=0;state.editorDirty=false;render()};
    const apply=$('#sfLyricsApplySong',canvas);
    if(apply)apply.onclick=async()=>{
      const track=state.track,status=$('#sfLyricsApplyStatus',canvas);
      if(!currentView(view)||!track||view.applyBusy||!confirm('Replace this song’s saved lyrics with its approved, published timeline lyric segments? Existing song lyrics will be overwritten.'))return;
      view.applyBusy=true;apply.disabled=true;status.textContent='Saving approved lyrics to the song…';
      try{await api('song-moments.php',{action:'apply_lyrics',track_id:track,confirmed:true});status.textContent='Approved lyrics saved to the song and Songbook.';}
      catch(e){status.textContent=e.message||'Could not save lyrics to the song.';}
      finally{view.applyBusy=false;if(currentView(view)){const currentButton=$('#sfLyricsApplySong',canvas);if(currentButton)currentButton.disabled=!state.track;}}
    };
    const form=$('#sfMomentAdminForm',canvas);
    if(form){
      form.addEventListener?.('input',()=>{state.editorDirty=true;});form.addEventListener?.('change',()=>{state.editorDirty=true;});
      function showFields(){const kind=form.elements.kind.value,lyric=kind==='lyric';const photo=$('[data-moment-photo]',form),story=$('[data-moment-story]',form);photo.hidden=kind!=='photo';story.hidden=kind!=='story';photo.querySelector('select').disabled=kind!=='photo';story.querySelector('select').disabled=kind!=='story';
        $$('[data-moment-lyric]',form).forEach(field=>{field.hidden=!lyric;field.querySelectorAll('input,select').forEach(input=>input.disabled=!lyric)});
        form.elements.end_position.required=lyric;form.elements.body.required=lyric;
        $('[data-moment-body-label]',form).textContent=lyric?'LYRIC TEXT':'AGENT DESCRIPTION';
        form.elements.body.maxLength=lyric?4200:1600;form.elements.body.placeholder=lyric?'Reviewed lyrics for this time range':'A brief context to surface while the song plays';
      }
      function invalidateLyric(timing=false,text=false){if(form.elements.kind.value!=='lyric')return;if(form.elements.lyric_state.value==='approved'||(text&&state.editing&&form.elements.lyric_state.value==='unverified'))form.elements.lyric_state.value='corrected';if(form.elements.status.value==='published')form.elements.status.value='draft';form.elements.confidence.value='';if(timing)form.elements.timing_source.value='manual';}
      showFields();form.elements.kind.onchange=()=>{showFields();invalidateLyric()};
      form.elements.body.oninput=()=>invalidateLyric(false,true);form.elements.position.oninput=()=>invalidateLyric(true);form.elements.end_position.oninput=()=>invalidateLyric(true);form.elements.track_id.onchange=()=>invalidateLyric(true);
      $('#sfMomentReset',form).onclick=()=>{state.editing=0;render()};
      form.onsubmit=async ev=>{ev.preventDefault();if(state.busy)return;const f=new FormData(form),at=parsePosition(f.get('position'));const status=$('#sfMomentFormStatus',form);
        if(at===null){status.textContent='Enter a time like 1:24 or 1:24.250.';return;}
        const kind=f.get('kind'),publish=f.get('status')==='published';
        const entry={id:state.editing,track_id:f.get('track_id'),at_ms:at,kind,status:f.get('status'),title:f.get('title'),body:f.get('body'),media_uuid:f.get('media_uuid')||'',story_id:Number(f.get('story_id')||0),confirmed:publish,revision:Number(f.get('revision')||0)};
        if(kind==='lyric'){
          const end=parsePosition(f.get('end_position')),track=(state.data?.references?.tracks||[]).find(t=>String(t.id)===String(entry.track_id)),duration=Number(track?.duration_ms)||Number(track?.duration)*1000;
          if(end===null||end<=at||(duration>0&&end>duration)){status.textContent='Choose an end after the start and within the song duration.';return;}
          const confidence=String(f.get('confidence')||'').trim();
          if(confidence!==''&&(!Number.isFinite(Number(confidence))||Number(confidence)<0||Number(confidence)>1)){status.textContent='Confidence must be between 0 and 1, or left blank.';return;}
          if(!String(entry.body||'').trim()){status.textContent='Enter lyric text for this range.';return;}
          entry.end_ms=end;entry.lyric_state=f.get('lyric_state');entry.confidence=confidence===''?null:Number(confidence);entry.timing_source=f.get('timing_source');
          if(publish&&entry.lyric_state!=='approved'){status.textContent='Check the lyric text and choose Approved by administrator before publishing.';return;}
        }
        if(publish&&!confirm(kind==='lyric'?'Publish this reviewed lyric segment to everyone listening to this song?':'Publish this artist moment to everyone listening to this song?'))return;
        state.busy=true;status.textContent='Saving…';try{await api('song-moments.php',{action:'save',entry});if(!await load(view))return;state.editing=0;state.editorDirty=false;say('Song moment saved.');render()}catch(e){status.textContent=e.message}finally{state.busy=false;}
      };
    }
    $$('[data-moment-edit]',canvas).forEach(b=>b.onclick=()=>{state.editing=Number(b.dataset.momentEdit);state.track=all.find(e=>Number(e.id)===state.editing)?.track_id||'';state.tab='timeline';render();$('#sfMomentAdminForm',canvas)?.scrollIntoView({block:'start',behavior:'smooth'})});
    for(const action of ['approve','reject','remove'])$$('[data-moment-'+action+']',canvas).forEach(b=>b.onclick=async()=>{
      if(state.busy)return;const id=Number(b.dataset['moment'+action[0].toUpperCase()+action.slice(1)]);if(!confirm((action==='approve'?'Make this fan comment public?':action==='reject'?'Reject this comment?':'Remove this moment from public display?')+' This decision is logged.'))return;
      state.busy=true;try{await api('song-moments.php',{action,id,confirmed:true});if(!await load(view))return;say('Moment '+action+'d.');render()}catch(e){if(currentView(view))say(e.message)}finally{state.busy=false;}
    });
  }
  async function open(tab='timeline',trackId=''){
    const view=state.view={route:ctx.app?.view,applyBusy:false};
    state.tab=tab;state.track=validTrackId(trackId)?trackId:'';state.editing=0;state.editorDirty=false;
    canvas.innerHTML=head('MUSIC / FAN EXPERIENCE','Song Moments','Loading the song timeline and approval queue…');
    try{
      if(!await load(view))return;
      if(state.track&&!(state.data?.references?.tracks||[]).some(t=>String(t.id)===state.track))state.track='';
      render();
    }catch(e){if(currentView(view))canvas.innerHTML='<section class="panel"><p role="alert">'+esc(e.message)+'</p></section>';}
  }
  async function dashboard(){const target=$('#sfSongMomentsDashboard',canvas);if(!target)return;
    try{const d=await api('song-moments.php'),m=d.metrics||{};if(!target.isConnected)return;
      target.innerHTML='<div class="panel-title"><h2>Song Moments &amp; Fan Approval</h2><button data-view="song-moments" class="panel-link">Open editor →</button></div><div class="dashboard-kpi-stack"><button class="dashboard-kpi" data-view="song-moderation"><small>AWAITING APPROVAL</small><strong>'+Number(m.pending_comments||0)+'</strong><span>fan comments not public</span></button><button class="dashboard-kpi" data-view="song-moments"><small>LIVE TIMELINE</small><strong>'+Number(m.published_artist_moments||0)+'</strong><span>published photos, stories and notes</span></button></div>';
      target.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>ctx.openView(b.dataset.view));
    }catch(e){if(target.isConnected)target.innerHTML='<div class="panel-title"><h2>Song Moments</h2><button data-view="song-moments" class="panel-link">Open editor →</button></div><p class="empty">Moment totals are unavailable.</p>';target.querySelector('button')?.addEventListener('click',()=>ctx.openView('song-moments'));}
  }
  window.SFSongMomentsAdmin=Object.freeze({open,dashboard,mountCatalogImport,resumePublishedTrack});
})();
