'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const window={};
const sandbox={window,console,Float32Array,Float64Array,Math,Number,AbortController};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../admin/assets/stem-intelligence.js'),'utf8'),sandbox);
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../admin/assets/stem-alignment.js'),'utf8'),sandbox);
const a=window.SFStemAlignment;let checks=0;
function ok(pass,label){assert.ok(pass,label);checks++;console.log('PASS '+label)}
function pcm(hz,amp=.3){
  const rate=48000,len=rate*12;
  const left=new Float32Array(len),right=new Float32Array(len);
  for(let i=0;i<len;i++){left[i]=amp*Math.sin(i*2*Math.PI*hz/rate);right[i]=left[i]}
  return {sampleRate:rate,numberOfChannels:2,duration:len/rate,length:len,
    getChannelData(ch){return ch===0?left:right}};
}
const b=pcm(80),track={source_file:'kick.wav',
 clips:[{file:'kick.wav',position:2,length:6,offset:1}]};
ok(a.windowsAt(b,track,1)===null,'No PCM sampled before clip begins on REAPER timeline');
ok(a.windowsAt(b,track,2)===48000,'REAPER clip start correctly maps to source offset');
ok(a.windowsAt(b,track,3)===96000,'Project-time 3s maps to 2s into source audio');
ok(a.windowsAt(b,track,8)===null,'No source PCM fabricated beyond clip end');
const points=a.measureTrack(b,track,[1,2,2.5,3,7.5,8]);
ok(points.length===4,'Only points within the actual linked clip are retained');
ok(points[0].time===2&&points[3].time===7.5,
 'Timed measurements preserve absolute song positions');
const bass=a.measureWindow(b,48000);
ok(bass.bands_dbfs.sub_bass>-25&&bass.bands_dbfs.high<-40,
 'Real 80Hz PCM window measures low band energy without fabricating highs');
ok(bass.rms_dbfs<-5&&bass.rms_dbfs>-30,'Full-window RMS computed from decoded PCM');
const treble=a.measureWindow(pcm(4000),48000);
ok(treble.bands_dbfs.presence>-28&&treble.bands_dbfs.sub_bass<-40,
 'Real 4000Hz audio isolated to presence region');
const silent=a.measureWindow(pcm(1000,0),48000);
ok(silent.rms_dbfs===-120&&Object.values(silent.bands_dbfs).every(x=>x===-120),
 'Silence is never scored as a strong overlap');
const double={...track,clips:[...track.clips,{file:'kick.wav',position:2,length:3,offset:2}]};
ok(a.windowsAt(b,double,3)===null,'Ambiguous overlapping takes skipped, never double-counted');
const overview=a.setting('overview',480,0),focus=a.setting('focus',480,29);
ok(overview.step_seconds>1&&a.grid(0,overview.end_seconds,overview.step_seconds).length<=120,
 'Full eight-minute song uses bounded shared-time scanning grid');
ok(focus.step_seconds===.5&&focus.start_seconds===23&&focus.end_seconds===35,
 'Deep focus analyzes a 12-second passage at subsecond steps');
ok(a.grid(0,1,.5).join(',')==='0,0.5,1','Deterministic grid enables actual simultaneous stem comparisons');
const ui=a.render();
ok(ui.includes('data-l-overview')&&ui.includes('data-l-focus')&&
 ui.includes('data-l-map')&&ui.includes('86L'),'Functional overview, deep focus and timeline visualization controls');
ok(!ui.includes('data-proposal-execute')&&!ui.includes('data-proposal-approve'),
 'Time-aligned viewer cannot execute or approve agent operations');
const studio=fs.readFileSync(path.join(__dirname,'../admin/assets/stem-studio.js'),'utf8');
ok(studio.includes('SFStemAlignment.render(p)')&&studio.includes('SFStemAlignment.bind(root,this)'),
 '86L uses original Stem Studio layout and lifecycle');
console.log('86L browser PCM and timeline: '+checks+' checks green');
