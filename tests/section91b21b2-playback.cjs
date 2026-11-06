'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const app=fs.readFileSync('assets/js/app.js','utf8');
const lifecycle=fs.readFileSync('assets/js/player-playback-lifecycle.js','utf8');
const functions=app.slice(app.indexOf('  function playbackEndedEarly(){'),app.indexOf('  async function stepTrack('));
function setup(){
 let at=60,sequence=0;const listeners=new Map(),timers=new Map(),plays=[];
 const audio={duration:240,readyState:4,seeking:false,paused:false,error:null,src:'api/media.php?id=test',mode:'normal',
  get currentTime(){return at},set currentTime(value){if(this.mode==='throws')throw Error('Seek unavailable');if(this.mode==='ignores')return;at=this.mode==='clamps'?0:value;this.seeking=true},
  addEventListener(n,fn){if(!listeners.has(n))listeners.set(n,new Set());listeners.get(n).add(fn)},removeEventListener(n,fn){listeners.get(n)?.delete(fn)},
  fire(n){for(const fn of [...(listeners.get(n)||[])])fn()},
  pause(){this.paused=true},load(){at=0;this.duration=NaN;this.readyState=0;this.seeking=false;this.error=null},
  getAttribute(n){return this[n]},play(){plays.push(at);this.paused=false;return Promise.resolve()},
  metadata(d=240){this.duration=d;this.readyState=1;this.fire('loadedmetadata')},finishSeek(){this.seeking=false;this.readyState=4;this.fire('seeked')},
  resetAtError(){at=0;this.error={code:2}},setPosition(n){at=n}};
 const ctx={window:{},audio,st:{track:{id:'song',audio:audio.src,duration:240},playlistPlayback:{},playSource:'player'},
  playbackWantsAudio:true,playbackBufferTimer:null,pendingNetworkRecovery:null,footerPlayer:{dataset:{}},playerPlaybackStatus:{},
  setTimeout(fn){const id=++sequence;timers.set(id,fn);return id},clearTimeout(id){timers.delete(id)},time:n=>String(n),
  songCaptureSkill:null,songListeningSkill:null,updateFooterPlayer(){},updateQueueUi(){},clearPlaylistPlayback(){},recordListen(){},agent(){},
  Promise,Number,Math,Error};
 vm.createContext(ctx);vm.runInContext(lifecycle,ctx);ctx.playbackLifecycle=ctx.window.StonefellowPlaybackLifecycle.create();vm.runInContext(functions,ctx);
 ctx.rememberPlaybackPosition();
 return {ctx,audio,plays,listeners,timers,fail(){for(const fn of [...timers.values()])fn()},start(){audio.error={code:2};return ctx.recoverNetworkPlayback()}};
}
for(const duration of [NaN,Infinity,0,240])test(`network recovery waits for verified seek with duration ${duration}`,async()=>{
 const h=setup();assert.equal(h.start(),true);h.audio.metadata(duration);assert.deepEqual(h.plays,[]);h.audio.finishSeek();await Promise.resolve();assert.deepEqual(h.plays,[60]);assert.equal(h.ctx.pendingNetworkRecovery,null);
});
for(const mode of ['throws','ignores','clamps'])test(`${mode}: rejected/ignored seek never restarts at zero`,()=>{
 const h=setup();h.audio.mode=mode;h.start();h.audio.metadata();h.audio.finishSeek();assert.deepEqual(h.plays,[]);h.fail();assert.deepEqual(h.plays,[]);assert.equal(h.ctx.st.playing,false);assert.equal(h.ctx.savedPlaybackPosition(),60);assert.equal(h.ctx.pendingNetworkRecovery,null);
});
test('temporarily rejected seek retries after later progress',async()=>{
 const h=setup();h.audio.mode='throws';h.start();h.audio.metadata();h.audio.mode='normal';h.audio.fire('progress');assert.deepEqual(h.plays,[]);h.audio.finishSeek();await Promise.resolve();assert.deepEqual(h.plays,[60]);
});
test('short metadata cannot clamp checkpoint or start again',async()=>{
 const h=setup();h.start();h.audio.metadata(30);assert.deepEqual(h.plays,[]);h.audio.duration=240;h.audio.fire('durationchange');h.audio.finishSeek();await Promise.resolve();assert.deepEqual(h.plays,[60]);
});
test('MediaError currentTime reset retains verified checkpoint',async()=>{
 const h=setup();h.audio.resetAtError();h.ctx.rememberPlaybackPosition();h.ctx.recoverNetworkPlayback();h.audio.metadata();h.audio.finishSeek();await Promise.resolve();assert.deepEqual(h.plays,[60]);
});
test('legitimate backward seek replaces checkpoint',async()=>{
 const h=setup();h.audio.setPosition(20);h.ctx.rememberPlaybackPosition();h.audio.resetAtError();h.ctx.recoverNetworkPlayback();h.audio.metadata();h.audio.finishSeek();await Promise.resolve();assert.deepEqual(h.plays,[20]);
});
test('pause/new action cancels listeners and stale seek never plays',()=>{
 const h=setup();h.start();h.audio.metadata();h.ctx.invalidatePlayback();h.audio.finishSeek();h.fail();assert.deepEqual(h.plays,[]);assert.equal(h.timers.size,0);assert.ok([...h.listeners.values()].every(s=>s.size===0));
});
test('only one automatic retry per playback epoch',async()=>{
 const h=setup();h.start();h.audio.metadata();h.audio.finishSeek();await Promise.resolve();h.audio.error={code:2};assert.equal(h.ctx.recoverNetworkPlayback(),false);assert.deepEqual(h.plays,[60]);
});
test('decode errors do not auto-reload',()=>{
 const h=setup();h.audio.error={code:3};assert.equal(h.ctx.recoverNetworkPlayback(),false);assert.deepEqual(h.plays,[]);
});
test('manual retry after seek timeout restores the saved time',async()=>{
 const h=setup();h.audio.mode='throws';h.start();h.audio.metadata();h.fail();h.audio.mode='normal';const p=h.ctx.play(h.ctx.st.track,h.ctx.savedPlaybackPosition());h.audio.metadata();assert.deepEqual(h.plays,[]);h.audio.finishSeek();assert.equal(await p,true);assert.deepEqual(h.plays,[60]);
});
test('new track cancels pending manual resume without dangling promise',async()=>{
 const h=setup();const p=h.ctx.play(h.ctx.st.track,80);h.ctx.invalidatePlayback();assert.equal(await p,false);h.audio.finishSeek();assert.deepEqual(h.plays,[]);
});
test('resume play rejection remains paused at checkpoint',async()=>{
 const h=setup();h.audio.play=()=>Promise.reject(Error('NotAllowedError'));h.start();h.audio.metadata();h.audio.finishSeek();await Promise.resolve();await Promise.resolve();assert.equal(h.ctx.st.playing,false);assert.equal(h.ctx.savedPlaybackPosition(),60);
});
test('early ended with full metadata does not complete a quarter-track',()=>{
 const h=setup();assert.equal(h.ctx.playbackEndedEarly(),true);h.ctx.st.track.audio_publishing_mode='preview_master_split';assert.equal(h.ctx.playbackEndedEarly(),true);h.audio.setPosition(30);h.audio.duration=30;assert.equal(h.ctx.playbackEndedEarly(),false);h.audio.setPosition(239);h.audio.duration=240;assert.equal(h.ctx.playbackEndedEarly(),false);
});
test('app keeps progress, manual retry, cleanup and premature-completion boundaries wired',()=>{
 assert.match(app,/audio.addEventListener\('timeupdate',\(\)=>\{rememberPlaybackPosition\(\)/);
 assert.match(app,/audio.addEventListener\('seeked',rememberPlaybackPosition\)/);
 assert.match(app,/play\(st.track,savedPlaybackPosition\(\)/);
 assert.match(app,/if\(playbackEndedEarly\(\)\)\{[\s\S]*?return;[\s\S]*?recordListen\('complete'\)/);
});

test('replaying an ended song clears its EOF checkpoint instead of waiting for an impossible resume seek',async()=>{
 const h=setup();h.audio.setPosition(240);h.ctx.rememberPlaybackPosition();h.audio.ended=true;h.audio.paused=true;
 assert.equal(h.ctx.playbackLifecycle.position(h.ctx.playbackLifecycle.token()),240,'fixture has a genuine EOF checkpoint');
 assert.equal(h.ctx.savedPlaybackPosition(),0,'EOF is a replay request, not a resume position');
 h.audio.play=()=>{h.audio.setPosition(0);h.audio.ended=false;h.audio.paused=false;h.plays.push(0);return Promise.resolve()};
 const fresh={...h.ctx.st.track,audio:'api/canvas-audio.php?action=source&track_id=song'};
 assert.equal(await h.ctx.play(fresh,h.ctx.savedPlaybackPosition()),true);
 assert.deepEqual(h.plays,[0]);assert.equal(h.timers.size,0,'completed song never enters the 12-second verified-seek wait');
});
test('an actual unseekable saved position retains progress and has a distinct safe failure classification',async()=>{
 const h=setup();h.audio.mode='throws';h.audio.setPosition(0);let failure;
 const pending=h.ctx.play(h.ctx.st.track,60,'player',false,error=>{failure=error});
 h.audio.metadata();h.fail();assert.equal(await pending,false);
 assert.equal(failure.code,'audio_seek_unavailable');assert.equal(failure.stage,'resume_seek');
 assert.equal(h.ctx.savedPlaybackPosition(),60);assert.deepEqual(h.plays,[]);
});

test('a prematurely ended incomplete file retains its verified progress for a repaired source',()=>{
 const h=setup();h.audio.duration=100;h.audio.setPosition(100);h.ctx.rememberPlaybackPosition();h.audio.ended=true;
 assert.equal(h.ctx.playbackEndedEarly(),true);assert.equal(h.ctx.savedPlaybackPosition(),100);
});
