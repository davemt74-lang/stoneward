'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const read=p=>fs.readFileSync(p,'utf8');
const api=read('admin/api/media.php');
const ui=read('admin/assets/image-generation.js');
const css=read('admin/assets/image-generation.css');
test('91B20G uses the existing trusted media publishers',()=>{
  assert.match(api,/function sf_admin_image_studio_publish_link/);
  assert.match(api,/sf_media_publish_track_artwork\(\$id,\$linkId\)/);
  assert.match(api,/sf_admin_media_publish_image\(\$type,\$id,\$linkId,\$role\)/);
  assert.match(api,/sf_media_guard_generated_image_approval/);
});
test('Only five supported primary artwork surfaces can be applied',()=>{
  for(const t of ["'track'=>['artwork']","'release'=>['cover']","'story_entry'=>['hero']","'campaign'=>['hero']","'site'=>['hero']"])
    assert.ok(api.includes(t),t);
  assert.doesNotMatch(api,/'site'=>\['hero','background'\]/);
  assert.match(ui,/publishablePlacements=new Set\(\['song_cover','release','story','campaign','site'\]\)/);
});
test('Apply enforces an approved job and exact staged asset identity',()=>{
  assert.match(api,/if\(\$action==='image_studio_apply'\|\|\$action==='image_studio_restore'\)/);
  assert.match(api,/\$job\['status'\].*'ready'/);
  assert.match(api,/\$job\['asset_approved_by'\]/);
  assert.match(api,/\(int\)\$link\['asset_id'\]!==\(int\)\$job\['media_asset_id'\]/);
  assert.match(api,/if\(empty\(\$b\['confirmed'\]\)\)/);
});
test('Apply and restore reject stale comparisons and require previous managed art',()=>{
  assert.match(api,/expected_current_link_id/);
  assert.match(api,/\$current!==max\(0,\(int\)\$b\['expected_current_link_id'\]\)/);
  assert.match(api,/\$current!==\(int\)\(\$details\['new_link_id'\]/);
  assert.match(api,/This revision has no managed previous image to restore/);
  assert.match(api,/sf_admin_image_studio_validate_link\(\$linkId,\$type,\$entityId,\$role\)/);
});
test('Admin audit receipts and Agent Brain record apply and restore',()=>{
  assert.match(api,/image_studio_placement_applied/);
  assert.match(api,/image_studio_placement_restored/);
  assert.match(api,/previous_link_id.*new_link_id/s);
  assert.match(api,/sf_log_admin_action\(\(int\)\$me\['id'\],\$auditAction/);
  assert.match(api,/sf_agent_brain_log\(\(int\)\$me\['id'\],'admin_media',\$auditAction/);
});
test('UI requests separate publication consent and offers history and restore',()=>{
  for(const marker of ['data-placement-publish-confirm','data-placement-apply','data-placement-restore','data-placement-history'])
    assert.ok(ui.includes(marker),marker);
  assert.match(ui,/publicConsent\.onchange=syncPublish/);
  assert.match(ui,/!publicConsent\.checked/);
  assert.match(ui,/action:'image_studio_apply'/);
  assert.match(ui,/action:'image_studio_restore'/);
  assert.match(ui,/image_placement_history/);
  assert.match(ui,/await updatePreview\(\)/);
  assert.doesNotMatch(ui.slice(ui.indexOf('apply.onclick=async'),ui.indexOf('restore.onclick=async')),/imagegen-generate|action:'generate'/);
});
test('Responsive editorial controls and permanent release gate are included',()=>{
  assert.match(css,/\.imagegen-placement-publish\{/);
  assert.match(css,/\.imagegen-placement-publish\[hidden\]/);
  assert.match(css,/@media\(max-width:720px\)/);
  assert.match(read('.github/workflows/release-gate.yml'),/node --test tests\/section91b20g-image-apply-restore\.test\.cjs/);
  const version=read('version.php').match(/'stonefellow'=>'1\.3\.(\d+)'/)?.[1];
  assert.ok(version&&Number(version)>=233,'image publication release must not regress below v1.3.233');
});
