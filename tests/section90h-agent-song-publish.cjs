'use strict';
const fs=require('node:fs');
const assert=require('node:assert/strict');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const client=read('admin/assets/agent-canvas.js');
const api=read('admin/api/agent-song-catalog.php');
const core=read('api/agent-song-catalog.php');
let checks=0;
function test(name,assertion){assertion();checks++;console.log('PASS '+name);}
test('Admin private drafts offer owner-triggered publication review for prepared songs',()=>{
  assert.match(client,/data-agent-song-publish-draft/);
  assert.match(client,/draft\.status!=='catalog_ready'/);
  assert.match(client,/state:'publish_review'/);
  assert.match(client,/Review track publication/);
});
test('New public MP3 preview is an explicit upload from the browser',()=>{
  assert.match(client,/data-agent-song-preview-choose/);
  assert.match(client,/previewPicker\?\.click\(\)/);
  assert.match(client,/accept="\.mp3,audio\/mpeg"/);
  assert.match(client,/uploadApi\('media-upload\.php',form\)/);
  assert.match(client,/active\.preview=a/);
});
test('Preview cannot reuse master ID or matching content SHA',()=>{
  assert.match(client,/Number\(a\.id\)===Number\(active\.draft\?\.asset_id\)/);
  assert.match(client,/a\.sha256===active\.draft\?\.asset_sha256/);
  assert.match(core,/private original/);
});
test('Publication requires explicit confirmation and the exact prepared revision',()=>{
  assert.match(client,/window\.confirm\('Publish/);
  assert.match(client,/action:'publish_track',request_key:d\.request_key/);
  assert.match(client,/expected_hash:d\.payload_hash/);
  assert.match(client,/preview_asset_id:Number\(active\.preview\.id\),confirmed:true/);
  assert.match(api,/sf_admin_require_auth\(true\)/);
});
test('Admin explains private master and distinct public track vs album release',()=>{
  assert.match(client,/The original/);
  assert.match(client,/will remain private/);
  assert.match(client,/does not create or publish an album/);
  assert.match(client,/No album or release was created/);
});
test('Published state offers public track route and owner-visible receipt',()=>{
  assert.match(client,/data-agent-song-confirm-publish/);
  assert.match(client,/active\.trackId=response\.track_id/);
  assert.match(client,/state='published'/);
  assert.match(client,/href="\.\.\/track\//);
  assert.match(client,/d\.status==='published'/);
});
test('No model dispatch, automatic publish or release mutation',()=>{
  assert.doesNotMatch(core,/sf_admin_write_releases|sf_campaign_send/);
  assert.doesNotMatch(api,/sf_admin_write_releases|sf_campaign_send/);
  assert.match(core,/sf_media_guard_link_exposure|sf_media_protected_master_asset/);
});
console.log(`${checks} Section 90H guided publishing checks green.`);
