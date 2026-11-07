'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const app=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
const callbacks=app.slice(app.indexOf('  function canvasAudioState(){'),app.indexOf('  async function renderAgentCanvas(){'));
const clone=value=>JSON.parse(JSON.stringify(value));
const deferred=()=>{let resolve;const promise=new Promise(done=>resolve=done);return {promise,resolve};};
function fixture(gallery=false){
 const calls=[],sourceCalls=[],plays=[];
 const scene=gallery?{gallery_id:'a'.repeat(32)}:{id:41};
 scene.playlist_manifest={playlists:[{title:'Cached private title must not be replayed'}]};
 const result={tracks:[{track_id:'song-a',source_available:true}],releases:[],playlists:[{title:'Fresh server projection'}]};
 const scope={URLSearchParams,Object,Number,WeakMap,DOMException,owner:'1',listeningRoom:scene,st:{auth:{authenticated:true},track:null},cfg:{apiBase:'api'},
  audio:{duration:6,currentTime:0,paused:true,ended:false,volume:1},
  canvasSceneOwner:()=>scope.owner,isGalleryScene:scene=>typeof scene?.gallery_id==='string',requirePublishedCanvas:async()=>{},
  api:async(url,body,signal)=>{calls.push({url,body,signal});return result;},
  fetch:async(url,options)=>{sourceCalls.push({url,options});return {ok:true,headers:{get:key=>key==='content-type'?'audio/mpeg':null},blob:async()=>({size:128})};},
  playerVolume:()=>1,trackById:id=>({id,title:'Current song'}),refreshSiteData:async()=>{},savedPlaybackPosition:()=>0,
  play:async(...args)=>{plays.push(args);return true;}};
 vm.createContext(scope);vm.runInContext(callbacks,scope);
 return {scope,scene,result,calls,sourceCalls,plays};
}
test('catalog context comes from captured host scene and fresh server manifest only',async()=>{
 const {scope,calls}=fixture();
 const result=await scope.loadCanvasAudioCatalog({track_id:'song-a',scene_id:999,gallery_id:'b'.repeat(32),playlist_manifest:{}});
 const query=new URLSearchParams(calls[0].url.split('?')[1]);
 assert.equal(query.get('scene_id'),'41');assert.equal(query.has('gallery_id'),false);assert.equal(query.get('track_id'),'song-a');
 assert.deepEqual(clone(result.playlists),[{title:'Fresh server projection'}]);
 assert.equal(calls[0].body,undefined);
});
test('gallery catalog has only trusted gallery identity and never private scene context',async()=>{
 const {scope,calls}=fixture(true);await scope.loadCanvasAudioCatalog({scene_id:999,gallery_id:'b'.repeat(32)});
 const query=new URLSearchParams(calls[0].url.split('?')[1]);
 assert.equal(query.get('gallery_id'),'a'.repeat(32));assert.equal(query.has('scene_id'),false);
});
test('MP3 reads forward the same scene context to independently validated source endpoint',async()=>{
 const {scope,calls,sourceCalls}=fixture();await scope.loadCanvasAudioSource('song-a');
 assert.equal(new URLSearchParams(calls[0].url.split('?')[1]).get('scene_id'),'41');
 assert.equal(sourceCalls[0].url,'api/canvas-audio.php?action=source&track_id=song-a&scene_id=41');
 assert.equal(sourceCalls[0].options.cache,'no-store');assert.equal(sourceCalls[0].options.credentials,'same-origin');
});
test('canonical playback uses context-bound wrapper and retains captured listening attribution',async()=>{
 const {scope,plays}=fixture(true);await scope.playCanvasAudio('song-a');
 assert.equal(plays[0][0].audio,'api/canvas-audio.php?action=source&track_id=song-a&gallery_id='+'a'.repeat(32));
 assert.deepEqual(clone(plays[0][5]),{gallery_id:'a'.repeat(32)});
});
for(const change of ['owner','scene'])test('late '+change+' catalog is discarded before source access or playback',async()=>{
 const {scope,result,sourceCalls,plays}=fixture();const pending=deferred();scope.api=()=>pending.promise;
 const request=scope.playCanvasAudio('song-a');await Promise.resolve();await Promise.resolve();
 if(change==='owner')scope.owner='2';else scope.listeningRoom={id:42};pending.resolve(result);
 await assert.rejects(request,error=>error.code==='audio_session_closed');assert.equal(sourceCalls.length,0);assert.equal(plays.length,0);
});
test('a scene change during publication validation prevents even a stale catalog request',async()=>{
 const {scope,calls}=fixture();const pending=deferred();scope.requirePublishedCanvas=()=>pending.promise;
 const request=scope.loadCanvasAudioCatalog();scope.listeningRoom={id:42};pending.resolve();
 await assert.rejects(request,error=>error.code==='audio_session_closed');assert.equal(calls.length,0);
});
test('source response from the previous owner cannot deliver a Blob to custom code',async()=>{
 const {scope}=fixture();const pending=deferred();scope.fetch=()=>pending.promise;
 const request=scope.loadCanvasAudioSource('song-a');await Promise.resolve();await Promise.resolve();await Promise.resolve();
 scope.owner='2';pending.resolve({ok:true,headers:{get:()=> 'audio/mpeg'},blob:async()=>({size:128})});
 await assert.rejects(request,error=>error.code==='audio_session_closed');
});
test('withdrawal and ownership failures reject without touching the canonical player',async()=>{
 for(const error of ['Source was withdrawn','Playlist owner changed']){
  const {scope,plays}=fixture();scope.api=async()=>{throw Error(error);};
  await assert.rejects(scope.playCanvasAudio('song-a'),error=>error.code==='audio_catalog_unavailable');assert.equal(plays.length,0);
 }
});
test('contextless and malformed host scenes fail closed instead of making a public catalog request',async()=>{
 for(const scene of [{},{id:0},{id:'41'},{gallery_id:'invalid'}]){
  const {scope,calls}=fixture();scope.listeningRoom=scene;
  await assert.rejects(scope.loadCanvasAudioCatalog(),error=>error.code==='audio_session_closed');assert.equal(calls.length,0);
 }
});
test('testing-agent sandbox uses the candidate server context and fresh manifest for catalog and MP3',async()=>{
 const {scope,scene,calls,sourceCalls}=fixture();let preview;
 scope.canvasOwner=()=>scope.owner;
 scope.window={SFCanvasBuildWorkflow:{create:options=>({run:async()=>options.test(scene,{signal:new AbortController().signal,onEvent(){}})})},
  SFCanvasSceneTester:{test:async(candidate,options)=>{preview=options;return {verified:true};}}};
 const begin=app.indexOf('  async function runCanvasBuildWorkflow('),end=app.indexOf('  async function stopCanvasBuildWorkflow(',begin);
 vm.runInContext(app.slice(begin,end),scope);
 const request={signal:new AbortController().signal,sceneBuild:{workflowEligible:true,owner:'1',payload:{request_id:'b'.repeat(32)}}};
 await scope.runCanvasBuildWorkflow({scene:{workflow_required:true}},request,{prepareSceneTest(){}},()=>true);
 assert.deepEqual(clone((await preview.loadCatalog({scene_id:999})).playlists),[{title:'Fresh server projection'}]);
 await preview.loadAudio('song-a');
 assert(calls.every(call=>new URLSearchParams(call.url.split('?')[1]).get('scene_id')==='41'));
 assert.equal(sourceCalls[0].url,'api/canvas-audio.php?action=source&track_id=song-a&scene_id=41');
 const count=calls.length;scope.owner='2';await assert.rejects(preview.loadCatalog(),error=>error.name==='AbortError');assert.equal(calls.length,count);
});
