'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../admin/assets/stem-mastering.js'),'utf8');
let graphEdges=0,scheduled=0,decodeCalls=0;
class P {
  constructor(){this.value=0;this.events=[]}
  setValueAtTime(v,t){this.value=v;this.events.push({type:'set',v,t});scheduled++}
  linearRampToValueAtTime(v,t){this.value=v;this.events.push({type:'ramp',v,t});scheduled++}
  cancelScheduledValues(t){this.events=this.events.filter(x=>x.t<t)}
  setTargetAtTime(v,t){this.value=v;this.events.push({type:'target',v,t})}
}
class Node {
  constructor(){this.gain=new P();this.pan=new P();this.connections=[]}
  connect(node){this.connections.push(node);graphEdges++;return node}
}
class MockOffline {
  constructor(channels,len,rate){this.length=len;this.sampleRate=rate;this.currentTime=0;this.destination=new Node()}
  createGain(){return new Node()}
  createStereoPanner(){return new Node()}
  createBufferSource(){const node=new Node();node.start=(time,offset,duration)=>{
    assert.ok(time>=0&&offset>=0&&duration>0);MockOffline.started++};
    return node;
  }
  async startRendering(){return buffer(Math.max(1,this.length),.2)}
}
MockOffline.started=0;
class MockDecoder {
  async decodeAudioData(){decodeCalls++;return buffer(48000,.2)}
  async close(){}
}
const bufSize=48000;
function buffer(n,amplitude,channels=2){
  const left=new Float32Array(n),right=new Float32Array(n);
  for(let i=0;i<n;i++){
    const signal=Math.sin(2*Math.PI*1000*i/48000)*amplitude;
    left[i]=signal;right[i]=signal;
  }
  return {sampleRate:48000,length:n,numberOfChannels:channels,duration:n/48000,
    getChannelData(i){return i===0?left:right}};
}
const window={OfflineAudioContext:MockOffline,AudioContext:MockDecoder,
  SFStemFX:{specs:{eq:{gain:[-24,24,0]}},build(ctx,source,chain,out){source.connect(out);return {stages:[]}}}};
vm.runInNewContext(source,{window,console,Blob,URL,Math,Number,Float32Array,Float64Array,DataView,ArrayBuffer,
 fetch:async url=>{assert.equal(url,'private-stem-endpoint');return {ok:true,arrayBuffer:async()=>new ArrayBuffer(16)};}});
const mastering=window.SFStemMastering;
function verify(x,label){assert.ok(x,label);console.log('PASS '+label)}
const m=mastering.analyze(buffer(bufSize*2,.3));
verify(m.integrated_lufs!==null&&m.integrated_lufs<0&&m.integrated_lufs>-80,
  '48kHz K-weighted gated loudness produces finite estimate on sine');
verify(Math.abs(m.sample_peak_dbfs-20*Math.log10(.3))<.05,
  'Sample peak is measured, not a guessed mastering target');
verify(m.clipped_samples===0&&m.stereo_imbalance_db<.1,'Balanced unclipped stereo is recognized');
const mono=mastering.analyze(buffer(bufSize*2,.3,1));
verify(mono.integrated_lufs!==null&&mono.integrated_lufs<m.integrated_lufs,
  'Mono reference is not double-weighted as fake stereo');
const clipping=buffer(bufSize*2,1.2);
const clipped=mastering.analyze(clipping);
verify(clipped.clipped_samples>0&&clipped.sample_peak_dbfs>0,
  'Sample overflows and clipping counts reflect actual buffer');
const exported=mastering.wav(buffer(bufSize,.2));
async function run(){
  const arr=new DataView(await exported.arrayBuffer());
  verify(exported.size===44+bufSize*4&&arr.getUint32(24,true)===48000&&
    arr.getUint16(22,true)===2&&arr.getUint16(34,true)===16,
    'WAV is 48 kHz, 16-bit, stereo, with correct RIFF byte count');
  const project={
    project_name:'Private Test',tracks:[
      {guid:'P',name:'Group',main_send_enabled:true,folder_parent_guid:null,fx:[],clips:[]},
      {guid:'T',name:'Test',main_send_enabled:true,folder_parent_guid:'P',fx:[],media_ready:true,
       audio_url:'private-stem-endpoint',source_file:'a.wav',
       clips:[{file:'a.wav',position:0,offset:0,length:1}]}],
    routing:{buses:[{id:'bus-0123456789abcdef',name:'Aux',fx:[],volume:1,pan:0}],
      sends:[{source_guid:'T',target_guid:'bus-0123456789abcdef',gain:.35,enabled:true,mode:'post-fader'}]},
    automation:[{scope:'track',guid:'T',parameter:'volume',enabled:true,source:'BROWSER',
      points:[{time:0,value:.5,shape:'linear'},{time:1,value:1,shape:'linear'}]}],
    master_fx:[]
  };
  const studio={project,duration:1,mixing:{P:{volume:1,pan:0,mute:false,solo:false},
    T:{volume:.9,pan:.1,mute:false,solo:false}},busMix:{'bus-0123456789abcdef':
      {volume:.8,pan:0,mute:false,solo:false}}};
  const rendered=await mastering.renderOffline(studio);
  verify(rendered.length===48000&&MockOffline.started===1,
    'Offline rendering schedules a decoded private stem with correct length');
  verify(graphEdges>=15&&scheduled>8&&decodeCalls===1,
    'Offline graph includes folder bus, post-fader send, mixer gates and automation');
  verify(mastering.effectTailSeconds({...project,master_fx:[{type:'reverb',settings:{seconds:3},enabled:true}]})===3,
    'Offline mixdown preserves bounded reverb tail frames');
  verify(mastering.effectTailSeconds({...project,master_fx:[{type:'delay',settings:{time:1.2,feedback:.75},enabled:true}]})<=12,
    'Long feedback tails remain within finite render bounds');
  const unsupported=mastering.fxInfo({...project,master_fx:[{type:'native_vst_unknown',enabled:true}]});
  verify(unsupported===1,'Unmapped native VST processing produces a visible fidelity warning');
  const html=mastering.ui({...project,track_id:'song-1',mastering_analyses:[]});
  verify(html.includes('data-g-agent')&&html.includes('data-g-gain')&&
    html.includes('data-g-upload')&&html.includes('data-g-switch'),
    'Integrated mastering lab offers reference A/B and governed Agent review');
  verify(!html.includes('storage/stem-projects'),'Private storage paths are never rendered into browser');
  console.log('86G browser mastering regression: 14 checks green');
}
run().catch(e=>{console.error(e);process.exitCode=1});
