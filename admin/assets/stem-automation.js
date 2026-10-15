(() => {
 'use strict';
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 function value(points,time){
   if(!Array.isArray(points)||!points.length)return null;
   let last=points[0];
   if(time<=Number(last.time))return Number(last.value);
   for(let i=1;i<points.length;i++){
     const next=points[i];
     if(time<=Number(next.time)){
       if(last.shape==='square')return Number(last.value);
       const span=Number(next.time)-Number(last.time);
       return span<=0?Number(next.value):Number(last.value)+(Number(next.value)-Number(last.value))*(time-Number(last.time))/span;
     }
     last=next;
   }
   return Number(last.value);
 }
 function snapshot(project,time){
   const lanes=Array.isArray(project?.automation)?project.automation:[];
   const values=new Map();
   // Browser edit lanes override immutable imported envelopes at identical targets.
   for(const lane of [...lanes.filter(l=>l.source==='REAPER'),...lanes.filter(l=>l.source==='BROWSER')]){
     if(lane.enabled===false)continue;
     const v=value(lane.points,time);
     if(v===null||!Number.isFinite(v))continue;
     const key=[lane.scope,lane.guid,lane.parameter,lane.fx_index??'',lane.fx_parameter??''].join('|');
     values.set(key,v);
   }
   return values;
 }
 function effective(studio,time){
   const automation=snapshot(studio.project,time);
   const find=(scope,id,param,fidx='',fname='')=>automation.get([scope,id,param,fidx,fname].join('|'));
   const ctx=studio.context;
   const set=(param,value)=>{if(param&&Number.isFinite(value))param.setTargetAtTime(value,ctx.currentTime,.008)};
   const solo=Object.values(studio.mixing).some(m=>m.solo);
   for(const [id,node] of studio.audio){
     const mix=studio.mixing[id];if(!mix)continue;
     const vol=find('track',id,'volume')??mix.volume,pan=find('track',id,'pan')??mix.pan;
     const mute=find('track',id,'mute')??(mix.mute?1:0);
     set(node.gain.gain,(mute>=.5||solo&&!mix.solo)?0:vol);
     set(node.pan.pan,pan);
   }
   for(const [id,node] of studio.busNodes){
     const mix=studio.busMix[id];if(!mix)continue;
     const vol=find('bus',id,'volume')??mix.volume,pan=find('bus',id,'pan')??mix.pan;
     const mute=find('bus',id,'mute')??(mix.mute?1:0);
     set(node.gain.gain,mute>=.5?0:vol);set(node.pan.pan,pan);
   }
   set(studio.master.gain,find('master','__master__','volume')??.82);
   // Never modify native plugin state; update only browser approximation parameters.
   function fx(scope,id,chain,rack){
     if(!rack?.update||!chain?.length)return;
     let changed=false;
     const next=chain.map((plugin,index)=>{
       const settings={...(plugin.settings||{})};
       const ranges=window.SFStemFX?.specs?.[plugin.type]||{};
       for(const key of Object.keys(ranges)){
         const v=find(scope,id,'fx',index,key);
         if(v!==undefined){settings[key]=v;changed=true}
       }
       return {...plugin,settings};
     });
     if(changed)rack.update(next);
   }
   for(const [id,node] of studio.audio)fx('track',id,node.track.fx,node.fx);
   for(const [id,node] of studio.busNodes){
     const bus=studio.project.routing?.buses?.find(b=>b.id===id);
     fx('bus',id,bus?.fx,node.fx);
   }
   fx('master','__master__',studio.project.master_fx,studio.masterFX);
 }
 function render(project){
   const lanes=project.automation||[];
   const options=[
     ...project.tracks.map(t=>({scope:'track',guid:t.guid,label:'Track: '+t.name})),
     ...(project.routing?.buses||[]).map(b=>({scope:'bus',guid:b.id,label:'Bus: '+b.name})),
     {scope:'master',guid:'__master__',label:'Master'}
   ];
   const list=options.map(o=>'<option value="'+esc(o.scope+'|'+o.guid)+'">'+esc(o.label)+'</option>').join('');
   return '<section class="sf-studio-automation"><h3>REAPER Automation · Mix Recall · Agent Brain</h3>'+
     '<p>Supported original volume/pan envelopes are read-only. Browser automation overlays and mapped FX parameters are separate from the preserved REAPER project.</p>'+
     '<div class="sf-auto-controls"><label>Target <select data-auto-target>'+list+'</select></label>'+
     '<label>Parameter <select data-auto-parameter><option value="volume">Volume</option><option value="pan">Pan</option><option value="mute">Mute (0=on, 1=off)</option><option value="fx">Browser FX parameter</option></select></label>'+
     '<label>FX slot (0-based)<input data-auto-fx-index type="number" min="0" max="31" value="0"></label>'+
     '<label>FX parameter<input data-auto-fx-parameter maxlength="32" placeholder="wet, gain, ratio…"></label>'+
     '<label>Points: time,value,shape (one per line)<textarea data-auto-points rows="4" placeholder="0,1,linear&#10;10,0.5,linear"></textarea></label>'+
     '<button data-auto-save>Save browser envelope</button><button data-auto-agent>Ask Agent Brain to review mix</button>'+
     '<button data-auto-propose>Prepare governed Agent edit</button></div>'+
     '<div class="sf-auto-lanes">'+lanes.map(l=>'<article data-auto-lane="'+esc(l.id)+'"><b>'+esc(l.scope+' · '+l.guid+' · '+l.parameter+(l.parameter==='fx'?' #'+l.fx_index+' '+l.fx_parameter:''))+'</b><small>'+esc(l.source||'')+' · '+(l.points||[]).length+' points</small>'+
     '<div class="sf-auto-preview">'+(l.points||[]).slice(0,12).map(p=>'<span>'+esc(p.time+'s → '+p.value+' ('+p.shape+')')+'</span>').join('')+'</div>'+
     (l.source==='BROWSER'?'<button data-auto-edit>Edit</button><button data-auto-delete>Remove</button>':'<small>Original REAPER · read-only</small>')+'</article>').join('')+'</div>'+
     '<p data-auto-agent-result role="status" aria-live="polite"></p></section>';
 }
 function bind(root,studio){
   const section=root.querySelector('.sf-studio-automation');if(!section)return;
   const q=selector=>section.querySelector(selector);
   const status=s=>{const out=q('[data-auto-agent-result]');if(out)out.textContent=s};
   const draft=()=>{
     const [scope,guid]=q('[data-auto-target]').value.split('|');
     const parameter=q('[data-auto-parameter]').value;
     const fxIndex=Number(q('[data-auto-fx-index]').value);
     const fxParam=q('[data-auto-fx-parameter]').value.trim();
     const points=q('[data-auto-points]').value.trim().split(/\r?\n/).filter(Boolean).map(line=>{
       const bits=line.split(',').map(x=>x.trim());
       return {time:Number(bits[0]),value:Number(bits[1]),shape:bits[2]||(parameter==='mute'?'square':'linear')};
     });
     if(!points.length||points.length>256||points.some(p=>!Number.isFinite(p.time)||!Number.isFinite(p.value)))
       throw Error('Provide 1–256 numeric points.');
     return {scope,guid,parameter,fx_index:parameter==='fx'?fxIndex:null,fx_parameter:parameter==='fx'?fxParam:'',points,enabled:true};
   };
   q('[data-auto-save]').onclick=()=>{try{studio.commitEdit({action:'automation_set_lane',lane:draft()})}catch(e){status(e.message)}};
   section.querySelectorAll('[data-auto-lane]').forEach(row=>{
     const lane=studio.project.automation.find(x=>x.id===row.dataset.autoLane);
     if(lane?.source!=='BROWSER')return;
     row.querySelector('[data-auto-delete]').onclick=()=>studio.commitEdit({action:'automation_delete_lane',id:lane.id});
     row.querySelector('[data-auto-edit]').onclick=()=>{
       q('[data-auto-target]').value=lane.scope+'|'+lane.guid;
       q('[data-auto-parameter]').value=lane.parameter;
       q('[data-auto-fx-index]').value=lane.fx_index??0;
       q('[data-auto-fx-parameter]').value=lane.fx_parameter||'';
       q('[data-auto-points]').value=lane.points.map(p=>[p.time,p.value,p.shape].join(',')).join('\n');
       q('[data-auto-points]').focus();
     };
   });
   q('[data-auto-agent]').onclick=async()=>{
     status('Requesting an admin-only Agent Brain mix review…');
     try{
       const result=await window.STONEFELLOW_ADMIN_CONTEXT.api('agent-chat.php',{
         message:'Review Stem Studio mix for song ID '+studio.id+'. Identify routing, envelopes, FX and mix-recall risks without making changes.'
       });
       status(result.text||'Agent Brain returned no advice.');
     }catch(e){status('Agent review unavailable: '+e.message)}
   };
   q('[data-auto-propose]').onclick=async()=>{
     let lane;try{lane=draft()}catch(e){status(e.message);return}
     // Owner-visible, exact-hash approval workflow; no write from prompt alone.
     const api=window.STONEFELLOW_ADMIN_CONTEXT.api;
     const bytes=new Uint8Array(20);crypto.getRandomValues(bytes);
     const requestKey='stem86f-'+Array.from(bytes,x=>x.toString(16).padStart(2,'0')).join('');
     let proposal;
     try{
       const res=await api('agent-actions.php',{action:'prepare',tool_id:'stem_automation_update',request_key:requestKey,
         draft:{track_id:studio.id,revision:studio.project.revision,lane}});
       proposal=res.proposal;
     }catch(e){status('Could not prepare Agent edit: '+e.message);return}
     const dialog=document.createElement('dialog');
     dialog.className='sf-auto-proposal';
     dialog.innerHTML='<h3>Agent Brain · exact automation review</h3><p>Review this project, revision and every point before approval. Approval does not execute.</p>'+
       '<pre data-proposal-data></pre><p>SHA-256 <code data-proposal-hash></code></p>'+
       '<label>Type APPROVE <input data-proposal-typed autocomplete="off"></label><button data-proposal-approve>Approve exact draft</button>'+
       '<button data-proposal-execute disabled>Execute approved edit</button><button data-proposal-close>Close</button><p data-proposal-status role="status"></p>';
     document.body.appendChild(dialog);
     const find=s=>dialog.querySelector(s);
     find('[data-proposal-data]').textContent=JSON.stringify(proposal.payload,null,2);
     find('[data-proposal-hash]').textContent=proposal.payload_hash;
     const tell=s=>find('[data-proposal-status]').textContent=s;
     find('[data-proposal-close]').onclick=()=>dialog.close();
     dialog.addEventListener('close',()=>dialog.remove());
     find('[data-proposal-approve]').onclick=async()=>{
       try{
         const r=await api('agent-actions.php',{action:'approve',proposal_id:proposal.id,payload_hash:proposal.payload_hash,typed_confirmation:find('[data-proposal-typed]').value});
         proposal=r.proposal;
         if(proposal.status!=='approved')throw Error('Approval not confirmed');
         find('[data-proposal-approve]').disabled=true;
         find('[data-proposal-execute]').disabled=false;tell('Approved. No edit made yet.');
       }catch(e){tell('Approval rejected: '+e.message)}
     };
     find('[data-proposal-execute]').onclick=async()=>{
       find('[data-proposal-execute]').disabled=true;
       try{
         const r=await api('agent-actions.php',{action:'execute',proposal_id:proposal.id});
         if(r.proposal.status!=='completed')throw Error('Review required');
         dialog.close();await studio.reload();status('Approved Agent automation edit saved and reflected in Stem Studio.');
       }catch(e){tell('Execution uncertain. Inspect Agent actions and reload this song; do not retry automatically.')}
     };
     if(dialog.showModal)dialog.showModal();else dialog.setAttribute('open','');
   };
 }
 window.SFStemAutomation={value,snapshot,effective,render,bind};
})();