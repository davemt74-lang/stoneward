const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const ctx={window:{}};
for(const p of ['admin/assets/stem-fx-engine.js','admin/assets/stem-plugin-rack.js'])
  vm.runInNewContext(fs.readFileSync(p,'utf8'),ctx);
const F=ctx.window.SFStemFX,U=ctx.window.SFStemPluginRack;
assert.ok(F&&U);
assert.ok(F.specs.limiter&&F.specs.eq5);
assert.equal(Object.keys(F.specs.eq5).length,15);
assert.equal(F.parameters({type:'limiter'}).ceiling,-.1);
assert.equal(F.parameters({type:'limiter',settings:{ceiling:100}}).ceiling,0);
const esc=text=>String(text).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const imported=[
  {name:'loser/masterLimiter',type:'limiter',format:'JS',
   preset_name:'',fx_guid:'{MASTER-01}',module:'loser/masterLimiter',
   slot:0,enabled:true,source_enabled:true,
   parameter_provenance:'exact-js-slider-tokens',native_state_sha256:'sha256-test',
   js_slider_tokens:['-8.3','200','100','0','250','-0.1','0']},
  {name:'VST: ReaComp (Cockos)',type:'compressor',format:'VST',
   preset_name:'stock - Master Bus Glue',fx_guid:'{MASTER-02}',
   module:'reacomp.dll',slot:1,enabled:true,source_enabled:true,
   parameter_provenance:'native-opaque-state-preserved',
   native_state_sha256:'acbd',settings:{threshold:-18}},
  {name:'Unsafe <script>',type:'unsupported',format:'VST',enabled:false,
   fx_guid:'{3}',parameter_provenance:'native-opaque-state-preserved'}
];
const html=U.render('master','__master__',imported,esc);
assert.ok(html.includes('Master Bus Plugin Rack'));
assert.ok(html.includes('stock - Master Bus Glue'));
assert.ok(html.includes('loser/masterLimiter'));
assert.ok(html.includes('sha256-test'));
assert.ok(html.includes('data-plugin-param="ceiling"'));
assert.ok(html.includes('data-plugin-action="plugin_add"'));
assert.ok(html.includes('data-plugin-action="plugin_move"'));
assert.ok(html.includes('5-band EQ'));
assert.ok(html.includes('Imported')===false||html.includes('REAPER'));
assert.ok(!html.includes('<script>')&&html.includes('&lt;script&gt;'));
assert.ok(!html.includes('data-plugin-action="plugin_remove"'));
const operations=[];
function param(init=0){return {value:init,setTargetAtTime(value){this.value=value;operations.push(value)}}}
function node(fields={}){return {connect(target){return target},disconnect(){},...fields}}
class MockAudioContext{
 constructor(){this.sampleRate=100;this.currentTime=4;}
 createGain(){return node({gain:param(1)});}
 createBiquadFilter(){return node({frequency:param(),gain:param(),Q:param()});}
 createDynamicsCompressor(){return node({threshold:param(),ratio:param(),attack:param(),release:param()});}
 createDelay(){return node({delayTime:param()});}
 createConvolver(){return node({buffer:null});}
 createBuffer(channels,length){return {getChannelData:()=>new Float32Array(length)}}
}
const audio=new MockAudioContext();
const arr=[{type:'limiter',enabled:true,settings:{threshold:-8.3,ceiling:-.1,attack:.01,release:.2}},
  {type:'eq5',enabled:true,settings:{f1:100,g1:2,q1:.9,f2:400,g2:-3,q2:.8,
  f3:1200,g3:1,q3:1,f4:4500,g4:0,q4:1,f5:10000,g5:-2,q5:.7}}];
const chain=F.build(audio,node(),arr,node());
assert.equal(chain.stages.length,2);
assert.equal(chain.stages[0].processor.threshold.value,-8.3);
assert.equal(chain.stages[0].processor.ratio.value,20);
assert.ok(chain.stages[0].ceilingGain.gain.value<1,'Limiter ceiling adds output trim');
assert.equal(chain.stages[1].bands.length,5);
assert.equal(chain.stages[1].bands[3].frequency.value,4500);
assert.equal(chain.stages[1].bands[1].gain.value,-3);
chain.update([{...arr[0],enabled:false},arr[1]]);
assert.equal(chain.stages[0].wet.gain.value,0,'Master limiter bypass');
assert.equal(chain.stages[0].dry.gain.value,1,'Master limiter dry path');
const editor=fs.readFileSync('admin/assets/stem-studio.js','utf8');
const page=fs.readFileSync('admin/index.php','utf8');
assert.ok(editor.includes("SFStemPluginRack.render('master'"));
assert.ok(editor.includes("SFStemPluginRack.render('track'"));
assert.ok(editor.includes('this.masterFX=window.SFStemFX.build'));
assert.ok(editor.includes('SFStemPluginRack.bind(root,payload=>this.commitEdit(payload))'));
assert.ok(page.includes('stem-plugin-rack.js'));
assert.ok(page.indexOf('stem-plugin-rack.js')<page.indexOf('stem-studio.js'));
console.log('86C plugin registry, master limiter, 5-band EQ, exact metadata UI: 30+ assertions passed');
