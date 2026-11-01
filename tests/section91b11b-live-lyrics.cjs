'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
let total=0;
const check=(name,fn)=>{fn();total++;console.log('91B11B PASS '+name);};
const track={id:'published-1',title:'Earth Girl',release:'Space Reggae',audio:'/uploads/song.mp3'};
let audio={paused:false,src:track.audio,currentSrc:track.audio,currentTime:7};
let instances=[],timers=new Map(),timerId=0,segments=[],networkCalls=0,tracksStopped=0;
const fakeTimer=fn=>{const id=++timerId;timers.set(id,fn);return id;};
const clearTimer=id=>timers.delete(id);
class Recorder{
  static isTypeSupported(mime){return mime==='audio/webm;codecs=opus';}
  constructor(stream,options){this.stream=stream;this.mimeType=options?.mimeType||'audio/webm';this.state='inactive';this.id=instances.length;instances.push(this);}
  start(){this.state='recording';}
  stop(){
    this.state='inactive';
    queueMicrotask(()=>{
      this.ondataavailable?.({data:new Blob(['self-contained segment '+this.id],{type:'audio/webm'})});
      this.onstop?.();
    });
  }
}
audio.captureStream=()=>({getAudioTracks:()=>[{kind:'audio'}],getTracks:()=>[{stop:()=>tracksStopped++}]});
const scope={window:{MediaRecorder:Recorder},Blob,Date,Promise,setTimeout:fakeTimer,clearTimeout:clearTimer};
vm.runInNewContext(read('assets/js/agent-song-capture-skill.js'),scope);
const capture=scope.window.StonefellowSongCapture.create({audio,getTrack:()=>track,
  onSegment:value=>{segments.push(value);networkCalls++;},segmentSeconds:8});
