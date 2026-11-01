'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
let checks=0;
const check=(name,fn)=>{fn();checks++;console.log('91B11B PASS '+name);};
class Recorder {
  static isTypeSupported(type){return type==='audio/webm;codecs=opus';}
  constructor(stream,options){this.mimeType=options?.mimeType||'audio/webm';this.state='inactive';Recorder.count++;}
  start(){this.state='recording';}
  stop(){this.state='inactive';queueMicrotask(()=>{
    this.ondataavailable?.({data:new Blob(['audio'],{type:'audio/webm'})});this.onstop?.();
  });}
}
Recorder.count=0;
const timers=[];
const audio={paused:false,currentTime:10,src:'/published.mp3',currentSrc:'/published.mp3',
  captureStream:()=>({getAudioTracks:()=>[{kind:'audio'}],getTracks:()=>[{stop(){}}]})};
const scope={window:{MediaRecorder:Recorder},Blob,Date,Promise,
  setTimeout:fn=>(timers.push(fn),timers.length),clearTimeout:()=>{}};
vm.runInNewContext(read('assets/js/agent-song-capture-skill.js'),scope);
const capture=scope.window.StonefellowSongCapture.create({
  audio,getTrack:()=>({id:'published-1',title:'Earth Girl',audio:'/published.mp3'})});
check('Record Clip remains explicitly started and browser-local',()=>{
  assert.equal(Recorder.count,0);assert.equal(capture.begin().ok,true);
  assert.equal(Recorder.count,1);assert.equal(capture.status().live_enabled,false);
});
async function main(){
  audio.currentTime=20;
  const completed=await capture.stop();
  check('Record Clip retains its independent optional transcript source',()=>{
    assert.equal(completed.ok,true);assert.ok(completed.capture.blob instanceof Blob);
    assert.equal(completed.capture.start_ms,10000);assert.equal(completed.capture.end_ms,20000);
  });
  check('Listen & Interpret does not call the recorder upload pipeline',()=>{
    const app=read('assets/js/app.js');
    assert.match(app,/songListeningSkill\.begin\(\)/);
    assert.match(app,/songListeningSkill\.stop\(\)/);
    assert.match(app,/currentSongSource\(\)/);
    assert.doesNotMatch(app,/uploadSongLiveSegment|enqueueSongLiveSegment|songLiveOptedIn/);
    assert.match(app,/if\(action\.type==='song_capture_start'\)/);
    assert.match(app,/songCaptureSkill\.begin\(\)\.text/);
    assert.match(app,/transcribeLastCapturedSong\(/);
    assert.match(app,/await analyzeLastSongDraft/);
  });
  check('Player command validation still distinguishes the two operations',()=>{
    const ctx={window:{}};
    vm.runInNewContext(read('assets/js/agent-audio-player-skill.js'),ctx);
    const resolve=ctx.window.StonefellowAgentAudioSkill.resolvePublished;
    assert.equal(resolve('Listen and interpret',[],{type:'song_capture_live_start'}).action.type,'song_capture_live_start');
    assert.equal(resolve('Record clip',[],{type:'song_capture_start'}).action.type,'song_capture_start');
  });
  class Element{
    constructor(tag){this.tag=tag;this.children=[];this.listeners={};this.content='';this.hidden=false;this.disabled=false;}
    set textContent(value){this.content=String(value);this.children=[];}
    get textContent(){return this.content+this.children.map(c=>c.textContent).join('');}
    append(...items){this.children.push(...items);}
    replaceChildren(...items){this.children=items;this.content='';}
    setAttribute(){}
    addEventListener(name,fn){this.listeners[name]=fn;}
    all(tag){return [...(this.tag===tag?[this]:[]),...this.children.flatMap(c=>c.all(tag))];}
  }
  const ctx={window:{},document:{createElement:tag=>new Element(tag)}};
  vm.runInNewContext(read('assets/js/agent-song-experience-ui.js'),ctx);
  const host=new Element('div');
  const base={phase:'capturing',title:'Earth Girl',start_ms:6000,end_ms:null,
    transcript:'',reports:[],canAnalyze:false};
  check('Record Clip mode does not automatically transcribe or interpret',()=>{
    ctx.window.SFSongExperienceUI.mount(host,{...base,listening_mode:'record_clip'},()=>{});
    assert.match(host.textContent,/Record Clip keeps a browser-local audio excerpt/);
    assert.doesNotMatch(host.textContent,/Start live transcription/);
    assert.match(host.textContent,/Stop listening/);
  });
  check('Live listening mode describes no retained song clip',()=>{
    ctx.window.SFSongExperienceUI.mount(host,{...base,listening_mode:'recognition',
      live_enabled:true,auto_interpret:true,transcript:'Estimated lyrics'},()=>{});
    assert.match(host.textContent,/live lyrics → interpretation on Stop/);
    assert.match(host.textContent,/No song clip is being recorded/);
    assert.doesNotMatch(host.textContent,/Transcribe excerpt/);
    assert.doesNotMatch(host.textContent,/Start live transcription/);
  });
  console.log('Section 91B11B: '+checks+' separate recording and listening assertions passed');
}
main().catch(error=>{console.error(error);process.exitCode=1});
