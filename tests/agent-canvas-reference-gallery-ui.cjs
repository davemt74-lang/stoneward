'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');
const media=read('admin/assets/media.js'),app=read('assets/js/app.js');
const controlsSource=media.slice(media.indexOf('  function referenceGalleryControl('),media.indexOf('  function assetCard('));

function adminHarness(api){
  const asset={id:17,category:'image',mime_type:'image/jpeg',in_reference_gallery:false};
  const control=()=>({dataset:{mediaReference:'17'},checked:true,disabled:false,status:{textContent:''},closest(){return this;}});
  const controls=[control(),control()],messages=[];
  const context={Number,api,state:{assets:[asset]},say:message=>messages.push(message),
    $:(_,node)=>node.status,$$:(_,root)=>root?.controls||controls};
  vm.runInNewContext(controlsSource+'\nglobalThis.controlsApi={referenceGalleryControl,bindReferenceGalleryControls};',context);
  context.controlsApi.bindReferenceGalleryControls({controls:[controls[0]]},[asset]);
  return {asset,controls,messages,...context.controlsApi};
}

test('reference gallery control clearly publishes supported images and respects the stored flag',()=>{
  const harness=adminHarness(async()=>({}));
  assert.match(harness.referenceGalleryControl(harness.asset),/Include in reference gallery/);
  assert.match(harness.referenceGalleryControl(harness.asset),/Available to all visitors in \/images/);
  assert.doesNotMatch(harness.referenceGalleryControl(harness.asset),/ checked/);
  assert.match(harness.referenceGalleryControl({...harness.asset,in_reference_gallery:1}),/ checked/);
  assert.equal(harness.referenceGalleryControl({...harness.asset,category:'audio'}),'');
  assert.equal(harness.referenceGalleryControl({...harness.asset,mime_type:'image/gif'}),'');
});

test('one explicit checkbox action saves strict booleans and synchronizes duplicate controls',async()=>{
  let release,captured;
  const harness=adminHarness((endpoint,payload)=>{
    captured={endpoint,payload};return new Promise(resolve=>{release=resolve;});
  });
  const pending=harness.controls[0].onchange();
  assert.ok(harness.controls.every(control=>control.disabled));
  assert.equal(captured.endpoint,'media.php');
  assert.equal(JSON.stringify(captured.payload),JSON.stringify({action:'set_reference_gallery',id:17,enabled:true}));
  release({asset:{in_reference_gallery:true}});await pending;
  assert.equal(harness.asset.in_reference_gallery,true);
  assert.ok(harness.controls.every(control=>control.checked&&!control.disabled));
  assert.equal(harness.messages.length,1);
});

test('a rejected protected image restores persisted selection and shows the server explanation',async()=>{
  const harness=adminHarness(async()=>{throw new Error('This image belongs to private member media.');});
  await harness.controls[0].onchange();
  assert.equal(harness.asset.in_reference_gallery,false);
  assert.ok(harness.controls.every(control=>!control.checked&&!control.disabled));
  assert.match(harness.controls[0].status.textContent,/private member media/);
  assert.equal(harness.messages.length,0);
});

test('photo-gallery attachment controls target the media asset ID',async()=>{
  const harness=adminHarness(async()=>({asset:{in_reference_gallery:false}}));
  assert.match(harness.referenceGalleryControl({...harness.asset,id:92,asset_id:17}),/data-media-reference="17"/);
  assert.ok(media.includes('bindReferenceGalleryControls(box,links)'));
  assert.ok(media.includes('referenceGalleryControl(l)'));
});

test('draft bindings keep private and approved artist placement separate from visual references',()=>{
  const source=app.slice(app.indexOf('  function chatDraftImageIds('),app.indexOf('  function syncChatImageDraftLimit('));
  const context={};vm.runInNewContext(source+'\nglobalThis.parse={chatDraftImageIds,chatDraftVisualReferenceIds};',context);
  const privateId='ab'.repeat(16),artistId='ref-'+'cd'.repeat(16);
  const draft=`Build {image-${privateId}} {image-${artistId}} {reference-image-${artistId}} {reference-image-${artistId}} {reference-image-${privateId}}`;
  assert.deepEqual(Array.from(context.parse.chatDraftImageIds(draft)),[privateId,artistId]);
  assert.deepEqual(Array.from(context.parse.chatDraftVisualReferenceIds(draft)),[artistId,privateId]);
  assert.equal(context.parse.chatDraftVisualReferenceIds('{reference-image-ref-../private}').length,0);
  assert.ok(app.includes('const chatImageId=/^(?:ref-)?[a-f0-9]{32}$/;'));
  assert.ok(app.includes("params.set('scope','reference')"));
});
