'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const api=read('admin/api/image-generation.php');
const ui=read('admin/assets/image-generation.js');
const media=read('admin/assets/media.js');
const css=read('admin/assets/image-generation.css');

test('Placement preview covers existing music, editorial, campaign and site workspaces',()=>{
  for(const key of ['song_cover','story','song_moment','release','campaign','site','canvas'])
    assert.match(ui,new RegExp('\\b'+key+':\\{label:'));
  for(const type of ['track','story_entry','release','campaign','site'])
    assert.match(api,new RegExp("'"+type+"'=>\\["));
});
test('Placement compares existing private/public media and approved proposed image',()=>{
  assert.match(ui,/api\('media\.php\?entity_type='/);
  assert.match(ui,/currentView\('Current'/);
  assert.match(ui,/currentView\('Proposed'/);
  assert.match(ui,/x\.role==='primary_artwork'/);
  assert.match(ui,/j\.status==='ready'/);
});
test('Placement preview never invokes the image provider or auto-publishes',()=>{
  const portion=ui.slice(ui.indexOf('async function previewPlacement('),ui.indexOf('function render(){'));
  assert.doesNotMatch(portion,/imagegen-generate|action:'generate'|sf_image_gen_request/);
  assert.doesNotMatch(portion,/publish_track_artwork|publish_entity_image|public_visible:true/);
  assert.match(portion,/No public artwork changed/);
});
test('Private staging requires a distinct human confirmation and approved job',()=>{
  const stage=api.slice(api.indexOf("if($action==='stage_placement')"),api.indexOf("sf_json_response(['ok'=>false,'message'=>'Unsupported image-generation action.'"));
  assert.match(stage,/empty\(\$body\['confirmed'\]\)/);
  assert.match(stage,/\$job\['status'\].*'ready'/);
  assert.match(stage,/\$job\['asset_approved_by'\]/);
  assert.match(stage,/\$asset\['category'\].*'image'/);
  assert.match(stage,/'public_visible'=>false/);
  assert.match(stage,/'featured'=>false/);
  assert.match(stage,/'download_allowed'=>false/);
  assert.doesNotMatch(stage,/sf_media_publish_track_artwork|sf_admin_media_publish_image|sf_image_gen_request/);
});
test('Server rejects unsupported roles and nonexistent placement targets',()=>{
  const stage=api.slice(api.indexOf("if($action==='stage_placement')"));
  assert.match(stage,/in_array\(\$role,\$roles\[\$type\],true\)/);
  assert.match(stage,/sf_catalog\(\)/);
  assert.match(stage,/sf_admin_releases\(\)/);
  assert.match(stage,/sf_story_get\(/);
  assert.match(stage,/sf_campaign_get\(/);
  assert.match(stage,/\$entityId!=='public'/);
});
test('Staging never downgrades an already-public link or duplicates a private link',()=>{
  const stage=api.slice(api.indexOf("if($action==='stage_placement')"));
  assert.match(stage,/SELECT public_visible,download_allowed FROM media_links/);
  assert.match(stage,/already public at that placement/);
  assert.match(stage,/'already_staged'=>true/);
});
test('Private placement audited and existing publication left untouched',()=>{
  assert.match(api,/'generated_image_placement_staged'/);
  assert.match(api,/'published'=>false/);
  assert.match(api,/'private'=>true/);
  assert.match(ui,/No publishing or provider request is performed here/);
});
test('Existing content selection derives from Catalog, Releases, Stories and Campaigns',()=>{
  assert.match(ui,/app\.catalog/);
  assert.match(ui,/app\.releases/);
  assert.match(ui,/api\('stories\.php'\)/);
  assert.match(ui,/api\('campaigns\.php'\)/);
  assert.match(ui,/No existing content found/);
});
test('Preview panel exposes a single mobile viewport and modal keyboard controls',()=>{
  assert.match(ui,/data-placement-device/);
  assert.match(ui,/aria-modal="true"/);
  assert.match(ui,/e\.key==='Escape'/);
  assert.match(ui,/e\.key!=='Tab'/);
  assert.match(css,/\.imagegen-placement-frame\.phone\.wide/);
  assert.match(css,/body\.imagegen-placement-open/);
  assert.doesNotMatch(css,/overflow-y:scroll|overflow-y:auto/);
});
test('Media Library can start a placement preview for an older approved image',()=>{
  assert.match(media,/data-media-preview-ai/);
  assert.match(media,/SFImageGenerationAdmin\?\.previewByJobId/);
  assert.match(ui,/function previewByJobId/);
  assert.match(ui,/image-generation\.php\?job_id=/);
  assert.match(api,/\$jobId=max\(0,\(int\)\(\$_GET\['job_id'\]/);
});
test('Approved-image lookup shares only sanitized identifiers and status',()=>{
  const lookup=api.slice(api.indexOf('if($jobId){'),api.indexOf("sf_json_response(['ok'=>true,'settings'"));
  assert.match(lookup,/'asset_uuid'/);
  assert.match(lookup,/'status'/);
  assert.doesNotMatch(lookup,/'prompt'|'secret_payload'|'usage_json'/);
});
