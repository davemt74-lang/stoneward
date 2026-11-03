'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {webcrypto}=require('node:crypto');
const code=fs.readFileSync('assets/js/agent-song-catalog-listening.js','utf8');
const app=fs.readFileSync('assets/js/app.js','utf8');
const fingerprint='a'.repeat(64);
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return {promise,resolve,reject}};
const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve()};
function fixture(options={}){
 const events=new EventTarget(),audio=new EventTarget();
 Object.assign(audio,{currentTime:60,paused:false,seeking:false,ended:false,playbackRate:1,currentSrc:'https://local.test/api/media.php?id=M123',src:'https://local.test/api/media.php?id=M123'});
 let track={id:'song-1',title:'Testing song',audio:'api/media.php?id=M123'},clock=0,timerId=0;
 const timers=new Map(),updates=[],finished=[],requests=[],readiness=[];
 const context={window:events,performance:{now:()=>clock},AbortController,Uint8Array,Date,Set,Promise,
  setTimeout:(fn,ms)=>{timers.set(++timerId,{fn,ms});return timerId},clearTimeout:id=>timers.delete(id)};
 events.crypto=webcrypto;vm.createContext(context);vm.runInContext(code,context);
 function result(payload,extra={}){return {ok:true,transcript:{...payload,source:'catalog-audio',provider:'openai',model:'gpt-transcribe',review_status:'unverified',text:'Words from the selected song',title:'Testing song',...extra}}}
 const skill=context.window.StonefellowCatalogListening.create({audio,getTrack:()=>track,monotonic:()=>clock,
  now:()=>new Date('2026-11-03T18:00:00Z'),eventTarget:events,
  prepare:async(id,ref,signal)=>{readiness.push({id,ref,signal});return options.prepare?options.prepare(id,ref,signal):{ok:true,available:true,max_seconds:90,source_fingerprint:fingerprint}},
  submit:async(payload,signal)=>{requests.push({payload,signal});return options.submit?options.submit(payload,signal):result(payload)},
  onUpdate:r=>updates.push(r),onFinish:r=>{finished.push(r);return r.text},...options.controller});
 return {skill,audio,events,timers,updates,finished,requests,readiness,result,context,
  setTrack:t=>{track=t},advance(ms,emit=true){clock+=ms;audio.currentTime+=ms/1000*audio.playbackRate;if(emit)audio.dispatchEvent(new Event('timeupdate'))},
  runTimer(ms){const entry=[...timers.entries()].find(([,t])=>t.ms===ms);assert.ok(entry,'timer '+ms);timers.delete(entry[0]);entry[1].fn()}};
}
test('Mobile selection requires no recording/microphone/browser recognition API and sends no audio before Stop',async()=>{
 const f=fixture();assert.equal((await f.skill.begin()).ok,true);
 assert.equal(f.skill.status().phase,'capturing');assert.equal(f.requests.length,0);
 assert.equal(f.readiness[0].ref,'api/media.php?id=M123');
 f.advance(8000);assert.equal(f.requests.length,0);assert.equal(f.updates.at(-1).end_ms,68000);
 f.skill.destroy();await flush();
});
test('Stop sends one canonical source-bound interval and returns an editable unverified draft',async()=>{
 const f=fixture();await f.skill.begin();f.advance(8000);
 const result=await f.skill.stop();assert.equal(result.ok,true);assert.equal(f.requests.length,1);
 const p=f.requests[0].payload;assert.equal(p.start_ms,60000);assert.equal(p.end_ms,68000);
 assert.equal(p.source_ref,'api/media.php?id=M123');assert.equal(p.source_fingerprint,fingerprint);
 assert.match(p.request_id,/^[a-f0-9]{32}$/);assert.equal(result.receipt.source,'catalog-audio');
 assert.equal(result.receipt.review_status,'unverified');assert.equal(f.skill.status().active,false);
 assert.equal(f.finished.length,1);assert.equal(f.timers.size,0);
});
test('Slow readiness starts the interval only after the source is ready',async()=>{
 const ready=deferred(),f=fixture({prepare:()=>ready.promise});const begin=f.skill.begin();
 await flush();f.advance(5000);ready.resolve({ok:true,available:true,max_seconds:90,source_fingerprint:fingerprint});
 await begin;assert.equal(f.skill.status().start_ms,65000);f.advance(2000);await f.skill.stop();
 assert.equal(f.requests[0].payload.start_ms,65000);
});
test('Duplicate starts during readiness do not create another request, and Stop cancels readiness immediately',async()=>{
 const ready=deferred(),f=fixture({prepare:()=>ready.promise});const first=f.skill.begin();await flush();
 const second=await f.skill.begin();assert.equal(second.pending,true);assert.equal(f.readiness.length,1);
 await f.skill.stop();assert.equal((await first).canceled,true);assert.equal(f.requests.length,0);
 ready.resolve({ok:true,available:true,source_fingerprint:fingerprint});await flush();assert.equal(f.skill.status().active,false);
});
test('Unavailable or unbound server source never becomes a recording or provider request',async()=>{
 for(const response of [{ok:true,available:false,message:'Enable OpenAI first.'},{ok:true,available:true}]){
  const f=fixture({prepare:()=>response});assert.equal((await f.skill.begin()).ok,false);
  assert.equal(f.requests.length,0);assert.equal(f.skill.status().active,false);assert.equal(f.finished.length,0);
 }
});
test('A selection shorter than one second is rejected without paid transcription',async()=>{
 const f=fixture();await f.skill.begin();f.advance(700);const result=await f.skill.stop();
 assert.equal(result.reason,'too_short');assert.equal(f.requests.length,0);assert.match(result.text,/one second/);
});
test('Pause and track end finalize once, preserving the selected source',async()=>{
 for(const event of ['pause','ended']){
  const f=fixture();await f.skill.begin();f.advance(6000);f.audio.paused=true;f.audio.ended=event==='ended';
  f.audio.dispatchEvent(new Event(event));await flush();
  assert.equal(f.requests.length,1);assert.equal(f.finished.length,1);assert.equal(f.finished[0].ok,true);
 }
});
test('Overlapping controller and app pause/end handlers share one provider request',async()=>{
 for(const reason of ['pause','end']){
  const provider=deferred(),f=fixture({submit:()=>provider.promise});await f.skill.begin();f.advance(4000);
  f.audio.paused=true;f.audio.ended=reason==='end';
  f.audio.dispatchEvent(new Event(reason==='end'?'ended':'pause'));
  const overlapping=f.skill.stop(reason);await flush();
  assert.equal(f.requests.length,1,reason);
  provider.resolve(f.result(f.requests[0].payload));assert.equal((await overlapping).ok,true);
  assert.equal(f.finished.length,1,reason);assert.equal(f.requests.length,1,reason);
 }
});
test('Seek, error, emptied player and pagehide discard selection without any provider request',async()=>{
 for(const event of ['seeking','error','emptied','pagehide']){
  const f=fixture();await f.skill.begin();f.advance(4000);
  (event==='pagehide'?f.events:f.audio).dispatchEvent(new Event(event));await flush();
  assert.equal(f.requests.length,0,event);assert.equal(f.skill.status().active,false,event);
 }
});
test('A forward seek that precedes its DOM event cannot upload the skipped section',async()=>{
 const f=fixture();await f.skill.begin();f.audio.currentTime=180;
 const result=await f.skill.stop();assert.equal(result.canceled,true);assert.equal(f.requests.length,0);
});
test('Changing a song identity or its published audio reference discards the old selection',async()=>{
 for(const changed of [{id:'song-2',title:'New',audio:'api/media.php?id=M123'},{id:'song-1',title:'Testing song',audio:'api/media.php?id=M456'}]){
  const f=fixture();await f.skill.begin();f.advance(5000);f.setTrack(changed);
  const result=await f.skill.stop();assert.equal(result.canceled,true);assert.equal(f.requests.length,0);
 }
});
test('The ninety-second audio limit bounds delayed timeupdate delivery and still submits only once',async()=>{
 const f=fixture();await f.skill.begin();f.advance(100000);await flush();
 assert.equal(f.requests.length,1);assert.equal(f.requests[0].payload.end_ms,150000);
 f.audio.dispatchEvent(new Event('ended'));await flush();assert.equal(f.requests.length,1);
});
test('A second Stop during submission discards local waiting and a late response cannot replace a new session',async()=>{
 const provider=deferred(),f=fixture({submit:()=>provider.promise});await f.skill.begin();f.advance(5000);
 const firstStop=f.skill.stop();await flush();assert.equal(f.requests.length,1);
 const old=f.requests[0].payload;const canceled=await f.skill.stop();assert.equal(canceled.canceled,true);
 assert.match(canceled.text,/may still complete and use Active Tokens/);
 assert.equal((await firstStop).canceled,true);
 await f.skill.begin();const current=f.skill.status().request_id;
 provider.resolve(f.result(old));await flush();
 assert.equal(f.skill.status().request_id,current);assert.equal(f.finished.filter(x=>x.ok).length,0);
 f.skill.destroy();await flush();
});
test('Seek while submission is pending prevents the returned draft from becoming current',async()=>{
 const provider=deferred(),f=fixture({submit:()=>provider.promise});await f.skill.begin();f.advance(4000);
 const stop=f.skill.stop();await flush();f.audio.dispatchEvent(new Event('seeking'));
 provider.resolve(f.result(f.requests[0].payload));const result=await stop;
 assert.equal(result.canceled,true);assert.equal(f.finished.filter(x=>x.ok).length,0);
});
test('Provider failure and timeout complete without retrying or fabricating a transcript',async()=>{
 const failed=fixture({submit:()=>Promise.reject(new Error('Provider unavailable'))});await failed.skill.begin();failed.advance(2000);
 const result=await failed.skill.stop();assert.equal(result.ok,false);assert.equal(failed.requests.length,1);assert.equal(result.receipt,null);
 const waiting=fixture({submit:()=>new Promise(()=>{})});await waiting.skill.begin();waiting.advance(2000);
 const stop=waiting.skill.stop();await flush();waiting.runTimer(75000);const timed=await stop;
 assert.equal(timed.ok,false);assert.match(timed.text,/timed out/);assert.equal(waiting.requests.length,1);
});
test('Only matching source/track/range responses may become the current lyric draft',async()=>{
 for(const alter of [{track_id:'other'},{source_fingerprint:'b'.repeat(64)},{source:'record-clip'},
  {provider:'other'},{start_ms:59000},{end_ms:99000},{text:''},{review_status:'verified'},{captured_at:'different'}]){
  let f;f=fixture({submit:p=>f.result(p,alter)});await f.skill.begin();f.advance(2000);
  const result=await f.skill.stop();assert.equal(result.ok,false,JSON.stringify(alter));assert.equal(result.receipt,null);
 }
});
test('Inward MP3 frame alignment is accepted as the actual draft interval',async()=>{
 let f;f=fixture({submit:p=>f.result(p,{start_ms:p.start_ms+40,end_ms:p.end_ms-40,silent_lead_ms:26,audio_duration_ms:1946})});
 await f.skill.begin();f.advance(2000);const result=await f.skill.stop();assert.equal(result.ok,true);
 assert.equal(result.receipt.start_ms,60040);assert.equal(result.receipt.end_ms,61960);
});
test('The combined controller prefers supported browser recognition and otherwise uses the catalog path',async()=>{
 const f=fixture();let starts=0;const browser={status:()=>({active:false}),begin:()=>{starts++;return {ok:true}},stop:async()=>({ok:true})};
 const desktop=f.context.window.StonefellowCatalogListening.combine(browser,f.skill,()=>true);
 assert.equal(desktop.mode(),'recognition');desktop.begin();assert.equal(starts,1);assert.equal(f.readiness.length,0);
 const mobile=f.context.window.StonefellowCatalogListening.combine(browser,f.skill,()=>false);
 assert.equal(mobile.mode(),'catalog');await mobile.begin();assert.equal(f.readiness.length,1);
 f.skill.destroy();await flush();
});
test('Production authenticated POST checks cancellation again after an asynchronous auth refresh',async()=>{
 const start=app.indexOf('  async function secureJsonPost('),end=app.indexOf('  function applyQueue(',start);
 assert.ok(start>=0&&end>start);const auth=deferred();let requests=0;
 const context={st:{auth:{csrf:null}},cfg:{apiBase:'api'},loadAuth:()=>auth.promise,fetch:()=>{requests++;throw Error('Unexpected POST')}};
 vm.createContext(context);vm.runInContext(app.slice(start,end),context);
 const abort=new AbortController(),pending=context.secureJsonPost('agent-song-catalog-transcribe.php',{},false,abort.signal);
 abort.abort();auth.resolve();await assert.rejects(pending,/canceled/);assert.equal(requests,0);
});
test('Production source/draft functions allow catalog receipts and keep another song from analyzing them',()=>{
 const a=app.indexOf('  function currentSongSource(){'),b=app.indexOf('  let songTranscriptionBusy',a);
 const c=app.indexOf('  function currentSongDraft(){'),d=app.indexOf('  async function executePrivateAnnotationCommand',c);
 const receipt={track_id:'song',start_ms:1000,end_ms:3000,source:'catalog-audio',provider:'openai'};
 const context={songLyricDraft:receipt,songListeningReceipt:receipt,songCaptureSkill:{getCapture:()=>null},songListeningSkill:{status:()=>({active:false})},st:{track:{id:'song'}}};
 vm.createContext(context);vm.runInContext(app.slice(a,b)+app.slice(c,d),context);
 assert.equal(context.currentSongSource(),receipt);assert.equal(context.currentSongDraft(),receipt);
 context.st.track.id='different';assert.equal(context.currentSongDraft(),null);
 context.st.track.id='song';context.songListeningSkill.status=()=>({active:true});assert.equal(context.currentSongDraft(),null);
});
test('An unavailable fresh selection preserves the completed reviewed draft',async()=>{
 const start=app.indexOf('  async function executeSongCaptureAction(action){'),end=app.indexOf('  // Private annotation commands',start);
 const previous={phase:'report',listening_mode:'catalog',track_id:'song',transcript:'Original words',corrected:'Reviewed words',reports:[{text:'Reviewed interpretation'}]};
 const receipt={track_id:'song',source:'catalog-audio',start_ms:1000,end_ms:3000};
 const context={songExperience:previous,songLyricDraft:receipt,songListeningReceipt:receipt,
  songLyricCorrected:'Reviewed words',songLyricReport:{report:'Reviewed interpretation'},songPersonalNote:'My note',
  songTranscriptionBusy:false,songAnalysisBusy:false,vocalStudio:null,
  songCaptureSkill:{status:()=>({active:false})},
  songListeningSkill:{mode:()=> 'catalog',status:()=>({active:false}),begin:async()=>({ok:false,text:'Source unavailable'})},
  st:{track:{id:'song',title:'Song'}},audio:{currentTime:6},window:{},
  updateSongExperience(changes){Object.assign(context.songExperience,changes)}};
 vm.createContext(context);vm.runInContext(app.slice(start,end),context);
 assert.equal(await context.executeSongCaptureAction({type:'song_capture_live_start'}),'Source unavailable');
 assert.equal(context.songExperience,previous);assert.equal(previous.phase,'report');
 assert.equal(context.songLyricDraft,receipt);assert.equal(context.songListeningReceipt,receipt);
 assert.equal(context.songLyricCorrected,'Reviewed words');assert.equal(context.songPersonalNote,'My note');
 assert.equal(context.songLyricReport.report,'Reviewed interpretation');
});
