'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const read=p=>fs.readFileSync(p,'utf8');
const app=read('assets/js/app.js');
function block(start,end){const a=app.indexOf(start),b=app.indexOf(end,a);assert.ok(a>=0&&b>a);return app.slice(a,b);}
const execute=block('  async function executeSongCaptureAction(action){','  // Private annotation commands');
const route=block('  function directSongListeningCommand(message){','  // One bounded, canonical browser snapshot');
function context(supported=true){
 const calls={record:0,listen:0,stop:0,open:0,updates:0},state={active:false};
 const ctx={window:{SFPublicAgentCanvas:{openLyrics(){calls.open++}}},st:{track:{id:'song',title:'Song'}},audio:{currentTime:60},
 songExperience:null,songListeningReceipt:null,songLyricDraft:null,songLyricReport:null,songLyricCorrected:'',songPersonalNote:'',songTranscriptionBusy:false,songAnalysisBusy:false,
 vocalStudio:{status:()=>({active:false})},songCaptureSkill:{status:()=>({active:false}),begin(){calls.record++;return {text:'record'}},stop:async()=>({text:'clip stop'})},
 songListeningSkill:{status:()=>({active:state.active,track_id:'song',start_ms:60000}),begin(){calls.listen++;state.active=supported;return {ok:supported,text:supported?'Listening':'Unsupported; no microphone or recording started'}},async stop(){calls.stop++;state.active=false;return {text:'stopped'}}},
 updateSongExperience(changes){calls.updates++;Object.assign(ctx.songExperience,changes)},currentSongDraft:()=>null};
 vm.createContext(ctx);vm.runInContext(execute+'\n'+route,ctx);return {ctx,calls,state};
}
test('Production typed commands route both and and ampersand to saved lyrics, never recording',()=>{
 const {ctx}=context();for(const input of ['Listen and interpret','Listen & Interpret','please listen to this song and interpret the lyrics']){
  assert.equal(ctx.directSongListeningCommand(input).type,'song_capture_live_start');}
});
test('Production listening action starts only the listening skill and opens its rail',async()=>{
 const {ctx,calls}=context();await ctx.executeSongCaptureAction({type:'song_capture_live_start',interpret_on_stop:true});
 assert.equal(calls.listen,1);assert.equal(calls.record,0);assert.equal(calls.open,1);assert.equal(ctx.songExperience.listening_mode,'catalog');
});
test('Repeated Listen reopens the existing session, without a second lookup or recording',async()=>{
 const {ctx,calls}=context();await ctx.executeSongCaptureAction({type:'song_capture_live_start'});await ctx.executeSongCaptureAction({type:'song_capture_live_start'});
 assert.equal(calls.listen,1);assert.equal(calls.record,0);assert.equal(calls.open,2);
});
test('Unsupported device presents an explicit unavailable state, with no Record Clip fallback',async()=>{
 const {ctx,calls}=context(false);await ctx.executeSongCaptureAction({type:'song_capture_live_start'});
 assert.equal(calls.record,0);assert.equal(ctx.songExperience.phase,'unavailable');assert.equal(ctx.songExperience.listening_mode,'catalog');assert.equal(calls.open,1);
});
test('Production stop finishes a lyric selection without stopping a clip',async()=>{
 const {ctx,calls}=context();await ctx.executeSongCaptureAction({type:'song_capture_live_start'});await ctx.executeSongCaptureAction({type:'song_capture_stop'});
 assert.equal(calls.stop,1);assert.equal(calls.record,0);
});
test('Recording cannot start while lyric selection or an analysis request is in progress',async()=>{
 const {ctx,calls}=context();await ctx.executeSongCaptureAction({type:'song_capture_live_start'});await ctx.executeSongCaptureAction({type:'song_capture_start'});assert.equal(calls.record,0);
 ctx.songAnalysisBusy=true;await ctx.executeSongCaptureAction({type:'song_capture_live_start'});assert.equal(calls.listen,1);
});
test('An existing Record Clip is not silently switched into lyric listening',async()=>{
 const {ctx,calls}=context();ctx.songCaptureSkill.status=()=>({active:true});await ctx.executeSongCaptureAction({type:'song_capture_live_start'});assert.equal(calls.listen,0);assert.equal(calls.record,0);
});
test('Canvas uses the native rail, truthful priority status and persistent explicit Stop',()=>{
 const src=read('assets/js/public-agent-canvas.js');for(const marker of ['openLyrics','LOADING SAVED LYRICS','SELECTING SONG SECTION','stop-lyrics','pageView===\'lyrics\''])assert.ok(src.includes(marker),marker);
 assert.ok(src.includes("pageRail.hasAttribute('inert')"));assert.ok(src.includes("button.dataset.orbitAction!=='stop'"));
 assert.ok(src.includes("stopSlot.style.left=listenSlot.style.left"));
});
test('Advisory model instruction and policy distinguish review from recording/paid interpretation',()=>{
 const src=read('api/agent-llm-orchestrator.php'),policy=read('api/agent-runtime.php');
 assert.ok(src.includes('without recording a clip'));assert.ok(src.includes('requires a subsequent explicit review/interpret request'));
 assert.ok(policy.includes("if($route==='song_capture_start'&&sf_agent_explicit_song_capture_route($message,$client)==='song_capture_live_start')"));
});
