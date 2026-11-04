'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const read=p=>fs.readFileSync(p,'utf8');
const styles=read('api/image-studio-styles.php');
const presets=read('api/image-studio-presets.php');
const api=read('admin/api/image-generation.php');
const ui=read('admin/assets/image-generation.js');
const css=read('admin/assets/image-generation.css');

test('Style Library has four reusable, independently seeded visual identities',()=>{
  for(const marker of ['nebula-noir','desert-afterglow','analog-dream','ocean-signal'])assert.ok(styles.includes(marker),marker);
  assert.match(styles,/CREATE TABLE IF NOT EXISTS image_studio_styles/);
  assert.match(styles,/INSERT OR IGNORE/);
  assert.match(styles,/INSERT IGNORE/);
  assert.match(styles,/PRIMARY KEY/);
});
test('Only Admin-authorized API actions can save or archive styles',()=>{
  assert.match(api,/\$me=sf_admin_require_auth\(\$write\)/);
  assert.match(api,/if\(\$action==='save_style'\)/);
  assert.match(api,/if\(\$action==='archive_style'\)/);
  assert.match(api,/if\(empty\(\$body\['confirmed'\]\)\)throw new InvalidArgumentException\('Confirm archiving this custom style/);
  assert.match(styles,/is_builtin=0 AND is_active=1/);
});
test('Palette, mood, description and image-reference constraints are enforced server-side',()=>{
  assert.match(styles,/\^#\[0-9A-F\]\{6\}\$/);
  assert.match(styles,/strlen\(\$guidance\)<30/);
  assert.match(styles,/sf_image_gen_available_references\(\)/);
  assert.match(styles,/image_studio_styles SET is_active=0/);
  assert.match(styles,/random_bytes\(8\)/);
});
test('Published style reads only active metadata and approved private inspiration images',()=>{
  assert.match(styles,/WHERE is_active=1/);
  assert.match(styles,/sf_image_studio_style_public/);
  assert.match(styles,/sf_media_asset\(/);
  assert.match(styles,/inspiration_uuid/);
  assert.match(api,/'styles'=>sf_image_studio_styles\(\)/);
});
test('A selected style is assembled into an editable prompt with colors and mood, without transferring thumbnail bytes',()=>{
  assert.match(presets,/require_once __DIR__\.'\/image-studio-styles.php'/);
  assert.match(presets,/function sf_image_studio_local_prompt\(string \$concept,string \$presetSlug,int \$styleId=0\)/);
  assert.match(presets,/sf_image_studio_style_direction\(\$style\)/);
  assert.match(styles,/Visual identity:/);
  assert.match(styles,/Palette \(primary, secondary, accent\)/);
  assert.doesNotMatch(styles,/sf_openai_call\(|sf_image_gen_request\(|curl_exec\(/);
});
test('Text model refinement still requires distinct confirmation; paid image generation remains a separate action',()=>{
  assert.match(api,/if\(\$action==='compose_prompt'\)/);
  assert.match(api,/Confirm sending this creative brief to your configured text model/);
  assert.match(api,/style_id/);
  assert.match(api,/if\(\$action==='generate'\)/);
  assert.match(api,/Paid image generation requires explicit confirmation/);
  assert.match(api,/if\(\$action==='save_style'\)/);
  assert.doesNotMatch(api.slice(api.indexOf("if($action==='save_style')"),api.indexOf("if($action==='archive_style')")),/sf_image_gen_request\(/);
});
test('Responsive visual identity cards, custom palette editor, archive and composer selection are connected',()=>{
  for(const marker of ['imagegen-style-library','imagegen-style-card','imagegen-style-swatches','imagegenCustomStyle','imagegenStyleSelect','data-imagegen-archive-style'])
    assert.ok(ui.includes(marker),marker);
  for(const marker of ["action:'save_style'","action:'archive_style'","style_id:Number($('#imagegenStyleSelect').value||0)"])
    assert.ok(ui.includes(marker),marker);
  assert.match(css,/\.imagegen-style-cards/);
  assert.match(css,/@media\(max-width:680px\)/);
  assert.match(css,/@media\(max-width:380px\)/);
});
test('User-owned inspiration previews never become auto-selected model source images',()=>{
  assert.match(ui,/The thumbnail is for private visual review only/);
  assert.match(ui,/Style thumbnail bytes remain private/);
  assert.doesNotMatch(ui.slice(ui.indexOf("$('#imagegenApplyPreset').onclick"),ui.indexOf("$('#imagegenAgentRefine').onclick")),/reference_asset_id:.*style/);
  assert.match(ui,/reference_consent/);
});
test('Style creation and archival are recorded in the official Admin audit',()=>{
  assert.match(api,/image_style_created/);
  assert.match(api,/image_style_archived/);
  assert.match(api,/sf_log_admin_action/);
});
test('Section is versioned and part of permanent release certification',()=>{
  assert.match(read('.github/workflows/release-gate.yml'),/node --test tests\/section91b20h-visual-style-library\.test\.cjs/);
  const release=read('version.php');
  const version=release.match(/'stonefellow'=>'(1\.3\.\d+)'/)?.[1];
  assert.ok(version);
  assert.match(release,/'admin_image_studio'=>'91B20H:/);
  assert.ok(read('admin/index.php').includes("define('SF_ADMIN_BUILD','"+version+"')"));
  assert.ok(read('service-worker.js').includes("const BUILD='"+version+"'"));
});
