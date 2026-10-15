'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../admin/assets/stem-intelligence.js'),'utf8');
const window={};vm.runInNewContext(source,{window,console,Float64Array,Float32Array,AbortController,Math,Number});
const api=window.SFStemIntelligence;
let checks=0;
function ok(value,label){assert.ok(value,label);checks++;console.log('PASS '+label);}
function fixture(freq,amp=.35,channels=2,rate=48000){
  const len=rate*2;
  const a=new Float32Array(len),b=new Float32Array(len);
  for(let i=0;i<len;i++){
    a[i]=amp*Math.sin(2*Math.PI*freq*i/rate);
    b[i]=channels===2?a[i]:0;
  }
  return {sampleRate:rate,duration:len/rate,length:len,numberOfChannels:channels,
    getChannelData:(n)=>n===0?a:b};
}
const track={guid:'{VOCALS}',source_file:'vocal.wav',
  clips:[{file:'vocal.wav',position:2,offset:0,length:1.5}]};
const low=api.analyzeBuffer(fixture(80),track);
ok(low.bands_pct.sub_bass>50,'Real 80 Hz input produces measured sub-bass FFT energy');
ok(low.windows===8&&low.sample_rate===48000&&low.observed_seconds>.3,
  'Analyzer samples bounded 2048-frame windows rather than inventing whole-track metrics');
ok(Math.abs(low.sample_peak_dbfs-20*Math.log10(.35))<.1,'Decoded PCM peak is measured numerically');
ok(low.active_window_ratio===1,'Sustained source reports active sampled windows');
ok(low.stereo_imbalance_db<.05,'Identical stereo channels are not falsely imbalanced');
const high=api.analyzeBuffer(fixture(4000),track);
ok(high.bands_pct.presence>50&&high.bands_pct.sub_bass<1,
  'Real 4 kHz input occupies high-mid band rather than low end');
const silent=api.analyzeBuffer(fixture(80,0),track);
ok(silent.rms_dbfs===-120&&silent.active_window_ratio===0,
  'Silent PCM source produces measured silence, not invented sound');
ok(Object.values(silent.bands_pct).every(v=>v===0),
  'Silent source carries no fake spectral energy');
const mono=api.analyzeBuffer(fixture(80,.2,1),track);
ok(mono.stereo_imbalance_db===0&&mono.rms_dbfs>-30,
  'Mono reference cannot be misclassified as extreme stereo imbalance');
assert.throws(()=>api.analyzeBuffer(fixture(100),{...track,clips:[]}),/No valid linked/);
ok(true,'Track with no real matching clip is not measured');
const tiny={...fixture(80),duration:20*60};
assert.throws(()=>api.analyzeBuffer(tiny,track),/oversized/);
ok(true,'Oversized decoded audio rejected');
const ui=api.render({track_id:'song-1'});
ok(ui.includes('data-k-scan')&&ui.includes('data-k-save')&&
  ui.includes('Per-Stem Audio Intelligence'),'Actual same-canvas analysis and save controls exist');
ok(!ui.includes('data-proposal-approve')&&!ui.includes('data-proposal-execute'),
  'Stem spectrum panel never grants autonomous actions');
const studio=fs.readFileSync(path.join(__dirname,'../admin/assets/stem-studio.js'),'utf8');
const admin=fs.readFileSync(path.join(__dirname,'../admin/index.php'),'utf8');
ok(studio.includes('SFStemIntelligence.render(p)')&&
  studio.includes('SFStemIntelligence.bind(root,this)')&&
  admin.includes('assets/stem-intelligence.js'),'Engine loads with existing Stem Studio and Admin canvas');
ok(source.includes('credentials:')&&source.includes("cache:'no-store'")&&
  source.includes('signal:controller.signal'),'Private audio is fetched with credentials and cancellable requests');
ok(source.includes('MAX_TRACKS=16')&&source.includes('MAX_FILE_BYTES=31457280')&&
  source.includes('controller.abort()'),'Browser CPU/file limits and lifecycle cleanup in place');
ok(!source.includes("action:'execute'")&&!source.includes("action:'approve'"),
  'Browser cannot approve or execute an Agent proposal');
console.log('86K JS: '+checks+' source-audio/UX checks green');
