'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {DatabaseSync}=require('node:sqlite');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const core=read('api/image-generation-core.php');
const endpoint=read('admin/api/image-generation.php');
const ui=read('admin/assets/image-generation.js');
const media=read('admin/assets/media.js');
const mediaCore=read('api/media-core.php');
const css=read('admin/assets/image-generation.css');

test('A variation has persistent source, parent job, hash, individual consent, and unique job lineage',()=>{
  assert.match(core,/CREATE TABLE IF NOT EXISTS image_generation_references/);
  assert.match(core,/job_id INTEGER NOT NULL PRIMARY KEY,source_asset_id INTEGER NOT NULL,parent_job_id INTEGER NULL/);
  assert.match(core,/source_sha256 TEXT NOT NULL,consent_admin_id INTEGER NOT NULL,consent_at TEXT NOT NULL/);
  assert.match(core,/INSERT INTO image_generation_references\(job_id,source_asset_id,parent_job_id,source_sha256,consent_admin_id,consent_at,purpose,created_at\)/);
  assert.match(core,/\$pdo->beginTransaction\(\)/);
  assert.match(core,/\$pdo->commit\(\)/);
});

test('SQLite enforces a single immutable reference row per job and retains parent lineage',()=>{
  const schema=core.match(/if\(\$sqlite\)\$pdo->exec\('CREATE TABLE IF NOT EXISTS image_generation_references \(([\s\S]*?)\)'\);/);
  assert.ok(schema,'Expected a SQLite reference table DDL');
  const db=new DatabaseSync(':memory:');
  db.exec('CREATE TABLE image_generation_references ('+schema[1].replaceAll("\\'","'")+')');
  const insert=db.prepare('INSERT INTO image_generation_references(job_id,source_asset_id,parent_job_id,source_sha256,consent_admin_id,consent_at,purpose,created_at) VALUES(?,?,?,?,?,?,?,?)');
  const hash='a'.repeat(64);
  insert.run(100,20,90,hash,3,'2026-11-02T12:00:00Z','reference_variation','2026-11-02T12:00:00Z');
  assert.equal(db.prepare('SELECT parent_job_id FROM image_generation_references WHERE job_id=100').get().parent_job_id,90);
  assert.throws(()=>insert.run(100,21,91,hash,3,'now','reference_variation','now'),/UNIQUE constraint failed/);
  db.close();
});

