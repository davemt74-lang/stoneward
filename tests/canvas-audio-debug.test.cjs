'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {webcrypto}=require('node:crypto');
const app=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/app.js'),'utf8');
const helpers=app.slice(app.indexOf('  function canvasAudioState(){'),app.indexOf('  async function renderAgentCanvas(){'));
const player=app.slice(app.indexOf('  function play(t,'),app.indexOf('  function toggle(){'));

function harness(name='NotAllowedError',mediaCode=0){
  const failure=Object.assign(new Error('Private native failure text'),{name});
  const scene={id:19,music_inputs:{mode:'selected',track_ids:['song-1']}};
  const track={id:'song-1',title:'Song',audio:'old.mp3',duration:120};
  const records=[],statuses=[];
  const scope={crypto:webcrypto,WeakMap,URLSearchParams,Uint8Array,
    owner:'1',listeningRoom:scene,cfg:{apiBase:'api'},records,statuses,
    canvasSceneOwner:()=>scope.owner,isGalleryScene:()=>false,requirePublishedCanvas:async()=>{},
    st:{auth:{authenticated:true},track:null,playlistPlayback:{}},
    audio:{paused:true,ended:false,error:mediaCode?{code:mediaCode}:null,duration:120,currentTime:0,
      getAttribute:()=>'',pause(){},load(){},play:()=>Promise.reject(failure)},
    trackById:()=>track,refreshSiteData:async()=>{},savedPlaybackPosition:()=>0,playerVolume:()=>1,
    api:async()=>({tracks:[{track_id:'song-1',source_available:true}],releases:[],playlists:[]}),
    playbackLifecycle:{begin:()=>1,isCurrent:()=>true},footerPlayer:{dataset:{}},
    songCaptureSkill:null,songListeningSkill:null,cancelNetworkRecovery(){},clearPlaybackBufferWatch(){},
    setPlaybackStatus(){},clearPlaylistPlayback(){},updateQueueUi(){},updateFooterPlayer(){},agent(){},
    recordListen(){},rememberPlaybackPosition(){},playbackProblem:message=>statuses.push(message),
    recordCanvasDebug:(id,event,data)=>records.push({id,event,data})};
  vm.createContext(scope);
  vm.runInContext('function canvasOwner(){return owner;}\n'+helpers+'\n'+player,scope);
  return {scope,records,statuses,failure,scene};
}

test('runtime audio reports retain the current canvas and isolate account/session identifiers',()=>{
  const {scope,records,scene}=harness();
  scope.recordCanvasAudioDiagnostic({event:'session_ready'});
  scope.recordCanvasAudioDiagnostic({event:'play_requested',method:'play'});
  assert.equal(records.length,2);
  assert.match(records[0].id,/^[a-f0-9]{32}$/);
  assert.equal(records[0].id,records[1].id);
  assert.equal(records[0].event,'canvas_audio');
  assert.equal(records[0].data.scene_id,scene.id);
  scope.owner='2';scope.recordCanvasAudioDiagnostic({event:'session_ready'});
  assert.notEqual(records[2].id,records[0].id);
  scope.listeningRoom={id:20};scope.recordCanvasAudioDiagnostic({event:'session_ready'});
  assert.notEqual(records[3].id,records[2].id);
  scope.owner='';scope.recordCanvasAudioDiagnostic({event:'rpc_error'});
  scope.owner='2';scope.listeningRoom=null;scope.recordCanvasAudioDiagnostic({event:'rpc_error'});
  assert.equal(records.length,4);
});

test('actual player rejection preserves browser permission failure for canvas diagnostics',async()=>{
  const {scope,statuses}=harness();
  await assert.rejects(scope.playCanvasAudio('song-1'),error=>error.code==='audio_gesture_required'&&error.name==='NotAllowedError');
  assert.equal(statuses[0],'Tap Play to allow audio for this session.');
  assert.equal(scope.st.track.audio,'api/canvas-audio.php?action=source&track_id=song-1&scene_id=19');
});

test('network, decode, unsupported and interrupted failures keep distinct safe diagnostic codes',async()=>{
  for(const [name,media,expected] of [['Error',2,'audio_network_error'],['Error',3,'audio_decode_error'],
    ['NotSupportedError',0,'audio_source_unavailable'],['AbortError',0,'audio_playback_interrupted']]){
    const {scope}=harness(name,media);
    await assert.rejects(scope.playCanvasAudio('song-1'),error=>error.code===expected&&!error.message.includes('Private'));
  }
});

test('catalog failures are classified before touching the player and never expose upstream content',async()=>{
 const {scope}=harness();let touched=false;
 scope.api=async()=>{throw new Error('Private upstream URL and account details')};scope.play=()=>{touched=true;return true};
 await assert.rejects(scope.playCanvasAudio('song-1'),error=>error.code==='audio_catalog_unavailable'&&error.stage==='catalog'&&!error.message.includes('Private'));
 assert.equal(touched,false);
});
test('published selection and site catalog failures identify their stages',async()=>{
 const {scope}=harness();scope.api=async()=>({tracks:[],releases:[],playlists:[]});
 await assert.rejects(scope.playCanvasAudio('song-1'),error=>error.code==='track_unavailable'&&error.stage==='selection');
 scope.api=async()=>({tracks:[{track_id:'song-1',source_available:true}],releases:[],playlists:[]});scope.trackById=()=>null;
 await assert.rejects(scope.playCanvasAudio('song-1'),error=>error.code==='track_unavailable'&&error.stage==='site_catalog');
});
test('a verified-seek timeout remains distinguishable from native playback rejection',async()=>{
 const {scope}=harness();scope.play=async(track,position,source,keepPlaylist,onFailure)=>{onFailure(Object.assign(new Error('Private restore failure'),{code:'audio_seek_unavailable',stage:'resume_seek'}));return false};
 await assert.rejects(scope.playCanvasAudio('song-1'),error=>error.code==='audio_seek_unavailable'&&error.stage==='resume_seek'&&!error.message.includes('Private'));
});
