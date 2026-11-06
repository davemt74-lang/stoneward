'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const crypto=require('node:crypto');
const source=fs.readFileSync(path.resolve(__dirname,'../assets/js/public-agent-canvas.js'),'utf8');
const raw='a'.repeat(32),reference='ref-'+('b'.repeat(32));
const idRule=source.match(/const sceneImageId = .*?;/)[0];
const assetFunction=source.slice(source.indexOf('  function sceneImageAssets('),source.indexOf('  function hasSceneCurrentTrackBinding('));
const imageActions=source.slice(source.indexOf('    function editableSceneImage('),source.indexOf('    async function loadSceneImage('));
const bridge=source.match(/const sceneImageBridge = `([\s\S]*?)`;/)[1];

class Element{
  constructor(){this.children=[];this.dataset={};this.attributes={};this.textContent='';this.classList={add:()=>{}};this.listeners={};}
  append(...children){this.children.push(...children);}
  replaceChildren(){this.children=[];}
  setAttribute(key,value){this.attributes[key]=value;}
  addEventListener(type,callback){this.listeners[type]=callback;}
  focus(){this.focused=true;}
}

function actionScope({edit=true,replace=true}={}){
  const calls=[];
  const frame={},picker=new Element();picker.open=true;
  const scope={document:{createElement:()=>new Element()},destroyed:false,access:true,owner:'user-7',
    sceneImagePicker:picker,sceneImagePickerLabel:new Element(),sceneImageChoices:new Element(),
    codingImageEdit:new Element(),codingImageReplace:new Element(),codingImageModalTrigger:null,
    listeningScene:{id:72,images:{editable:true,owner:'user-7',assets:new Map([[raw,'Generated art'],[reference,'Approved artwork']]),
      ready:true,layer:{contentWindow:frame},loaded:new Map()}},calls};
  scope.options={canUseScenes:()=>scope.access,getSceneOwner:()=>scope.owner};
  if(edit)scope.options.onEditSceneImage=asset=>{calls.push(['edit',JSON.parse(JSON.stringify(asset))]);return false;};
  if(replace)scope.options.onReplaceSceneImage=asset=>{calls.push(['replace',JSON.parse(JSON.stringify(asset))]);return false;};
  vm.createContext(scope);vm.runInContext(idRule+imageActions,scope);
  return scope;
}

test('Canvas image assets accept generated and explicit reference IDs only when bound',()=>{
  const scope={};vm.createContext(scope);vm.runInContext(idRule+source.match(/const sceneImageLimit = .*?;/)[0]+assetFunction,scope);
  const unbound='c'.repeat(32),malformed='ref-'+('x'.repeat(32));
  const assets=scope.sceneImageAssets({html:`<div class="sf-image-${reference}"></div>`,css:`.room{background:var(--sf-image-${raw})}`,
    image_assets:[{id:raw,title:'Generated'},{id:reference,title:'Approved'},{id:unbound,title:'Unused'},{id:malformed},{id:reference+' '}]});
  assert.deepEqual([...assets.keys()],[raw,reference]);
});

test('Sandbox bridge displays references, rejects invalid senders and keeps the exact CSP hash',()=>{
  const expected=source.match(/const sceneImageHash = "'sha256-([^']+)'";/)[1];
  assert.equal(crypto.createHash('sha256').update(bridge).digest('base64'),expected);
  const messages=[],styles=new Map(),slots=new Map([[raw,new Element()],[reference,new Element()]]);
  const parent={postMessage:message=>messages.push(message)};
  let receive;
  const scope={Blob,URL:{createObjectURL:()=>'blob:verified'},parent,addEventListener:(type,fn)=>{receive=fn;},
    document:{documentElement:{style:{setProperty:(k,v)=>styles.set(k,v)}},
      querySelectorAll:selector=>[slots.get(selector.slice('.sf-image-'.length))].filter(Boolean),createElement:()=>new Element()}};
  vm.runInNewContext(bridge,scope);
  const blob=new Blob(['pixels'],{type:'image/png'});
  const data={stonefellowSceneImages:[{id:reference,title:'Approved',blob},{id:raw,title:'Generated',blob},
    {id:'ref-'+('z'.repeat(32)),blob}],stonefellowSceneImageReplaceable:true};
  receive({source:{},data});assert.equal(styles.size,0);
  receive({source:parent,data});assert.equal(styles.size,2);
  const image=slots.get(reference).children[0];
  assert.equal(image.src,'blob:verified');assert.equal(image.alt,'Approved');
  assert.equal(image.attributes.role,undefined);assert.equal(image.tabIndex,undefined);
  assert.equal(image.attributes['aria-description'],undefined);assert.equal(image.title,undefined);
  assert.deepEqual(Object.keys(image.listeners),[]);assert.equal(messages.length,0);
  receive({source:parent,data});assert.equal(slots.get(reference).children.length,1);
});

