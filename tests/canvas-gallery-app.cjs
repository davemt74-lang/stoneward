'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {webcrypto}=require('node:crypto');
const app=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/app.js'),'utf8');
const commands=app.slice(app.indexOf('  function canvasDesignBrief('),app.indexOf('  async function liveAgent('));
const audioHelpers=app.slice(app.indexOf('  function canvasAudioState(){'),app.indexOf('  async function renderAgentCanvas(){'));
const auth=app.slice(app.indexOf('  function syncAuthMenu(){'),app.indexOf('  async function loadAuth('));
const first='a'.repeat(32),second='b'.repeat(32);
const scene=id=>({gallery_id:id,canvas_kind:'custom',track_id:'',title:'Shared experience',html:'<p>Experience</p>',css:'p{color:tan}',music_inputs:{mode:'selected',track_ids:['published']}});
function harness(authenticated=false){
  const reads=[],posts=[],shown=[],notices=[],routes=[],restores=[],plays=[];
  let closes=0;
  const scope={AbortController,DOMException,URL,URLSearchParams,Uint8Array,WeakMap,crypto:webcrypto,
    cfg:{apiBase:'api'},document:{baseURI:'https://stonefellow.test/app/',documentElement:{dataset:{}},getElementById:()=>null},
    location:{href:'https://stonefellow.test/app/?view=canvas-gallery'},mainCanvas:{},canvas:{},
    st:{auth:{authenticated,user:authenticated?{id:7,display_name:'Viewer'}:null},track:{id:'published',title:'Published song',duration:90}},
    localStorage:{getItem:()=>null,setItem(){},removeItem(){}},
    window:{SFPublicAgentCanvas:{active:()=>true,sceneActive:()=>shown.length>closes,
      showScene:(value)=>{shown.push(value);return true;},closePage(){},setSceneWorkflow(){},setSceneCoding(){},closeSceneHistory(){},
      closeScene:()=>{closes++;},syncSceneAccess(){}}},
    $:()=>null,observedPlayer:()=>({track:{id:'published'}}),setCanvasImageEditDraft(){},
    loadAuth:async()=>{},popupEntry:(role,message)=>notices.push({role,message}),setUrl:(view,params)=>routes.push({view,params}),
    navigate:async(view,params)=>routes.push({view,params}),
    accountPhotoPreferencesRequest:null,chatImagePicker:null,chatMusicPicker:null,chatResourcePicker:null,canvasImageEditDraft:null,
    audio:{duration:90,currentTime:0,paused:true,ended:false,volume:1,muted:false},playerVolume:()=>1,
    trackById:id=>id==='published'?{id,title:'Published song',duration:90}:null,refreshSiteData:async()=>{},savedPlaybackPosition:()=>0,
    play:async track=>{plays.push(track);scope.audio.paused=false;return true;},
    api:async path=>{
      reads.push(path);
      if(path.startsWith('canvas-gallery.php?')){const id=new URLSearchParams(path.split('?')[1]).get('id');return {ok:true,item:{id,title:'Shared experience'},scene:scene(id)};}
      if(path.startsWith('canvas-audio.php?'))return {tracks:[{track_id:'published',source_available:true}],releases:[]};
      throw new Error('Unexpected API read: '+path);
    },
    secureJsonPost:async(path,payload)=>{posts.push({path,payload});assert.equal(path,'canvas-gallery.php');assert.equal(payload.action,'remix');return {scene:{id:91}};},
  };
  vm.createContext(scope);
  vm.runInContext('let listeningRoom=null,listeningRoomRequest=null,listeningRoomOwner=null,canvasSelection=null;'+commands+'\n'+audioHelpers+'\n'+auth,scope);
  scope.canvasProjects=()=>null;
  scope.syncCanvasCodingModelMenu=()=>{};
  scope.handleListeningRoomCommand=async action=>{scope.cancelGalleryOpen();restores.push(action);vm.runInContext('listeningRoom={id:91,canvas_kind:"custom"};listeningRoomOwner=canvasOwner()',scope);};
  return {scope,reads,posts,shown,notices,routes,restores,plays,getCloses:()=>closes,
    current:()=>vm.runInContext('listeningRoom',scope),setPending:()=>vm.runInContext('listeningRoomRequest=new AbortController()',scope)};
}
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};};

