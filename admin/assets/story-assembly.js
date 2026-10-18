(() => {
 'use strict';
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const ms=v=>Number.isFinite(Number(v))?Math.round(Number(v)):0;
 let chosen=0,view=null,all=[],candidates=[],proposals=[],composerEnabled=false,busy=false,dirty=false;
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
 function draw(){
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
       '<label>Director notes<input maxlength="600" data-asm-field="notes" data-i="'+i+'" value="'+esc(r.notes||'')+'" placeholder="Why this beat matters"></label>'+
       (warning?'<p class="assembly-warning">'+esc(warning)+'</p>':'')+'</article>';
    }).join(''):'<p class="hint">No segments. Select a verified scene, verified dialogue or catalog track.</p>')+'</div>'+
    '<div class="actions"><button type="button" class="primary" id="assemblySave" '+(!dirty?'disabled':'')+'>Save timeline revision</button>'+
    '<span id="assemblyDirty">'+(dirty?'Unsaved local edits':'Saved private editorial draft')+'</span></div>'+
    '<div class="assembly-director"><h3>Director’s proposed coverage</h3>'+
    '<p class="hint">Deterministic editorial suggestions from this assembly, not an LLM inference or finished edit.</p>'+
    (plan.gaps.length?plan.gaps.map(g=>'<div class="assembly-gap"><span><strong>'+esc(g.code.replace(/_/g,' '))+'</strong> · '+esc(g.brief)+
       ' (missing '+Number(g.missing)+')</span><button type="button" data-assembly-shot="'+esc(g.code)+'">Draft shot request</button></div>').join(''):
     '<p>Basic coverage criteria are met. Human storytelling and copyright review are still required.</p>')+
    (plan.notes||[]).map(n=>'<p class="hint">• '+esc(n)+'</p>').join('')+
    '<p class="hint">Publication clearance gaps: '+Number(plan.publication_rights_pending||0)+
      '. Music licensing is separately required. Final video render remains disabled.</p></div>':'<p class="hint">Create an assembly to start your private editorial timeline.</p>')+
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
           '<ol>'+((q.beats||[]).map(b=>'<li>'+esc(b.reason)+' · '+esc(b.transition)+'</li>').join(''))+'</ol>'+
           ((q.missing_shots||[]).length?'<p class="hint">Missing shots: '+esc(q.missing_shots.join('; '))+'</p>':''):'')+
           (p.status==='pending_review'?'<button type="button" data-composer-review="approved" data-proposal-id="'+Number(p.id)+'">Approve proposal</button>'+
             '<button type="button" class="secondary" data-composer-review="rejected" data-proposal-id="'+Number(p.id)+'">Reject</button>':'')+
           (p.status==='approved'?'<button type="button" class="primary" data-composer-apply="'+Number(p.id)+'" '+(Number(p.revision)!==Number(project.revision)||dirty?'disabled':'')+'>Apply approved ordering</button>':'')+
          '</article>';
        }).join(''):'<p class="hint">No AI composition proposals for this assembly.</p>')+
       '</div>':'')+
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
  host.querySelector('#assemblyAdd').onclick=()=>{
   const v=host.querySelector('#assemblySource')?.value;
   const src=editorCandidates().find(x=>key(x)===v);
   if(!src){note('Select a verified source.',true);return;}
   if(view.items.length>=50){note('Maximum 50 editorial items.',true);return;}
   view.items.push(defaultItem(src));changed();draw();
  };
  host.querySelectorAll('[data-asm-field]').forEach(input=>input.onchange=()=>{
   const i=Number(input.dataset.i),field=input.dataset.asmField;
   if(!view.items[i])return;
   view.items[i][field]=field==='notes'?input.value:ms(input.value);changed();
  });
  host.querySelectorAll('[data-assembly-up],[data-assembly-down],[data-assembly-remove]').forEach(b=>b.onclick=()=>{
   const type=b.hasAttribute('data-assembly-remove')?'remove':(b.hasAttribute('data-assembly-up')?'up':'down');
   const i=Number(b.dataset[type==='remove'?'assemblyRemove':(type==='up'?'assemblyUp':'assemblyDown')]);
   if(type==='remove'){view.items.splice(i,1);}else{
    const j=i+(type==='up'?-1:1);if(j<0||j>=view.items.length)return;
    [view.items[i],view.items[j]]=[view.items[j],view.items[i]];
   }
   changed();draw();
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
  const host=panel();if(!host)return;
  try{
   const c=ctx(),r=await c.api('story-assembly.php'+(chosen?'?assembly_id='+encodeURIComponent(chosen):''));
   all=Array.isArray(r.assemblies)?r.assemblies:[];candidates=Array.isArray(r.candidates)?r.candidates:[];
   composerEnabled=Boolean(r.composer_enabled);proposals=Array.isArray(r.composer_proposals)?r.composer_proposals:[];
   if(chosen&&!r.assembly){chosen=0;dirty=false;}
   view=r.assembly||null;if(view&&!Array.isArray(view.items))view.items=[];
   draw();
  }catch(e){host.innerHTML='<section class="panel"><h2>Story Assembly unavailable</h2><p role="alert">'+esc(e.message||'Unable to load editor')+'</p></section>';}
 }
 window.SFStoryAssembly={render:()=>{void load();}};
})();