'use strict';
// Production composer and discussion transport: selection is a draft operation only.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const app=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
const start=app.indexOf('  let chatMusicPicker=null,chatMusicCommandTimer=null;');
const end=app.indexOf('  function chatImageCommandRange(',start);
assert(start>=0&&end>start);
const block=app.slice(start,end);
const A='a'.repeat(64),B='b'.repeat(64),C='c'.repeat(64);
let checks=0;
function check(value,label){assert(value,label);checks++;console.log('PASS '+label);}
function equal(actual,expected,label){assert.deepEqual(JSON.parse(JSON.stringify(actual)),expected,label);checks++;console.log('PASS '+label);}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const deferred=()=>{let resolve;const promise=new Promise(done=>resolve=done);return {promise,resolve};};
function fixture(){
  const ctx={AbortController,DOMException,setTimeout,clearTimeout,console,owner:'1',chatImagePicker:null,
    input:{value:'',selectionStart:0,selectionEnd:0,setSelectionRange(a,b){this.selectionStart=a;this.selectionEnd=b;},addEventListener(){}},
    listeningRoom:{id:4,playlist_inputs:{items:[{ref:'own-17',version:A}]},music_inputs:{mode:'selected',track_ids:['direct','derived']},direct_music_inputs:{mode:'selected',track_ids:['direct']}},
    outcome:{ids:[],items:[]},opens:[],loads:[],notices:[],syncs:0,window:{},chatImageReferenceLimit:4,chatImageDraftLimit:2000};
  ctx.canvasOwner=()=>ctx.owner;
  ctx.loadAuth=async()=>{};
  ctx.popupEntry=(kind,text)=>ctx.notices.push({kind,text});
  ctx.directListeningRoomCommand=draft=>/^\/edit\b/i.test(draft)?{kind:'revise'}:/^let.s build/i.test(draft)?{kind:'create'}:null;
  ctx.api=async(url,body,signal)=>{ctx.loads.push({url,body,signal});return {ok:true,items:[]};};
  ctx.window.SFCanvasResourcePicker={open:async options=>{ctx.opens.push(options);await options.load(options.signal);return ctx.outcome;}};
  ctx.window.SFCanvasMusicPicker={open:async options=>{ctx.opens.push(options);return ctx.musicOutcome;}};
  vm.runInNewContext(block+'\nglobalThis.helpers={chatDraftResourceInputs,chatResourceCommandRange,applyChatResourceSelection,openChatResourcePicker,openChatMusicPicker,chatMusicCommandRange,applyChatMusicSelection,chatDraftMusicInputs,chatDraftBindingLength,active:()=>chatResourcePicker};',ctx);
  return ctx;
}
(async()=>{
  let ctx=fixture(),h=ctx.helpers;
  const bindings='{playlist:own-17@'+A+'} {playlist:artist-42@'+B+'}';
  equal(h.chatDraftResourceInputs(bindings+' '+bindings),{playlist_inputs:{items:[{ref:'own-17',version:A},{ref:'artist-42',version:B}]}},'Versioned playlist refs preserve order and deduplicate exact bindings');
  equal(h.chatDraftResourceInputs('{playlists-clear}'),{playlist_inputs:{items:[]}},'An explicit empty playlist selection clears inheritance');
  equal(h.chatDraftResourceInputs('No resource selection'),{},'Omitted playlists stay omitted for server-side inheritance');
  equal(h.chatDraftResourceInputs('{playlist:own-0@'+A+'} {playlist:own-1@bad} {playlist:foreign-2@'+A+'}'),{},'Malformed playlist refs never become authoritative input');
  equal(h.chatDraftResourceInputs('{playlist:own-17@'+A+'} {playlist:own-17@'+B+'}').playlist_inputs.items,[{ref:'own-17',version:A},{ref:'own-17',version:B}],'Conflicting versions stay visible to server validation rather than silently choosing one');
  equal(h.chatResourceCommandRange('Build (/Playlists).'),{kind:'playlists',start:7,end:17,standalone:false},'Playlist command keeps its exact inline replacement range');
  check(!h.chatResourceCommandRange('/playlists-all')&&!h.chatResourceCommandRange('test/playlists')&&!h.chatResourceCommandRange('/playlists',3),'Partial words and a caret inside a command do not open the picker');
  ctx.input.value='/edit {music-track:direct} {image-'+ 'a'.repeat(32)+'} {merch:hoodie} '+bindings+' /playlists';
  h.applyChatResourceSelection(h.chatResourceCommandRange(ctx.input.value),['artist-42'],[{ref:'artist-42',version:C}]);
  check(ctx.input.value.includes('{playlist:artist-42@'+C+'}')&&!ctx.input.value.includes(A)&&!ctx.input.value.includes(B)&&ctx.input.value.includes('{music-track:direct}')&&ctx.input.value.includes('{merch:hoodie}')&&ctx.input.value.includes('{image-'),'Playlist replacement changes only playlist bindings and keeps independent resources');
  ctx.input.value+=' /playlists';h.applyChatResourceSelection(h.chatResourceCommandRange(ctx.input.value),[]);
  check(ctx.input.value.includes('{playlists-clear}')&&!ctx.input.value.includes('{playlist:')&&ctx.input.value.includes('{music-track:direct}'),'Clearing playlists preserves directly selected overlapping tracks');
  ctx.input.value='/edit '+bindings+' /music';h.applyChatMusicSelection(h.chatMusicCommandRange(ctx.input.value),{mode:'selected',track_ids:['new-direct']});
  check(ctx.input.value.includes(bindings)&&ctx.input.value.includes('{music-track:new-direct}'),'Replacing /music never removes playlist provenance');
  ctx.input.value+=' /music';h.applyChatMusicSelection(h.chatMusicCommandRange(ctx.input.value),{mode:'selected',track_ids:[]});
  check(ctx.input.value.includes(bindings)&&ctx.input.value.includes('{music-clear}')&&!ctx.input.value.includes('{music-track:'),'An explicit empty /music action clears only the direct selection');
  equal(h.chatDraftMusicInputs(ctx.input.value),{mode:'selected',track_ids:[]},'The direct clear marker stays explicit alongside selected playlists');
  ctx.input.value+=' /music';h.applyChatMusicSelection(h.chatMusicCommandRange(ctx.input.value),{mode:'selected',track_ids:['overlap']});
  check(ctx.input.value.includes(bindings)&&!ctx.input.value.includes('{music-clear}')&&ctx.input.value.includes('{music-track:overlap}'),'Reselecting direct music replaces its clear marker without changing playlists');
  equal(h.chatDraftMusicInputs('No music binding'),null,'Untouched music scope remains omitted rather than emitting a clear marker');
  const before=ctx.input.value='/playlists';assert.throws(()=>h.applyChatResourceSelection(h.chatResourceCommandRange(before),['own-17'],[]));
  check(ctx.input.value===before,'Unverified picker results cannot modify the draft');
  check(h.chatDraftBindingLength(bindings)===bindings.length+1,'Playlist refs receive draft binding allowance without consuming the design brief budget');
  const design={};const designStart=app.indexOf('  function canvasDesignBrief(brief){');
  vm.runInNewContext(app.slice(designStart,app.indexOf('  let manualAgentHidden=',designStart))+'\nglobalThis.clean=canvasDesignBrief;',design);
  equal(design.clean('Describe the sequence '+bindings+' {music-clear} {playlists-clear}'),'Describe the sequence','Playlist and direct clear bindings never become literal canvas design text');

  ctx=fixture();h=ctx.helpers;ctx.input.value='/edit /playlists';ctx.outcome={ids:['own-17'],items:[{ref:'own-17',version:B}]};
  await h.openChatResourcePicker(h.chatResourceCommandRange(ctx.input.value));
  equal(ctx.opens[0].selectedIds,['own-17'],'An edit inherits only the original saved playlist refs');
  equal(ctx.opens[0].selectedVersions,{'own-17':A},'Saved playlist versions reach the picker so changes are disclosed');
  check(ctx.input.value==='/edit {playlist:own-17@'+B+'}'&&ctx.loads.length===1&&ctx.loads[0].url==='canvas-resources.php?kind=playlists'&&ctx.loads[0].body===undefined&&ctx.loads[0].signal.aborted,'Apply uses one cancellable inventory GET and edits only the unsent draft');
  ctx.input.value='/edit {playlists-clear} /playlists';ctx.outcome={ids:[],items:[]};await h.openChatResourcePicker(h.chatResourceCommandRange(ctx.input.value));
  equal(ctx.opens[1].selectedIds,[],'An explicit clear does not reselect saved playlists');
  ctx.input.value='/edit /music';ctx.musicOutcome=null;await h.openChatMusicPicker(h.chatMusicCommandRange(ctx.input.value));
  equal(ctx.opens[2].selectedIds,['direct'],'Music picker edits direct selection rather than the effective playlist union');
  ctx.listeningRoom.direct_music_inputs=null;ctx.input.value='/edit /music';await h.openChatMusicPicker(h.chatMusicCommandRange(ctx.input.value));
  equal(ctx.opens[3].selectedIds,[],'An explicit null direct selection cannot promote playlist-derived tracks into direct scope');
  delete ctx.listeningRoom.direct_music_inputs;await h.openChatMusicPicker(h.chatMusicCommandRange(ctx.input.value));
  equal(ctx.opens[4].selectedIds,['direct','derived'],'Legacy canvases still treat their old music selection as direct');

  ctx=fixture();h=ctx.helpers;ctx.owner='';ctx.input.value='/playlists';ctx.outcome={ids:['artist-42'],items:[{ref:'artist-42',version:B}]};await h.openChatResourcePicker(h.chatResourceCommandRange(ctx.input.value));
  check(ctx.opens[0].signedIn===false&&ctx.input.value==='{playlist:artist-42@'+B+'}'&&!ctx.notices.length,'Guests can select the published artist inventory without a private account probe');
  ctx.input.value='/canvas';await h.openChatResourcePicker(h.chatResourceCommandRange(ctx.input.value));
  check(ctx.opens.length===1&&ctx.notices.length===1,'The guest exception does not open private canvas inventory');

  for(const mutation of ['owner','draft','scene','cancel']){
    ctx=fixture();h=ctx.helpers;ctx.input.value='/edit /playlists';const pending=deferred();ctx.outcome=pending.promise;
    const opening=h.openChatResourcePicker(h.chatResourceCommandRange(ctx.input.value));await tick();
    if(mutation==='owner')ctx.owner='2';
    if(mutation==='draft')ctx.input.value='Keep my newer draft';
    if(mutation==='scene')ctx.listeningRoom={...ctx.listeningRoom,id:5};
    const expected=ctx.input.value;pending.resolve(mutation==='cancel'?null:{ids:['own-17'],items:[{ref:'own-17',version:B}]});await opening;
    check(ctx.input.value===expected&&ctx.opens[0].signal.aborted&&!h.active(),'Late '+mutation+' changes cannot attach a stale playlist version');
  }
  const scopeStart=app.indexOf('  function scopeCanvasMusicCatalog('),scopeEnd=app.indexOf('  async function loadCanvasAudioCatalog(',scopeStart);
  const scope={};vm.runInNewContext(app.slice(scopeStart,scopeEnd)+'\nglobalThis.scope=scopeCanvasMusicCatalog;',scope);
  equal(scope.scope({tracks:[{track_id:'a',source_available:true}],releases:[{track_ids:['a']}]},{music_inputs:{mode:'selected',track_ids:[]}}),{tracks:[],releases:[]},'An empty effective selected union denies every track instead of falling back to the entire catalog');

  const projects={window:{},document:{getElementById:()=>null},crypto:require('node:crypto').webcrypto,DOMException,AbortController,Uint8Array,setTimeout,clearTimeout};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/js/canvas-projects-ui.js'),'utf8'),projects);
  const calls=[],controller=projects.window.SFCanvasProjects.create({getOwner:()=> '1',get:async()=>({preferences:{ideas_enabled:true,auto_approve:false},proposals:[]}),post:async payload=>{calls.push(payload);return {reply:'Discussion only'};}});
  await controller.discuss({brief:'Discuss my sequence',playlistInputs:{items:[{ref:'own-17',version:A}]},musicInputs:{mode:'selected',track_ids:['direct']}});
  equal(calls[0].playlist_inputs,{items:[{ref:'own-17',version:A}]},'Project discussion forwards exact playlist versions beside independent direct music');
  await controller.discuss({brief:'Clear the sequence',playlistInputs:{items:[]}});
  equal(calls[1].playlist_inputs,{items:[]},'Project discussion preserves explicit empty playlist scope');
  await controller.discuss({brief:'Keep the sequence'});
  check(!Object.hasOwn(calls[2],'playlist_inputs')&&calls.every(call=>call.action==='discuss'),'Discussion omission remains omission without authorizing a build');
  await controller.discuss({brief:'Keep playlist songs; clear direct music',playlistInputs:{items:[{ref:'own-17',version:A}]},musicInputs:{mode:'selected',track_ids:[]}});
  equal(calls[3].music_inputs,{mode:'selected',track_ids:[]},'Mixed project discussion binds direct clearing separately from retained playlists');
  controller.destroy();
  console.log(`PASS ${checks} playlist composer checks`);
})().catch(error=>{console.error(error);process.exitCode=1;});
