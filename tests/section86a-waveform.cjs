const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
let fetches=0,decoded=0,closes=0,draws=[];
class AudioContext {
 async decodeAudioData(raw){
   decoded++;
   assert.equal(raw.byteLength,8);
   return {length:8,numberOfChannels:2,getChannelData:i=>
     Float32Array.from(i?[0,0,.6,0,0,0,.2,0]:[0,.4,0,-.8,.2,0,0,0])};
 }
 async close(){closes++;}
}
const win={devicePixelRatio:1,AudioContext};
const fakeResponse={ok:true,headers:{get:key=>key==='Content-Length'?'8':null},arrayBuffer:async()=>new ArrayBuffer(8)};
const scope={window:win,fetch:async(url,opts)=>{
  assert.equal(url,'../api/media.php?id=TEST');
  assert.equal(opts.credentials,'same-origin');fetches++;
  return fakeResponse;
},console};
vm.runInNewContext(fs.readFileSync('admin/assets/stem-waveform.js','utf8'),scope);
const wave=win.SFStemWaveform;
const fake={getBoundingClientRect:()=>({width:320,height:60}),getContext:()=>({
  setTransform:()=>{},clearRect:()=>{},fillRect:(...args)=>draws.push(args),set fillStyle(v){this.color=v}
})};
(async()=>{
  const first=await wave.load(fake,'../api/media.php?id=TEST',8);
  assert.equal(first.length,320);
  assert.ok(first.some(v=>v>=.4));
  assert.ok(draws.length===320);
  assert.equal(fetches,1);
  await wave.load(fake,'../api/media.php?id=TEST',8);
  assert.equal(fetches,1,'Cached peaks cannot re-fetch audio');
  assert.equal(decoded,1);
  assert.equal(closes,1);
  const sample=wave.peaks({length:4,numberOfChannels:1,
    getChannelData:()=>new Float32Array([0,.1,-.75,0])},32);
  assert.ok(sample.some(v=>v>=.75),'Waveform uses actual PCM amplitude');
  await assert.rejects(()=>wave.load(fake,'private',32*1024*1024+1),/limit/i);
  await assert.rejects(()=>wave.load(fake,'',8),/Assign audio/i);
  const js=fs.readFileSync('admin/assets/stem-studio.js','utf8');
  const index=fs.readFileSync('admin/index.php','utf8');
  assert.ok(js.includes('data-stem-waveform-canvas')&&js.includes('SFStemWaveform.load('));
  assert.ok(index.includes('stem-waveform.js')&&index.indexOf('stem-waveform.js')<index.indexOf('stem-studio.js'));
  console.log('Section 86A decoded waveform: 12+ assertions passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
