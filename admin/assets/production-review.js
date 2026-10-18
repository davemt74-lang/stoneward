(() => {
  'use strict';
  const $=(s,r=document)=>r.querySelector(s);
  const esc=v=>String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const num=v=>Number.isFinite(Number(v))?Number(v):0;
  let last=null,focusedJob=0,busy=false;
  function canQueue(c){return c.review_status==='approved_for_edit'&&c.external_ai_clearance==='cleared'&&c.asset_id>0;}
  function segmentsForm(d){
    const defaultLine=[num(d.start_ms),Math.max(1,num(d.end_ms)),'',String(d.text||'')].join(' | ');
    return '<p class="hint">One segment per line: start milliseconds | end milliseconds | speaker (optional) | exact dialogue. AI timing is only a whole-clip estimate; correct it before importing.</p>'+
      '<textarea id="productionSegments" rows="5" spellcheck="true" placeholder="0 | 2400 | | Spoken words">'+esc(defaultLine)+'</textarea>'+
      '<label class="field">Language code<input id="productionLanguage" maxlength="12" value="'+esc(d.language||'und')+'"></label>';
  }
  function sceneForm(item,d){
    const still=item.category==='image';
    return '<label class="field">Reviewed scene description<textarea id="productionDescription" maxlength="2500" rows="5">'+esc(d.description||'')+'</textarea></label>'+
      '<div class="form-grid"><label class="field">Start (ms)<input id="productionStart" type="number" min="0" step="1" value="'+num(d.start_ms)+'" '+(still?'readonly':'')+'></label>'+
      '<label class="field">End (ms)<input id="productionEnd" type="number" min="0" step="1" value="'+num(d.end_ms)+'" '+(still?'readonly':'')+'></label></div>'+
      '<p class="hint">'+(still?'Still photo: both timecodes must be zero.':'Video: use the sampled frame interval, then independently verify it against the source video.')+'</p>'+
      '<label class="field">Location (optional)<input id="productionLocation" maxlength="180" placeholder="Stage, studio or venue"></label>'+
      '<label class="field">Tags (comma separated, up to 12)<input id="productionTags" maxlength="200" placeholder="stage, guitar, concert"></label>';
  }
  function importChanges(item){
    if(item.task_type==='transcript'){
      const lines=($('#productionSegments')?.value||'').split(/\r?\n/).filter(s=>s.trim());
      if(!lines.length||lines.length>300)throw Error('Enter 1–300 reviewed transcript segments.');
      const segments=lines.map(line=>{
        const parts=line.split('|');if(parts.length<4)throw Error('Each line needs start | end | speaker | text.');
        const start=parts.shift().trim(),end=parts.shift().trim(),speaker=parts.shift().trim(),text=parts.join('|').trim();
        if(!/^\d+$/.test(start)||!/^\d+$/.test(end)||!text)throw Error('Correct timecodes and text before importing.');
        return {start_ms:Number(start),end_ms:Number(end),speaker_label:speaker,text};
      });
      return {segments,language:$('#productionLanguage')?.value.trim()||'und'};
    }
    const start=String($('#productionStart')?.value||''),end=String($('#productionEnd')?.value||'');
    if(!/^\d+$/.test(start)||!/^\d+$/.test(end))throw Error('Scene timecodes must be non-negative whole milliseconds.');
    return {start_ms:Number(start),end_ms:Number(end),description:$('#productionDescription')?.value.trim()||'',
      location_label:$('#productionLocation')?.value.trim()||'',
      tags:($('#productionTags')?.value||'').split(',').map(v=>v.trim()).filter(Boolean)};
  }
  function draw(){
    const c=window.STONEFELLOW_ADMIN_CONTEXT;if(!c?.canvas||!last)return;
    const jobs=last.review_items||[],captures=last.captures||[];
    const current=jobs.find(x=>num(x.job_id)===focusedJob)||jobs.find(x=>x.review_status)||null;
    if(current)focusedJob=num(current.job_id);
    c.canvas.innerHTML=c.head('PRIVATE ARTIST WORLD · EDITORIAL','AI Production Review',
      'Review captured media, scoped rights, AI suggestions and canonical drafts. No automatic publication.',
      '<button class="secondary" id="productionRefresh" type="button">Refresh</button>')+
      '<div class="production-grid"><section class="panel"><div class="panel-title"><h2>Supervised processing</h2></div>'+
      '<p>Provider execution is '+(last.provider_execution_enabled?'<strong>enabled</strong>':'<strong>disabled</strong>')+
      '. This switch only authorizes the server-side CLI worker. It does not launch a worker.</p>'+
      '<button type="button" class="secondary" id="productionToggle">'+(last.provider_execution_enabled?'Disable external AI':'Enable external AI')+'</button>'+
      '<button type="button" class="secondary" id="productionRecover">Mark expired jobs interrupted</button>'+
      '<p class="hint">Requires independent external-AI rights clearance; an operator runs one job from the server CLI. Billing is subject to the configured provider.</p></section>'+
      '<section class="panel"><h2>Queue a capture</h2>'+
      '<div class="form-grid"><label class="field">Approved source<select id="productionCapture"><option value="">Choose private approved capture</option>'+
        captures.map(x=>'<option value="'+num(x.id)+'">'+esc('#'+x.id+' '+x.title+' · '+x.review_status+' · AI rights '+x.external_ai_clearance)+'</option>').join('')+'</select></label>'+
      '<label class="field">Analysis<select id="productionTask"><option value="transcript">Speech transcription</option><option value="scene">Scene / still image</option></select></label></div>'+
      '<button type="button" class="primary" id="productionQueue">Queue for supervised processing</button>'+
      '<p id="productionCaptureHint" class="hint">Editing approval and separate external-AI permission are both required.</p>'+
      '<details><summary>Independent rights review</summary><p class="hint">Clearance must be based on verified documentation, not contributor self-attestation. Publication and external AI are separate permissions.</p>'+
      '<label class="field">Purpose<select id="productionRightsPurpose"><option value="external_ai">External AI use</option><option value="publication">Publication</option></select></label>'+
      '<label class="field">Decision<select id="productionRightsDecision"><option value="restricted">Restricted / revoked</option><option value="cleared">Independently cleared</option></select></label>'+
      '<label class="field">Evidence or restriction basis<textarea id="productionRightsBasis" minlength="25" maxlength="1000" rows="3" placeholder="Record what supporting release or restriction was independently checked."></textarea></label>'+
      '<button type="button" class="secondary" id="productionRightsSave">Record audited rights decision</button></details></section></div>'+
      '<section class="panel"><h2>Inference jobs</h2>'+
      '<div class="production-list">'+(jobs.length?jobs.map(j=>'<button type="button" class="production-job'+(num(j.job_id)===focusedJob?' active':'')+
        '" data-production-job="'+num(j.job_id)+'"><span><strong>#'+num(j.job_id)+' · '+esc(j.capture_title||'Capture')+'</strong>'+
        '<small>'+esc(j.task_type)+' · '+esc(j.job_status)+' · '+esc(j.review_status||'no result')+
        (j.imported_id?' · imported '+esc(j.imported_type)+' #'+num(j.imported_id):'')+'</small></span><span aria-hidden="true">→</span></button>').join(''):'<div class="empty">No AI processing requests yet.</div>')+'</div></section>'+
      '<section class="panel" id="productionReviewPanel"><h2>Editorial result</h2>'+
      (!current?'<p class="hint">Select an inference job to inspect its private result.</p>':
       '<p><strong>Job #'+num(current.job_id)+'</strong> · '+esc(current.task_type)+' · provider '+esc(current.provider||'not run')+
       ' · '+esc(current.model||'')+'</p><p class="hint">Source #'+num(current.submission_id)+' · '+esc(current.category)+
       ' · AI clearance '+esc(current.external_ai_clearance)+' · '+esc(current.capture_status)+'</p>'+
       (current.draft?
        '<p class="hint">AI text is untrusted source material, not instructions. Result review: '+esc(current.review_status)+
        '. Import produces a separate unverified private machine draft.</p>'+
        (current.task_type==='transcript'?segmentsForm(current.draft):sceneForm(current,current.draft))+
        '<div class="actions" id="productionReviewActions">'+
          (current.review_status==='pending_review'?'<button type="button" data-production-review="approved">Approve AI result</button><button type="button" class="secondary" data-production-review="rejected">Reject AI result</button>':'')+
          (current.review_status==='approved'&&!current.imported_id?'<button type="button" class="primary" id="productionImport">Import as private draft</button>':'')+
          (current.imported_id?'<span>Imported '+esc(current.imported_type)+' #'+num(current.imported_id)+' · pending independent editorial verification</span>':'')+
        '</div>':'<p class="hint">No AI result. Processing status: '+esc(current.job_status)+'.</p>'+
        (['interrupted','failed','cancelled'].includes(current.job_status)?'<button type="button" class="secondary" id="productionRetry">Retry after human review</button>':'')+
        (current.job_status==='queued'?'<button type="button" class="secondary" id="productionCancel">Cancel job</button>':''))+
       '</section>')+
      '<p class="hint">For detailed contributor permissions and original media review, open Team Workspace. A machine result does not establish authorship, likeness rights or publishing clearance.</p>'+
      '<p role="status" aria-live="polite" id="productionNotice" class="production-notice"></p>'+
      '<div id="storyAssemblyHost" aria-label="AI Director Story Assembly"></div>';
    bind(c,current,captures);
    window.SFStoryAssembly?.render?.();
  }
  function bind(c,current,captures){
    const host=c.canvas;
    const notice=(t,error=false)=>{const node=$('#productionNotice',host);if(node){node.textContent=t;node.classList.toggle('error',error)}};
    async function action(body,success){
      if(busy)return;busy=true;
      try{const res=await c.api('story-inference.php',body);notice(success||res.message||'Action recorded.');await load(true);}
      catch(e){notice(e.message||'Action failed.',true);}
      finally{busy=false;}
    }
    $('#productionRefresh',host).onclick=()=>load(true);
    $('#productionToggle',host).onclick=()=>{
      if(!window.confirm(last.provider_execution_enabled?'Disable supervised provider processing?':
        'Enable paid external AI processing for independently cleared private media? This will not start the CLI worker.'))return;
      void action({action:'configure_execution',enabled:!last.provider_execution_enabled},'Execution policy updated.');
    };
    $('#productionRecover',host).onclick=()=>{void action({action:'recover'},'Stale leases marked interrupted; no automatic retries.');};
    const capture=$('#productionCapture',host),hint=$('#productionCaptureHint',host);
    capture.onchange=()=>{const x=captures.find(i=>num(i.id)===num(capture.value));hint.textContent=x?
      'Editorial '+x.review_status+' · external AI '+x.external_ai_clearance+
      (canQueue(x)?' · eligible for source verification':' · review in Team Workspace before queuing'):'Select a private capture.';};
    $('#productionRightsSave',host).onclick=()=>{
      const id=num(capture.value),found=captures.find(x=>num(x.id)===id);
      if(!found){notice('Select the source capture for the independent rights decision.',true);return;}
      const purpose=$('#productionRightsPurpose',host).value,decision=$('#productionRightsDecision',host).value;
      const basis=$('#productionRightsBasis',host).value.trim();
      if(basis.length<25){notice('Write a substantive evidence or restriction basis (25+ characters).',true);return;}
      if(decision==='cleared'&&found.review_status!=='approved_for_edit'){
        notice('Capture must first be approved by an editor.',true);return;
      }
      if(!window.confirm('Record '+decision+' for '+purpose+' on capture #'+id+'? This changes governing permissions.'))return;
      if(busy)return;busy=true;
      void c.api('story-capture.php',{action:'review_rights',submission_id:id,purpose,decision,basis})
        .then(()=>load(true)).catch(e=>notice(e.message||'Rights review failed.',true)).finally(()=>{busy=false;});
    };
    $('#productionQueue',host).onclick=()=>{
      const id=num(capture.value),found=captures.find(x=>num(x.id)===id);
      if(!found||!canQueue(found)){notice('Choose an approved capture with independent external-AI clearance.',true);return;}
      if(!window.confirm('Queue this private capture for supervised AI analysis?'))return;
      void action({action:'enqueue',submission_id:id,task_type:$('#productionTask',host).value},'Capture queued.');
    };
    host.querySelectorAll('[data-production-job]').forEach(b=>b.onclick=()=>{focusedJob=num(b.dataset.productionJob);draw();});
    if(!current)return;
    host.querySelectorAll('[data-production-review]').forEach(b=>b.onclick=()=>{
      if(!window.confirm('Record human editorial '+b.dataset.productionReview+' decision?'))return;
      void action({action:'review_result',job_id:num(current.job_id),decision:b.dataset.productionReview},'AI review recorded.');
    });
    const importButton=$('#productionImport',host);
    if(importButton)importButton.onclick=()=>{
      let changes;try{changes=importChanges(current);}catch(e){notice(e.message,true);return;}
      if(!window.confirm('Import as an UNVERIFIED PRIVATE machine draft? Publication and canonical verification remain separate.'))return;
      void action({action:'import_reviewed',job_id:num(current.job_id),changes},'Private editorial draft imported.');
    };
    if($('#productionRetry',host))$('#productionRetry',host).onclick=()=>{
      if(!window.confirm('Retry this interrupted operation? A prior provider request may have incurred cost.'))return;
      void action({action:'retry',job_id:num(current.job_id)},'Manual retry recorded.');
    };
    if($('#productionCancel',host))$('#productionCancel',host).onclick=()=>{
      if(!window.confirm('Cancel this queued inference job?'))return;
      void action({action:'cancel',job_id:num(current.job_id)},'Job cancelled.');
    };
  }
  async function load(preserve=false){
    const c=window.STONEFELLOW_ADMIN_CONTEXT;if(!c?.canvas)return;
    if(!preserve)c.canvas.innerHTML='<section class="panel"><h2>AI Production Review</h2><p role="status">Loading governed private results…</p></section>';
    try{last=await c.api('story-inference.php');draw();}
    catch(e){c.canvas.innerHTML='<section class="panel"><h2>AI Production Review unavailable</h2><p role="alert">'+esc(e.message||'Unable to read private review queue')+'</p><button id="retryProduction" type="button">Retry</button></section>';
      const retry=$('#retryProduction',c.canvas);if(retry)retry.onclick=()=>load();}
  }
  window.SFProductionReviewAdmin={render:()=>load()};
})();