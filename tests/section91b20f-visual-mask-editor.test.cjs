'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('admin/assets/image-mask-editor.js','utf8');
const css=fs.readFileSync('admin/assets/image-mask-editor.css','utf8');
const studio=fs.readFileSync('admin/assets/image-generation.js','utf8');
const shell=fs.readFileSync('admin/index.php','utf8');
const route=fs.readFileSync('admin/api/media-upload.php','utf8');
const gate=fs.readFileSync('.github/workflows/release-gate.yml','utf8');
const core=fs.readFileSync('api/image-generation-core.php','utf8');
test('91B20F is loaded before the Studio and linked to private PNG upload',()=>{
  assert.ok(shell.indexOf('image-mask-editor.js')<shell.indexOf('image-generation.js'));
  assert.match(shell,/image-mask-editor\.css/);
  assert.match(studio,/id="imagegenDrawMask"/);
  assert.match(studio,/SFImageMaskEditor\.open\(\{reference,getCsrf:/);
  assert.match(studio,/mask_asset_id:Number\(maskAsset\.id\),mask_consent:false/);
  assert.match(studio,/await refresh\(\);render\(\)/);
});
test('A local brush creates transparent edit pixels and a private PNG matching the source',()=>{
  for(const expected of ["ctx.fillRect(0,0,width,height)","ctx.globalCompositeOperation=action.type==='restore'?'source-over':'destination-out'",
    "mask.toBlob(value=>value?resolve(value)","'image/png'","photo.naturalWidth!==width||photo.naturalHeight!==height",
    "result.asset.mime_type!=='image/png'"])assert.ok(source.includes(expected),expected);
  assert.match(source,/width\*height>16000000/);
  assert.match(source,/if\(pixels<Math\.max\(32/);
  assert.match(core,/getimagesize\(\$candidate\['path'\]\)/);
  assert.match(core,/ord\(\$header\[25\]\),\[4,6\]/);
});
test('Browser masking cannot bypass Admin CSRF, private media or generation approvals',()=>{
  assert.match(source,/fetch\('api\/media-upload\.php'/);
  assert.match(source,/credentials:'same-origin'/);
  assert.match(source,/'X-CSRF-Token':csrf/);
  assert.match(route,/sf_admin_require_auth\(true\)/);
  assert.doesNotMatch(source,/api\/image-generation\.php|action:'generate'|action:'approve_asset'|public_visible:true/);
  assert.match(studio,/mask_consent:.*imagegenMaskConsent/);
  assert.match(studio,/pendingDraft=null/);
});
test('Editor supports pointer and keyboard input, zoom-to-selection, and undo/redo',()=>{
  for(const item of ["'pointerdown',onDown","'pointermove',onMove","'pointerup',onUp",
    "pointercancel","data-mask-tool=\"focus\"","data-mask-tool=\"restore\"",
    "data-mask-tool=\"rect\"","data-mask-undo","data-mask-redo","function undo()","function redo()",
    "e.metaKey||e.ctrlKey","stage.clientWidth/2-stage.clientWidth*cx/width*zoom"])
    assert.ok(source.includes(item),item);
  assert.match(source,/const width=Number\(reference\?\.width/);
  assert.match(source,/mask\.width=display\.width=width/);
});
test('Responsive modal uses one scroll surface and accessible touch controls',()=>{
  assert.match(css,/imagegen-mask-editor-overlay\{[^}]*overflow-y:auto/);
  assert.doesNotMatch(css,/\.imagegen-mask-editor\{[^}]*overflow-y:auto/);
  assert.match(css,/touch-action:none/);
  assert.match(css,/@media\(max-width:720px\)/);
  assert.match(source,/role="dialog" aria-modal="true"/);
  assert.match(source,/aria-pressed="true"/);
  assert.match(source,/document\.activeElement/);
  assert.match(source,/e\.key==='Escape'/);
});
test('Mask feature is part of the permanent release gate and version markers',()=>{
  assert.match(gate,/node --test tests\/section91b20f-visual-mask-editor\.test\.cjs/);
  assert.match(gate,/node --check admin\/assets\/image-mask-editor\.js/);
  for(const file of ['admin/index.php','stonefellow-v120.php','service-worker.js','assets/js/app.js','assets/css/turntable-builder.css','.htaccess','version.php'])
    assert.ok(fs.readFileSync(file,'utf8').includes('1.3.232'),file);
});
test('A simulated Admin can paint, undo/redo, crop/focus, and save once without paying for generation',async()=>{
  const callbacks=[],fetched=[],toolButtons=[],values=new Map();
  class Elem{
    constructor(tag='div'){this.tag=tag;this.style={setProperty(k,v){values.set(k,v);}};this.listeners={};this.dataset={};this.disabled=false;this.hidden=false;this.value='54';this.clientWidth=300;this.clientHeight=200;this.textContent='';this.attrs={};}
    addEventListener(name,fn){this.listeners[name]=fn;}
    removeEventListener(name){delete this.listeners[name];}
    setAttribute(k,v){this.attrs[k]=v;}
    getBoundingClientRect(){return {left:0,top:0,width:300,height:200};}
    setPointerCapture(){}
    focus(){}
    remove(){this.removed=true;}
    querySelector(sel){return lookup.get(sel)||null;}
    querySelectorAll(sel){if(sel==='[data-mask-tool]')return toolButtons;return Array.from(lookup.values()).filter(x=>x.tag==='button');}
  }
  class FakeCanvas extends Elem{
    constructor(){super('canvas');this.width=0;this.height=0;this.ctx=null;}
    getContext(){if(this.ctx)return this.ctx;const canvas=this;
      const alpha=new Uint8Array(600*400);let operation='source-over',circle=null;
      function apply(x,y,r){
        for(let yy=Math.max(0,Math.floor(y-r));yy<Math.min(400,Math.ceil(y+r));yy++)
          for(let xx=Math.max(0,Math.floor(x-r));xx<Math.min(600,Math.ceil(x+r));xx++)
            if((xx-x)*(xx-x)+(yy-y)*(yy-y)<=r*r)alpha[yy*600+xx]=operation==='destination-out'?0:255;
      }
      this.ctx={
        set fillStyle(v){},set strokeStyle(v){},set lineWidth(v){this.size=v;},
        set lineCap(v){},set lineJoin(v){},
        get globalCompositeOperation(){return operation;},set globalCompositeOperation(v){operation=v;},
        fillRect(x,y,w,h){for(let yy=Math.max(0,Math.floor(y));yy<Math.min(400,Math.ceil(y+h));yy++)for(let xx=Math.max(0,Math.floor(x));xx<Math.min(600,Math.ceil(x+w));xx++)alpha[yy*600+xx]=operation==='destination-out'?0:255;},
        save(){},restore(){operation='source-over';},beginPath(){},arc(x,y,r){circle={x,y,r};},fill(){if(circle)apply(circle.x,circle.y,circle.r);},
        moveTo(x,y){this.from={x,y};},lineTo(x,y){const from=this.from||{x,y};this.to={x,y};const steps=Math.max(1,Math.ceil(Math.hypot(x-from.x,y-from.y)));for(let i=0;i<=steps;i++)apply(from.x+(x-from.x)*i/steps,from.y+(y-from.y)*i/steps,(this.size||54)/2);this.from={x,y};},
        stroke(){},clearRect(){},drawImage(){},
        getImageData(){const data=new Uint8ClampedArray(alpha.length*4);for(let i=0;i<alpha.length;i++)data[i*4+3]=alpha[i];return {data};},
        get edited(){return alpha.reduce((sum,a)=>sum+(a<128),0);}
      };
      return this.ctx;
    }
    toBlob(fn,type){assert.equal(type,'image/png');fn({size:1800,type:'image/png'});}
  }
  const lookup=new Map();
  const button=(name)=>{const e=new Elem('button');lookup.set(name,e);return e;};
  for(const key of ['[data-mask-close]','[data-mask-undo]','[data-mask-redo]','[data-mask-fit]','[data-mask-clear]','[data-mask-upload]'])button(key);
  for(const name of ['brush','restore','rect','focus']){const e=button('[data-mask-tool="'+name+'"]');e.dataset.maskTool=name;toolButtons.push(e);}
  for(const key of ['[data-mask-stage]','[data-mask-transform]','[data-mask-photo]','[data-mask-display]','[data-mask-focus]','[data-mask-status]','[data-mask-brush]','[data-mask-zoom]','[data-mask-size]','[data-mask-zoom-label]'])lookup.set(key,key==='[data-mask-display]'?new FakeCanvas():new Elem());
  lookup.get('[data-mask-brush]').value='54';lookup.get('[data-mask-zoom]').value='100';
  const photo=lookup.get('[data-mask-photo]');
  Object.defineProperty(photo,'src',{set(v){this.source=v;this.naturalWidth=600;this.naturalHeight=400;this.onload();}});
  let overlay=null,lastCanvas=null;const realCreate=tag=>{
    if(tag==='div'){overlay=new Elem();overlay.innerHTML='';return overlay;}
    if(tag==='canvas'){lastCanvas=new FakeCanvas();return lastCanvas;}
    return new Elem(tag);
  };
  const sourceCallbacks={};
  const dom={activeElement:new Elem(),body:{appendChild(x){this.child=x;}},createElement:realCreate};
  const confirmValues=[];
  const ctx={window:{addEventListener(k,fn){sourceCallbacks[k]=fn;},removeEventListener(k){delete sourceCallbacks[k];}},
    document:dom,Image:class{},alert:msg=>{throw new Error(msg);},
    confirm:msg=>{confirmValues.push(msg);return true;},requestAnimationFrame:fn=>{callbacks.push(fn);return callbacks.length;},cancelAnimationFrame(){},
    fetch:async(url,options)=>{fetched.push({url,options});return {ok:true,json:async()=>({ok:true,asset:{id:19,mime_type:'image/png',width:600,height:400}})};},
    File:class{constructor(parts,name,opts){this.parts=parts;this.name=name;this.type=opts.type;}},
    FormData:class{constructor(){this.data=[];}append(...a){this.data.push(a);}},
    Date,Math,Number,Array,Map,Uint8Array,Uint8ClampedArray,console};
  vm.runInNewContext(source,ctx,{filename:'image-mask-editor.js'});
  let attached=null;
  const opened=ctx.window.SFImageMaskEditor.open({reference:{id:7,uuid:'local-reference',width:600,height:400},
    getCsrf:()=> 'private-csrf',onSaved:async asset=>{attached=asset;}});
  assert.equal(opened,true);assert.equal(fetched.length,0);
  assert.equal(lastCanvas.width,600);assert.equal(lastCanvas.height,400);
  assert.equal(values.get('--mask-ratio'),'1.5');
  await lookup.get('[data-mask-upload]').onclick();
  assert.equal(fetched.length,0,'An empty fully opaque mask cannot upload');
  while(callbacks.length)callbacks.shift()();
  const display=lookup.get('[data-mask-display]'),pointer=(x,y)=>({clientX:x,clientY:y,button:0,pointerId:1,preventDefault(){}});
  display.listeners.pointerdown(pointer(95,85));display.listeners.pointermove(pointer(112,85));display.listeners.pointerup(pointer(112,85));
  while(callbacks.length)callbacks.shift()();
  const painted=lastCanvas.getContext().edited;assert.ok(painted>200,'painted edit area must be transparent');
  lookup.get('[data-mask-undo]').onclick();assert.equal(lastCanvas.getContext().edited,0);
  lookup.get('[data-mask-redo]').onclick();assert.equal(lastCanvas.getContext().edited,painted);
  lookup.get('[data-mask-tool="restore"]').onclick();
  display.listeners.pointerdown(pointer(95,85));display.listeners.pointerup(pointer(95,85));
  const restored=lastCanvas.getContext().edited;assert.ok(restored<painted,'Restore protects pixels again');
  lookup.get('[data-mask-undo]').onclick();assert.equal(lastCanvas.getContext().edited,painted);
  lookup.get('[data-mask-redo]').onclick();assert.equal(lastCanvas.getContext().edited,restored);
  lookup.get('[data-mask-tool="rect"]').onclick();
  display.listeners.pointerdown(pointer(160,140));display.listeners.pointermove(pointer(200,160));display.listeners.pointerup(pointer(200,160));
  assert.ok(lastCanvas.getContext().edited>restored,'Rectangle defines another editable region');
  lookup.get('[data-mask-tool="focus"]').onclick();
  display.listeners.pointerdown(pointer(80,55));display.listeners.pointermove(pointer(160,120));display.listeners.pointerup(pointer(160,120));
  assert.equal(lastCanvas.width,600);assert.match(lookup.get('[data-mask-transform]').style.transform,/scale\(/);
  assert.equal(fetched.length,0,'Focus/undo must never upload or generate');
  await lookup.get('[data-mask-upload]').onclick();
  assert.equal(fetched.length,1);assert.equal(fetched[0].url,'api/media-upload.php');
  assert.equal(fetched[0].options.headers['X-CSRF-Token'],'private-csrf');
  assert.equal(attached.id,19);assert.ok(overlay.removed);assert.equal(confirmValues.length,1);
});