test('Replace dispatches a current owned image without invoking Edit and blocks stale or malformed requests',()=>{
  const scope=actionScope();
  scope.replaceSceneImage(reference);
  assert.deepEqual(scope.calls,[['replace',{id:reference,title:'Approved artwork',sceneId:72}]]);
  assert.equal(scope.sceneImagePicker.open,false);assert.equal(scope.sceneImagePickerLabel.focused,true);
  scope.editSceneImage(raw);assert.equal(scope.calls[1][0],'edit');
  for(const id of ['ref-'+('c'.repeat(32)),reference+' ',{},null])scope.replaceSceneImage(id);
  scope.owner='user-8';scope.replaceSceneImage(reference);
  scope.owner='user-7';scope.access=false;scope.replaceSceneImage(reference);
  scope.access=true;scope.destroyed=true;scope.replaceSceneImage(reference);
  assert.equal(scope.calls.length,2);
});

test('Image menu exposes replacement separately and routes trusted reference activation',()=>{
  const scope=actionScope({edit:false});
  scope.renderSceneImageChoices();
  assert.equal(scope.sceneImagePicker.hidden,false);
  const items=scope.sceneImageChoices.children;
  assert.equal(items.length,2);assert.equal(items[1].children.length,1);
  const button=items[1].children[0];
  assert.equal(button.textContent,'Replace Approved artwork');assert.equal(button.dataset.sceneImageReplace,reference);
  scope.cleanup=[];scope.on=(element,type,callback)=>{scope.menuClick=callback;};
  const listener=source.match(/cleanup\.push\(on\(sceneImageChoices,'click',[\s\S]*?\n    \}\)\);/)[0];
  vm.runInContext(listener,scope);
  scope.menuClick({isTrusted:false,target:{closest:()=>button}});assert.equal(scope.calls.length,0);
  scope.menuClick({isTrusted:true,target:{closest:()=>button}});assert.equal(scope.calls[0][0],'replace');
  assert.equal(scope.codingImageEdit.hidden,true);
});

test('Canvas image rendering has no implicit edit target or iframe edit action channel',()=>{
  assert.doesNotMatch(source,/sf-scene-image-editable|stonefellowSceneImageEdit|stonefellowSceneImageReplace|receiveSceneImageEdit|foregroundImages/);
  assert.match(source,/const interactive=disclosure\|\|!!script\.js\|\|trackActions/);
  const scope=actionScope(),messages=[];
  scope.listeningScene.images.loaded.set(reference,{id:reference,title:'Approved',blob:{}});
  scope.listeningScene.images.layer.contentWindow.postMessage=message=>messages.push(message);
  scope.postSceneImages(scope.listeningScene.images);
  assert.deepEqual(Object.keys(messages[0]),['stonefellowSceneImages']);
});

test('Preview Replace closes its modal and passes only the guarded image identity',()=>{
  const scope=actionScope();
  const button=new Element();
  scope.codingImageModalTrigger={id:reference,button};
  scope.codingImageDialog={close(){this.closed=true;}};
  scope.cleanup=[];scope.on=(element,type,callback)=>{scope.replaceClick=callback;};
  const listener=source.match(/cleanup\.push\(on\(codingImageReplace,'click',[\s\S]*?\n    \}\)\);/)[0];
  vm.runInContext(listener,scope);scope.replaceClick();
  assert.equal(scope.codingImageDialog.closed,true);assert.equal(scope.codingImageModalTrigger,null);
  assert.deepEqual(scope.calls,[['replace',{id:reference,title:'Approved artwork',sceneId:72}]]);
  assert.equal(button.focused,true);
});