check('Ordinary listening has no live recording or upload',()=>{
  assert.equal(capture.begin().ok,true);
  assert.equal(instances.length,1);assert.equal(networkCalls,0);
  assert.equal(capture.status().live_enabled,false);
});
check('Only the separate live opt-in starts short standalone containers',()=>{
  assert.equal(capture.enableLive().ok,true);
  assert.equal(capture.status().live_enabled,true);
  assert.equal(instances.length,2);
  assert.equal(capture.enableLive().ok,true);
  assert.equal(instances.length,2);
});
async function main(){
  audio.currentTime=15;
  // End only the 8-second preview timer, not the 90-second full-capture timer.
  const segmentTimer=[...timers.values()].at(-1);segmentTimer();
  await Promise.resolve();await Promise.resolve();
  check('Live preview includes track identity and accurate section range',()=>{
    assert.equal(segments.length,1);
    assert.equal(segments[0].track_id,track.id);
    assert.equal(segments[0].start_ms,7000);
    assert.equal(segments[0].end_ms,15000);
    assert.ok(segments[0].blob instanceof Blob);
    assert.equal(instances.length,3);
  });
  audio.currentTime=20;
  capture.playbackPosition();
  const stopped=await capture.stop();
  await Promise.resolve();await Promise.resolve();
  check('Stop retains the original full recording as the canonical clip',()=>{
    assert.equal(stopped.ok,true);assert.equal(stopped.capture.start_ms,7000);
    assert.equal(stopped.capture.end_ms,20000);
    assert.equal(capture.status().live_enabled,false);
    assert.equal(segments.length,1);
    assert.ok(tracksStopped>0);
  });
  check('Discard prevents stale media from re-entering the live queue',()=>{
    audio.currentTime=30;capture.begin();capture.enableLive();
    capture.discard();
  });
  await Promise.resolve();await Promise.resolve();
  check('Discard invalidates the original full recording',()=>{
    assert.equal(capture.status().active,false);
    assert.equal(capture.getCapture(),null);
    assert.equal(networkCalls,1);
  });
  class NoSecondRecorder extends Recorder{
    constructor(...args){if(instances.length>=1)throw Error('second recorder unsupported');super(...args);}
  }
  instances=[];audio.currentTime=40;
  const fallback=scope.window.StonefellowSongCapture.create({audio,getTrack:()=>track,recorderFactory:NoSecondRecorder});
  fallback.begin();
  const failed=fallback.enableLive();
  check('Unsupported dual recording fails live only, preserving local capture',()=>{
    assert.equal(failed.ok,false);assert.equal(fallback.status().active,true);
    assert.equal(fallback.status().live_enabled,false);
  });
  audio.currentTime=44;
  await fallback.stop();
  await exerciseAppQueue();
  check('Live route is preserved by player command validation',()=>{
    const ctx={window:{}};vm.runInNewContext(read('assets/js/agent-audio-player-skill.js'),ctx);
    const result=ctx.window.StonefellowAgentAudioSkill.resolvePublished('Transcribe live',[],{type:'song_capture_live_start'});
    assert.equal(result.action.type,'song_capture_live_start');
  });
  check('The original full-clip transcription and follow-up analysis remain in the player',()=>{
    const app=read('assets/js/app.js');
    assert.match(app,/void transcribeLastCapturedSong\(false,songInterpretAfterTranscribe\)/);
    assert.match(app,/if\(songLiveOptedIn&&!songLiveFinalizing\)/);
    assert.match(app,/song_capture_live_start/);
    assert.match(app,/song_lyric_draft_available:songLyricDraft\?1:0/);
  });
  console.log(`Section 91B11B: ${total} live transcription assertions passed`);
}
async function exerciseAppQueue(){
  const app=read('assets/js/app.js');
  const start=app.indexOf('  let songLiveGeneration=0;');
  const end=app.indexOf('  const songCaptureSkill=',start);
  assert.ok(start>0&&end>start);
  const calls=[],updates=[];
  let firstResolve,staleResolve,holdStale=false;
  const context={Blob,FormData,Promise,st:{auth:{csrf:'token'}},cfg:{apiBase:'api'},
    songExperience:{track_id:track.id},
    songCaptureSkill:{status:()=>({active:true})},
    loadAuth:async()=>{},
    updateSongExperience:changes=>updates.push(changes),
    fetch:(url,opts)=>{
      calls.push({url,opts});
      const respond=()=>({status:200,ok:true,json:async()=>({ok:true,transcript:{
        text:'caption '+opts.body.get('start_ms'),track_id:track.id,review_status:'unverified'
      }})});
      if(calls.length===1)return new Promise(resolve=>{firstResolve=()=>resolve(respond());});
      if(holdStale)return new Promise(resolve=>{staleResolve=()=>resolve(respond());});
      return Promise.resolve(respond());
    }
  };
  vm.createContext(context);
  vm.runInContext(app.slice(start,end)+`\nthis.optIn=()=>{songLiveOptedIn=true};\n`+
    `this.enqueue=enqueueSongLiveSegment;this.finalize=()=>{songLiveFinalizing=true;songLiveGeneration++;songLiveQueue=[]};`+
    `this.pending=()=>songLiveQueue.length;this.skipped=()=>songLiveSkipped;this.captions=()=>songLiveCaptions.length;`,context);
  const segment=i=>({track_id:track.id,start_ms:i*8000,end_ms:i*8000+8000,
    captured_at:'2026-10-31T12:00:00Z',mime_type:'audio/webm',blob:new Blob(['audio '+i])});
  context.enqueue(segment(1));
  check('No segment network request before explicit live consent',()=>assert.equal(calls.length,0));
  context.optIn();
  context.enqueue(segment(1));
  await Promise.resolve();await Promise.resolve();
  check('Live request uses the existing authenticated bounded upload route',()=>{
    assert.equal(calls.length,1);
    assert.equal(calls[0].url,'api/agent-song-transcribe.php');
    assert.equal(calls[0].opts.headers['X-CSRF-Token'],'token');
    assert.equal(calls[0].opts.credentials,'same-origin');
    assert.equal(calls[0].opts.body.get('start_ms'),'8000');
  });
  context.enqueue(segment(2));context.enqueue(segment(3));context.enqueue(segment(4));
  check('The live upload queue is bounded to two pending clips',()=>{
    assert.equal(context.pending(),2);assert.equal(context.skipped(),1);
  });
  firstResolve();
  for(let i=0;i<30;i++)await Promise.resolve();
  check('Provisional text is displayed in the shared chat card, in segment order',()=>{
    assert.equal(calls.length,3);
    assert.equal(context.captions(),3);
    assert.ok(updates.at(-1).transcript.includes('caption 32000'));
    assert.ok(updates.at(-1).transcript.includes('caption 8000'));
  });
  holdStale=true;
  context.enqueue(segment(5));
  await Promise.resolve();await Promise.resolve();
  assert.equal(typeof staleResolve,'function');
  context.finalize();
  staleResolve();
  for(let i=0;i<12;i++)await Promise.resolve();
  check('Final pass invalidates queued and already-uploaded stale caption responses',()=>{
    assert.equal(context.pending(),0);
    assert.equal(context.captions(),3);
  });
}
function exerciseLiveCard(){
  class Node{
    constructor(tag){this.tag=tag;this.children=[];this.listeners={};this.content='';this.hidden=false;this.disabled=false;}
    set textContent(value){this.content=String(value);this.children=[];}
    get textContent(){return this.content+this.children.map(c=>c.textContent).join('');}
    append(...items){this.children.push(...items);}
    replaceChildren(...items){this.children=items;this.content='';}
    setAttribute(){}
    addEventListener(name,cb){this.listeners[name]=cb;}
    all(tag){return [...(this.tag===tag?[this]:[]),...this.children.flatMap(n=>n.all(tag))];}
  }
  const ctx={window:{},document:{createElement:tag=>new Node(tag)}};
  vm.runInNewContext(read('assets/js/agent-song-experience-ui.js'),ctx);
  const host=new Node('div'),actions=[];
  const base={phase:'capturing',title:'Earth Girl',start_ms:6000,end_ms:null,transcript:'',reports:[],canAnalyze:false};
  ctx.window.SFSongExperienceUI.mount(host,base,action=>actions.push(action));
  check('Card exposes explicit live consent and keeps local listening as default',()=>{
    assert.match(host.textContent,/Start live transcription/);
    assert.match(host.textContent,/active tokens/);
    assert.match(host.textContent,/Ordinary listening stays local/);
    assert.equal(actions.length,0);
  });
  host.all('button').find(b=>b.textContent==='Start live transcription').listeners.click();
  check('Live upload action is initiated by the visible user button only',()=>{
    assert.deepEqual(actions,['live']);
  });
  ctx.window.SFSongExperienceUI.mount(host,{...base,live_enabled:true,
    transcript:'estimated <script>fake</script>',segments_completed:2},()=>{});
  check('Provisional lyrics are visible and cannot be analyzed or saved yet',()=>{
    assert.match(host.textContent,/Listening · provisional live lyrics/);
    assert.match(host.textContent,/2 section\(s\) transcribed/);
    assert.match(host.textContent,/<script>fake<\/script>/);
    assert.equal(host.all('script').length,0);
    assert.equal(host.all('button').find(b=>b.textContent==='Interpret lyrics').disabled,true);
    assert.equal(host.all('form').length,0);
    assert.equal(host.all('button').some(b=>b.textContent==='Start live transcription'),false);
  });
}
exerciseLiveCard();
main().catch(error=>{console.error(error);process.exitCode=1});
