/* Exercise the production admin editor without browser or provider dependencies. */
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const test=require('node:test');
const assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../admin/assets/song-moments.js'),'utf8');
const decode=s=>String(s||'').replace(/&quot;/g,'"').replace(/&#39;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
const attrs=s=>Object.fromEntries(Array.from(s.matchAll(/([\w-]+)="([^"]*)"/g),m=>[m[1],decode(m[2])]));
const esc=v=>String(v??'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
function harness(options={}){
  const calls=[],confirmations=[],queries=[],reviews=[];
  const app={view:'song-moments'};
  let panel=null;
  let jobs=(options.jobs||[]).map(j=>({...j}));
  const entries=[
    {id:1,track_id:'song-b',at_ms:0,kind:'note',body:'Artist note',status:'draft',revision:1},
    {id:2,track_id:'song-b',at_ms:12345,end_ms:18567,kind:'lyric',body:'Saved lyric',status:'published',lyric_state:'approved',confidence:.85,timing_source:options.timingSource||'provider',revision:4},
    {id:3,track_id:'song-a',at_ms:0,end_ms:1000,kind:'lyric',body:'PRIVATE LISTENER TEXT',status:'private',source_annotation_id:10,revision:1},
    {id:4,track_id:'song-a',at_ms:1000,kind:'comment',body:'Pending comment',status:'pending',revision:1},
    {id:5,track_id:'song-a',at_ms:5000,kind:'note',body:'Different song note',status:'draft',revision:1}
  ];
  const data={entries,metrics:{},references:{tracks:[{id:'song-a',title:'First song',duration:60},{id:'song-b',title:'Second song',duration:90}],stories:[],photos:[]}};
  const canvas={html:'',form:null,buttons:new Map(),nodes:new Map(),
    set innerHTML(html){
      if(panel)panel.isConnected=false;
      this.html=html;this.buttons=new Map();this.nodes=new Map();
      for(const m of html.matchAll(/<button\b([^>]*)>/g)){
        const a=attrs(m[1]),dataset={};for(const [k,v] of Object.entries(a))if(k.startsWith('data-'))dataset[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=v;
        const b={dataset,disabled:/\bdisabled\b/.test(m[1]),scrollIntoView(){}};if(a.id)this.nodes.set('#'+a.id,b);
        for(const [k,v] of Object.entries(a))if(k.startsWith('data-')){const key='['+k+']';if(!this.buttons.has(key))this.buttons.set(key,[]);this.buttons.get(key).push(b);}
      }
      const filter=html.match(/<select id="sfMomentFilterSong"[^>]*>([\s\S]*?)<\/select>/);
      if(filter)this.nodes.set('#sfMomentFilterSong',{value:decode(filter[1].match(/<option value="([^"]*)" selected/)?.[1])});
      if(html.includes('id="sfLyricsApplyStatus"'))this.nodes.set('#sfLyricsApplyStatus',{textContent:''});
      const found=html.match(/<form\b[^>]*id="sfMomentAdminForm"[^>]*>([\s\S]*?)<\/form>/);
      if(!found){this.form=null;return;}
      const body=found[1],elements={};
      for(const m of body.matchAll(/<input\b([^>]*)>/g)){const a=attrs(m[1]);if(a.name)elements[a.name]={value:a.value||'',disabled:false,required:/\brequired\b/.test(m[1])};}
      for(const m of body.matchAll(/<textarea\b([^>]*)>([\s\S]*?)<\/textarea>/g)){const a=attrs(m[1]);elements[a.name]={value:decode(m[2]),disabled:false};}
      for(const m of body.matchAll(/<select\b([^>]*)>([\s\S]*?)<\/select>/g)){const a=attrs(m[1]),options=Array.from(m[2].matchAll(/<option\b([^>]*)>/g),x=>({a:attrs(x[1]),selected:/\bselected\b/.test(x[1])}));elements[a.name]={value:(options.find(o=>o.selected)||options[0])?.a.value||'',disabled:false};}
      const nodes=new Map([['#sfMomentFormStatus',{textContent:''}],['#sfMomentReset',{}],['[data-moment-body-label]',{textContent:''}]]);
      const group=name=>({hidden:false,querySelector:()=>elements[name],querySelectorAll:()=>[elements[name]]});
      nodes.set('[data-moment-photo]',group('media_uuid'));nodes.set('[data-moment-story]',group('story_id'));
      const lyricFields=['end_position','lyric_state','timing_source','confidence'].map(group);lyricFields.push({hidden:false,querySelectorAll:()=>[]});
      this.form={elements,lyricFields,events:{},addEventListener(name,fn){this.events[name]=fn;},querySelector:s=>nodes.get(s)||null,querySelectorAll:s=>s==='[data-moment-lyric]'?lyricFields:[],scrollIntoView(){}};
    },
    get innerHTML(){return this.html;},
    querySelector(s){if(s==='#sfMomentAdminForm')return this.form;if(s==='#sfMomentFilterSong')return this.nodes.get(s)||null;return this.nodes.get(s)||null;},
    querySelectorAll(s){return this.buttons.get(s)||[];}
  };
  const api=async(endpoint,payload)=>{
    if(payload)calls.push(payload);
    if(endpoint.startsWith('song-lyrics-import.php?track_id=')){
      const id=decodeURIComponent(endpoint.split('=')[1]);queries.push(id);
      if(options.read)await options.read(id);
      return {jobs:jobs.filter(j=>j.track_id===id).map(j=>({...j}))};
    }
    if(endpoint==='song-lyrics-import.php'){
      if(!payload)return {jobs:jobs.map(j=>({...j}))};
      if(payload.action==='enqueue'){const job={id:99,track_id:payload.track_id,status:'queued',completed_chunks:0,total_chunks:2,can_advance:true};jobs.push(job);return {job};}
      const current=jobs.find(j=>Number(j.id)===payload.job_id);
      if(['resume','retry','pause'].includes(payload.action)){
        const pausing=payload.action==='pause';
        const job={...current,status:pausing?'paused':'queued',can_advance:!pausing,pause_requested:pausing};
        jobs=jobs.map(j=>j.id===job.id?job:j);
        return {job};
      }
      const complete=Number(current?.completed_chunks||0)+1;
      const result=options.advance?await options.advance(current):{advanced:true,job:{...current,completed_chunks:complete,status:complete===current.total_chunks?'ready':'queued'}};
      if(result?.job)jobs=jobs.map(j=>j.id===result.job.id?result.job:j);
      return result;
    }
    if(payload)return {ok:true};if(options.timeline)await options.timeline();return data;
  };
  const open=track=>{app.view='song-moments';return context.window.SFSongMomentsAdmin.open('timeline',track);};
  const context={window:{STONEFELLOW_ADMIN_CONTEXT:{app,api,canvas,head:()=>'',say:()=>{},esc,openSongTimeline:track=>{reviews.push(track);return open(track);}}},document:{querySelector:()=>null},FormData:class{constructor(form){this.form=form;}get(name){const e=this.form.elements[name];return e&&!e.disabled?e.value:null;}},confirm:message=>{confirmations.push(message);return true;},setTimeout,clearTimeout};
  vm.runInNewContext(source,context,{filename:'song-moments.js'});
  function navigateToCatalog(){app.view='catalog';canvas.innerHTML='<p>Catalog editor</p>';}
  async function mount(track){
    navigateToCatalog();
    const target=panel={html:'',nodes:new Map(),isConnected:true,
      set innerHTML(markup){this.html=markup;this.nodes=new Map();for(const m of markup.matchAll(/<(?:button|select)\b([^>]*)>/g)){const a=attrs(m[1]);if(a.id)this.nodes.set('#'+a.id,{disabled:/\bdisabled\b/.test(m[1])});}},
      get innerHTML(){return this.html;},querySelector(s){return this.nodes.get(s)||null;}
    };
    return context.window.SFSongMomentsAdmin.mountCatalogImport(target,track);
  }
  return {canvas,calls,confirmations,queries,reviews,panel:()=>panel,importClick:id=>panel.querySelector('#'+id).onclick(),mount,navigateToCatalog,open,resumePublishedTrack:id=>context.window.SFSongMomentsAdmin.resumePublishedTrack(id),edit:id=>{const b=canvas.querySelectorAll('[data-moment-edit]').find(b=>Number(b.dataset.momentEdit)===id);assert.ok(b);b.onclick();},submit:()=>canvas.form.onsubmit({preventDefault(){}})};
}
const flush=()=>new Promise(resolve=>setImmediate(resolve));
const job=(id,track,status,completed=0,total=2)=>({id,track_id:track,status,completed_chunks:completed,total_chunks:total,can_advance:status==='queued'});
test('existing editor selects the correct song and excludes private listener lyrics',async()=>{
  const h=harness();await h.open();assert.equal(h.canvas.form.elements.track_id.value,'song-a');assert.doesNotMatch(h.canvas.html,/PRIVATE LISTENER TEXT/);assert.match(h.canvas.html,/Lyric segment/);
  h.edit(2);assert.equal(h.canvas.form.elements.track_id.value,'song-b');assert.equal(h.canvas.form.elements.position.value,'0:12.345');assert.equal(h.canvas.form.elements.end_position.value,'0:18.567');assert.equal(h.canvas.form.elements.revision.value,'4');assert.match(h.canvas.html,/PROVIDER TIMING/);
});
test('editing approved lyric invalidates review, publication and provider confidence',async()=>{
  const h=harness();await h.open();h.edit(2);const f=h.canvas.form.elements;
  f.body.value='Corrected words';f.body.oninput();assert.equal(f.lyric_state.value,'corrected');assert.equal(f.status.value,'draft');assert.equal(f.confidence.value,'');
  f.position.value='0:12.500';f.position.oninput();assert.equal(f.timing_source.value,'manual');
  await h.submit();assert.equal(h.calls.length,1);const e=h.calls[0].entry;assert.equal(e.at_ms,12500);assert.equal(e.end_ms,18567);assert.equal(e.revision,4);assert.equal(e.confidence,null);assert.equal(e.lyric_state,'corrected');assert.equal(e.status,'draft');
});
test('publication requires explicit lyric approval and confirmation',async()=>{
  const h=harness();await h.open();h.edit(2);const f=h.canvas.form.elements;f.lyric_state.value='unverified';f.status.value='published';await h.submit();assert.equal(h.calls.length,0);assert.match(h.canvas.form.querySelector('#sfMomentFormStatus').textContent,/Approved/);
  f.lyric_state.value='approved';await h.submit();assert.equal(h.calls.length,1);assert.equal(h.calls[0].entry.confirmed,true);assert.equal(h.calls[0].entry.confidence,.85);assert.equal(h.calls[0].entry.timing_source,'provider');assert.match(h.confirmations[0],/reviewed lyric segment/);
});
test('range, confidence and format checks block invalid lyric saves',async()=>{
  const h=harness();await h.open();h.edit(2);const f=h.canvas.form.elements;
  for(const end of ['0:12.345','0:09.000','1:30.001','0:61']){f.end_position.value=end;await h.submit();assert.equal(h.calls.length,0);}
  f.end_position.value='0:20';f.confidence.value='1.01';await h.submit();assert.equal(h.calls.length,0);f.confidence.value='';f.position.value='0:12.2';await h.submit();assert.equal(h.calls.length,1);assert.equal(h.calls[0].entry.at_ms,12200);assert.equal(h.calls[0].entry.end_ms,20000);
});
test('non-lyric moments keep their existing workflow without lyric fields',async()=>{
  const h=harness();await h.open();h.edit(1);const f=h.canvas.form.elements;assert.equal(f.kind.value,'note');assert.ok(f.end_position.disabled);assert.ok(h.canvas.form.lyricFields.every(n=>n.hidden));f.position.value='0:42';await h.submit();assert.equal(h.calls.length,1);assert.equal(h.calls[0].entry.at_ms,42000);assert.equal(h.calls[0].entry.kind,'note');assert.equal('end_ms' in h.calls[0].entry,false);
});
test('Catalog advances only the current song serially and Song Moments has no import controls',async()=>{
  const h=harness({jobs:[job(1,'song-a','queued'),job(2,'song-b','queued')]});
  await h.open();await flush();
  assert.doesNotMatch(h.canvas.html,/sfLyricsImport|SONG LYRICS IMPORT/);assert.deepEqual(h.queries,[]);assert.deepEqual(h.calls,[]);
  await h.mount('song-a');await flush();
  assert.deepEqual(h.calls.filter(c=>c.action==='advance').map(c=>c.job_id),[1,1]);
  assert.deepEqual(h.queries,['song-a']);
  const panel=h.panel();assert.match(panel.html,/READY · 2 \/ 2 blocks saved/);assert.match(panel.html,/APPROXIMATE BLOCK TIMING/);assert.match(panel.html,/Review lyric segments/);assert.equal(panel.querySelector('#sfLyricsImportEnqueue').disabled,true);
  assert.doesNotMatch(panel.html,/<select|Save approved lyrics to song/);
});
test('stopping the worker lets the current request finish and preserves the Catalog editor',async()=>{
  let resolve;const pending=new Promise(r=>{resolve=r;});const h=harness({jobs:[job(1,'song-a','queued')],advance:()=>pending});await h.mount('song-a');
  const form={title:'Unsaved title'};h.canvas.form=form;h.importClick('sfLyricsImportWorker');
  resolve({advanced:true,job:job(1,'song-a','queued',1)});await flush();
  assert.deepEqual(h.calls.filter(c=>c.action==='advance').map(c=>c.job_id),[1]);assert.match(h.panel().html,/Resume worker/);assert.equal(h.canvas.form,form);assert.equal(form.title,'Unsaved title');
});
test('pausing an import saves the selected job pause without stopping the browser worker for other jobs',async()=>{
  let release;
  const pending=new Promise(resolve=>{release=resolve;});
  const h=harness({jobs:[job(1,'song-a','queued'),job(2,'song-b','queued')],advance:current=>current.id===1?pending:{advanced:true,job:job(2,'song-b','ready',2)}});
  await h.mount('song-a');
  assert.equal(await h.resumePublishedTrack('song-b'),true);
  assert.match(h.panel().html,/Pause import/);
  await h.importClick('sfLyricsImportPause');
  assert.match(h.panel().html,/PAUSED/);
  assert.match(h.panel().html,/Resume import/);
  release({advanced:true,job:job(1,'song-a','paused',1)});
  await flush();
  assert.deepEqual(h.calls.map(c=>[c.action,c.job_id]),[['advance',1],['pause',1],['advance',2]]);
});
test('retry is explicit for failed imports and unavailable for unknown provider outcomes',async()=>{
  const h=harness({jobs:[job(1,'song-a','failed'),job(2,'song-b','unknown')]});
  await h.mount('song-a');
  assert.equal(h.calls.length,0);
  assert.match(h.panel().html,/Retry import/);
  await h.mount('song-b');
  assert.doesNotMatch(h.panel().html,/Retry import/);
  assert.equal(h.panel().querySelector('#sfLyricsImportEnqueue').disabled,true);
  await h.importClick('sfLyricsImportEnqueue');
  assert.equal(h.calls.length,0);
  await h.mount('song-a');
  await h.importClick('sfLyricsImportEnqueue');
  await flush();
  assert.deepEqual(h.calls.map(c=>[c.action,c.job_id]),[['retry',1],['advance',1],['advance',1]]);
});
test('a queued response without durable progress stops the worker instead of spinning',async()=>{
  const queued=job(1,'song-a','queued');const h=harness({jobs:[queued],advance:async()=>({advanced:true,job:{...queued,error_code:'local_timeline_import_pending'}})});await h.mount('song-a');await flush();
  assert.equal(h.calls.filter(c=>c.action==='advance').length,1);assert.match(h.panel().html,/No block was saved/);assert.match(h.panel().html,/Resume worker/);
  assert.equal(h.panel().querySelector('#sfLyricsImportWorker').disabled,true);
  await h.importClick('sfLyricsImportWorker');assert.equal(h.calls.length,1);
  await h.importClick('sfLyricsImportRefresh');assert.equal(h.panel().querySelector('#sfLyricsImportWorker').disabled,false);
});
test('Catalog queues its song, then Review opens the filtered timeline for explicit application',async()=>{
  const h=harness();await h.mount('song-b');
  await h.importClick('sfLyricsImportEnqueue');await flush();assert.equal(h.calls[0].action,'enqueue');assert.equal(h.calls[0].track_id,'song-b');assert.match(h.panel().html,/READY/);
  await h.importClick('sfLyricsImportReview');assert.deepEqual(h.reviews,['song-b']);
  assert.equal(h.canvas.querySelector('#sfMomentFilterSong').value,'song-b');assert.equal(h.canvas.form.elements.track_id.value,'song-b');assert.match(h.canvas.html,/Saved lyric/);assert.doesNotMatch(h.canvas.html,/Different song note|sfLyricsImport/);
  assert.equal(h.calls.some(c=>c.action==='apply_lyrics'),false);
  await h.canvas.querySelector('#sfLyricsApplySong').onclick();const apply=h.calls.find(c=>c.action==='apply_lyrics');assert.equal(apply.track_id,'song-b');assert.equal(apply.confirmed,true);assert.match(h.confirmations.at(-1),/Existing song lyrics will be overwritten/);
});
test('imported audio blocks retain approximate timing provenance when approved',async()=>{
  const h=harness({timingSource:'catalog_chunk_estimate'});await h.open();assert.doesNotMatch(h.canvas.html,/Imported audio block \/ approximate timing/);h.edit(2);assert.match(h.canvas.html,/Imported audio block \/ approximate timing/);await h.submit();assert.equal(h.calls[0].entry.timing_source,'catalog_chunk_estimate');
});
test('successful publication immediately advances only the already queued listening copy outside the timeline',async()=>{
  const h=harness({jobs:[job(1,'song-a','queued'),job(2,'song-b','queued')]});h.canvas.innerHTML='<p>Catalog upload finished.</p>';
  assert.equal(await h.resumePublishedTrack('song-b'),true);await flush();
  assert.deepEqual(h.calls.map(c=>[c.action,c.job_id]),[['advance',2],['advance',2]]);
  assert.equal(h.canvas.html,'<p>Catalog upload finished.</p>');assert.equal(await h.resumePublishedTrack('song-b'),false);
  assert.deepEqual(h.queries,['song-b','song-b']);assert.equal(h.calls.length,2);
});
test('publication notifications share one worker and do not bypass Stop',async()=>{
  let release;const pending=new Promise(resolve=>{release=resolve;});
  const h=harness({jobs:[job(1,'song-a','queued'),job(2,'song-b','queued')],advance:()=>pending});await h.mount('song-a');
  h.importClick('sfLyricsImportWorker');assert.equal(await h.resumePublishedTrack('song-b'),true);
  release({advanced:true,job:job(1,'song-a','queued',1)});await flush();
  assert.deepEqual(h.calls.map(c=>c.job_id),[1]);assert.match(h.panel().html,/Resume worker/);
});
test('leaving a Catalog editor completes the current block without spending on its next block',async()=>{
  let release;const pending=new Promise(resolve=>{release=resolve;});
  const h=harness({jobs:[job(1,'song-a','queued')],advance:()=>pending});
  await h.mount('song-a');await h.open('song-b');
  const form=h.canvas.form;form.elements.body.value='Unsaved timeline edit';form.events.input();
  release({advanced:true,job:job(1,'song-a','queued',1)});await flush();
  assert.deepEqual(h.calls.map(c=>c.job_id),[1]);
  assert.equal(h.canvas.form,form);assert.equal(form.elements.body.value,'Unsaved timeline edit');
});
test('publication work continues after navigation without replacing the timeline form',async()=>{
  let release;const pending=new Promise(resolve=>{release=resolve;});
  const h=harness({jobs:[job(1,'song-a','queued')],advance:current=>current.completed_chunks===0?pending:{advanced:true,job:job(1,'song-a','ready',2)}});
  await h.mount('song-a');assert.equal(await h.resumePublishedTrack('song-a'),true);await h.open('song-a');
  const form=h.canvas.form;form.elements.body.value='Unsaved timeline edit';form.events.input();
  release({advanced:true,job:job(1,'song-a','queued',1)});await flush();
  assert.deepEqual(h.calls.map(c=>c.job_id),[1,1]);
  assert.equal(h.canvas.form,form);assert.equal(form.elements.body.value,'Unsaved timeline edit');
  await h.mount('song-a');assert.match(h.panel().html,/READY · 2 \/ 2 blocks saved/);
});
test('a late Song Moments read leaves the subsequently opened Catalog editor in place',async()=>{
  let release;const pending=new Promise(resolve=>{release=resolve;});
  const h=harness({timeline:()=>pending});
  const opening=h.open('song-a');await h.mount('song-b');
  release();await opening;
  assert.equal(h.canvas.html,'<p>Catalog editor</p>');
  assert.ok(h.panel().isConnected);assert.match(h.panel().html,/NOT IMPORTED/);
});
test('a failed status check does not queue from the editor until it is refreshed',async()=>{
  let available=false;
  const h=harness({read:()=>{if(!available)throw new Error('Fixture status unavailable.');}});
  await h.mount('song-a');
  assert.match(h.panel().html,/Fixture status unavailable/);assert.equal(h.panel().querySelector('#sfLyricsImportEnqueue').disabled,true);
  await h.importClick('sfLyricsImportEnqueue');assert.deepEqual(h.calls,[]);
  available=true;await h.importClick('sfLyricsImportRefresh');
  assert.equal(h.panel().querySelector('#sfLyricsImportEnqueue').disabled,false);
});
test('the central admin API schedules only successful public audio publications',async()=>{
  const admin=fs.readFileSync(path.join(__dirname,'../admin/assets/admin.js'),'utf8');
  const first=admin.indexOf('  function publishedLyricsTrack('),last=admin.indexOf('  async function uploadApi(',first);assert.ok(first>=0&&last>first);
  const resumed=[],window={SFSongMomentsAdmin:{resumePublishedTrack:id=>resumed.push(id)}};
  const context={window,app:{csrf:'fixture'},queueMicrotask,jsonFetch:async(_url,opts)=>JSON.parse(opts.body).fixture};
  vm.runInNewContext(admin.slice(first,last),context,{filename:'admin-api.js'});
  await context.api('media-upload.php',{action:'upload',fixture:{track_id:'private-upload'}});
  await context.api('stem-publishing.php',{action:'publish_private_master',fixture:{track_id:'private-master'}});
  await context.api('media-catalog.php',{action:'intake_asset',fixture:{track_id:'private-draft',publication:'not_published'}});
  await context.api('media-catalog.php',{action:'intake_asset',fixture:{track_id:'catalog-song',publication:'published'}});
  await context.api('agent-song-catalog.php',{action:'publish_track',fixture:{track_id:'agent-song'}});
  await context.api('media.php',{action:'publish_track_audio',fixture:{track:{id:'preview-song'}}});
  await context.api('media-health.php',{action:'relink_track_audio',fixture:{track_id:'restored-song',verified:true}});
  assert.deepEqual(resumed,['catalog-song','agent-song','preview-song','restored-song']);
});