test('guest Open uses only public reads, has a distinct public identity and spends no tokens',async()=>{
  const h=harness();await h.scope.openGalleryCanvas({id:first,title:'Shared experience',creator_label:'Artist'});
  assert.equal(h.shown.length,1);assert.equal(h.current().gallery_id,first);assert.equal(h.current().id,undefined);
  assert.equal(h.scope.canvasSceneOwner(),'gallery-guest');assert.equal(h.posts.length,0);
  assert.equal(h.reads.length,1);assert.match(h.reads[0],/action=open/);assert.doesNotMatch(h.reads[0],/user_id|scene_id|agent-scene/);
});
test('guest canvas audio uses the published catalog and the canonical main player',async()=>{
  const h=harness();await h.scope.openGalleryCanvas({id:first});
  const catalog=await h.scope.loadCanvasAudioCatalog();assert.equal(catalog.tracks.length,1);
  await h.scope.playCanvasAudio('published');assert.equal(h.plays.length,1);
  assert.equal(h.plays[0].audio,'api/canvas-audio.php?action=source&track_id=published');
  assert.equal(h.posts.length,0);assert.ok(h.reads.filter(path=>path.includes('action=open')).length>=3);
});
test('a public canvas never loosens private canvas audio authorization',()=>{
  const h=harness();vm.runInContext('listeningRoom={id:17}',h.scope);
  assert.throws(()=>h.scope.requireCanvasAudioSession('gallery-guest',h.current()),/no longer active/);
});
test('a newer gallery Open supersedes an older response',async()=>{
  const h=harness(),wait=deferred();h.scope.api=async path=>path.includes(first)?wait.promise:{scene:scene(second)};
  const old=h.scope.openGalleryCanvas({id:first});await h.scope.openGalleryCanvas({id:second});wait.resolve({scene:scene(first)});await old;
  assert.deepEqual(h.shown.map(value=>value.gallery_id),[second]);
});
test('a newer private request prevents a delayed public response replacing its canvas',async()=>{
  const h=harness(),wait=deferred();h.scope.api=()=>wait.promise;
  const pending=h.scope.openGalleryCanvas({id:first});h.setPending();wait.resolve({scene:scene(first)});await pending;
  assert.equal(h.shown.length,0);
});
test('closing while Open is pending discards its late response',async()=>{
  const h=harness(),wait=deferred();h.scope.api=()=>wait.promise;
  const pending=h.scope.openGalleryCanvas({id:first});h.scope.closeListeningRoom(false);wait.resolve({scene:scene(first)});await pending;
  assert.equal(h.shown.length,0);
});
test('an aborted gallery page cannot apply a late Open',async()=>{
  const h=harness(),wait=deferred(),request=new AbortController();h.scope.api=()=>wait.promise;
  const pending=h.scope.openGalleryCanvas({id:first},request.signal);request.abort();wait.resolve({scene:scene(first)});await pending;
  assert.equal(h.shown.length,0);
});
test('logout destroys a public frame containing the former viewer data',async()=>{
  const h=harness(true);await h.scope.openGalleryCanvas({id:first});assert.equal(h.scope.canvasSceneOwner(),'7');
  h.scope.st.auth={authenticated:false,user:null};h.scope.syncAuthMenu();
  assert.equal(h.current(),null);assert.ok(h.getCloses()>0);
});
test('account changes destroy the prior viewer public frame',async()=>{
  const h=harness(true);await h.scope.openGalleryCanvas({id:first});h.scope.st.auth.user={id:8};h.scope.syncAuthMenu();
  assert.equal(h.current(),null);assert.ok(h.getCloses()>0);
});
test('guest Remix requests sign-in and never saves or starts a model',async()=>{
  const h=harness();await h.scope.remixGalleryCanvas({id:first});assert.equal(h.posts.length,0);assert.equal(h.routes[0].view,'login');
});
test('signed-in Remix creates one free private copy and reuses normal history restore',async()=>{
  const h=harness(true);await h.scope.remixGalleryCanvas({id:first});
  assert.equal(h.posts.length,1);assert.equal(h.posts[0].payload.id,first);assert.match(h.posts[0].payload.request_id,/^[a-f0-9]{32}$/);
  assert.equal(h.restores.length,1);assert.equal(h.restores[0].kind,'restore');assert.equal(h.restores[0].id,91);assert.equal(h.current().gallery_id,undefined);
});
test('a newer Open supersedes a slow Remix without applying the private copy',async()=>{
  const h=harness(true),wait=deferred();h.scope.secureJsonPost=()=>wait.promise;
  const pending=h.scope.remixGalleryCanvas({id:first});await h.scope.openGalleryCanvas({id:second});wait.resolve({scene:{id:91}});await pending;
  assert.equal(h.restores.length,0);assert.equal(h.current().gallery_id,second);
});
test('a newer Remix supersedes a slow Open',async()=>{
  const h=harness(true),wait=deferred();h.scope.api=()=>wait.promise;
  const pending=h.scope.openGalleryCanvas({id:first});await h.scope.remixGalleryCanvas({id:second});wait.resolve({scene:scene(first)});await pending;
  assert.equal(h.shown.length,0);assert.equal(h.restores.length,1);
});
test('unpublished gallery authorization blocks the next audio action and closes the scene',async()=>{
  const h=harness();await h.scope.openGalleryCanvas({id:first});h.scope.api=async()=>{throw Object.assign(new Error('Unavailable'),{status:404});};
  await assert.rejects(h.scope.loadCanvasAudioCatalog());assert.equal(h.current(),null);assert.equal(h.plays.length,0);
});
test('public scene identity and exact entry must match the requested catalog item',async()=>{
  const h=harness();h.scope.api=async()=>({scene:scene(second)});
  await assert.rejects(h.scope.openGalleryCanvas({id:first}),/invalid canvas/);assert.equal(h.shown.length,0);
});
test('account changes abort an in-flight Remix before its result can be opened',async()=>{
  const h=harness(true),wait=deferred();let request;
  h.scope.secureJsonPost=(_path,_payload,_retry,signal)=>{request=signal;return wait.promise;};
  const pending=h.scope.remixGalleryCanvas({id:first});h.scope.st.auth.user={id:8};h.scope.syncAuthMenu();
  assert.equal(request.aborted,true);wait.resolve({scene:{id:91}});await pending;assert.equal(h.restores.length,0);
});
function catalogPlayCallback(scope){
  const start=app.indexOf('      onSceneCatalogPlay:async trackId=>{'),end=app.indexOf('      onSceneCatalogOpen:',start);
  assert.ok(start>0&&end>start);
  const fn=app.slice(start,end).trim().replace(/^onSceneCatalogPlay:/,'').replace(/,$/,'');
  vm.runInContext('globalThis.catalogPlay=('+fn+');',scope);return scope.catalogPlay;
}
for(const operation of ['close','account change'])test('late catalog button authorization cannot play after '+operation,async()=>{
  const h=harness(true);await h.scope.openGalleryCanvas({id:first});h.scope.st.track=null;
  const wait=deferred();h.scope.api=()=>wait.promise;const click=catalogPlayCallback(h.scope)('published');
  if(operation==='close')h.scope.closeListeningRoom(false);else{h.scope.st.auth.user={id:8};h.scope.syncAuthMenu();}
  wait.resolve({scene:scene(first)});await click;assert.equal(h.plays.length,0);
});
