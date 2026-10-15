const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const env={window:{devicePixelRatio:1}};
for(const file of ['admin/assets/stem-timeline.js','admin/assets/stem-fx-engine.js'])
  vm.runInNewContext(fs.readFileSync(file,'utf8'),env);
const T=env.window.SFStemTimeline,F=env.window.SFStemFX;
assert.ok(T&&F);
assert.equal(T.snap(.76,120,true),1);
assert.equal(T.snap(.76,120,false),.76);
assert.equal(T.location(5,10,100).left,5);
assert.equal(T.location(5,10,100).width,10);
const esc=t=>String(t).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const html=T.render({guid:'guid-1',source_file:'Stem.wav',asset_uuid:'ABC',
  clips:[{position:1,length:4,file:'Stem.wav'},{position:6,length:2,file:'<missing>.wav'}]},10,esc);
assert.ok(html.includes('data-stem-clip-index="0"')&&html.includes('data-stem-clip-index="1"'));
assert.ok(html.includes('sf-studio-unlinked'));
assert.ok(html.includes('&lt;missing&gt;.wav')&&!html.includes('<missing>'));
assert.ok(html.includes('sf-studio-trim-handle'));
assert.ok(!html.includes('fake-waveform'));
assert.equal(F.parameters({type:'eq'}).frequency,900);
assert.equal(F.parameters({type:'delay',settings:{feedback:3}}).feedback,.75);
const operations=[];
function param(init=0){return {value:init,setTargetAtTime(v){this.value=v;operations.push(v)}}}
function node(extra={}){return {connect(to){return to},disconnect(){},...extra}}
class MockCtx{
 constructor(){this.sampleRate=100;this.currentTime=1;}
 createGain(){return node({gain:param(1)});}
 createBiquadFilter(){return node({frequency:param(),gain:param(),Q:param()});}
 createDynamicsCompressor(){return node({threshold:param(),ratio:param(),attack:param(),release:param()});}
 createDelay(){return node({delayTime:param()});}
 createConvolver(){return node({buffer:null});}
 createBuffer(ch,len){return {getChannelData:()=>new Float32Array(len)}}
}
const context=new MockCtx();
const all=[{type:'eq',enabled:true,settings:{frequency:1100,gain:-4,q:1}},
  {type:'compressor',enabled:true,settings:{threshold:-28,ratio:4,attack:.03,release:.3}},
  {type:'delay',enabled:true,settings:{time:.4,feedback:.25,wet:.3}},
  {type:'reverb',enabled:false,settings:{wet:.1,seconds:.5}},
  {type:'unsupported',name:'Vendor VST'}];
const chain=F.build(context,node(),all,node());
assert.equal(chain.stages.length,4,'Unsupported native VST excluded from processing');
assert.equal(chain.stages[0].processor.frequency.value,1100);
assert.equal(chain.stages[0].processor.gain.value,-4);
assert.equal(chain.stages[1].processor.ratio.value,4);
assert.equal(chain.stages[2].processor.delayTime.value,.4);
assert.equal(chain.stages[2].feedback.gain.value,.25);
assert.equal(chain.stages[3].wet.gain.value,0,'Disabled reverb bypassed');
chain.update(all.map((v,i)=>i===0?{...v,enabled:false}:v));
assert.equal(chain.stages[0].dry.gain.value,1,'Bypass restores dry audio');
assert.equal(chain.stages[0].wet.gain.value,0,'Bypass silences wet processing');
const editor=fs.readFileSync('admin/assets/stem-studio.js','utf8');
assert.ok(editor.includes('SFStemTimeline.bind('));
assert.ok(editor.includes('this.commitEdit({action:'));
assert.ok(editor.includes('offset+elapsed'),'Media seeks honor source offset');
assert.ok(editor.includes('String(c.file||\'\').toLowerCase()===String(t.source_file||\'\').toLowerCase()'),'Only matching uploaded takes are played');
assert.ok(editor.includes('for(const {media,clip} of nodes.clips'),'One media element per timeline clip');
const admin=fs.readFileSync('admin/index.php','utf8');
assert.ok(admin.indexOf('stem-fx-engine.js')<admin.indexOf('stem-studio.js'));
assert.ok(admin.indexOf('stem-timeline.js')<admin.indexOf('stem-studio.js'));
console.log('86B timeline snapping, real clip sync, Web Audio FX, bypass and Admin integration: 25+ assertions passed');
