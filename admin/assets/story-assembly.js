(() => {
 'use strict';
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const ms=v=>Number.isFinite(Number(v))?Math.round(Number(v)):0;
 let chosen=0,view=null,all=[],candidates=[],proposals=[],storyboard=null,renderCert=null,renderEnabled=false,renderJobs=[],audioMasters=[],renderMix=null,renderTransition=null,renderEdl=null,composerEnabled=false,busy=false,dirty=false;
 let previewMs=0,previewTimer=null,undoEdits=[],redoEdits=[],savedSnapshot='';
 const key=r=>String(r.kind)+':'+String(r.source_id);
 const humanTime=n=>{const s=Math.floor(Math.max(0,n)/1000);return Math.floor(s/60)+':'+String(s%60).padStart(2,'0');};
 function ctx(){return window.STONEFELLOW_ADMIN_CONTEXT;}
 function panel(){return document.getElementById('storyAssemblyHost');}
 function editorCandidates(){return candidates.filter(x=>['scene','transcript','track'].includes(x.kind));}
 function defaultItem(src){return {kind:src.kind,source_id:String(src.source_id),in_ms:ms(src.in_ms),
  out_ms:ms(src.out_ms??src.duration_ms),duration_ms:ms(src.duration_ms)||3500,notes:''};}
 function sourceFor(item){return candidates.find(x=>key(x)===key(item));}
 function warnFor(item){const src=sourceFor(item);return !src?'Source no longer verified / available':
  src.publication_clearance!=='cleared'?(src.kind==='track'?'Confirm synchronization and master-use music licenses':'Publication clearance: '+src.publication_clearance):'';}
 function editSnapshot(){return JSON.stringify(view?.items||[]);}
 function editCheckpoint(){if(!view)return;undoEdits.push(editSnapshot());if(undoEdits.length>40)undoEdits.shift();redoEdits=[];}
 function editSync(){
   dirty=Boolean(view&&editSnapshot()!==savedSnapshot);
   const save=document.getElementById('assemblySave');if(save)save.disabled=!dirty;
   const label=document.getElementById('assemblyDirty');
   if(label)label.textContent=dirty?'Unsaved local edits':'Saved private editorial draft';
   const undo=document.getElementById('assemblyUndo'),redo=document.getElementById('assemblyRedo');
   if(undo)undo.disabled=!undoEdits.length;if(redo)redo.disabled=!redoEdits.length;
 }
 function editRestore(stack,other){
   if(!view||!stack.length)return;other.push(editSnapshot());
   view.items=JSON.parse(stack.pop());dirty=editSnapshot()!==savedSnapshot;draw();
 }
 function draw(){
  if(previewTimer){clearInterval(previewTimer);previewTimer=null;}
  const host=panel(),c=ctx();if(!host||!c)return;
  const project=view,items=project?.items||[],total=items.reduce((sum,x)=>sum+ms(x.duration_ms),0);
  const plan=project?.director||{gaps:[],notes:[]};
  const selected=candidates.map(s=>'<option value="'+esc(key(s))+'">'+esc(
   s.kind.toUpperCase()+' · '+s.label+' · '+humanTime(s.duration_ms||0)+
   (s.publication_clearance!=='cleared'?' · clearance pending':'')
  )+'</option>').join('');
  host.innerHTML='<section class="panel"><div class="panel-title"><div><h2>AI Director · Editorial Assembly</h2>'+
   '<p class="hint">Build a private timeline from verified sources and existing Stonefellow songs. Nothing is rendered or published.</p></div>'+
   '<button id="assemblyRefresh" type="button" class="secondary">Refresh</button></div>'+
   '<div class="form-grid"><label class="field">Saved assembly<select id="assemblySelect"><option value="">New assembly</option>'+
   all.map(a=>'<option value="'+Number(a.id)+'" '+(Number(a.id)===chosen?'selected':'')+'>'+
   esc(a.title+' · rev '+a.revision+' · '+a.item_count+' segments')+'</option>').join('')+'</select></label>'+
   '<label class="field">New story title<input id="assemblyNewTitle" maxlength="180" placeholder="Opening night · episode one"></label></div>'+
   '<label class="field">Story premise (private)<textarea id="assemblyNewBrief" maxlength="1500" rows="2" placeholder="What is the story about?"></textarea></label>'+
   '<button type="button" id="assemblyCreate">Create private assembly</button>'+
   (project?'<div class="assembly-heading"><strong>'+esc(project.title)+'</strong><span>Revision '+Number(project.revision)+' · '+humanTime(total)+' / 60:00 max</span></div>'+
    '<div class="form-grid"><label class="field">Verified source or catalog track<select id="assemblySource">'+selected+'</select></label>'+
    '<button type="button" class="secondary" id="assemblyAdd">Add selected source</button></div>'+
    '<div id="assemblyTimeline" class="assembly-timeline" aria-label="Private editorial timeline">'+
    (items.length?items.map((r,i)=>{
      const src=sourceFor(r);const warning=warnFor(r);const isImage=src?.category==='image';
      return '<article class="assembly-item" data-ordinal="'+i+'"><div class="assembly-item-header">'+
       '<strong>'+esc((i+1)+'. '+r.kind.toUpperCase()+' · '+(src?.label||r.source_id))+'</strong>'+
       '<div class="assembly-actions"><button type="button" data-assembly-up="'+i+'" '+(i===0?'disabled':'')+' aria-label="Move item up">↑</button>'+
       '<button type="button" data-assembly-down="'+i+'" '+(i===items.length-1?'disabled':'')+' aria-label="Move item down">↓</button>'+
       '<button type="button" data-assembly-remove="'+i+'" aria-label="Remove item">Remove</button></div></div>'+
       '<div class="assembly-inputs"><label>Source in (ms)<input type="number" min="0" step="1" data-asm-field="in_ms" data-i="'+i+'" value="'+ms(r.in_ms)+'" '+(isImage?'readonly':'')+'></label>'+
       '<label>Source out (ms)<input type="number" min="0" step="1" data-asm-field="out_ms" data-i="'+i+'" value="'+ms(r.out_ms)+'" '+(isImage?'readonly':'')+'></label>'+
       '<label>Timeline duration (ms)<input type="number" min="500" max="600000" step="100" data-asm-field="duration_ms" data-i="'+i+'" value="'+ms(r.duration_ms)+'"></label></div>'+
       (isImage?'<div class="assembly-trim-hint">Still image: adjust its placement duration above.</div>':
        '<div class="assembly-trim"><button type="button" data-trim-side="in" data-trim-index="'+i+'">Trim IN +100 ms</button>'+
        '<button type="button" data-trim-side="out" data-trim-index="'+i+'">Trim OUT −100 ms</button>'+
        '<small>Non-destructive source edit; saving creates a new revision.</small></div>')+
        '<label>Director notes<input maxlength="600" data-asm-field="notes" data-i="'+i+'" value="'+esc(r.notes||'')+'" placeholder="Why this beat matters"></label>'+
       (warning?'<p class="assembly-warning">'+esc(warning)+'</p>':'')+'</article>';
    }).join(''):'<p class="hint">No segments. Select a verified scene, verified dialogue or catalog track.</p>')+'</div>'+
    '<div class="actions"><button type="button" class="secondary" id="assemblyUndo" '+(!undoEdits.length?'disabled':'')+'>Undo</button>'+
     '<button type="button" class="secondary" id="assemblyRedo" '+(!redoEdits.length?'disabled':'')+'>Redo</button>'+
     '<button type="button" class="primary" id="assemblySave" '+(!dirty?'disabled':'')+'>Save timeline revision</button>'+
    '<span id="assemblyDirty">'+(dirty?'Unsaved local edits':'Saved private editorial draft')+'</span></div>'+
    '<div class="assembly-director"><h3>Director’s proposed coverage</h3>'+
    '<p class="hint">Deterministic editorial suggestions from this assembly, not an LLM inference or finished edit.</p>'+
    (plan.gaps.length?plan.gaps.map(g=>'<div class="assembly-gap"><span><strong>'+esc(g.code.replace(/_/g,' '))+'</strong> · '+esc(g.brief)+
       ' (missing '+Number(g.missing)+')</span><button type="button" data-assembly-shot="'+esc(g.code)+'">Draft shot request</button></div>').join(''):
     '<p>Basic coverage criteria are met. Human storytelling and copyright review are still required.</p>')+
    (plan.notes||[]).map(n=>'<p class="hint">• '+esc(n)+'</p>').join('')+
    '<p class="hint">Publication clearance gaps: '+Number(plan.publication_rights_pending||0)+
      '. Music licensing is separately required. Final video render remains disabled.</p></div>':'<p class="hint">Create an assembly to start your private editorial timeline.</p>')+
    (project&&storyboard?'<section class="assembly-storyboard" aria-label="Private production storyboard">'+
       '<div class="assembly-board-head"><div><h3>Storyboard · timed production preview</h3>'+
       '<p class="hint">Private metadata-only timing simulation. Scene cards are descriptions, not thumbnails or streaming footage.</p></div>'+
       '<span>'+humanTime(renderEdl?.output_total_ms||storyboard.total_ms)+' · revision '+Number(storyboard.revision)+'</span></div>'+
       '<div class="assembly-board-reel">'+
       (storyboard.cards||[]).map((card,i)=>'<article class="assembly-board-card'+(card.source_verified?'':' unverified')+'" data-board-index="'+i+'">'+
        '<div class="assembly-board-art" aria-hidden="true">'+
        (card.kind==='scene'?'🎬':card.kind==='track'?'♫':'🎙')+'</div>'+
        '<div class="assembly-board-details"><strong>'+esc(card.label)+'</strong>'+
        '<small>'+esc(card.kind.toUpperCase())+' · '+humanTime(card.start_ms)+'–'+humanTime(card.end_ms)+
        ' · '+esc(card.transition)+'</small>'+
        (card.transition_note?'<span class="hint">'+esc(card.transition_note)+'</span>':'')+
        (card.notes?'<span>'+esc(card.notes)+'</span>':'')+
        '<span class="hint">'+(card.kind==='track'?'Sync: '+esc(card.sync_clearance)+' · Master: '+esc(card.master_clearance):
            'Publishing: '+esc(card.publication_clearance)+' · Source file: '+(card.asset_present?'present':'unavailable'))+'</span>'+
        '</div></article>').join('')+'</div>'+
       (renderEdl?.clips?.length?'<div class="assembly-edl" aria-label="Synchronized private production timeline">'+
        '<h4>G4C · synchronized production timeline</h4>'+
        '<p class="hint">Exact editorial timings for the saved revision. Overlap, dialogue and licensed music are synchronized metadata, not a public video.</p>'+
        '<p><strong>Final timing: '+humanTime(renderEdl.output_total_ms)+'</strong> · '+renderEdl.output_total_ms+
          ' ms · '+renderEdl.cuts.length+' cuts · '+renderEdl.dialogue_duck_windows.length+' dialogue windows</p>'+
        '<div class="assembly-edl-tracks">'+
          (renderEdl.clips||[]).map(c=>'<div class="assembly-edl-clip"><strong>#'+Number(c.ordinal)+' '+esc(c.kind)+'</strong>'+
            '<span>'+Number(c.output_in_ms)+'–'+Number(c.output_out_ms)+' ms</span></div>').join('')+
          (renderEdl.cuts||[]).map(c=>'<div class="assembly-edl-cut">Transition '+esc(c.style)+
            ' · '+Number(c.start_ms)+'–'+Number(c.end_ms)+' ms</div>').join('')+
          (renderEdl.dialogue_duck_windows||[]).map(w=>'<div class="assembly-edl-duck">Dialogue duck '+
            Number(w.start_ms)+'–'+Number(w.end_ms)+' ms</div>').join('')+
        '</div><p class="hint">Licensed soundtrack: '+(renderEdl.music.enabled?
            Number(renderEdl.music.gain_pct)+'% background · '+Number(renderEdl.music.duck_pct)+'% under dialogue':
            'No approved music bed preset')+'</p></div>':'')+
    '<div class="assembly-preview-controls"><button id="assemblyPreviewPlay" type="button">Play timing</button>'+
       '<button id="assemblyPreviewReset" type="button" class="secondary">Reset</button>'+
       '<label>Virtual playhead<input id="assemblyPreviewRange" type="range" min="0" max="'+Math.max(1,Number(renderEdl?.output_total_ms||storyboard.total_ms))+
       '" step="100" value="'+Math.min(previewMs,Number(renderEdl?.output_total_ms||storyboard.total_ms))+'"></label>'+
       '<output id="assemblyPreviewClock">'+humanTime(previewMs)+' / '+humanTime(renderEdl?.output_total_ms||storyboard.total_ms)+'</output></div>'+
       '<div class="assembly-readiness"><h3>Production readiness</h3>'+
       '<p><strong>'+((storyboard.blockers||[]).length?'Blocked — '+storyboard.blockers.length+' action(s) required':
          'Evidence/rights preflight clear; render worker still unavailable')+'</strong></p>'+
       '<p class="hint">This preflight checks verified metadata, publication and music permissions, and local source-file presence/size. It is NOT a hash-verified render certification. Rendering and publication are disabled.</p>'+
       (storyboard.blockers||[]).map(b=>'<p class="assembly-blocker">⚠ '+esc(b.message)+'</p>').join('')+
       '<details><summary>Production follow-up checks</summary>'+
       (storyboard.warnings||[]).map(w=>'<p class="hint">• '+esc(w.message)+'</p>').join('')+
       '<p class="hint">A future render worker must rehash source files, verify music masters and enforce release rights at execution time.</p></details>'+
       '<div class="assembly-license"><h4>Human music-license review</h4>'+
       '<p class="hint">Record synchronization and master-use permissions separately for each Stonefellow track on this saved revision. A new revision invalidates previous decisions.</p>'+
       '<label>Track<select id="assemblyLicenseTrack">'+
       (storyboard.cards||[]).filter(card=>card.kind==='track').map(card=>'<option value="'+esc(card.source_id)+'">'+esc(card.label)+'</option>').join('')+
       '</select></label><label>License purpose<select id="assemblyLicenseType"><option value="sync">Synchronization</option>'+
       '<option value="master">Master recording</option></select></label>'+
       '<label>Decision<select id="assemblyLicenseDecision"><option value="restricted">Restricted/revoked</option>'+
       '<option value="cleared">Cleared after review</option></select></label>'+
       '<label>Review evidence<textarea id="assemblyLicenseBasis" rows="2" minlength="25" maxlength="1000" placeholder="Record independently checked agreement or restriction reference."></textarea></label>'+
       '<button type="button" id="assemblyLicenseSave" '+(!(storyboard.cards||[]).some(x=>x.kind==='track')?'disabled':'')+'>Record scoped license decision</button>'+
       '</div></div></section>':'')+
    (project?'<div class="assembly-composer"><h3>AI Writer / Director · Story Proposals</h3>'+
       '<p class="hint">Supervised proposal from short editorial metadata only (title, premise, source IDs and verified summaries). No raw media or transcript text leaves the server. A configured paid provider may incur cost; rights clearance is required for all captured sources.</p>'+
       '<div class="actions"><button type="button" class="secondary" id="composerToggle">'+(composerEnabled?'Disable story composer':'Enable story composer')+'</button>'+
       '<button type="button" class="primary" id="composerRequest" '+(!composerEnabled||dirty||items.length<2?'disabled':'')+'>Request AI composition</button></div>'+
       '<p class="hint">The server CLI must separately process queued proposals. No model call starts from this page. An editor then reviews and explicitly applies any reordering.</p>'+
       (proposals.length?proposals.map(p=>{
          const q=p.suggestion||{},order=(q.order||[]).map(v=>Number(v)+1).join(' → ');
          return '<article class="assembly-proposal"><strong>Proposal #'+Number(p.id)+' · revision '+Number(p.revision)+' · '+esc(p.status)+'</strong>'+
           (p.provider?'<p class="hint">Provider: '+esc(p.provider)+' / '+esc(p.model)+'</p>':'')+
           (q.logline?'<p>'+esc(q.logline)+'</p><p class="hint">Proposed positions: '+esc(order)+'</p>'+
           '<ol>'+((q.beats||[]).map(b=>'<li>'+esc(b.reason)+' · '+esc(b.transition)+(Number(b.trim_start_ms||0)+Number(b.trim_end_ms||0)>0?' · trim IN +'+Number(b.trim_start_ms||0)+' / OUT −'+Number(b.trim_end_ms||0)+' ms':'')+'</li>').join(''))+'</ol>'+
           ((q.missing_shots||[]).length?'<p class="hint">Missing shots: '+esc(q.missing_shots.join('; '))+'</p>':''):'')+
           (p.status==='pending_review'?'<button type="button" data-composer-review="approved" data-proposal-id="'+Number(p.id)+'">Approve proposal</button>'+
             '<button type="button" class="secondary" data-composer-review="rejected" data-proposal-id="'+Number(p.id)+'">Reject</button>':'')+
           (p.status==='approved'?'<button type="button" class="primary" data-composer-apply="'+Number(p.id)+'" '+(Number(p.revision)!==Number(project.revision)||dirty?'disabled':'')+'>Apply approved ordering</button>':'')+
          ((['approved','applied'].includes(p.status)&&
             (p.status==='applied'?Number(p.applied_revision)===Number(project.revision):
                Number(p.revision)===Number(project.revision)&&
                (q.order||[]).every((v,i)=>Number(v)===i))&&
              (q.beats||[]).some(x=>Number(x.trim_start_ms||0)+Number(x.trim_end_ms||0)>0))?
            '<button type="button" class="secondary" data-composer-refine="'+Number(p.id)+'" '+(dirty?'disabled':'')+'>Stage AI trim ideas</button>':'')+
          '</article>';
        }).join(''):'<p class="hint">No AI composition proposals for this assembly.</p>')+
       '</div>':'')+
    (project&&storyboard?'<section class="assembly-render"><h3>Private 720p render · supervised pilot</h3>'+
      '<p class="hint">Real MP4 generation runs only via a server CLI operator. No public link, preview player, download or publication endpoint is active. Initial pilot uses hard cuts and accepts 1–12 clips, up to 60 seconds.</p>'+
      '<p><strong>SHA-256 source certification: '+(renderCert?.eligible?'Eligible':'Blocked')+'</strong></p>'+
      '<p class="hint">'+esc(renderCert?.eligible?'Source bytes, independent rights and song-master bindings are verified.':'Current rights, original capture evidence, supported media or revision-bound music masters need verification.')+'</p>'+
      '<div class="assembly-master-picker"><label>Stonefellow track<select id="renderTrack">'+
        (storyboard.cards||[]).filter(x=>x.kind==='track').map(x=>'<option value="'+esc(x.source_id)+'">'+esc(x.label)+'</option>').join('')+'</select></label>'+
      '<label>Verified Media Library audio master<select id="renderMaster">'+
        audioMasters.map(x=>'<option value="'+Number(x.id)+'">#'+Number(x.id)+' · '+esc(x.title||x.original_name||'Audio')+' · '+humanTime(Number(x.duration_seconds||0)*1000)+'</option>').join('')+'</select></label>'+
      '<button type="button" class="secondary" id="renderBindMaster" '+(dirty||!audioMasters.length?'disabled':'')+'>Bind private song master to current revision</button></div>'+
      '<fieldset class="assembly-mix-preset"><legend>Licensed music bed · dialogue ducking</legend>'+
       '<p class="hint">Optional private MP4 mix. The selected song must already be in the timeline with both cleared use licenses and a verified audio master. Presets lock when a render is queued for this revision.</p>'+
       '<label>Soundtrack<select id="mixTrack">'+
        (storyboard.cards||[]).filter(x=>x.kind==='track').map(card=>'<option value="'+esc(card.source_id)+'" '+(renderMix?.track_id===card.source_id?'selected':'')+'>'+esc(card.label)+'</option>').join('')+'</select></label>'+
       '<label>Background music level<select id="mixGain">'+
        [10,15,20,25,30].map(n=>'<option value="'+n+'" '+((renderMix?.gain_pct??20)===n?'selected':'')+'>'+n+'%</option>').join('')+'</select></label>'+
       '<label>Music level during dialogue<select id="mixDuck">'+
        [25,35,50,65].map(n=>'<option value="'+n+'" '+((renderMix?.duck_pct??35)===n?'selected':'')+'>'+n+'% of music level</option>').join('')+'</select></label>'+
       '<label>Start/end fade<select id="mixFade">'+
        [250,500,1000].map(n=>'<option value="'+n+'" '+((renderMix?.fade_ms??500)===n?'selected':'')+'>'+n+' ms</option>').join('')+'</select></label>'+
       '<button type="button" class="secondary" id="mixSave" '+(dirty||renderJobs.some(j=>Number(j.revision)===Number(project.revision))?'disabled':'')+'>Save music mix for this revision</button>'+
       (renderMix?'<p class="hint">Current revision preset: '+esc(renderMix.track_id)+' · '+Number(renderMix.gain_pct)+'% music · '+Number(renderMix.duck_pct)+'% dialogue duck · '+Number(renderMix.fade_ms)+'ms fades</p>':'<p class="hint">No music overlay preset. Existing hard-cut output remains the default.</p>')+
       '</fieldset>'+
      '<fieldset class="assembly-transition"><legend>Video & audio crossfades</legend>'+
       '<p class="hint">Optional short-clip pilot (2–6 shots, 30 seconds maximum). Applies real overlapping video transitions and matched audio crossfades; output duration is shortened by each overlap. Saved settings lock after a render is queued.</p>'+
       '<label>Visual transition<select id="transitionStyle">'+
       ['fade','wipeleft'].map(v=>'<option value="'+v+'" '+((renderTransition?.style??'fade')===v?'selected':'')+'>'+esc(v==='fade'?'Dissolve':'Wipe left')+'</option>').join('')+'</select></label>'+
       '<label>Overlap duration<select id="transitionOverlap">'+
       [250,500].map(n=>'<option value="'+n+'" '+((renderTransition?.overlap_ms??250)===n?'selected':'')+'>'+n+' ms</option>').join('')+'</select></label>'+
       '<button type="button" class="secondary" id="transitionSave" '+(dirty||renderJobs.some(j=>Number(j.revision)===Number(project.revision))?'disabled':'')+'>Save crossfades for this revision</button>'+
       (renderTransition?'<p class="hint">Current revision: '+esc(renderTransition.style)+' · '+Number(renderTransition.overlap_ms)+' ms between each clip</p>':'<p class="hint">No crossfade preset: current hard cuts remain unchanged.</p>')+
       (renderTransition&&items.length>=2&&items.length<=6?
         '<div class="assembly-per-cut"><strong>G4E · edit each video/audio transition</strong>'+
         '<p class="hint">Individual cut styles and overlaps. Only saved revisions; locked after a render is queued. The existing global settings remain the fallback.</p>'+
         items.slice(1).map((item,i)=>{
           const cut=renderTransition.cuts?.[i]||renderTransition;
           return '<div class="assembly-cut-row" data-cut-ordinal="'+(i+1)+'"><span>Cut '+(i+1)+' → '+(i+2)+'</span>'+
             '<label>Effect<select data-cut-style="'+i+'">'+
             ['fade','wipeleft'].map(v=>'<option value="'+v+'" '+(cut.style===v?'selected':'')+'>'+esc(v==='fade'?'Dissolve':'Wipe left')+'</option>').join('')+
             '</select></label><label>Overlap<select data-cut-overlap="'+i+'">'+
             [250,500].map(n=>'<option value="'+n+'" '+(Number(cut.overlap_ms)===n?'selected':'')+'>'+n+' ms</option>').join('')+
             '</select></label></div>';
         }).join('')+
         '<button type="button" id="cutSave" class="secondary" '+(dirty||renderJobs.some(j=>Number(j.revision)===Number(project.revision))?'disabled':'')+'>Save individually approved cuts</button>'+
         '</div>':'')+

       '</fieldset>'+
      '<div class="actions"><button type="button" class="secondary" id="renderToggle">'+(renderEnabled?'Disable private renderer':'Enable private renderer')+'</button>'+
      '<button type="button" class="primary" id="renderQueue" '+(!renderEnabled||!renderCert?.eligible||dirty?'disabled':'')+'>Queue private render</button></div>'+
      '<p class="hint">Execute one approved job on the server: php scripts/artist-world-private-render.php --editor-id=EDITOR_ID. Outputs remain outside the web root, awaiting a separate video review phase.</p>'+
      (renderJobs.length?renderJobs.map(j=>'<article class="assembly-render-job"><p class="hint"><strong>Private render #'+Number(j.id)+'</strong> · revision '+Number(j.revision)+' · '+esc(j.status)+
        ' · '+Number(j.output_bytes||0)+' bytes</p>'+
        (['private_review','approved_private'].includes(j.status)&&Number(j.revision)===Number(project.revision)?
         '<video controls preload="metadata" controlsList="nodownload noplaybackrate" disablepictureinpicture playsinline class="assembly-video-private" data-private-review-video="'+Number(j.id)+'" src="api/story-render-review.php?action=stream&amp;job_id='+Number(j.id)+'" aria-label="Private reviewed video #'+Number(j.id)+'"></video>'+
         '<p class="hint">Authenticated private reviewer playback. No public or unguarded video URL.</p>'+
         '<div class="assembly-qc"><button type="button" data-load-review-cues="'+Number(j.id)+'" '+(dirty?'disabled':'')+'>Load verified QC cues</button>'+
         '<div data-review-cue-host="'+Number(j.id)+'" aria-live="polite"></div></div>':'')+
        (j.status==='private_review'?'<label>Human editorial review basis<textarea rows="2" minlength="25" maxlength="1000" data-render-basis="'+Number(j.id)+'" placeholder="Record what you checked in the video and any restrictions."></textarea></label>'+
         '<div class="actions"><button type="button" data-render-review="approved_private" data-render-id="'+Number(j.id)+'">Approve private draft</button>'+
         '<button type="button" class="secondary" data-render-review="rejected" data-render-id="'+Number(j.id)+'">Reject private draft</button></div>':'')+
        '</article>').join(''):'<p class="hint">No private render jobs for this assembly.</p>')+
      '</section>':'')+
    '<p id="assemblyStatus" role="status" aria-live="polite"></p></section>';
  bind(host);
 }
 function note(msg,error=false){const p=document.getElementById('assemblyStatus');if(p){p.textContent=msg;p.classList.toggle('error',error);}}
 function changed(){dirty=true;const b=document.getElementById('assemblySave');if(b)b.disabled=false;const status=document.getElementById('assemblyDirty');if(status)status.textContent='Unsaved local edits';}
 function persistBody(){return (view?.items||[]).map(x=>({
   kind:x.kind,source_id:String(x.source_id),in_ms:ms(x.in_ms),out_ms:ms(x.out_ms),
   duration_ms:ms(x.duration_ms),notes:String(x.notes||'')
 }));}
 function bind(host){
  const c=ctx();
  host.querySelector('#assemblyRefresh').onclick=()=>{
   if(dirty&&!window.confirm('Discard unsaved timeline edits and reload?'))return;
   dirty=false;void load();
  };
  host.querySelector('#assemblySelect').onchange=e=>{
   if(dirty&&!window.confirm('Discard unsaved edits and switch assemblies?')){e.target.value=String(chosen);return;}
   chosen=Number(e.target.value)||0;dirty=false;void load();
  };
  host.querySelector('#assemblyCreate').onclick=async ()=>{
    const title=host.querySelector('#assemblyNewTitle').value.trim();
    if(title.length<3){note('Enter a private story title with at least three characters.',true);return;}
    if(busy)return;busy=true;
    try{const r=await c.api('story-assembly.php',{action:'create',title,brief:host.querySelector('#assemblyNewBrief').value});
      chosen=Number(r.assembly_id);dirty=false;await load();}
    catch(e){note(e.message,true);}finally{busy=false;}
  };
  if(!view)return;
  host.querySelector('#assemblyUndo').onclick=()=>editRestore(undoEdits,redoEdits);
  host.querySelector('#assemblyRedo').onclick=()=>editRestore(redoEdits,undoEdits);
  host.querySelector('#assemblyAdd').onclick=()=>{
   const v=host.querySelector('#assemblySource')?.value;
   const src=editorCandidates().find(x=>key(x)===v);
   if(!src){note('Select a verified source.',true);return;}
   if(view.items.length>=50){note('Maximum 50 editorial items.',true);return;}
   editCheckpoint();view.items.push(defaultItem(src));changed();draw();
  };
  host.querySelectorAll('[data-asm-field]').forEach(input=>input.onchange=()=>{
   const i=Number(input.dataset.i),field=input.dataset.asmField;
   if(!view.items[i])return;
   editCheckpoint();view.items[i][field]=field==='notes'?input.value:ms(input.value);changed();editSync();
  });
  host.querySelectorAll('[data-assembly-up],[data-assembly-down],[data-assembly-remove]').forEach(b=>b.onclick=()=>{
   const type=b.hasAttribute('data-assembly-remove')?'remove':(b.hasAttribute('data-assembly-up')?'up':'down');
   const i=Number(b.dataset[type==='remove'?'assemblyRemove':(type==='up'?'assemblyUp':'assemblyDown')]);
   if(type==='remove'){editCheckpoint();view.items.splice(i,1);}else{
    const j=i+(type==='up'?-1:1);if(j<0||j>=view.items.length)return;
    editCheckpoint();[view.items[i],view.items[j]]=[view.items[j],view.items[i]];
   }
   changed();draw();
  });
  host.querySelectorAll('[data-trim-side]').forEach(button=>button.onclick=()=>{
    const i=Number(button.dataset.trimIndex),side=button.dataset.trimSide;
    const item=view.items[i],src=sourceFor(item);
    if(!item||!src||src.category==='image'||!['in','out'].includes(side))return;
    const start=Number(item.in_ms),end=Number(item.out_ms);
    if(!Number.isSafeInteger(start)||!Number.isSafeInteger(end)||end-start<600){
      note('Trimming would remove the required 500 ms minimum.',true);return;
    }
    editCheckpoint();
    if(side==='in')item.in_ms=start+100;else item.out_ms=end-100;
    item.duration_ms=item.out_ms-item.in_ms;changed();draw();
  });
  host.querySelector('#assemblySave').onclick=async ()=>{
   if(!dirty||busy)return;busy=true;
   try{
    const r=await c.api('story-assembly.php',{action:'replace',assembly_id:Number(view.id),
      revision:Number(view.revision),items:persistBody()});
    dirty=false;await load();note('Saved revision '+r.result.revision+'; no source was published.');
   }catch(e){note('Save failed: '+(e.message||'unknown error')+'. Refresh to reconcile if another editor updated the timeline.',true);}
   finally{busy=false;}
  };
  const range=host.querySelector('#assemblyPreviewRange');
  const playback=host.querySelector('#assemblyPreviewPlay');
  function refreshPlayhead(){
    if(!storyboard||!range)return;
    const endMs=Number(renderEdl?.output_total_ms||storyboard.total_ms);
    previewMs=Math.max(0,Math.min(endMs,previewMs));
    range.value=String(previewMs);
    const clock=host.querySelector('#assemblyPreviewClock');
    if(clock)clock.textContent=humanTime(previewMs)+' / '+humanTime(endMs);
    host.querySelectorAll('[data-board-index]').forEach(node=>{
      const i=Number(node.dataset.boardIndex),clip=renderEdl?.clips?.[i],card=storyboard.cards[i];
      const start=clip?clip.output_in_ms:card?.start_ms,finish=clip?clip.output_out_ms:card?.end_ms;
      node.classList.toggle('playing',Number.isFinite(start)&&Number.isFinite(finish)&&
        previewMs>=start&&previewMs<finish);
    });
    if(previewMs>=endMs&&previewTimer){
      clearInterval(previewTimer);previewTimer=null;if(playback)playback.textContent='Play timing';
    }
  }
  if(range){
    range.oninput=()=>{previewMs=Number(range.value);refreshPlayhead();};
    playback.onclick=()=>{
      if(previewTimer){clearInterval(previewTimer);previewTimer=null;playback.textContent='Play timing';return;}
      if(previewMs>=Number(renderEdl?.output_total_ms||storyboard.total_ms))previewMs=0;
      let lastTick=Date.now();
      previewTimer=setInterval(()=>{
        const now=Date.now();previewMs+=Math.max(0,Math.min(1000,now-lastTick));lastTick=now;
        refreshPlayhead();
      },100);
      playback.textContent='Pause timing';refreshPlayhead();
    };
    host.querySelector('#assemblyPreviewReset').onclick=()=>{
      if(previewTimer){clearInterval(previewTimer);previewTimer=null;}previewMs=0;
      playback.textContent='Play timing';refreshPlayhead();
    };
    refreshPlayhead();
  }
  const license=host.querySelector('#assemblyLicenseSave');
  if(license)license.onclick=async()=>{
    const trackId=host.querySelector('#assemblyLicenseTrack')?.value||'';
    const purpose=host.querySelector('#assemblyLicenseType')?.value||'';
    const decision=host.querySelector('#assemblyLicenseDecision')?.value||'';
    const basis=host.querySelector('#assemblyLicenseBasis')?.value.trim()||'';
    if(basis.length<25){note('Document independently reviewed license evidence or restriction (25+ characters).',true);return;}
    if(!trackId||dirty||busy){note('Save the timeline and choose an existing music cue first.',true);return;}
    if(!window.confirm('Record '+decision+' for '+purpose+' license at assembly revision '+view.revision+'?'))return;
    busy=true;try{
      await c.api('story-assembly.php',{action:'review_music_rights',assembly_id:Number(view.id),
        revision:Number(view.revision),track_id:trackId,license_type:purpose,decision,basis});
      await load();note('Scoped music-license decision recorded for this exact revision.');
    }catch(e){note(e.message,true);}finally{busy=false;}
  };
  const bindMaster=host.querySelector('#renderBindMaster');
  if(bindMaster)bindMaster.onclick=async()=>{
    if(busy||dirty)return;
    const trackId=host.querySelector('#renderTrack')?.value||'';
    const assetId=Number(host.querySelector('#renderMaster')?.value)||0;
    if(!trackId||!assetId){note('Choose an existing Stonefellow song and an audio master.',true);return;}
    if(!window.confirm('Bind this private Media Library audio master to the saved story revision? Only verified source bytes will be accepted.'))return;
    busy=true;try{await c.api('story-assembly.php',{action:'bind_render_master',
      assembly_id:Number(view.id),revision:Number(view.revision),track_id:trackId,asset_id:assetId});
      await load();note('Private track master bound and source certificate refreshed.');
    }catch(e){note(e.message,true);}finally{busy=false;}
  };
  const renderToggle=host.querySelector('#renderToggle');
  if(renderToggle)renderToggle.onclick=async()=>{
    if(busy||!window.confirm(renderEnabled?'Disable supervised private render execution?':
      'Enable paid compute/private FFmpeg execution? Rendering still requires an operator-run CLI and a separately approved output review.'))return;
    busy=true;try{await c.api('story-assembly.php',{action:'configure_render',enabled:!renderEnabled});
      await load();note('Private render execution policy updated.');
    }catch(e){note(e.message,true);}finally{busy=false;}
  };
  host.querySelectorAll('[data-render-review]').forEach(button=>button.onclick=async()=>{
    if(busy||dirty)return;
    const jobId=Number(button.dataset.renderId),decision=button.dataset.renderReview;
    const basis=host.querySelector('[data-render-basis="'+jobId+'"]')?.value.trim()||'';
    if(basis.length<25){note('Record an independent video review basis (at least 25 characters).',true);return;}
    if(!window.confirm('Record '+decision+' for this private video? This never publishes or releases the MP4.'))return;
    busy=true;try{
      const answer=await c.api('story-render-review.php',{action:'review',job_id:jobId,decision,basis});
      await load();note(answer.changed?'Private video review recorded. Publication remains disabled.':'Video was already reviewed.');
    }catch(e){note(e.message||'Review unavailable.',true);}finally{busy=false;}
  });
  host.querySelectorAll('[data-load-review-cues]').forEach(button=>button.onclick=async()=>{
    if(busy||dirty)return;
    const jobId=Number(button.dataset.loadReviewCues);
    const area=host.querySelector('[data-review-cue-host="'+jobId+'"]');
    const video=host.querySelector('[data-private-review-video="'+jobId+'"]');
    if(!area||!video)return;
    button.disabled=true;area.textContent='Verifying private output and source rights…';
    try{
      const r=await c.api('story-render-review.php?action=cues&job_id='+encodeURIComponent(jobId));
      const qc=r.review;
      if(!qc||Number(qc.job_id)!==jobId||qc.private_only!==true||
         !Array.isArray(qc.cues)||qc.cues.length>40||
         !Number.isSafeInteger(qc.duration_ms)||qc.duration_ms<1000||qc.duration_ms>60000)
        throw new Error('Invalid authenticated cue sheet.');
      const cues=qc.cues;
      for(const cue of cues){
        if(!['clip','transition','dialogue'].includes(cue.type)||
           !Number.isSafeInteger(cue.start_ms)||!Number.isSafeInteger(cue.end_ms)||
           cue.start_ms<0||cue.end_ms>qc.duration_ms||cue.end_ms<cue.start_ms)
          throw new Error('Invalid authenticated cue.');
      }
      area.innerHTML='<p class="hint">Verified '+humanTime(qc.duration_ms)+
        ' private cut · select a marker to seek inside the authenticated player.</p>'+
        '<div class="assembly-qc-list">'+cues.map((cue,i)=>
          '<button type="button" data-qc-seek="'+i+'" class="assembly-qc-cue" aria-label="Seek to cue '+(i+1)+'">'+
          '<strong>'+esc(cue.type)+' · '+esc(cue.label)+'</strong><span>'+
          humanTime(cue.start_ms)+' – '+humanTime(cue.end_ms)+'</span></button>'
        ).join('')+'</div><output class="assembly-qc-clock" aria-live="off">0:00</output>';
      area.querySelectorAll('[data-qc-seek]').forEach(b=>b.onclick=()=>{
        const cue=cues[Number(b.dataset.qcSeek)];
        if(!cue)return;
        const seek=()=>{
          const cap=Number.isFinite(video.duration)&&video.duration>0?
            Math.max(0,video.duration-0.04):qc.duration_ms/1000;
          video.currentTime=Math.min(cue.start_ms/1000,cap);
          video.focus();
        };
        if(video.readyState>=1)seek();else video.addEventListener('loadedmetadata',seek,{once:true});
      });
      video.addEventListener('timeupdate',()=>{
        const ms=Math.max(0,video.currentTime*1000);
        const clock=area.querySelector('.assembly-qc-clock');
        if(clock)clock.textContent=humanTime(ms);
        area.querySelectorAll('[data-qc-seek]').forEach(b=>{
          const cue=cues[Number(b.dataset.qcSeek)];
          b.classList.toggle('active',Boolean(cue&&ms>=cue.start_ms&&
            ms<(cue.end_ms===cue.start_ms?cue.end_ms+100:cue.end_ms)));
        });
      });
      button.hidden=true;
    }catch(e){area.textContent='Private QC cues unavailable: '+(e.message||'verification failed');button.disabled=false;}
  });
  const mixSave=host.querySelector('#mixSave');
  if(mixSave)mixSave.onclick=async()=>{
   if(busy||dirty)return;
   const track=host.querySelector('#mixTrack')?.value||'';
   if(!track){note('Choose a licensed Stonefellow song already on the timeline.',true);return;}
   if(!window.confirm('Apply a revision-locked, licensed soundtrack mix to the next private MP4? This does not publish the film.'))return;
   busy=true;try{
    await c.api('story-assembly.php',{action:'configure_mix',assembly_id:Number(view.id),
     revision:Number(view.revision),track_id:track,
     gain_pct:Number(host.querySelector('#mixGain').value),
     duck_pct:Number(host.querySelector('#mixDuck').value),
     fade_ms:Number(host.querySelector('#mixFade').value)});
    await load();note('Licensed music bed preset saved. Queue a new private render to apply it.');
   }catch(e){note(e.message,true);}finally{busy=false;}
  };
  const transitionSave=host.querySelector('#transitionSave');
  if(transitionSave)transitionSave.onclick=async()=>{
    if(busy||dirty)return;
    if(!window.confirm('Configure the next supervised private render to use real video and audio crossfades? Any existing music bed is kept, with corrected timing.'))return;
    busy=true;try{
     await c.api('story-assembly.php',{action:'configure_transition',
       assembly_id:Number(view.id),revision:Number(view.revision),
       style:host.querySelector('#transitionStyle').value,
       overlap_ms:Number(host.querySelector('#transitionOverlap').value)});
     await load();note('Revision-scoped audio and video crossfades saved.');
    }catch(e){note(e.message,true);}finally{busy=false;}
  };
  const cutSave=host.querySelector('#cutSave');
  if(cutSave)cutSave.onclick=async()=>{
   if(busy||dirty||!renderTransition)return;
   const count=Math.max(0,(view.items||[]).length-1),cuts=[];
   for(let i=0;i<count;i++){
    const style=host.querySelector('[data-cut-style="'+i+'"]')?.value;
    const overlap=Number(host.querySelector('[data-cut-overlap="'+i+'"]')?.value);
    if(!['fade','wipeleft'].includes(style)||![250,500].includes(overlap)){
     note('Use only approved transition effects and overlaps.',true);return;
    }
    cuts.push({style,overlap_ms:overlap});
   }
   if(!window.confirm('Save these individual video and audio cuts for this exact private revision? Existing queued jobs cannot be changed.'))return;
   busy=true;try{
    await c.api('story-assembly.php',{action:'configure_cuts',assembly_id:Number(view.id),revision:Number(view.revision),cuts});
    await load();note('Per-cut visual and audio overlaps saved for the certified revision.');
   }catch(e){note('Cut preset was not saved: '+(e.message||'verification failed'),true);}finally{busy=false;}
  };
  const renderQueue=host.querySelector('#renderQueue');
  if(renderQueue)renderQueue.onclick=async()=>{
    if(busy||dirty||!renderCert?.eligible)return;
    if(!window.confirm('Queue private 720p cut-only render for this exact certified revision? Publication stays disabled.'))return;
    busy=true;try{const r=await c.api('story-assembly.php',{action:'enqueue_render',
       assembly_id:Number(view.id),revision:Number(view.revision)});
      await load();note('Private render job #'+r.job.job_id+' '+(r.job.existing?'already exists':'queued')+'.');
    }catch(e){note(e.message,true);}finally{busy=false;}
  };
  host.querySelector('#composerToggle').onclick=async ()=>{
   if(busy)return;
   if(!window.confirm(composerEnabled?'Disable AI composition?':
    'Enable paid, metadata-only AI composition? Approved editorial source summaries and premise may be sent to your configured provider. This does not execute a worker or publish anything.'))return;
   busy=true;try{await c.api('story-assembly.php',{action:'configure_composer',enabled:!composerEnabled});
     await load();note('Composer permission updated.');}catch(e){note(e.message,true);}finally{busy=false;}
  };
  host.querySelector('#composerRequest').onclick=async ()=>{
   if(busy||dirty||!composerEnabled)return;
   if(!window.confirm('Queue a paid composition proposal for this exact private timeline revision? Each source must be cleared for external AI.'))return;
   busy=true;try{const r=await c.api('story-assembly.php',{action:'compose',
      assembly_id:Number(view.id),revision:Number(view.revision)});
     await load();note('Proposal #'+r.proposal.id+' '+(r.proposal.existing?'already exists':'queued')+
       '. Run the supervised server-side CLI to request a model suggestion.');}
   catch(e){note(e.message,true);}finally{busy=false;}
  };
  host.querySelectorAll('[data-composer-review]').forEach(b=>b.onclick=async ()=>{
   if(busy)return;const choice=b.dataset.composerReview;
   if(!window.confirm('Record editorial '+choice+' decision? This alone will not change the timeline.'))return;
   busy=true;try{await c.api('story-assembly.php',{action:'review_composer',
       proposal_id:Number(b.dataset.proposalId),decision:choice});
     await load();note('Proposal review recorded; timeline unchanged.');}
   catch(e){note(e.message,true);}finally{busy=false;}
  });
  host.querySelectorAll('[data-composer-apply]').forEach(b=>b.onclick=async ()=>{
   if(busy||dirty)return;
   if(!window.confirm('Apply ONLY the existing verified source ordering? No clips, times, or music rights will be changed or published.'))return;
   busy=true;try{await c.api('story-assembly.php',{action:'apply_composer',
      proposal_id:Number(b.dataset.composerApply)});
     await load();note('Approved ordering applied to a new private timeline revision.');}
   catch(e){note(e.message,true);}finally{busy=false;}
  });
  host.querySelectorAll('[data-composer-refine]').forEach(button=>button.onclick=()=>{
    if(busy||dirty)return;
    const proposal=proposals.find(p=>Number(p.id)===Number(button.dataset.composerRefine));
    const q=proposal?.suggestion,beats=q?.beats,order=q?.order;
    const eligible=proposal&&(proposal.status==='applied'?
      Number(proposal.applied_revision)===Number(view.revision):
      proposal.status==='approved'&&Number(proposal.revision)===Number(view.revision)&&
        Array.isArray(order)&&order.length===view.items.length&&order.every((v,i)=>Number(v)===i));
    if(!eligible||!Array.isArray(beats)||beats.length!==view.items.length){
      note('Approved AI refinement no longer matches this saved revision.',true);return;
    }
    const next=JSON.parse(editSnapshot());let applied=0;
    for(let i=0;i<next.length;i++){
      const item=next[i],beat=beats[i],source=sourceFor(item);
      const start=Number(beat?.trim_start_ms??0),end=Number(beat?.trim_end_ms??0);
      if(!Number.isSafeInteger(start)||!Number.isSafeInteger(end)||
         start<0||end<0||start>500||end>500||start%100!==0||end%100!==0||
         start+end>Math.max(0,Number(item.duration_ms)-500)){
        note('AI trim is outside safe editorial limits.',true);return;
      }
      if(start+end===0||source?.category==='image')continue;
      const inbound=Number(item.in_ms),outbound=Number(item.out_ms);
      if(!Number.isSafeInteger(inbound)||!Number.isSafeInteger(outbound)||
         outbound-inbound<start+end+500)continue;
      item.in_ms=inbound+start;item.out_ms=outbound-end;
      item.duration_ms=item.out_ms-item.in_ms;applied++;
    }
    if(!applied){note('No verified media clips can use these AI trim ideas.',true);return;}
    if(!window.confirm('Stage '+applied+' AI trim refinements locally? Nothing will be saved, published or rendered. Review every change and save a new private revision explicitly.'))return;
    editCheckpoint();view.items=next;changed();draw();
    note('Staged '+applied+' suggestions. Review the source times and save when ready.');
  });
  host.querySelectorAll('[data-assembly-shot]').forEach(b=>b.onclick=async ()=>{
   const code=b.dataset.assemblyShot,g=(view.director?.gaps||[]).find(x=>x.code===code);
   if(!g||!window.confirm('Create an UNPUBLISHED suggested shot request for '+view.title+'?'))return;
   if(busy)return;busy=true;
   try{const r=await c.api('story-capture.php',{action:'create_request',
     title:'Assembly #'+view.id+': '+code.replace(/_/g,' '),
     brief:g.brief,shot_type:g.shot_type,target_role:g.target_role,
     world_context:'Private assembly #'+view.id,suggestion_origin:'ai_suggestion',
     creative_suggestion:'Deterministic coverage suggestion; requires editorial approval.',publish:false});
     note('Private shot request #'+r.request_id+' created. Review it in Team Workspace before opening to contributors.');
   }catch(e){note(e.message,true);}finally{busy=false;}
  });
 }
 async function load(){
  if(previewTimer){clearInterval(previewTimer);previewTimer=null;}
  const host=panel();if(!host)return;
  try{
   const c=ctx(),r=await c.api('story-assembly.php'+(chosen?'?assembly_id='+encodeURIComponent(chosen):''));
   all=Array.isArray(r.assemblies)?r.assemblies:[];candidates=Array.isArray(r.candidates)?r.candidates:[];
   composerEnabled=Boolean(r.composer_enabled);proposals=Array.isArray(r.composer_proposals)?r.composer_proposals:[];
   storyboard=r.storyboard||null;renderCert=r.render_certification||null;
   renderEnabled=Boolean(r.render_enabled);renderJobs=Array.isArray(r.render_jobs)?r.render_jobs:[];
   audioMasters=Array.isArray(r.render_audio_candidates)?r.render_audio_candidates:[];
   renderMix=r.render_mix||null;renderTransition=r.render_transition||null;renderEdl=r.render_edl||null;previewMs=0;
   if(chosen&&!r.assembly){chosen=0;dirty=false;}
   view=r.assembly||null;if(view&&!Array.isArray(view.items))view.items=[];
    savedSnapshot=editSnapshot();undoEdits=[];redoEdits=[];dirty=false;
   draw();
  }catch(e){host.innerHTML='<section class="panel"><h2>Story Assembly unavailable</h2><p role="alert">'+esc(e.message||'Unable to load editor')+'</p></section>';}
 }
 window.SFStoryAssembly={render:()=>{void load();}};
})();