test('A source must be a local, hash-verified, approved managed Media Library image',()=>{
  const candidate=core.slice(core.indexOf('function sf_image_gen_reference_candidate'),core.indexOf('function sf_image_gen_draft'));
  assert.match(candidate,/sf_media_asset\(\$assetId\)/);
  assert.match(candidate,/sf_media_guard_generated_image_approval\(\$assetId\)/);
  assert.match(candidate,/\['image\/png','image\/jpeg','image\/webp'\]/);
  assert.match(candidate,/10\*1024\*1024/);
  assert.match(candidate,/realpath\(SF_ROOT\.'\/storage\/media'\)/);
  assert.match(candidate,/str_starts_with\(\$stored,\$mediaRoot\.DIRECTORY_SEPARATOR\)/);
  assert.match(candidate,/hash_file\('sha256',\$stored\)/);
  assert.match(candidate,/hash_equals\(\$expectedHash,\$sha\)/);
  assert.doesNotMatch(candidate,/file_get_contents\(\$input\[|https?:\/\//);
});

test('Rights acknowledgement gates draft creation and is audited without exposing image bytes',()=>{
  const draft=core.slice(core.indexOf('function sf_image_gen_draft'),core.indexOf('function sf_image_gen_approve_prompt'));
  assert.match(draft,/empty\(\$input\['reference_consent'\]\)/);
  assert.match(draft,/permission to use the reference image/);
  assert.match(draft,/sf_image_gen_reference_candidate\(\$sourceAssetId\)/);
  assert.match(draft,/Draft retry conflicts with the already saved prompt or reference/);
  assert.match(endpoint,/'reference_asset_id'=>\$reference\?/);
  assert.match(endpoint,/'reference_consent_recorded'=>\(bool\)\$reference/);
  assert.doesNotMatch(endpoint,/base64_encode|source_sha256|source_path/);
});

test('Only ready generated images or ordinary images appear in reference picker',()=>{
  const list=core.slice(core.indexOf('function sf_image_gen_available_references'),core.indexOf('function sf_image_gen_reference_candidate'));
  assert.match(list,/status='ready'/);
  assert.match(list,/\$approved\[\(int\)\$asset\['id'\]\]/);
  assert.match(list,/sf_media_list\('image'/);
  assert.match(endpoint,/'references'=>sf_image_gen_available_references\(\)/);
  assert.match(ui,/referenceAssets=r\.references\|\|\[\]/);
});

test('An altered source is rejected before a daily paid attempt is reserved',()=>{
  const reserve=core.slice(core.indexOf('function sf_image_gen_reserve_job'),core.indexOf('function sf_image_gen_request'));
  assert.ok(reserve.indexOf('sf_image_gen_reference_candidate')>=0);
  assert.ok(reserve.indexOf('sf_image_gen_reference_candidate')<reserve.indexOf('$pdo->beginTransaction()'));
  assert.match(reserve,/status='generating'.*status='approved'/);
  assert.match(reserve,/Daily paid-generation limit reached/);
});

test('Provider switches to one image edit with a base64 data URL only for an approved reference',()=>{
  const request=core.slice(core.indexOf('function sf_image_gen_request'),core.indexOf('function sf_image_gen_image_bytes'));
  assert.match(request,/v1\/images\/generations/);
  assert.match(request,/v1\/images\/edits/);
  assert.match(request,/if\(\$reference\)/);
  assert.match(request,/\$payload\['images'\]=\[\['image_url'=>'data:'/);
  assert.match(request,/base64_encode\(\$bytes\)/);
  assert.match(request,/'n'=>1/);
  assert.match(request,/sf_ai_provider_secret\('openai'\)/);
  assert.doesNotMatch(request,/CURLOPT_FOLLOWLOCATION=>true|retry\(/);
});

test('Reference originals cannot be removed while any variation depends on them',()=>{
  const del=mediaCore.slice(mediaCore.indexOf('function sf_media_delete_asset'),mediaCore.indexOf('function sf_media_list'));
  assert.match(del,/SELECT COUNT\(\*\) FROM image_generation_references WHERE source_asset_id=\?/);
  assert.ok(del.indexOf('image_generation_references')<del.indexOf('DELETE FROM media_assets'));
  assert.match(del,/Preserve it to keep variation history intact/);
});

test('Studio and Media Library expose source handoff, explicit consent, preview and revision comparisons',()=>{
  assert.match(ui,/id="imagegenReferenceSelect"/);
  assert.match(ui,/id="imagegenReferenceConsent"/);
  assert.match(ui,/reference_consent=f\.get\('reference_consent'\)==='on'/);
  assert.match(ui,/data-imagegen-vary/);
  assert.match(ui,/imagegen-variation-pair/);
  assert.match(ui,/parent_job_id/);
  assert.match(media,/data-media-vary/);
  assert.match(media,/SFImageGenerationAdmin\?\.openWithDraft/);
  assert.match(css,/\.imagegen-reference-preview/);
  assert.match(css,/@media\(max-width:680px\)\{\.imagegen-reference/);
  assert.doesNotMatch(css,/overflow-y:scroll|overflow-y:auto/);
});

test('Switching images resets consent and Media Library checks eligibility before handoff',()=>{
  const switchHandler=ui.slice(ui.indexOf("$('#imagegenReferenceSelect').addEventListener('change'"),ui.indexOf("$('#imagegenBackMedia').onclick"));
  assert.match(switchHandler,/\$\('#imagegenReferenceConsent'\)\.checked=false/);
  assert.match(switchHandler,/pendingDraft=null/);
  const handoff=media.slice(media.indexOf("$$('[data-media-vary]'"),media.indexOf("$$('[data-media-jump]'"));
  assert.match(handoff,/api\('image-generation\.php'\)/);
  assert.match(handoff,/available\.references/);
  assert.match(handoff,/Approve generated images in AI Image Studio first/);
});

test('Reference edit still needs separate prompt, paid request, generated-image and publish approvals',()=>{
  assert.match(ui,/Approve exact prompt/);
  assert.match(ui,/Generate · paid API request/);
  assert.match(ui,/Approve image for Media Library use/);
  assert.match(ui,/This does not publish it/);
  assert.match(endpoint,/if\(empty\(\$body\['confirmed'\]\)\)/);
  assert.match(endpoint,/'public_visible'=>false/);
  assert.doesNotMatch(core,/sf_media_attach\(/);
});

test('Dashboard presents variation activity, request limits and honest cost disclosure',()=>{
  const dash=read('api/image-studio-presets.php');
  assert.match(dash,/reference_variations/);
  assert.match(dash,/variation_attempts_30d/);
  assert.match(ui,/Provider billing is separate from customer Active Tokens/);
  assert.match(ui,/Dollar cost depends on the provider/);
  assert.match(ui,/variation attempts/);
});
