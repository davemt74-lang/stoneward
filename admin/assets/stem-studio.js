(() => {
 'use strict';
 const ctx=window.STONEFELLOW_ADMIN_CONTEXT;if(!ctx)return;
 const {api,uploadApi,esc}=ctx;
 let studio=null;
 const el=(s,root=document)=>root.querySelector(s);
 const all=(s,root=document)=>Array.from(root.querySelectorAll(s));
 const n=(x,d=0)=>Number.isFinite(Number(x))?Number(x):d;
 const fmt=s=>{const v=Math.max(0,Math.floor(s));return Math.floor(v/60)+':'+String(v%60).padStart(2,'0')};
 const clone=o=>JSON.parse(JSON.stringify(o));
 const section=track=>'<section class="form-section sf-stems-section"><h3>Stems &amp; REAPER Studio</h3><p class="hint">Upload one ZIP with the REAPER .rpp and consolidated stems, then edit the song’s private multitrack mix. Public song media remains unchanged.</p><div class="actions"><button type="button" class="secondary" data-stem-open="'+esc(track.id)+'">Open Stem Studio</button></div><p class="status-line" data-stem-project-status>Project not loaded</p></section>';
 function bind(trackId){
   const b=el('[data-stem-open]');if(!b)return;
   b.onclick=()=>open(trackId);
   api('stem-studio.php?track_id='+encodeURIComponent(trackId)).then(j=>{
     const status=el('[data-stem-project-status]');if(status)status.textContent=j.project?j.project.tracks.length+' stem tracks · '+j.project.tempo_bpm+' BPM · project saved':'No project yet. Import an .rpp file to get started.';
   }).catch(e=>{const status=el('[data-stem-project-status]');if(status)status.textContent=e.message});
 }
 class StemStudio {
   constructor(id){this.id=id;this.project=null;this.overlay=null;this.audio=new Map();this.context=null;this.master=null;this.masterFX=null;this.masterProcessor=null;this.running=false;this.anchor=0;this.anchorClock=0;this.loop=false;this.raf=0;this.duration=0;this.mixing={};this.busMix={};this.busNodes=new Map();this.meters=new Map();this.selectedClips={};this.lastAutoFrame=-1;this.saving=false;}
   async init(){
     const overlay=document.createElement('div');overlay.className='sf-studio-overlay';overlay.innerHTML='<div class="sf-studio-shell"><header class="sf-studio-head"><div><span class="eyebrow">STONEFELLOW · STEM STUDIO</span><h2>Song multitrack editor</h2><p data-studio-message>Import a REAPER project to initialize your song.</p></div><button type="button" class="secondary" data-studio-close>Close</button></header><section class="sf-studio-import"><label class="sf-studio-file">Import project + stems ZIP <input type="file" accept=".zip,application/zip" data-studio-bundle></label><label class="sf-studio-file">Import REAPER project (.rpp) <input type="file" accept=".rpp,text/plain" data-studio-rpp></label><label class="sf-studio-file">Upload consolidated stems (WAV / MP3) <input type="file" accept=".wav,.mp3,audio/wav,audio/mpeg" multiple data-studio-audio></label><span data-studio-upload-progress role="status"></span></section><div data-studio-report role="status" aria-live="polite"></div><div data-studio-body></div></div>';
     document.body.appendChild(overlay);this.overlay=overlay;
     el('[data-studio-close]',overlay).onclick=()=>this.close();
     el('[data-studio-bundle]',overlay).onchange=async e=>{const file=e.target.files?.[0];if(file)await this.importBundle(file);e.target.value='';};
     el('[data-studio-rpp]',overlay).onchange=async e=>{const file=e.target.files?.[0];if(file)await this.importRpp(file);e.target.value='';};
     el('[data-studio-audio]',overlay).onchange=async e=>{const files=[...(e.target.files||[])];if(files.length)await this.uploadStems(files);e.target.value='';};
     await this.reload();
   }
   say(m){const e=el('[data-studio-message]',this.overlay);if(e)e.textContent=m}
   async reload(){try{const j=await api('stem-studio.php?track_id='+encodeURIComponent(this.id));this.project=j.project;this.updateMix();this.render()}catch(e){this.say(e.message)}}
   updateMix(){this.mixing={};for(const t of this.project?.tracks||[])this.mixing[t.guid]={volume:n(t.volume,1),pan:n(t.pan),mute:!!t.mute,solo:!!t.solo};this.busMix={};for(const b of this.project?.routing?.buses||[])this.busMix[b.id]={volume:n(b.volume,1),pan:n(b.pan),mute:!!b.mute,solo:!!b.solo};this.recalculateDuration()}
   recalculateDuration(){
     this.duration=Math.max(1,...(this.project?.tracks||[]).flatMap(t=>(t.clips||[]).map(c=>n(c.position)+n(c.length))));
   }
   async importRpp(file){
     if(!/\.rpp$/i.test(file.name)||file.size>8388608)return this.say('Select a REAPER .rpp under 8 MB.');
     const fd=new FormData();fd.append('track_id',this.id);fd.append('file',file,file.name);
     this.stop();this.say('Parsing '+file.name+'…');
     try{const j=await uploadApi('stem-studio.php',fd);this.project=j.project;this.updateMix();this.render();this.say('Imported '+this.project.tracks.length+' REAPER tracks. Assign their audio files below.')}catch(e){this.say(e.message)}
   }
   async importBundle(file){
     if(!/\\.zip$/i.test(file.name)||file.size>2147483648||file.size===0)
       return this.say('Choose a REAPER project + stems ZIP up to 2 GiB.');
     const progress=el('[data-studio-upload-progress]',this.overlay);
     const report=el('[data-studio-report]',this.overlay);
     const fd=new FormData();fd.append('track_id',this.id);fd.append('file',file,file.name);
     this.stop();if(progress)progress.textContent='Uploading '+file.name+' and validating project and audio…';
     this.say('Importing ZIP securely into the existing Media Library.');
     try{
       const j=await uploadApi('stem-studio.php',fd);
       this.project=j.project;this.updateMix();this.render();
       const info=j.import_report||{};
       const missing=Array.isArray(info.missing_files)?info.missing_files:[];
       const extra=Array.isArray(info.unmatched_audio_files)?info.unmatched_audio_files:[];
       if(report){
         report.textContent=(Number(info.matched_stems)||0)+' of '+(Number(info.stem_tracks)||0)+
           ' REAPER lanes matched · '+(Number(info.uploaded_media_assets)||0)+' private audio assets imported'+
           (missing.length?' · Missing: '+missing.join(', '):' · All referenced stems present')+
           (extra.length?' · Extra ZIP audio not imported: '+extra.join(', '):'');
       }
       this.say('ZIP import complete. Public song media and purchased originals remain unchanged.');
     }catch(e){this.say('ZIP import failed: '+e.message)}
     finally{if(progress)progress.textContent=''}
   }
   async uploadStems(files){
     if(!this.project)return this.say('Import the REAPER project before attaching stems.');
     const out=el('[data-studio-upload-progress]',this.overlay);
     for(let i=0;i<files.length;i++){
       const file=files[i];if(!/\.(wav|mp3)$/i.test(file.name)){this.say('Skipped unsupported file: '+file.name);continue;}
       out.textContent='Uploading '+(i+1)+' / '+files.length+': '+file.name;
       try{
         const fd=new FormData();fd.append('file',file,file.name);
         const j=await uploadApi('media-upload.php',fd);
         const target=this.project.tracks.find(t=>!t.asset_uuid&&t.source_file.toLowerCase()===file.name.toLowerCase())
           ||this.project.tracks.find(t=>!t.asset_uuid&&t.source_file.toLowerCase().includes(file.name.toLowerCase()));
         if(target){const saved=await api('stem-studio.php',{action:'assign',track_id:this.id,revision:this.project.revision,guid:target.guid,asset_id:j.asset.id});this.project=saved.project}
         else this.say('Uploaded '+file.name+' to Media Library; select its stem manually below.');
       }catch(e){this.say(file.name+': '+e.message);break;}
     }
     out.textContent='';this.updateMix();this.render();
   }
   render(){
     this._masteringDispose?.();this._masteringDispose=null;
     this._stemIntelligenceDispose?.();this._stemIntelligenceDispose=null;
     this._stemAlignmentDispose?.();this._stemAlignmentDispose=null;
     const area=el('[data-studio-body]',this.overlay);if(!area)return;
     if(!this.project){area.innerHTML='<div class="sf-studio-empty">Upload a single ZIP with your .rpp and WAV/MP3 stems, or import them separately. The REAPER project defines the lanes, plugins, timeline offsets and master bus.</div>';return}
     const p=this.project,tracks=p.tracks||[];
     const max=this.duration;
     area.innerHTML='<div class="sf-studio-top"><div class="sf-studio-properties"><strong>'+esc(p.project_name)+'</strong><span>'+tracks.length+' tracks</span><span>'+esc(p.tempo_bpm)+' BPM</span><span>'+esc(p.time_signature)+'</span><span>'+esc(p.sample_rate||'Unknown')+' Hz</span></div><div class="sf-studio-transport"><button type="button" data-studio-play>▶ Play</button><button type="button" data-studio-stop>■ Stop</button><label><input type="checkbox" data-studio-loop> Loop</label><label><input type="checkbox" data-studio-snap checked> Snap to beat</label><button type="button" data-studio-undo title="Undo last clip/effect edit">↶ Undo</button><button type="button" data-studio-redo title="Redo last clip/effect edit">↷ Redo</button><input type="range" data-studio-seek min="0" max="'+max+'" step=".05" value="0"><output data-studio-time>0:00 / '+fmt(max)+'</output></div></div><div class="sf-studio-master"><h3>Master bus</h3><meter data-meter="__master__" min="0" max="1" value="0" aria-label="Master audio peak"></meter><p>'+esc(p.master_fx.map(f=>f.name).join(' → ')||'No imported effects')+'</p><button type="button" class="secondary" data-studio-native-refresh>Verify native REAPER parameters</button><details class="sf-studio-master-rack"><summary>Master plugins · original REAPER order and settings</summary>'+window.SFStemPluginRack.render('master','__master__',p.master_fx||[],esc)+'</details></div>'+this.routingHTML()+window.SFStemAutomation.render(p)+'<div class="sf-studio-tracks">'+tracks.map((t,i)=>this.trackHTML(t,i,max)).join('')+'</div>'+window.SFStemMastering.ui(p)+window.SFStemProductionAdvisor.render(p)+window.SFStemIntelligence.render(p)+window.SFStemAlignment.render(p)+'<footer class="sf-studio-footer"><label>Save mix <input data-studio-mix-name maxlength="100" placeholder="Mix name" value="Studio mix"></label><button type="button" class="primary" data-studio-save>Save mix</button><label>Recall mix <select data-studio-recall><option value="">Choose saved mix</option>'+Object.keys(p.mixes||{}).map(k=>'<option value="'+esc(k)+'">'+esc(k)+'</option>').join('')+'</select></label><span>Project revision '+p.revision+'</span></footer>';
     this.bindControls();
   }
   routingHTML(){const r=this.project.routing||{},b=r.buses||[],sends=r.sends||[];
     const nodes=[...this.project.tracks.map(t=>({id:t.guid,name:t.name})),...b.map(x=>({id:x.id,name:x.name}))];
     const opts=nodes.map(x=>'<option value="'+esc(x.id)+'">'+esc(x.name)+'</option>').join('');
     return '<section class="sf-studio-routing"><h3>Auxiliary routing &amp; group buses</h3><p>Browser interpretation of supported REAPER post-fader sends. Original project remains preserved.</p><small>'+n(r.unsupported_receives)+' unsupported native receives</small>'+
       '<div class="sf-route-add"><input data-route-name placeholder="Bus name" maxlength="80"><button data-route-add-bus>Create bus</button></div>'+
       '<div class="sf-route-buses">'+b.map(x=>'<article data-route-bus="'+esc(x.id)+'"><b>'+esc(x.name)+'</b><meter data-meter="'+esc(x.id)+'" min="0" max="1" value="0" aria-label="Bus peak"></meter><label>Gain <input data-bus-volume type="range" min="0" max="2" step=".01" value="'+n(this.busMix[x.id]?.volume,1)+'"></label><label>Pan <input data-bus-pan type="range" min="-1" max="1" step=".01" value="'+n(this.busMix[x.id]?.pan)+'"></label><label>Mute <input type="checkbox" data-bus-mute '+(this.busMix[x.id]?.mute?'checked':'')+'></label><button data-bus-save>Save</button><button data-bus-delete>Delete</button><details><summary>Bus FX</summary>'+window.SFStemPluginRack.render('bus',x.id,x.fx||[],esc)+'</details></article>').join('')+'</div>'+
       '<div class="sf-route-add"><label>From <select data-route-source>'+opts+'</select></label><label>To <select data-route-target>'+opts+'</select></label><button data-route-add-send>Add send</button></div>'+
       '<div class="sf-route-sends">'+sends.map(x=>'<div data-route-send="'+esc(x.id)+'"><b>'+esc(nodes.find(n=>n.id===x.source_guid)?.name||'?')+' → '+esc(nodes.find(n=>n.id===x.target_guid)?.name||'?')+'</b><small>'+esc(x.origin||'')+'</small><label>Level <input data-send-gain type="number" min="0" max="4" step=".05" value="'+n(x.gain,1)+'" '+(x.origin==='BROWSER'?'':'disabled')+'></label>'+(x.origin==='BROWSER'?'<button data-send-save>Save</button><button data-send-delete>Delete</button>':'')+'</div>').join('')+'</div></section>';
   }
   trackHTML(t,i,total){
     const mix=this.mixing[t.guid]||{},files=(t.clips||[]),left=Math.max(0,Math.min(100,100*n(t.source_position)/total));
     const width=Math.max(0,Math.min(100-left,100*n(t.duration_seconds)/total));
     const types=t.fx?.map(f=>f.type==='unsupported'?f.name:f.type.toUpperCase())||[];
     const assigned=t.asset_uuid?'Ready':'Missing audio';
     return '<article class="sf-studio-track" data-guid="'+esc(t.guid)+'"><div class="sf-studio-track-meta"><b>'+(i+1)+'. '+esc(t.name)+'</b><meter data-meter="'+esc(t.guid)+'" min="0" max="1" value="0" aria-label="Track peak"></meter><span>'+esc(t.role||'Other')+' · '+assigned+'</span><span class="sf-studio-fx">'+esc(types.join(' → ')||'No track effects')+'</span></div><div class="sf-studio-mix-strip"><label>Gain<input type="range" data-stem-vol min="0" max="2" step=".01" value="'+n(mix.volume,1)+'"></label><label>Pan<input type="range" data-stem-pan min="-1" max="1" step=".01" value="'+n(mix.pan)+'"></label><label><input type="checkbox" data-stem-mute '+(mix.mute?'checked':'')+'>Mute</label><label><input type="checkbox" data-stem-solo '+(mix.solo?'checked':'')+'>Solo</label></div><div class="sf-studio-lane" data-studio-timeline>'+window.SFStemTimeline.render(t,total,esc)+'<div class="sf-studio-playhead" data-studio-head></div></div><details class="sf-studio-inspector"><summary>Track details · '+files.length+' clips · '+esc(t.fx?.length||0)+' plugins</summary><label>Name<input data-stem-name maxlength="180" value="'+esc(t.name)+'"></label><label>Role<input data-stem-role maxlength="80" value="'+esc(t.role||'Other')+'"></label><button type="button" class="secondary" data-stem-edit>Save track</button><button type="button" class="secondary" data-stem-waveform>Load real waveform</button><p>Attached REAPER source: '+esc(t.source_file||'none')+'</p><label>Attach existing Media Library asset ID<input type="number" min="1" data-stem-asset-id placeholder="Asset ID"></label><button type="button" class="secondary" data-stem-assign>Attach asset</button>'+this.clipInspector(t)+window.SFStemPluginRack.render('track',t.guid,t.fx||[],esc)+'</details></article>';
   }
   clipInspector(t){
     const files=t.clips||[];
     if(!files.length)return '<p>No REAPER clips in this track.</p>';
     const selected=Math.min(files.length-1,Math.max(0,this.selectedClips[t.guid]||0)),clip=files[selected];
     return '<div class="sf-studio-clip-inspector"><h4>Timeline clips · non-destructive edit</h4><label>Clip <select data-stem-clip-select>'+
       files.map((c,i)=>'<option value="'+i+'" '+(i===selected?'selected':'')+'>'+esc((i+1)+'. '+(c.file||'No source'))+'</option>').join('')+
       '</select></label><label>Position (s)<input type="number" data-clip-position min="0" max="86400" step=".01" value="'+n(clip.position)+'"></label>'+
       '<label>Length (s)<input type="number" data-clip-length min=".02" max="86400" step=".01" value="'+n(clip.length)+'"></label>'+
       '<label>Source offset (s)<input type="number" data-clip-offset min="0" max="86400" step=".01" value="'+n(clip.offset)+'"></label>'+
       '<button type="button" class="secondary" data-stem-clip-save>Save clip</button><p>Drag clip to move; drag its right edge to trim. Snap-to-beat is optional. Unlinked takes are visible but do not play.</p></div>';
   }
   legacyFXInspector(t){
     const effects=t.fx||[],specs=window.SFStemFX?.specs||{};
     return '<div class="sf-studio-effects"><h4>REAPER effects · browser approximation</h4>'+
       (effects.length?effects.map((fx,i)=>{
         const spec=specs[fx.type];
         const parameters=window.SFStemFX?.parameters(fx)||{};
         return '<div class="sf-studio-effect" data-stem-fx-index="'+i+'"><b>'+esc(fx.name||fx.type||'Unknown plugin')+'</b>'+
           (!spec?'<small>Plugin identity preserved; browser controls not supported.</small>':
             '<label><input type="checkbox" data-fx-enabled '+(fx.enabled!==false?'checked':'')+'> Enabled</label>'+
             Object.entries(spec).map(([name,range])=>'<label>'+esc(name)+
               '<input type="number" data-fx-param="'+esc(name)+'" min="'+range[0]+'" max="'+range[1]+
               '" step="any" value="'+n(parameters[name],range[2])+'"></label>').join('')+
             '<button type="button" class="secondary" data-stem-fx-save>Save effect</button>')+
           '</div>';
       }).join(''):'<small>No REAPER effects on this track.</small>')+
       '<small>These are editable Web Audio approximations. Native REAPER VST states are not executed or overwritten.</small></div>';
   }
   async commitEdit(payload){
     if(this.saving)return;
     this.saving=true;
     try{
       const result=await api('stem-studio.php',{
         ...payload,track_id:this.id,revision:this.project.revision
       });
       this.pause();
       this.project=result.project;
       if(payload.action==='recall_mix')this.updateMix();
       this.recalculateDuration();
       await this.resetAudio();
       this.render();
       this.say('Stem project edit saved · revision '+this.project.revision);
     }catch(e){
       this.say('Edit not saved: '+e.message);
       this.render();
     }finally{this.saving=false}
   }
   async resetAudio(){
     for(const entry of this.audio.values())for(const item of entry.clips||[]){
       item.media.pause();item.media.removeAttribute('src');item.media.load();
     }
     this.audio.clear();this.busNodes.clear();this.meters.clear();
     if(this.context){try{await this.context.close()}catch(_){}
       this.context=null;this.master=null;
     }
   }
   bindControls(){
     const root=this.overlay;
     window.SFStemPluginRack.bind(root,payload=>this.commitEdit(payload));
     window.SFStemAutomation.bind(root,this);
     window.SFStemMastering.bind(root,this);
     window.SFStemProductionAdvisor.bind(root,this);
     window.SFStemIntelligence.bind(root,this);
     window.SFStemAlignment.bind(root,this);
     const route=payload=>this.commitEdit(payload);
     el('[data-route-add-bus]',root)?.addEventListener('click',()=>route({action:'route_add_bus',name:el('[data-route-name]',root).value}));
     el('[data-route-add-send]',root)?.addEventListener('click',()=>route({action:'route_add_send',source_guid:el('[data-route-source]',root).value,target_guid:el('[data-route-target]',root).value}));
     all('[data-route-bus]',root).forEach(row=>{const id=row.dataset.routeBus;
       for(const [key,attr] of [['volume','[data-bus-volume]'],['pan','[data-bus-pan]']])el(attr,row).oninput=e=>{this.busMix[id][key]=n(e.target.value);this.applyMix()};
       el('[data-bus-mute]',row).onchange=e=>{this.busMix[id].mute=e.target.checked;this.applyMix()};
       el('[data-bus-save]',row).onclick=()=>route({action:'route_update_bus',id,...this.busMix[id]});
       el('[data-bus-delete]',row).onclick=()=>route({action:'route_delete_bus',id});
     });
     all('[data-route-send]',root).forEach(row=>{const id=row.dataset.routeSend;
       el('[data-send-save]',row)?.addEventListener('click',()=>route({action:'route_update_send',id,gain:n(el('[data-send-gain]',row).value)}));
       el('[data-send-delete]',row)?.addEventListener('click',()=>route({action:'route_delete_send',id}));
     });

     const nativeRefresh=el('[data-studio-native-refresh]',root);
     if(nativeRefresh)nativeRefresh.onclick=()=>{
       if(!confirm('Recheck the saved REAPER project for verified native settings? Your clips, original .rpp and deliberate browser parameter overrides are preserved.'))return;
       this.commitEdit({action:'native_decode_refresh'});
     };
     el('[data-studio-play]',root).onclick=()=>this.running?this.pause():this.play();
     el('[data-studio-stop]',root).onclick=()=>this.stop();
     el('[data-studio-loop]',root).onchange=e=>this.loop=e.target.checked;
     el('[data-studio-seek]',root).oninput=e=>this.seek(n(e.target.value));
     el('[data-studio-undo]',root).disabled=!(this.project.edit_history?.undo?.length);
     el('[data-studio-redo]',root).disabled=!(this.project.edit_history?.redo?.length);
     el('[data-studio-undo]',root).onclick=()=>this.commitEdit({action:'undo_edit'});
     el('[data-studio-redo]',root).onclick=()=>this.commitEdit({action:'redo_edit'});
     // Preserve the existing on-demand real PCM waveform action for linked lanes.
     all('[data-stem-clip-index]',root).forEach(clipElement=>{
       const t=this.project.tracks.find(track=>track.guid===clipElement.dataset.stemClipGuid);
       const clip=t?.clips[Number(clipElement.dataset.stemClipIndex)];
       if(!t?.media_ready||!clip||String(clip.file||'').toLowerCase()!==String(t.source_file||'').toLowerCase())return;
       const canvas=document.createElement('canvas');
       canvas.className='sf-studio-waveform';
       canvas.dataset.stemWaveformCanvas='';
       canvas.setAttribute('aria-hidden','true');
       clipElement.prepend(canvas);
     });
     window.SFStemTimeline.bind(root,this.project,{
       duration:this.duration,locked:()=>this.saving,
       snap:()=>!!el('[data-studio-snap]',root)?.checked,
       onSelect:(guid,index)=>{
         this.selectedClips[guid]=index;this.render();
         const row=all('[data-guid]',root).find(e=>e.dataset.guid===guid);
         row?.querySelector('.sf-studio-inspector')?.setAttribute('open','');
       },
       onEdit:(guid,index,clip)=>this.commitEdit({action:'edit_clip',guid,clip_index:index,...clip})
     });
     el('[data-studio-save]',root).onclick=()=>this.saveMix();
     el('[data-studio-recall]',root).onchange=e=>this.recall(e.target.value);
     all('[data-guid]',root).forEach(row=>{
       const guid=row.dataset.guid, state=this.mixing[guid];
       for(const [key,attr] of [['volume','[data-stem-vol]'],['pan','[data-stem-pan]']]){
         el(attr,row).oninput=e=>{state[key]=n(e.target.value);this.applyMix()};
       }
       for(const [key,attr] of [['mute','[data-stem-mute]'],['solo','[data-stem-solo]']]){
         el(attr,row).onchange=e=>{state[key]=e.target.checked;this.applyMix()};
       }
       el('[data-stem-edit]',row).onclick=async()=>{
         try{const j=await api('stem-studio.php',{action:'edit_track',track_id:this.id,revision:this.project.revision,guid,name:el('[data-stem-name]',row).value,role:el('[data-stem-role]',row).value});this.project=j.project;this.say('Track metadata saved');this.render()}catch(e){this.say(e.message)}
       };
       el('[data-stem-clip-select]',row)?.addEventListener('change',e=>{
         this.selectedClips[guid]=Number(e.target.value)||0;this.render();
         const next=all('[data-guid]',root).find(element=>element.dataset.guid===guid);
         next?.querySelector('.sf-studio-inspector')?.setAttribute('open','');
       });
       el('[data-stem-clip-save]',row)?.addEventListener('click',()=>this.commitEdit({
         action:'edit_clip',guid,clip_index:this.selectedClips[guid]||0,
         position:el('[data-clip-position]',row).value,
         length:el('[data-clip-length]',row).value,
         offset:el('[data-clip-offset]',row).value
       }));
       all('[data-stem-fx-save]',row).forEach(button=>button.onclick=()=>{
         const fxRow=button.closest('[data-stem-fx-index]');
         const settings={};
         all('[data-fx-param]',fxRow).forEach(input=>settings[input.dataset.fxParam]=input.value);
         this.commitEdit({action:'edit_fx',guid,fx_index:Number(fxRow.dataset.stemFxIndex),
           enabled:!!el('[data-fx-enabled]',fxRow)?.checked,settings});
       });
       el('[data-stem-waveform]',row).onclick=async()=>{
         const track=this.project.tracks.find(t=>t.guid===guid);
         const canvas=el('[data-stem-waveform-canvas]',row);
         try{
           if(!window.SFStemWaveform)throw new Error('Waveform module unavailable.');
           await window.SFStemWaveform.load(canvas,track?.audio_url,track?.audio_size_bytes);
           this.say('Waveform decoded for '+(track?.name||'stem'));
         }catch(e){this.say('Waveform: '+e.message);}
       };
       el('[data-stem-assign]',row).onclick=async()=>{
         try{const j=await api('stem-studio.php',{action:'assign',track_id:this.id,revision:this.project.revision,guid,asset_id:n(el('[data-stem-asset-id]',row).value)});this.project=j.project;this.say('Private media assigned');this.render()}catch(e){this.say(e.message)}
       };
     });
   }
   async ensureAudio(){
     if(this.context)return;
     this.context=new (window.AudioContext||window.webkitAudioContext)();
     this.master=this.context.createGain();this.master.gain.value=.82;
     this.masterProcessor=this.context.createGain();
     this.masterFX=window.SFStemFX.build(this.context,this.master,this.project.master_fx||[],this.masterProcessor);
     this.meters=new Map();
     const masterMeter=this.context.createAnalyser();masterMeter.fftSize=512;
     this.masterProcessor.connect(masterMeter);masterMeter.connect(this.context.destination);
     this.meters.set('__master__',masterMeter);
     this.busNodes=new Map();
     for(const b of this.project.routing?.buses||[]){
       const input=this.context.createGain(),gain=this.context.createGain(),pan=this.context.createStereoPanner();
       const fx=window.SFStemFX.build(this.context,input,b.fx||[],gain);
       const meter=this.context.createAnalyser();meter.fftSize=512;
       gain.connect(pan);pan.connect(meter);meter.connect(this.master);
       this.meters.set(b.id,meter);
       this.busNodes.set(b.id,{input,gain,pan,out:meter,fx});
     }
     for(const t of this.project.tracks){
       const selected=(t.media_ready&&t.audio_url?(t.clips||[]):[]).filter(c=>String(c.file||'').toLowerCase()===String(t.source_file||'').toLowerCase()&&n(c.length)>.01);
       const input=this.context.createGain(),gain=this.context.createGain(),pan=this.context.createStereoPanner();
       const fx=window.SFStemFX.build(this.context,input,t.fx||[],gain);
       const meter=this.context.createAnalyser();meter.fftSize=512;
       gain.connect(pan);pan.connect(meter);this.meters.set(t.guid,meter);
       const clips=[];
       for(const clip of selected){const media=new Audio(t.audio_url);media.preload='metadata';this.context.createMediaElementSource(media).connect(input);clips.push({media,clip});}
       this.audio.set(t.guid,{clips,input,gain,pan,out:meter,track:t,fx});
     }
     // Routing may reference unlinked audio tracks: do not invent silent processors.
     for(const t of this.project.tracks){const entry=this.audio.get(t.guid);if(!entry)continue;
       const parent=this.audio.get(t.folder_parent_guid)?.input||this.busNodes.get(t.folder_parent_guid)?.input;
       if(t.main_send_enabled!==false)entry.out.connect(parent||this.master);
     }
     for(const send of this.project.routing?.sends||[]){
       if(send.enabled===false||send.mode!=='post-fader')continue;
       const source=this.audio.get(send.source_guid)?.out||this.busNodes.get(send.source_guid)?.out;
       const target=this.audio.get(send.target_guid)?.input||this.busNodes.get(send.target_guid)?.input;
       if(source&&target){const gain=this.context.createGain();gain.gain.value=n(send.gain,0);source.connect(gain);gain.connect(target)}
     }
     this.applyMix();this.applyAutomation(this.anchor);
   }
   applyMix(){if(!this.context)return;
     const solo=Object.values(this.mixing).some(x=>x.solo);
     for(const [guid,nodes] of this.audio){const state=this.mixing[guid];if(!state)continue;
       const active=!state.mute&&(!solo||state.solo);
       nodes.gain.gain.setTargetAtTime(active?state.volume:0,this.context.currentTime,.008);
       nodes.pan.pan.setTargetAtTime(state.pan,this.context.currentTime,.008);
     }
     for(const [id,nodes] of this.busNodes){const state=this.busMix[id];if(!state)continue;
       nodes.gain.gain.setTargetAtTime(state.mute?0:state.volume,this.context.currentTime,.008);
       nodes.pan.pan.setTargetAtTime(state.pan,this.context.currentTime,.008);
     }
     this.applyAutomation(this.currentTime());
   }
   applyAutomation(time){if(!this.context||!this.project)return;
     window.SFStemAutomation.effective(this,Math.max(0,time));
   }
   currentTime(){return this.running&&this.context?Math.min(this.duration,this.anchor+(this.context.currentTime-this.anchorClock)):this.anchor}
   sync(){
     const time=this.currentTime();
     for(const nodes of this.audio.values())for(const {media,clip} of nodes.clips){
       const elapsed=time-n(clip.position);
       const offset=n(clip.offset);
       const playable=elapsed>=0&&elapsed<n(clip.length);
       if(!playable || (Number.isFinite(media.duration)&&offset+elapsed>=media.duration)){
         if(!media.paused)media.pause();
         continue;
       }
       if(media.readyState<1)continue;
       const target=offset+elapsed;
       if(Math.abs(media.currentTime-target)>.08){
         try{media.currentTime=Math.max(0,target)}catch(_){}
       }
       if(this.running&&media.paused)media.play().catch(()=>{});
     }
   }
   async play(){if(!this.project)return;try{
     await this.ensureAudio();await this.context.resume();
     if(this.anchor>=this.duration)this.anchor=0;
     this.anchorClock=this.context.currentTime;this.running=true;this.sync();
     el('[data-studio-play]',this.overlay).textContent='Ⅱ Pause';this.tick();
   }catch(e){this.running=false;this.say('Audio could not start: '+e.message)}}
   pause(){this.anchor=this.currentTime();this.running=false;cancelAnimationFrame(this.raf);
     for(const entry of this.audio.values())for(const {media} of entry.clips)media.pause();const b=el('[data-studio-play]',this.overlay);if(b)b.textContent='▶ Play';this.paintTime();}
   stop(){this.pause();this.anchor=0;for(const entry of this.audio.values())for(const {media} of entry.clips)try{media.currentTime=0}catch(_){}this.paintTime();}
   seek(t){const was=this.running;this.anchor=Math.max(0,Math.min(this.duration,t));if(this.context)this.anchorClock=this.context.currentTime;if(was)this.sync();this.applyAutomation(this.anchor);this.paintTime()}
   tick(){if(!this.running)return;const t=this.currentTime();if(t>=this.duration){if(this.loop)this.seek(0);else {this.stop();return;}}
     this.sync();this.applyAutomation(t);this.paintTime();this.raf=requestAnimationFrame(()=>this.tick());}
   paintMeters(){if(!this.context||this.context.currentTime-this.lastMeterDraw<0.08)return;
     this.lastMeterDraw=this.context.currentTime;
     for(const [id,node] of this.meters){const output=this.overlay?.querySelectorAll('[data-meter]');if(!output)continue;
       const meter=[...output].find(e=>e.dataset.meter===id);if(!meter)continue;
       const values=new Float32Array(node.fftSize);node.getFloatTimeDomainData(values);
       let peak=0;for(const sample of values)peak=Math.max(peak,Math.abs(sample));
       meter.value=Math.min(1,peak);
       meter.title=Math.round(20*Math.log10(Math.max(peak,0.00001)))+' dBFS peak';
     }
   }
   paintTime(){this.paintMeters();const t=this.currentTime(),e=el('[data-studio-time]',this.overlay),seek=el('[data-studio-seek]',this.overlay);if(e)e.textContent=fmt(t)+' / '+fmt(this.duration);if(seek)seek.value=t;
     all('[data-studio-head]',this.overlay).forEach(x=>x.style.left=Math.min(100,100*t/this.duration)+'%');}
   async saveMix(){const name=el('[data-studio-mix-name]',this.overlay)?.value?.trim();if(!name)return this.say('Enter a mix name.');
     try{const j=await api('stem-studio.php',{action:'save_mix',track_id:this.id,revision:this.project.revision,mix:{name,tracks:clone(this.mixing),buses:clone(this.busMix)}});this.project=j.project;this.say('Saved mix '+name);this.render()}catch(e){this.say(e.message)}}
   recall(name){if(!this.project?.mixes?.[name])return;this.commitEdit({action:'recall_mix',name})}
   close(){this._stemAlignmentDispose?.();this._stemIntelligenceDispose?.();this._masteringDispose?.();this.stop();void this.resetAudio();this.overlay?.remove();if(studio===this)studio=null}
 }
 async function open(id){if(studio)studio.close();studio=new StemStudio(id);await studio.init();}
 window.SFStemStudio={section,bind,open};
})();
