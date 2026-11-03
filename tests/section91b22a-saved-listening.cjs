'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const {webcrypto}=require('node:crypto');
const code=fs.readFileSync('assets/js/agent-song-catalog-listening.js','utf8');
const fingerprint='a'.repeat(64);
const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve()};
const deferred=()=>{let resolve;const promise=new Promise(r=>{resolve=r});return {promise,resolve}};
function fixture(options={}){
 const events=new EventTarget(),audio=new EventTarget();
 Object.assign(audio,{currentTime:65,paused:false,seeking:false,ended:false,playbackRate:1,currentSrc:'https://local.test/song.mp3',src:'https://local.test/song.mp3'});
 let track={id:'song-1',title:'Saved Song',audio:'api/media.php?id=M123'},clock=0,id=0;
 const timers=new Map(),updates=[],finished=[],requests=[];
 const context={window:events,performance:{now:()=>clock},AbortController,Uint8Array,Date,Set,Promise,TextEncoder,
  setTimeout:(fn,ms)=>{timers.set(++id,{fn,ms});return id},clearTimeout:n=>timers.delete(n)};
 events.crypto=webcrypto;vm.createContext(context);vm.runInContext(code,context);
 function result(p,extra={}){return {ok:true,transcript:{...p,title:'Saved Song',text:'Reviewed stored words',
  source:'saved-catalog-lyrics',provider:'stored',model:'',review_status:'approved',timing:'saved_segments',
  selected_start_ms:p.start_ms,selected_end_ms:p.end_ms,
  segments:[{id:1,start_ms:60000,end_ms:120000,text:'Reviewed stored words',lyric_state:'approved',timing_source:'catalog_chunk_estimate'}],...extra}}}
 const skill=context.window.StonefellowCatalogListening.create({audio,getTrack:()=>track,savedLyrics:true,
  monotonic:()=>clock,now:()=>new Date('2026-11-03T18:00:00Z'),eventTarget:events,
  prepare:options.prepare||(async()=>({ok:true,available:true,max_seconds:90,source_fingerprint:fingerprint})),
  submit:async(p,signal)=>{requests.push({p,signal});return options.submit?options.submit(p,signal):result(p)},
  onUpdate:r=>updates.push(r),onFinish:r=>{finished.push(r);return r.text}});
 return {skill,audio,events,timers,updates,finished,requests,result,setTrack:t=>{track=t},
  advance(ms){clock+=ms;audio.currentTime+=ms/1000;audio.dispatchEvent(new Event('timeupdate'))}};
}
test('Saved lyrics select a source-bound interval and return stored blocks without pretending exact alignment',async()=>{
 const f=fixture();const begin=await f.skill.begin();assert.equal(begin.ok,true);assert.match(begin.text,/saved lyrics/);
 f.advance(5000);assert.equal(f.requests.length,0);const r=await f.skill.stop();
 assert.equal(r.ok,true);assert.equal(f.requests.length,1);assert.equal(r.receipt.source,'saved-catalog-lyrics');
 assert.equal(r.receipt.provider,'stored');assert.equal(r.receipt.start_ms,65000);assert.equal(r.receipt.end_ms,70000);
 assert.equal(r.receipt.segments[0].start_ms,60000);assert.equal(r.receipt.segments[0].end_ms,120000);
 assert.equal(r.receipt.review_status,'approved');assert.equal(f.timers.size,0);
 assert.ok(f.updates.every(u=>!/sends a temporary|with OpenAI|may still use Active Tokens/.test(u.text)));
});
test('Missing saved lyrics never start a fallback capture or transcription',async()=>{
 const f=fixture({prepare:async()=>({ok:true,available:false,message:'Reviewed lyrics are not available yet.'})});
 const r=await f.skill.begin();assert.equal(r.ok,false);assert.match(r.text,/not available/);assert.equal(f.requests.length,0);
 assert.equal(f.skill.status().active,false);assert.equal(f.finished.length,0);
});
test('Saved lookup explicitly rejects a fresh-provider or mismatched receipt',async()=>{
 for(const alter of [{source:'catalog-audio',provider:'openai'}, {selected_start_ms:64000}, {timing:'whole_clip_estimate'},
  {segments:[]},{source_fingerprint:'b'.repeat(64)},{segments:[{start_ms:0,end_ms:1000,text:'Other range',lyric_state:'approved',timing_source:'manual'}]},
  {segments:[{start_ms:60000,end_ms:120000,text:'Unreviewed',lyric_state:'unverified',timing_source:'manual'}]}]){
  let f;f=fixture({submit:p=>f.result(p,alter)});await f.skill.begin();f.advance(2000);const r=await f.skill.stop();
  assert.equal(r.ok,false,JSON.stringify(alter));assert.equal(r.receipt,null);assert.equal(f.requests.length,1);
 }
});
test('Admin draft previews retain unverified status and timing provenance',async()=>{
 let f;f=fixture({submit:p=>f.result(p,{review_status:'unverified',segments:[{start_ms:60000,end_ms:120000,text:'Draft words',lyric_state:'unverified',timing_source:'catalog_chunk_estimate'}]})});
 await f.skill.begin();f.advance(2000);const r=await f.skill.stop();assert.equal(r.ok,true);assert.equal(r.receipt.review_status,'unverified');
});
test('The same UTF-8 byte limit applies to saved lyrics in the browser and server',async()=>{
 for(const [length,ok] of [[1050,true],[1051,false]]){
  let f;const text='🎵'.repeat(length);f=fixture({submit:p=>f.result(p,{text})});
  await f.skill.begin();f.advance(2000);const r=await f.skill.stop();assert.equal(r.ok,ok);
 }
});
test('Canceling a saved lookup suppresses late text without paid-provider language',async()=>{
 const pending=deferred(),f=fixture({submit:()=>pending.promise});await f.skill.begin();f.advance(3000);const old=f.skill.stop();await flush();
 const canceled=await f.skill.stop();assert.equal(canceled.canceled,true);assert.doesNotMatch(canceled.text,/may still|use Active Tokens/);
 await f.skill.begin();const current=f.skill.status().request_id;pending.resolve(f.result(f.requests[0].p));await old;await flush();
 assert.equal(f.skill.status().request_id,current);assert.equal(f.finished.filter(x=>x.ok).length,0);f.skill.destroy();
});
test('Seeking discards the selected range, while pause retrieves saved lyrics exactly once',async()=>{
 const f=fixture();await f.skill.begin();f.advance(3000);f.audio.dispatchEvent(new Event('seeking'));await flush();
 assert.equal(f.requests.length,0);await f.skill.begin();f.advance(2000);f.audio.paused=true;f.audio.dispatchEvent(new Event('pause'));await flush();
 assert.equal(f.requests.length,1);assert.equal(f.finished.filter(x=>x.ok).length,1);
});
test('Saved endpoint source contains no transcription invocation, audio extraction, or token spend path',()=>{
 const endpoint=fs.readFileSync('api/song-lyrics-section.php','utf8');
 assert.doesNotMatch(endpoint,/\b(?:sf_song_catalog_transcribe|sf_song_audio_extract|sf_ai_reserve|sf_consume_ai|curl_exec)\s*\(/);
 assert.match(endpoint,/sf_song_moments_lyric_section\(/);assert.match(endpoint,/sf_require_user\(false,\$method==='POST'\)/);
 assert.match(endpoint,/hash_equals\(\$source\['fingerprint'\],\$meta\['source_fingerprint'\]\)/);
});
