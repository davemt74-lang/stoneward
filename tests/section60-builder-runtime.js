'use strict';
// Section 60: execute the real renderer with deterministic DOM stubs, then invoke real handlers.
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const src=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/app.js'),'utf8');
const start=src.indexOf('  function renderBuilder(){');
const end=src.indexOf('\n  async function quoteCart()',start);
assert.ok(start>0&&end>start,'actual renderBuilder function must exist');
const code="let builderTab='songs';let builderNotice={text:'',kind:'info'};\n"+src.slice(start,end)+"\nthis.renderBuilder=renderBuilder;";
const names=['songs','song-lists','album-artwork','personal-notes','saved-drafts','submit'];
function el(){return{hidden:false,innerHTML:'',onclick:null,onkeydown:null,dataset:{},classList:{toggle(){}},setAttribute(name,value){this[name]=value},focus(){this.focused=true},appendChild(){}}}
const tabButtons=names.map(name=>Object.assign(el(),{dataset:{builderTab:name}}));
const panels=Object.fromEntries(names.map(n=>[n,el()]));
const sideNodes={A:el(),B:el()},items={};
const $=(q,context)=>{
  const side=/^\[data-side="([AB])"\]$/.exec(q);if(side)return sideNodes[side[1]];
  const id=/^#builder-panel-(.*)$/.exec(q);if(id)return panels[id[1]]||null;
  if(q==='.side-tracks')return el();
  if(!items[q])items[q]=el();return items[q];
};
const $$=(q)=>q==='[data-builder-tab]'?tabButtons:[];
const build={version:4,format:'cassette',title:'My Stonefellow Record',theme:'desert',A:[],B:[],customNote:'',coverFront:'stonefellow',coverBack:'stonefellow'};
const state={builder:build,auth:{authenticated:false},builderDrafts:{items:[],activeId:0,loaded:true},cart:[]};
const canvas=el();
const sandbox={canvas,st:state,cfg:{formats:{vinyl:{label:'12-inch vinyl'},cassette:{label:'Cassette'}},artworkThemes:[]},
  catalog:[],releases:[],window:{StonefellowTurntable:{mount(){}}},document:{createElement(){return el()}},
  $,$$,esc:x=>String(x??''),time:n=>String(n),sideTime:side=>state.builder[side].length*200,limit:()=>1200,
  builderArtworkTiles:()=>'',duplicateBuildIds:()=>[],trackById:()=>null,trackArtwork:()=>'',navigate(){},money:()=>'$0.00',has:()=>false,
  fillBuild(){},move(){},transfer(){},remove(){},relocateTrack(){},add(){},play(){},
  persist(){},renderCart(){},saveBuilderDraft(){},addBuildToCart(){},download(){},persistCart(){},say(){},
  norm:x=>x,defaultBuild:()=>build,loadBuilderDraft(){},deleteBuilderDraft(){},clearBuild(){},dedupeBuild(){},
  $, $$,URL,location:{href:'https://example.org/?view=builder'},localStorage:{removeItem(){}},console};
vm.runInNewContext(code,sandbox,{filename:'real-builder.js',timeout:2500});
sandbox.renderBuilder();
assert.equal(state.builder.format,'vinyl','legacy format should display vinyl editor');
assert.equal((canvas.innerHTML.match(/role="tab"/g)||[]).length,6);
assert.equal((canvas.innerHTML.match(/role="tabpanel"/g)||[]).length,6);
assert.ok(!canvas.innerHTML.includes('data-format="cassette"'));
assert.ok(!canvas.innerHTML.includes('format-switch'));
assert.match(canvas.innerHTML,/<button[^>]*id="builder-tab-songs"[^>]*aria-selected="true"/,'initial selected state is encoded in rendered HTML');
console.log('PASS real builder renders six accessible tabs and vinyl-only sequence');
tabButtons[3].onclick();
assert.equal(panels['personal-notes'].hidden,false);
assert.equal(panels['song-lists'].hidden,true);
assert.equal(tabButtons[3]['aria-selected'],'true');
console.log('PASS real tab click switches visible panel without navigation');
let prevented=0;
tabButtons[3].onkeydown({key:'Home',preventDefault(){prevented++}});
assert.equal(tabButtons[0]['aria-selected'],'true');
assert.equal(tabButtons[0].focused,true);
tabButtons[0].onkeydown({key:'End',preventDefault(){prevented++}});
assert.equal(tabButtons[5]['aria-selected'],'true');
assert.equal(panels.submit.hidden,false);
tabButtons[5].onkeydown({key:'ArrowRight',preventDefault(){prevented++}});
assert.equal(tabButtons[0]['aria-selected'],'true');
tabButtons[0].onkeydown({key:'ArrowLeft',preventDefault(){prevented++}});
assert.equal(tabButtons[5]['aria-selected'],'true');
assert.equal(prevented,4);
console.log('PASS real keyboard navigation including wrap, Home and End');
sandbox.renderBuilder();
assert.equal(tabButtons[5]['aria-selected'],'true');
assert.ok(canvas.innerHTML.includes('id="builder-panel-submit"'));
assert.equal(typeof items['#orderBuild'].onclick,'function');
assert.equal(typeof items['#saveBuild'].onclick,'function');
assert.equal(typeof items['#exportBuild'].onclick,'function');
assert.equal(typeof items['#clearBuild'].onclick,'function');
console.log('PASS selected tab persists across re-render; Submit handlers are wired');
console.log('Section 60 runtime: 4/4 checks passed');
