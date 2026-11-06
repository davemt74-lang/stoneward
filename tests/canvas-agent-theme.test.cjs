'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');
const imageId = '0123456789abcdef0123456789abcdef';
const source = fs.readFileSync(path.join(__dirname, '../assets/js/canvas-agent-theme.js'), 'utf8');
function runtime() {
  const named = {red:'#ff0000',gold:'#ffd700',navy:'#000080',white:'#ffffff',black:'#000000',silver:'#c0c0c0',
    'rgb(24, 96, 192)':'#1860c0','rgba(0, 0, 0, 0)':'rgba(0, 0, 0, 0)'};
  let fill = '#000000', created = 0;
  const parser = {
    get fillStyle(){return fill;},
    set fillStyle(input){if (/^#[0-9a-f]{6}$/i.test(input)) fill=input.toLowerCase(); else if(named[input]) fill=named[input];}
  };
  const context = {window:{}, document:{createElement(type){assert.equal(type,'canvas');created++;return {getContext:()=>parser};}}};
  vm.runInNewContext(source, context);
  return {resolve:context.window.SFCanvasAgentTheme.resolve, created:()=>created};
}
const rgb = value => [1,3,5].map(i=>parseInt(value.slice(i,i+2),16));
function luminance(value){return rgb(value).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((n,v,i)=>n+v*[.2126,.7152,.0722][i],0);}
function contrast(a,b){const x=luminance(a),y=luminance(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);}

test('complete authored palette wins over unrelated image and CSS colors',()=>{
  const {resolve,created}=runtime();
  const result=resolve({agent_palette:{accent:'#80CCFF',secondary:'#DD88FF',surface:'#101820'},image_assets:[{colors:['gold']}],css:'.x{color:red}'});
  assert.equal(result.accent,'#80ccff');assert.equal(result.secondary,'#dd88ff');assert.equal(result.surface,'#101820');
  assert.deepEqual(Array.from(result.rgb),[128,204,255]);assert.equal(result.border,'#80ccff80');
  assert.ok(Object.isFrozen(result));assert.ok(Object.isFrozen(result.rgb));assert.equal(created(),0);
});
test('saved approved metadata colors are used before authored CSS and browser names parse once',()=>{
  const {resolve,created}=runtime();
  const result=resolve({image_assets:[{id:imageId,colors:['gold details over navy']}],css:'.x{background:var(--sf-image-'+imageId+');color:#dd33aa}'});
  assert.equal(result.accent,'#ffd700');assert.equal(result.surface,'#000080');assert.equal(created(),1);
});
test('CSS declarations supply a palette for code-only canvases without metadata',()=>{
  const {resolve}=runtime();
  const result=resolve({css:'.x{background:linear-gradient(45deg,#081018,#102040);color:rgb(24, 96, 192);border:1px solid #33aaff}'});
  assert.ok(result);assert.equal(result.surface,'#081018');assert.ok(contrast(result.accent,result.surface)>=3);assert.ok(contrast(result.text,result.surface)>=4.5);
});
test('selectors, content, URLs and animations never become palette inputs',()=>{
  const {resolve}=runtime();
  assert.equal(resolve({css:'.red .gold{animation:navy 2s;content:"color:red";background-image:url("/gold/red/#ff0000");width:20px}'}),null);
  assert.equal(resolve({css:'.red{background:var(--navy)}'}),null);
});
test('invalid and incomplete palettes fall back safely, never inject model strings',()=>{
  const {resolve}=runtime();
  const bad=['#fff','red','#123456;position:fixed','url(https://example.com/a)'];
  for(const accent of bad){const result=resolve({agent_palette:{accent,secondary:'#ffffff',surface:'#111111'},css:'.x{background:#111111;color:#abcdef}'});assert.equal(result.surface,'#111111');assert.equal(result.accent,'#abcdef');}
  assert.equal(resolve({agent_palette:{accent:'#abcdef'}}),null);
});
test('monochrome palettes remain monochrome and readable on light or dark surfaces',()=>{
  const {resolve}=runtime();
  for(const scene of [{css:'.x{background:#ddd;color:#333}'},{agent_palette:{accent:'#eeeeee',secondary:'#dddddd',surface:'#fafafa'}},{agent_palette:{accent:'#151515',secondary:'#171717',surface:'#101010'}}]){
    const result=resolve(scene);assert.ok(result);for(const key of ['accent','secondary','surface','text']){const values=rgb(result[key]);assert.equal(values[0],values[1]);assert.equal(values[1],values[2]);}
    assert.ok(contrast(result.accent,result.surface)>=3);assert.ok(contrast(result.text,result.surface)>=4.5);
  }
});
test('no usable colors leave the existing atmosphere alone',()=>{
  const {resolve}=runtime();
  for(const scene of [null,undefined,{}, {image_assets:[{colors:'gold'}]}, {image_assets:[{colors:['unrecognized descriptions']}]},{css:'.x{display:flex;gap:12px}'},{css:'.x{color:transparent;background:inherit}'}])assert.equal(resolve(scene),null);
});
test('transparent browser colors cannot override the current atmosphere',()=>{
  const {resolve}=runtime();
  assert.equal(resolve({image_assets:[{id:imageId,colors:['rgba(0, 0, 0, 0)']}],css:'.x{background:var(--sf-image-'+imageId+')}'}),null);
});

test('actual background metadata wins over foreground and unused image inventory',()=>{
  const {resolve}=runtime(), foreground='11111111111111111111111111111111', unused='22222222222222222222222222222222';
  const result=resolve({image_assets:[{id:unused,colors:['red']},{id:foreground,colors:['silver']},{id:imageId,colors:['gold details over navy']}],
    html:'<figure class="sf-image-'+foreground+'"></figure>',css:'.scene{background-image:var(--sf-image-'+imageId+')}'});
  assert.equal(result.accent,'#ffd700');assert.equal(result.surface,'#000080');
});
test('unused inventory metadata is ignored and foreground metadata requires a real binding',()=>{
  const {resolve}=runtime();
  const image_assets=[{id:imageId,colors:['gold details over navy']}];
  assert.equal(resolve({image_assets,css:'.x{background:#112233;color:#abcdef}'}).accent,'#abcdef');
  assert.equal(resolve({image_assets,html:'<figure class="sf-image-'+imageId+'"></figure>'}).accent,'#ffd700');
  assert.equal(resolve({image_assets,html:'<p>sf-image-'+imageId+'</p>'}),null);
});
