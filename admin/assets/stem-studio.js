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
   constructor(id){this.id=id;this.project=null;this.overlay=null;this.audio=new Map();this.context=null;this.master=null;this.running=false;this.anchor=0;this.anchorClock=0;this.loop=false;this.raf=0;this.duration=0;this.mixing={};}
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
   updateMix(){this.mixing={};for(const t of this.project?.tracks||[])this.mixing[t.guid]={volume:n(t.volume,1),pan:n(t.pan),mute:!!t.mute,solo:!!t.solo};this.duration=Math.max(1,...(this.project?.tracks||[]).flatMap(t=>(t.clips||[]).map(c=>n(c.position)+n(c.length))))}
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
     const area=el('[data-studio-body]',this.overlay);if(!area)return;
     if(!this.project){area.innerHTML='<div class="sf-studio-empty">Upload a single ZIP with your .rpp and WAV/MP3 stems, or import them separately. The REAPER project defines the lanes, plugins, timeline offsets and master bus.</div>';return}
     const p=this.project,tracks=p.tracks||[];
     const max=this.duration;
     area.innerHTML='<div class="sf-studio-top"><div class="sf-studio-properties"><strong>'+esc(p.project_name)+'</strong><span>'+tracks.length+' tracks</span><span>'+esc(p.tempo_bpm)+' BPM</span><span>'+esc(p.time_signature)+'</span><span>'+esc(p.sample_rate||'Unknown')+' Hz</span></div><div class="sf-studio-transport"><button type="button" data-studio-play>▶ Play</button><button type="button" data-studio-stop>■ Stop</button><label><input type="checkbox" data-studio-loop> Loop</label><input type="range" data-studio-seek min="0" max="'+max+'" step=".05" value="0"><output data-studio-time>0:00 / '+fmt(max)+'</output></div></div><div class="sf-studio-master"><h3>Master bus</h3><p>'+esc(p.master_fx.map(f=>f.name).join(' → ')||'No imported effects')+'</p><small>REAPER plugin identities are retained. Native VST presets are not executed in a browser; supported processing uses approximate Web Audio nodes.</small></div><div class="sf-studio-tracks">'+tracks.map((t,i)=>this.trackHTML(t,i,max)).join('')+'</div><footer class="sf-studio-footer"><label>Save mix <input data-studio-mix-name maxlength="100" placeholder="Mix name" value="Studio mix"></label><button type="button" class="primary" data-studio-save>Save mix</button><label>Recall mix <select data-studio-recall><option value="">Choose saved mix</option>'+Object.keys(p.mixes||{}).map(k=>'<option value="'+esc(k)+'">'+esc(k)+'</option>').join('')+'</select></label><span>Project revision '+p.revision+'</span></footer>';
     this.bindControls();
   }
   trackHTML(t,i,total){
     const mix=this.mixing[t.guid]||{},files=(t.clips||[]),left=Math.max(0,Math.min(100,100*n(t.source_position)/total));
     const width=Math.max(0,Math.min(100-left,100*n(t.duration_seconds)/total));
     const types=t.fx?.map(f=>f.type==='unsupported'?f.name:f.type.toUpperCase())||[];
     const assigned=t.asset_uuid?'Ready':'Missing audio';
     return '<article class="sf-studio-track" data-guid="'+esc(t.guid)+'"><div class="sf-studio-track-meta"><b>'+(i+1)+'. '+esc(t.name)+'</b><span>'+esc(t.role||'Other')+' · '+assigned+'</span><span class="sf-studio-fx">'+esc(types.join(' → ')||'No track effects')+'</span></div><div class="sf-studio-mix-strip"><label>Gain<input type="range" data-stem-vol min="0" max="2" step=".01" value="'+n(mix.volume,1)+'"></label><label>Pan<input type="range" data-stem-pan min="-1" max="1" step=".01" value="'+n(mix.pan)+'"></label><label><input type="checkbox" data-stem-mute '+(mix.mute?'checked':'')+'>Mute</label><label><input type="checkbox" data-stem-solo '+(mix.solo?'checked':'')+'>Solo</label></div><div class="sf-studio-lane"><div class="sf-studio-clip" style="left:'+left+'%;width:'+width+'%" title="'+esc(t.source_file)+'"><canvas class="sf-studio-waveform" data-stem-waveform-canvas aria-label="Real decoded audio waveform"></canvas><span>'+esc(t.source_file||'Unlinked source')+'</span></div><div class="sf-studio-playhead" data-studio-head></div></div><details class="sf-studio-inspector"><summary>Track details · '+files.length+' clips · '+esc(t.fx?.length||0)+' plugins</summary><label>Name<input data-stem-name maxlength="180" value="'+esc(t.name)+'"></label><label>Role<input data-stem-role maxlength="80" value="'+esc(t.role||'Other')+'"></label><button type="button" class="secondary" data-stem-edit>Save track</button><button type="button" class="secondary" data-stem-waveform>Load real waveform</button><p>Expected file: '+esc(t.source_file||'none')+'</p><label>Attach an existing Media Library asset ID<input type="number" min="1" data-stem-asset-id placeholder="Asset ID"></label><button type="button" class="secondary" data-stem-assign>Attach asset</button><ul>'+files.slice(0,40).map(c=>'<li>'+fmt(c.position)+' · '+fmt(c.length)+' · '+esc(c.file)+'</li>').join('')+'</ul></details></article>';
   }
   bindControls(){
     const root=this.overlay;
     el('[data-studio-play]',root).onclick=()=>this.running?this.pause():this.play();
     el('[data-studio-stop]',root).onclick=()=>this.stop();
     el('[data-studio-loop]',root).onchange=e=>this.loop=e.target.checked;
     el('[data-studio-seek]',root).oninput=e=>this.seek(n(e.target.value));
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
     this.master=this.context.createGain();this.master.gain.value=.82;this.master.connect(this.context.destination);
     for(const t of this.project.tracks){if(!t.media_ready||!t.audio_url)continue;
       const media=new Audio(t.audio_url);media.preload='metadata';
       const source=this.context.createMediaElementSource(media);
       const gain=this.context.createGain();const pan=this.context.createStereoPanner();
       const eq=this.context.createBiquadFilter();eq.type='peaking';eq.frequency.value=900;eq.Q.value=.9;
       const hasEQ=t.fx.some(x=>x.type==='eq');eq.gain.value=hasEQ?0:0;
       const comp=this.context.createDynamicsCompressor();comp.threshold.value=-14;comp.ratio.value=t.fx.some(x=>x.type==='compressor')?2:1;
       source.connect(eq);eq.connect(comp);comp.connect(gain);gain.connect(pan);pan.connect(this.master);
       this.audio.set(t.guid,{media,gain,pan,track:t});
     }
     this.applyMix();
   }
   applyMix(){const solo=Object.values(this.mixing).some(x=>x.solo);for(const [guid,nodes] of this.audio){
     const state=this.mixing[guid];if(!state)continue;
     const active=!state.mute&&(!solo||state.solo);
     nodes.gain.gain.setTargetAtTime(active?state.volume:0,this.context.currentTime,.008);
     nodes.pan.pan.setTargetAtTime(state.pan,this.context.currentTime,.008);
   }}
   currentTime(){return this.running&&this.context?Math.min(this.duration,this.anchor+(this.context.currentTime-this.anchorClock)):this.anchor}
   sync(){const time=this.currentTime();for(const {media,track} of this.audio.values()){
     const offset=n(track.source_position),relative=time-offset;
     if(relative<0||relative>=media.duration&&!Number.isNaN(media.duration)){media.pause();continue}
     if(media.readyState<1)continue;
     if(Math.abs(media.currentTime-relative)>.15){try{media.currentTime=Math.max(0,relative)}catch(_){}}
     if(this.running&&media.paused)media.play().catch(()=>{});
   }}
   async play(){if(!this.project)return;try{
     await this.ensureAudio();await this.context.resume();
     if(this.anchor>=this.duration)this.anchor=0;
     this.anchorClock=this.context.currentTime;this.running=true;this.sync();
     el('[data-studio-play]',this.overlay).textContent='Ⅱ Pause';this.tick();
   }catch(e){this.running=false;this.say('Audio could not start: '+e.message)}}
   pause(){this.anchor=this.currentTime();this.running=false;cancelAnimationFrame(this.raf);
     for(const {media} of this.audio.values())media.pause();const b=el('[data-studio-play]',this.overlay);if(b)b.textContent='▶ Play';this.paintTime();}
   stop(){this.pause();this.anchor=0;for(const {media} of this.audio.values())try{media.currentTime=0}catch(_){}this.paintTime();}
   seek(t){const was=this.running;this.anchor=Math.max(0,Math.min(this.duration,t));if(this.context)this.anchorClock=this.context.currentTime;if(was)this.sync();this.paintTime()}
   tick(){if(!this.running)return;const t=this.currentTime();if(t>=this.duration){if(this.loop)this.seek(0);else {this.stop();return;}}
     this.sync();this.paintTime();this.raf=requestAnimationFrame(()=>this.tick());}
   paintTime(){const t=this.currentTime(),e=el('[data-studio-time]',this.overlay),seek=el('[data-studio-seek]',this.overlay);if(e)e.textContent=fmt(t)+' / '+fmt(this.duration);if(seek)seek.value=t;
     all('[data-studio-head]',this.overlay).forEach(x=>x.style.left=Math.min(100,100*t/this.duration)+'%');}
   async saveMix(){const name=el('[data-studio-mix-name]',this.overlay)?.value?.trim();if(!name)return this.say('Enter a mix name.');
     try{const j=await api('stem-studio.php',{action:'save_mix',track_id:this.id,revision:this.project.revision,mix:{name,tracks:clone(this.mixing)}});this.project=j.project;this.say('Saved mix '+name);this.render()}catch(e){this.say(e.message)}}
   recall(name){const m=this.project?.mixes?.[name];if(!m)return;this.mixing=clone(m.tracks);this.applyMix();this.say('Recalled '+name);this.render()}
   close(){this.stop();for(const {media} of this.audio.values()){media.pause();media.removeAttribute('src');media.load()}this.audio.clear();this.context?.close();this.overlay?.remove();if(studio===this)studio=null}
 }
 async function open(id){if(studio)studio.close();studio=new StemStudio(id);await studio.init();}
 window.SFStemStudio={section,bind,open};
})();
