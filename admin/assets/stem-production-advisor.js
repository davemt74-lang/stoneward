(() => {
 'use strict';
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const priorityLabel={blocking:'Blocking',high:'High',medium:'Medium',low:'Review'};
 function render(project){
   return '<section class="sf-j-advisor" data-j-advisor>'+
     '<div class="sf-j-head"><div><h3>86J · Agent Brain Production Assistant</h3>'+
     '<p>Private project-aware analysis of tracks, buses, sends, browser FX, automation, measured mastering and release readiness. Never changes your mix without approval.</p></div>'+
     '<div class="sf-j-buttons"><button type="button" data-j-inspect>Analyze this project</button>'+
     '<button type="button" data-j-chat>Discuss with Admin Agent Brain</button></div></div>'+
     '<p data-j-state role="status" aria-live="polite">Inspect the saved project to see grounded observations and reviewed proposals.</p>'+
     '<div data-j-results></div>'+
     '<p class="sf-g-note">Measurements are browser-reported, not independently certified. Native VST processing and instrument-level spectral issues require listening in REAPER.</p>'+
     '</section>';
 }
 function bind(root,studio){
   const area=root.querySelector('[data-j-advisor]');if(!area)return;
   const q=s=>area.querySelector(s),status=text=>q('[data-j-state]').textContent=text;
   let current=null;
   const describe=row=>{
     const note=document.createElement('article');note.className='sf-j-issue';
     const title=document.createElement('strong');title.textContent=(priorityLabel[row.priority]||'Review')+' · '+row.id;
     const evidence=document.createElement('p');evidence.textContent=row.evidence;
     const advice=document.createElement('p');advice.textContent=row.recommendation;
     note.append(title,evidence,advice);return note;
   };
   const propose=item=>{
     if(!current || current.revision!==studio.project.revision){
       status('The project has changed. Inspect the current revision before preparing a proposal.');return;
     }
     if(item.tool_id!=='stem_automation_update'||item.owner_approval_required!==true){
       status('This action is not approved by the Stem Studio governance contract.');return;
     }
     const lane=item.draft?.lane, draft=item.draft;
     if(!lane||draft.track_id!==studio.id||draft.revision!==studio.project.revision){
       status('Proposed change no longer matches this song. Inspect again.');return;
     }
     const editor=studio.overlay?.querySelector('.sf-studio-automation');
     if(!editor)return status('The existing Automation approval workbench is unavailable.');
     const target=editor.querySelector('[data-auto-target]');
     const parameter=editor.querySelector('[data-auto-parameter]');
     const index=editor.querySelector('[data-auto-fx-index]');
     const key=editor.querySelector('[data-auto-fx-parameter]');
     const points=editor.querySelector('[data-auto-points]');
     const launch=editor.querySelector('[data-auto-propose]');
     if(!target||!parameter||!points||!launch)
       return status('The exact-approval controls are not available.');
     target.value=lane.scope+'|'+lane.guid;
     if(target.value!==lane.scope+'|'+lane.guid)
       return status('The proposed target is no longer present in this mix.');
     parameter.value=lane.parameter;
     if(index)index.value=lane.fx_index??0;
     if(key)key.value=lane.fx_parameter||'';
     points.value=lane.points.map(p=>[p.time,p.value,p.shape].join(',')).join('\n');
     editor.scrollIntoView({block:'start',behavior:'smooth'});
     status('Prepared a draft in the existing Agent approval workbench. No change has been applied.');
     launch.click();
   };
   const draw=snapshot=>{
     const output=q('[data-j-results]');output.replaceChildren();
     const overview=document.createElement('p');
     const project=snapshot.project||{};
     overview.textContent='Project revision '+snapshot.revision+' · '+(project.track_count||0)+' tracks · '+
       (project.bus_count||0)+' buses · '+(project.send_count||0)+' sends · '+
       (project.automation_count||0)+' automation lanes · '+(project.saved_mix_count||0)+' saved mixes.';
     output.append(overview);
     const heading=document.createElement('h4');heading.textContent='Evidence-backed mix observations';output.append(heading);
     if(!snapshot.issues?.length){
       const none=document.createElement('p');
       none.textContent='No specific issues identified from available metadata. Listen in REAPER; this is not an audio certification.';
       output.append(none);
     }
     for(const row of snapshot.issues||[])output.append(describe(row));
     const trackDetails=document.createElement('details'),summary=document.createElement('summary');
     summary.textContent='Inspect track, FX and bus context';trackDetails.append(summary);
     const list=document.createElement('ul');
     for(const t of (project.tracks||[])){
       const li=document.createElement('li');
       li.textContent=(t.name||'Track')+' · '+(t.role||'unassigned')+
         ' · '+(t.has_private_audio?'linked':'unlinked')+
         ' · FX: '+(t.fx||[]).map(x=>x.type).join(', ')+
         ' · automation: '+t.automation_lanes;
       list.append(li);
     }
     for(const b of project.buses||[]){
       const li=document.createElement('li');
       li.textContent='BUS '+b.name+' · FX: '+(b.fx||[]).map(x=>x.type).join(', ');
       list.append(li);
     }
     trackDetails.append(list);output.append(trackDetails);
     for(const item of snapshot.proposals||[]){
       const row=document.createElement('article');row.className='sf-j-proposal';
       const title=document.createElement('strong');title.textContent=item.title;
       const reason=document.createElement('p');reason.textContent=item.rationale;
       const btn=document.createElement('button');btn.type='button';
       btn.textContent='Review exact change in Agent Brain';
       btn.onclick=()=>propose(item);
       row.append(title,reason,btn);output.append(row);
     }
     status('Read-only diagnosis of revision '+snapshot.revision+
       '. '+(snapshot.issues?.length||0)+' observations; '+(snapshot.proposals?.length||0)+
       ' governed draft(s). No audio processing or publication has been changed.');
   };
   q('[data-j-inspect]').onclick=async()=>{
     const btn=q('[data-j-inspect]');btn.disabled=true;
     status('Inspecting the private saved project and its mastering evidence…');
     try{
       const response=await fetch('api/stem-advisor.php?track_id='+encodeURIComponent(studio.id),
         {credentials:'same-origin',cache:'no-store'});
       if(!response.ok)throw Error('Private project inspection unavailable (HTTP '+response.status+').');
       const data=await response.json();
       if(!data.ok)throw Error(data.message||'Inspection rejected');
       current=data.inspection;draw(current);
     }catch(e){status('Agent inspection failed: '+e.message)}
     finally{btn.disabled=false}
   };
   q('[data-j-chat]').onclick=async()=>{
     status('Asking Stonefellow Admin Agent Brain to explain the private production evidence…');
     try{
       const data=await window.STONEFELLOW_ADMIN_CONTEXT.api('agent-chat.php',{
         message:'Review the REAPER mix and production evidence for song ID '+studio.id+
           '. Explain specific track, FX, routing, automation, mastering and publishing risks. Suggest only governed reviewed changes; do not execute.'
       });
       status(data.text||'Agent Brain returned no explanation.');
     }catch(e){status('Admin Agent Brain unavailable: '+e.message)}
   };
 }
 window.SFStemProductionAdvisor={render,bind};
})();