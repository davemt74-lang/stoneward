'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const root=path.join(__dirname,'..');
let passed=0;
const check=(label,fn)=>{fn();passed++;process.stdout.write(`91B8A PASS ${label}\n`)};
class FakeRecorder {
  static isTypeSupported(type){return type==='audio/webm;codecs=opus'}
  constructor(stream,options){this.stream=stream;this.mimeType=options?.mimeType||'';this.state='inactive'}
  start(){this.state='recording'}
  stop(){this.state='inactive';queueMicrotask(()=>{
    this.ondataavailable?.({data:new Blob(['fixture audio'],{type:'audio/webm'})});
    this.onstop?.();
  })}
}
const sandbox={window:{MediaRecorder:FakeRecorder},Blob,Date,setTimeout,clearTimeout};
vm.runInNewContext(fs.readFileSync(path.join(root,'assets/js/agent-song-capture-skill.js'),'utf8'),sandbox);
const skill=sandbox.window.StonefellowSongCapture;
const track={id:'track-1',title:'Genius of Unhumankind',release:'Happier Than Hay',audio:'/uploads/track-1.mp3'};
const stopped=[];
const audio={paused:false,src:track.audio,currentSrc:track.audio,currentTime:13.1,
  captureStream:()=>({getAudioTracks:()=>[{kind:'audio'}],getTracks:()=>[{stop:()=>stopped.push(true)}]})};
let changes=0;
const capture=skill.create({audio,getTrack:()=>track,onChange:()=>changes++});
check('Capture is inactive by default',()=>assert.equal(capture.status().active,false));
check('Capture starts only on explicit invocation',()=>{assert.equal(capture.begin().ok,true);assert.equal(capture.status().start_ms,13100)});
check('Double start is rejected',()=>assert.equal(capture.begin().ok,false));
async function main(){
  audio.currentTime=18.9;
  capture.playbackPosition();
  const result=await capture.stop();
  check('Stop returns exact song identity and playback range',()=>{
    assert.equal(result.ok,true);assert.equal(result.capture.track_id,track.id);
    assert.equal(result.capture.start_ms,13100);assert.equal(result.capture.end_ms,18900);
  });
  check('Audio stays in memory and is not uploaded',()=>{
    assert.ok(result.capture.blob instanceof Blob);assert.equal(result.capture.blob.size,13);
    assert.equal(capture.status().active,false);assert.ok(stopped.length);
  });
  check('Capture status exposes only metadata, not the blob',()=>{
    assert.equal(capture.status().last.title,track.title);
    assert.equal(Object.hasOwn(capture.status().last,'blob'),false);
  });
  check('Status updates were notified',()=>assert.ok(changes>=2));
  const repeatedStop=await capture.stop();
  check('No active capture refuses another stop',()=>assert.equal(repeatedStop.ok,false));
  audio.currentTime=30.0;
  const another=capture.begin();assert.equal(another.ok,true);
  capture.discard();
  await Promise.resolve();await Promise.resolve();
  check('Seeking or switching discards unfinished audio',()=>{
    assert.equal(capture.status().active,false);
    assert.equal(capture.getCapture(),null);
  });
  const unsupported=skill.create({audio:{paused:false,src:'/audio.mp3',currentTime:1},getTrack:()=>track});
  check('Unsupported browser clearly reports no capture',()=>assert.equal(unsupported.begin().ok,false));
  check('No song refuses capture',()=>{
    const empty=skill.create({audio,getTrack:()=>null});assert.equal(empty.begin().ok,false);
  });
  const playerSandbox={window:{},Blob};
  vm.runInNewContext(fs.readFileSync(path.join(root,'assets/js/agent-audio-player-skill.js'),'utf8'),playerSandbox);
  const verified=playerSandbox.window.StonefellowAgentAudioSkill.resolvePublished('Listen to this verse',[],{type:'song_capture_start'});
  check('Named-song validator does not misroute lyric capture into track search',()=>assert.equal(verified.action.type,'song_capture_start'));
  const app=fs.readFileSync(path.join(root,'assets/js/app.js'),'utf8');
  const php=fs.readFileSync(path.join(root,'stonefellow-v120.php'),'utf8');
  check('App sends capture-active state through existing agent path',()=>{
    assert.match(app,/song_capture_active:/);assert.match(app,/executeSongCaptureAction\(verified\.action\)/);
  });
  check('Player seek, error, and track change invalidate capture',()=>{
    assert.match(app,/songCaptureSkill\?\.discard\(\)/);assert.match(app,/addEventListener\('seeking'/);
  });
  check('Capture skill is loaded before the main app',()=>{
    assert.ok(php.indexOf('agent-song-capture-skill.js')>0);
    assert.ok(php.indexOf('agent-song-capture-skill.js')<php.indexOf('assets/js/app.js'));
  });
  check('Release headers, service worker and browser agree on current version',()=>{
    const current=fs.readFileSync(path.join(root,'version.php'),'utf8').match(/'stonefellow'\s*=>\s*'([^']+)'/)[1];
    for(const file of ['.htaccess','stonefellow-v120.php','version.php','service-worker.js','admin/index.php','assets/js/app.js']){
      assert.ok(fs.readFileSync(path.join(root,file),'utf8').includes(current),file);
    }
  });
  process.stdout.write(`Section 91B8A: ${passed} JavaScript capture assertions passed\n`);
}
main().catch(e=>{console.error(e);process.exitCode=1});
