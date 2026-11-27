'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const music = require('../assets/js/vp3-music-player.js');
const ids = { installation_id:'11111111-1111-4111-8111-111111111111',site_id:'22222222-2222-4222-8222-222222222222',recording_id:'33333333-3333-4333-8333-333333333333' };
const track = { id:'vp3-song', title:'Imported song', audio_source:{kind:'vp3',...ids} };
const tick = async () => { for(let i=0;i<12;i++) await new Promise(resolve=>setImmediate(resolve)); };
class Media extends EventTarget {
  constructor(){super();this.paused=true;this.ended=false;this.seeking=false;this.duration=180;this.currentTime=0;this.playbackRate=1;this.volume=1;this.muted=false;this.loop=false;this.readyState=4;this.error=null;this.src='';this.plays=0;this.loads=0;}
  getAttribute(name){return name==='src'?this.src:null;}
  removeAttribute(name){if(name==='src')this.src='';}
  emit(name){this.dispatchEvent(new Event(name));}
  load(){this.loads++;this.error=null;this.emit('emptied');this.emit('loadedmetadata');}
  pause(){if(!this.paused){this.paused=true;this.emit('pause');}}
  async play(){this.plays++;if(this.blocked)throw Object.assign(Error('blocked'),{name:'NotAllowedError'});this.paused=false;this.ended=false;this.emit('play');this.emit('playing');}
  seek(value){this.seeking=true;this.currentTime=value;this.emit('seeking');this.seeking=false;this.emit('seeked');}
}
function fixture(options={}){
  const audio=new Media(),calls=[],receipts=new Map(),sessions=new Map(),timers=new Map(),errors=[];
  let now=1000,wall=1700000000000,timerId=0,ticket=0,mode={};
  const setTimeout=(fn,ms)=>{const id=++timerId;timers.set(id,{fn,ms});if(ms<=1000)queueMicrotask(()=>{if(timers.delete(id))fn();});return id;};
  const clearTimeout=id=>timers.delete(id);
  const media=()=>({url:'https://vp3.example.test/api/music-provider-media-v1.php?ticket=mpd1.test'+(++ticket),ticket_expires_ms:wall+300000,mime:'audio/mpeg',byte_size:12000,etag:'"sha256-'+('a'.repeat(64))+'"'});
  function response(p,b){return {ok:true,protocol:music.protocol,session_id:p.id,play_id:p.play_id,site_id:ids.site_id,recording_id:ids.recording_id,
    asset_id:'44444444-4444-4444-8444-444444444444',identity:{provider_id:ids.installation_id},accepted_seq:p.seq,accepted_played_ms:p.played,...b};}
  const fetch=async(url,init)=>{
    const b=JSON.parse(init.body);calls.push({url,init,body:b});
    if(mode.hold&&b.action===mode.hold.action){
      const held=mode.hold;mode.hold=null;
      await (held.honorAbort?Promise.race([held.promise,new Promise((_,reject)=>init.signal.addEventListener('abort',()=>reject(Object.assign(Error('aborted'),{name:'AbortError'})),{once:true}))]):held.promise);
    }
    if(mode.opaque===true||mode.opaque===b.action)throw new TypeError('opaque network or CORS denial');
    if(mode.revoked)return {ok:false,status:404,json:async()=>({ok:false,protocol:music.protocol,code:'unavailable',retryable:false})};
    let result;
    if(b.action==='playback.begin'){
      if(!sessions.has(b.play_id))sessions.set(b.play_id,{id:webcrypto.randomUUID(),play_id:b.play_id,seq:0,played:0,key:b.playback_key});
      const p=sessions.get(b.play_id),key='begin:'+b.play_id;
      if(!receipts.has(key))receipts.set(key,response(p,{media:media()}));result=receipts.get(key);
    }else{
      const p=[...sessions.values()].find(p=>p.id===b.session_id);assert.ok(p,'known session');assert.equal(init.headers.Authorization,'Bearer '+p.key);
      const key=b.event_id||b.request_id;
      if(receipts.has(key))result=receipts.get(key);
      else if(b.action==='playback.events'){
        assert.equal(b.seq,p.seq+1,'strict consecutive event sequence');p.seq=b.seq;p.played=b.cumulative_played_ms;
        result=response(p);receipts.set(key,result);
      }else{result=response(p,{request_id:b.request_id,media:media()});receipts.set(key,result);}
    }
    if(mode.badMedia&&result.media)result={...result,media:{...result.media,...mode.badMedia}};
    if(mode.badReceipt)result={...result,...mode.badReceipt};
    if(mode.lose===b.action){mode.lose=null;if(mode.loseAdvance){now+=mode.loseAdvance;wall+=mode.loseAdvance;mode.loseAdvance=0;}throw new TypeError('network response lost');}
    if(mode.loseAlways===b.action)throw new TypeError('committed response repeatedly lost');
    return {ok:true,status:200,json:async()=>result};
  };
  const controller=music.create({audio,resolveSource:async()=>({ok:true,source:{kind:'vp3',...ids,provider_api:'https://vp3.example.test/api/music-provider-v1.php'}}),fetch,crypto:webcrypto,
    now:()=>now,wallNow:()=>wall,setTimeout,clearTimeout,onError:error=>errors.push(error),sourceUrl:()=> 'https://artist.example.test/music.php?private-token=discard#discard',...options});
  return {audio,controller,calls,sessions,timers,errors,mode,advance(ms,seconds=ms/1000){now+=ms;wall+=ms;if(!audio.paused)audio.currentTime+=seconds;audio.emit('timeupdate');},setWall(value){wall=value;},get wall(){return wall;},events(){return calls.filter(x=>x.body.action==='playback.events');},begins(){return calls.filter(x=>x.body.action==='playback.begin');}};
}
test('only explicit stable VP3 references select the provider transport',()=>{
  assert.equal(music.supports(track),true);assert.equal(music.supports({id:'local',audio:'assets/music.mp3'}),false);
  assert.equal(music.supports({...track,audio_source:{...track.audio_source,recording_id:'../../../private'}}),false);
  assert.equal(music.hasPlayableSource({audio:'assets/local.mp3'}),true);
  assert.equal(music.hasPlayableSource({audio:'assets/private.mp3',audio_publishing_mode:'vp3_stream_only'}),false);
  assert.equal(music.hasPlayableSource({audio:'assets/private.mp3',audio_source:{kind:'vp3'}}),false);
  assert.equal(music.safeSourcePage('https://artist.example.test/music.php?token=secret#private'),'https://artist.example.test/music.php');
  assert.equal(music.safeSourcePage('https://artist.example.test/account/private-id'),'');
  assert.equal(music.safeSourcePage('https://secret@artist.example.test/music.php'),'');
  assert.equal(music.safeSourcePage('https://artist.example.test/track/public-song'),'');
  assert.equal(music.safeSourcePage('https://artist.example.test/release/public-release'),'');
  assert.equal(music.safeSourcePage('https://artist.example.test/stonefellow-v120.php'),'');
});
test('begin allocates no listening; actual first resume is zero and transport omits site identity credentials',async()=>{
  const f=fixture();await f.controller.play(track,{position:0});await tick();
  assert.equal(f.sessions.size,1);const begin=f.begins()[0];assert.equal(begin.body.position_ms,0);
  assert.equal(begin.body.source_url,'https://artist.example.test/music.php');
  assert.match(begin.body.playback_key,/^mps1_[a-f0-9]{64}$/);assert.equal(begin.init.headers.Authorization,undefined);
  assert.equal(f.events()[0].body.event_type,'resume');assert.equal(f.events()[0].body.cumulative_played_ms,0);
  for(const call of f.calls){assert.equal(call.init.credentials,'omit');assert.equal(call.init.mode,'cors');assert.equal(call.init.referrerPolicy,'no-referrer');assert.equal(call.init.redirect,'error');assert.equal(new URL(call.url).searchParams.get('site_id'),ids.site_id);assert.equal(Object.keys(call.body).some(k=>/user|customer|email|cookie|account/.test(k)),false);}
  assert.equal(JSON.stringify(f.controller.state()).includes('mps1_'),false);assert.equal(JSON.stringify(f.controller.state()).includes('mpd1.'),false);f.controller.dispose();
});
test('pause, seek, buffering and implausible movement cannot manufacture listening',async()=>{
  const f=fixture();await f.controller.play(track,{position:0});f.advance(2000);f.controller.flush();await tick();assert.equal(f.controller.state().accepted_played_ms,2000);
  f.controller.pause();f.advance(10000);f.audio.seek(40);await f.controller.play(track,{position:40});f.advance(1500);f.controller.flush();await tick();
  assert.equal(f.sessions.size,1);assert.equal(f.controller.state().accepted_played_ms,3500);
  f.audio.emit('waiting');f.advance(10000,0);f.audio.emit('playing');f.advance(500,50);f.controller.flush();await tick();
  assert.equal(f.controller.state().accepted_played_ms,3500);assert.ok(f.events().some(e=>e.body.event_type==='seek'));assert.ok(f.events().some(e=>e.body.observation_partial));f.controller.dispose();
});
test('a lost begin acknowledgement replays the frozen request rather than a new play',async()=>{
  const f=fixture();f.mode.lose='playback.begin';await f.controller.play(track,{position:3});await tick();
  assert.equal(f.begins().length,2);assert.equal(f.sessions.size,1);assert.equal(f.begins()[0].init.body,f.begins()[1].init.body);
  assert.equal(f.events()[0].body.cumulative_played_ms,0);f.controller.dispose();
});
test('a lost event acknowledgement preserves event id, sequence, body and one accepted interval',async()=>{
  const f=fixture();await f.controller.play(track);await tick();f.mode.lose='playback.events';f.advance(2500);f.controller.flush();await tick();
  const heartbeat=f.events().filter(x=>x.body.event_type==='heartbeat');assert.equal(heartbeat.length,2);assert.equal(heartbeat[0].init.body,heartbeat[1].init.body);
  assert.equal(f.controller.state().accepted_played_ms,2500);assert.equal(f.controller.state().accepted_seq,2);f.controller.dispose();
});
test('blocked autoplay keeps one pending grant and records nothing until actual play',async()=>{
  const f=fixture();f.audio.blocked=true;await assert.rejects(f.controller.play(track),{name:'NotAllowedError'});await tick();
  const before=f.controller.state();assert.equal(f.events().length,0);assert.equal(before.state,'pending');assert.equal(before.accepted_played_ms,0);
  f.audio.blocked=false;await f.controller.play(track);await tick();assert.equal(f.begins().length,1);assert.equal(f.controller.state().session_id,before.session_id);assert.equal(f.events()[0].body.cumulative_played_ms,0);f.controller.dispose();
});
test('renewal continues the same asset, session, position and counters without allocating a play',async()=>{
  const f=fixture();await f.controller.play(track);f.advance(12500);f.controller.flush();await tick();
  const before=f.controller.state(),old=f.audio.src;f.mode.lose='playback.renew';await f.controller.renew();await tick();
  const after=f.controller.state();assert.equal(after.session_id,before.session_id);assert.equal(after.play_id,before.play_id);assert.equal(after.asset_id,before.asset_id);
  assert.equal(after.accepted_played_ms,before.accepted_played_ms);assert.equal(f.audio.currentTime,12.5);assert.equal(f.audio.paused,false);assert.notEqual(f.audio.src,old);
  assert.equal(f.begins().length,1);const renews=f.calls.filter(x=>x.body.action==='playback.renew');assert.equal(renews.length,2);assert.equal(renews[0].init.body,renews[1].init.body);f.controller.dispose();
});
test('renewing a paused session cannot resume it',async()=>{
  const f=fixture();await f.controller.play(track);f.advance(3000);f.controller.pause();await tick();const plays=f.audio.plays;
  await f.controller.renew();await tick();assert.equal(f.audio.paused,true);assert.equal(f.audio.plays,plays);assert.equal(f.audio.currentTime,3);assert.equal(f.sessions.size,1);f.controller.dispose();
});
test('pause during an unabortable begin response prevents playback and reuses the pending grant',async()=>{
  const f=fixture();let release;f.mode.hold={action:'playback.begin',promise:new Promise(resolve=>release=resolve)};
  const attempt=f.controller.play(track);await tick();f.controller.pause();release();await assert.rejects(attempt,{name:'AbortError'});await tick();
  assert.equal(f.audio.paused,true);assert.equal(f.events().length,0);await f.controller.play(track);await tick();assert.equal(f.sessions.size,1);assert.equal(f.begins().length,1);f.controller.dispose();
});
test('pause during renewal wins over a late renewal response',async()=>{
  const f=fixture();await f.controller.play(track);f.advance(4000);let release;f.mode.hold={action:'playback.renew',promise:new Promise(resolve=>release=resolve)};
  const attempt=f.controller.renew();await tick();f.controller.pause();const plays=f.audio.plays;release();await attempt;await tick();assert.equal(f.audio.paused,true);assert.equal(f.audio.plays,plays);f.controller.dispose();
});
test('revocation is terminal and cannot silently allocate a replacement play',async()=>{
  const f=fixture();await f.controller.play(track);await tick();f.mode.revoked=true;await assert.rejects(f.controller.renew());await tick();
  assert.equal(f.audio.paused,true);assert.equal(f.controller.state().terminal,true);assert.equal(f.errors.length,1);
  await assert.rejects(f.controller.play(track));assert.equal(f.begins().length,1);
  await f.audio.play();assert.equal(f.audio.paused,true,'a late native play cannot restart buffered terminal media');f.controller.dispose();
});
test('a different asset or foreign media URL is rejected before assigning it to audio',async()=>{
  for(const badMedia of [{url:'https://foreign.example.test/api/music-provider-media-v1.php?ticket=mpd1.stolen'},{url:'https://vp3.example.test/private/master.wav?ticket=mpd1.stolen'},{etag:'not-pinned'}]){
    const f=fixture();f.mode.badMedia=badMedia;await assert.rejects(f.controller.play(track));assert.equal(f.audio.src,'');assert.equal(f.events().length,0);f.controller.dispose();
  }
  const f=fixture();await f.controller.play(track);const old=f.audio.src;f.mode.badReceipt={asset_id:'55555555-5555-4555-8555-555555555555'};await assert.rejects(f.controller.renew());assert.equal(f.audio.src,old);assert.equal(f.controller.state().terminal,true);f.controller.dispose();
});
test('source resolver identity mismatch cannot begin playback',async()=>{
  const f=fixture({resolveSource:async()=>({source:{kind:'vp3',...ids,site_id:'55555555-5555-4555-8555-555555555555',provider_api:'https://vp3.example.test/api/music-provider-v1.php'}})});
  await assert.rejects(f.controller.play(track));assert.equal(f.calls.length,0);assert.equal(f.audio.plays,0);f.controller.dispose();
});
test('an expired frozen renewal receipt requests a genuinely later ticket without replacing the play',async()=>{
  const f=fixture();await f.controller.play(track);f.advance(2000);await tick();
  f.mode.lose='playback.renew';f.mode.loseAdvance=301000;await f.controller.renew();await tick();
  const renews=f.calls.filter(x=>x.body.action==='playback.renew');assert.equal(renews.length,3);
  assert.equal(renews[0].init.body,renews[1].init.body);assert.notEqual(renews[1].body.request_id,renews[2].body.request_id);
  assert.equal(f.sessions.size,1);assert.equal(f.begins().length,1);assert.equal(f.audio.currentTime,2);f.controller.dispose();
});
test('an immediate Tap Play waits for an aborted begin then replays the same frozen identity',async()=>{
  const f=fixture();f.mode.hold={action:'playback.begin',honorAbort:true,promise:new Promise(()=>{})};
  const first=f.controller.play(track);const rejected=assert.rejects(first,{name:'AbortError'});await tick();
  f.controller.pause();const second=f.controller.play(track);await rejected;await second;await tick();
  assert.equal(f.begins().length,2);assert.equal(f.begins()[0].init.body,f.begins()[1].init.body);
  assert.equal(f.sessions.size,1);assert.equal(f.events()[0].body.cumulative_played_ms,0);f.controller.dispose();
});
test('late native play completion cannot override an explicit pause or create a start event',async()=>{
  const f=fixture(),nativePlay=f.audio.play.bind(f.audio);let release;
  f.audio.play=()=>new Promise(resolve=>{release=()=>nativePlay().then(resolve);});
  const task=f.controller.play(track);const rejected=assert.rejects(task,{name:'AbortError'});await tick();
  f.controller.pause();await release();await rejected;await tick();
  assert.equal(f.audio.paused,true);assert.equal(f.events().length,0);assert.equal(f.controller.state().state,'pending');f.controller.dispose();
});
test('a timer renewal and explicit resume share one request and preserve the current intent',async()=>{
  const f=fixture();await f.controller.play(track);f.advance(2000);await tick();f.setWall(f.wall+290000);
  let release;f.mode.hold={action:'playback.renew',promise:new Promise(resolve=>release=resolve)};
  const renewal=f.controller.renew();await tick();const resume=f.controller.play(track,{position:2});await tick();release();
  await Promise.all([renewal,resume]);await tick();
  assert.equal(f.calls.filter(x=>x.body.action==='playback.renew').length,1);assert.equal(f.begins().length,1);
  assert.equal(f.audio.currentTime,2);assert.equal(f.audio.paused,false);assert.equal(f.controller.state().terminal,false);f.controller.dispose();
});
test('pause during recovery cannot be overridden by its remembered resume intent',async()=>{
  const f=fixture();await f.controller.play(track);f.advance(3000);f.audio.error={code:2};f.audio.pause();
  let release;f.mode.hold={action:'playback.renew',promise:new Promise(resolve=>release=resolve)};
  const recovery=f.controller.recover();await tick();f.controller.pause();const plays=f.audio.plays;release();await recovery;await tick();
  assert.equal(f.audio.paused,true);assert.equal(f.audio.plays,plays);assert.equal(f.begins().length,1);f.controller.dispose();
});
test('server accepted counters cannot move backwards at a newer sequence',async()=>{
  const f=fixture();await f.controller.play(track);f.advance(1000);f.controller.flush();await tick();
  assert.equal(f.controller.state().accepted_played_ms,1000);f.mode.badReceipt={accepted_played_ms:0};f.advance(1000);f.controller.flush();await tick();
  assert.equal(f.controller.state().terminal,true);assert.equal(f.controller.state().accepted_played_ms,1000);assert.equal(f.audio.paused,true);f.controller.dispose();
});
test('the accepted provider MIME profile includes M4A, Wave and Ogg without broadening it',async()=>{
  for(const mime of ['audio/x-m4a','audio/vnd.wave','application/ogg']){
    const f=fixture();f.mode.badMedia={mime};await f.controller.play(track);assert.equal(f.audio.paused,false);f.controller.dispose();
  }
  const f=fixture();f.mode.badMedia={mime:'text/html'};await assert.rejects(f.controller.play(track));assert.equal(f.audio.src,'');f.controller.dispose();
});
test('a host-owned loop retains its play while a natural-end queue replacement finalizes once',async()=>{
  const f=fixture({isLooping:()=>true});await f.controller.play(track);f.advance(1000);f.audio.paused=true;f.audio.ended=true;f.audio.emit('ended');
  f.audio.ended=false;f.audio.seek(0);await f.controller.play(track,{position:0});await tick();
  assert.equal(f.begins().length,1);assert.equal(f.events().some(x=>x.body.event_type==='ended'),false);assert.ok(f.events().every(x=>x.body.loop));f.controller.dispose();
  const g=fixture();await g.controller.play(track);g.advance(2000);g.audio.ended=true;g.audio.paused=true;g.controller.stop();await tick();
  assert.equal(g.events().filter(x=>x.body.event_type==='ended').length,1);assert.equal(g.events().filter(x=>x.body.event_type==='stop').length,0);g.controller.dispose();
});
test('long observation gaps are marked partial and cannot claim the entire unattended interval',async()=>{
  const f=fixture();await f.controller.play(track);f.advance(120000);f.controller.flush();await tick();
  assert.equal(f.controller.state().accepted_played_ms,30000);assert.ok(f.events().some(x=>x.body.observation_partial));f.controller.dispose();
});
test('opaque renewal denial pauses buffered media, parks retries and requires confirmation of the same grant',async()=>{
  const f=fixture();await f.controller.play(track);f.advance(2500);f.controller.flush();await tick();const before=f.controller.state(),position=f.audio.currentTime;
  f.mode.opaque='playback.renew';await assert.rejects(f.controller.renew(),error=>error.retryable===true);await tick();
  const denied=f.controller.state();assert.equal(denied.confirmation_required,true);assert.equal(denied.state,'confirmation_required');assert.equal(denied.terminal,false);
  assert.equal(f.audio.paused,true);assert.equal(denied.session_id,before.session_id);assert.equal(denied.play_id,before.play_id);assert.equal(denied.accepted_played_ms,before.accepted_played_ms);
  const calls=f.calls.length;f.advance(20000);f.controller.flush();await tick();assert.equal(f.calls.length,calls,'no automatic retry or heartbeat while authority is unconfirmed');assert.equal(f.timers.size,0);
  await assert.rejects(f.controller.play(track,{position}),error=>error.retryable===true);await tick();
  const failed=f.calls.filter(x=>x.body.action==='playback.renew');assert.equal(failed.length,6);assert.equal(new Set(failed.map(x=>x.init.body)).size,1);
  assert.equal(f.audio.paused,true);assert.equal(f.audio.currentTime,position);assert.equal(f.begins().length,1);
  f.mode.opaque=false;await f.controller.play(track,{position});await tick();
  assert.equal(f.controller.state().confirmation_required,false);assert.equal(f.controller.state().session_id,before.session_id);assert.equal(f.audio.paused,false);assert.equal(f.begins().length,1);
  assert.equal(new Set(f.calls.filter(x=>x.body.action==='playback.renew').map(x=>x.init.body)).size,1);f.controller.dispose();
});
test('opaque event denial stops playback and preserves its frozen first event for confirmed manual retry',async()=>{
  const f=fixture();await f.controller.play(track);await tick();f.mode.opaque='playback.events';f.advance(2500);f.controller.flush();await tick();
  const before=f.controller.state(),blocked=f.events().filter(x=>x.body.seq===2);assert.equal(blocked.length,3);assert.equal(new Set(blocked.map(x=>x.init.body)).size,1);
  assert.equal(f.audio.paused,true);assert.equal(before.confirmation_required,true);const count=f.calls.length;f.advance(20000);await tick();assert.equal(f.calls.length,count);
  f.mode.opaque=false;await f.controller.play(track,{position:f.audio.currentTime});await tick();
  const retries=f.events().filter(x=>x.body.seq===2);assert.equal(retries.length,4);assert.equal(new Set(retries.map(x=>x.init.body)).size,1);
  assert.equal(f.calls.filter(x=>x.body.action==='playback.renew').length,1);assert.equal(f.controller.state().session_id,before.session_id);
  assert.equal(f.controller.state().accepted_played_ms,2500);assert.equal(f.begins().length,1);assert.equal(f.audio.paused,false);f.controller.dispose();
});
test('exhausted lost renewal acknowledgements retain the original committed receipt identity',async()=>{
  const f=fixture();await f.controller.play(track);await tick();const before=f.controller.state();f.mode.loseAlways='playback.renew';
  await assert.rejects(f.controller.renew(),error=>error.retryable===true);assert.equal(f.audio.paused,true);assert.equal(f.controller.state().confirmation_required,true);
  f.mode.loseAlways=null;await f.controller.play(track);await tick();
  const renews=f.calls.filter(x=>x.body.action==='playback.renew');assert.equal(renews.length,4);assert.equal(new Set(renews.map(x=>x.init.body)).size,1);
  assert.equal(f.controller.state().session_id,before.session_id);assert.equal(f.controller.state().asset_id,before.asset_id);assert.equal(f.begins().length,1);f.controller.dispose();
});
test('an explicitly selected next track is not blocked by an abandoned unconfirmed event sequence',async()=>{
  const f=fixture();await f.controller.play(track);await tick();f.mode.opaque='playback.events';f.advance(1000);f.controller.flush();await tick();
  assert.equal(f.controller.state().confirmation_required,true);f.mode.opaque=false;
  await f.controller.play({...track,id:'another-local-import'},{position:0});await tick();
  assert.equal(f.sessions.size,2);assert.equal(f.controller.state().track_id,'another-local-import');assert.equal(f.controller.state().confirmation_required,false);
  assert.equal(f.controller.state().accepted_seq,1);assert.equal(f.controller.state().accepted_played_ms,0);assert.equal(f.audio.paused,false);f.controller.dispose();
});
