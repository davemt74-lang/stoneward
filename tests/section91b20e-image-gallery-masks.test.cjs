'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {DatabaseSync}=require('node:sqlite');
const read=p=>fs.readFileSync(path.join(__dirname,'..',p),'utf8');
const core=read('api/image-generation-core.php');
const api=read('admin/api/image-generation.php');
const dash=read('api/image-studio-presets.php');
const media=read('api/media-core.php');
const ui=read('admin/assets/image-generation.js');
const css=read('admin/assets/image-generation.css');

test('SQLite image options retain background, mask hash, consent and a unique job key',()=>{
  const match=core.match(/if\(\$sqlite\)\$pdo->exec\('CREATE TABLE IF NOT EXISTS image_generation_options \(([\s\S]*?)\)'\);/);
  assert.ok(match,'Image options SQLite DDL must be present');
  const db=new DatabaseSync(':memory:');
  db.exec('CREATE TABLE image_generation_options ('+match[1].replaceAll("\\'","'")+')');
  const q=db.prepare('INSERT INTO image_generation_options(job_id,background,mask_asset_id,mask_sha256,mask_consent_admin_id,mask_consent_at,created_at) VALUES(?,?,?,?,?,?,?)');
  q.run(1,'transparent',21,'a'.repeat(64),2,'2026-11-02T10:00:00Z','2026-11-02T10:00:00Z');
  const options=db.prepare('SELECT * FROM image_generation_options WHERE job_id=1').get();
  assert.equal(options.background,'transparent');assert.equal(options.mask_asset_id,21);assert.equal(options.mask_consent_admin_id,2);
  assert.throws(()=>q.run(1,'opaque',null,null,null,null,'later'),/UNIQUE constraint failed/);
  db.close();
});

test('Background selection is an immutable one-job setting and only documented options are accepted',()=>{
  const draft=core.slice(core.indexOf('function sf_image_gen_draft'),core.indexOf('function sf_image_gen_approve_prompt'));
  assert.match(draft,/\['auto','opaque','transparent'\]/);
  assert.match(draft,/INSERT INTO image_generation_options\(job_id,background,mask_asset_id,mask_sha256,mask_consent_admin_id,mask_consent_at,created_at\)/);
  assert.match(draft,/\(string\)\$options\['background'\]!==\$background/);
  assert.match(ui,/name="background"/);
  assert.match(ui,/Transparent · PNG\/WebP output/);
});

test('Mask requires approved local PNG reference, matching geometry, and alpha-channel edit areas',()=>{
  const mask=core.slice(core.indexOf('function sf_image_gen_mask_candidate'),core.indexOf('function sf_image_gen_reference_candidate'));
  assert.match(mask,/sf_image_gen_reference_candidate\(\$assetId,\$expectedHash\)/);
  assert.match(mask,/image\/png/);
  assert.match(mask,/getimagesize\(\$candidate\['path'\]\)/);
  assert.match(mask,/getimagesize\(\$reference\['path'\]\)/);
  assert.match(mask,/\$info\[0\].*\$sourceInfo\[0\]/);
  assert.match(mask,/\$info\[1\].*\$sourceInfo\[1\]/);
  assert.match(mask,/ord\(\$header\[25\]\),\[4,6\]/);
  assert.doesNotMatch(mask,/https?:\/\/|\$input\['path'\]/);
});

test('Masks require separate explicit usage permission and cannot exist without a reference',()=>{
  const draft=core.slice(core.indexOf('function sf_image_gen_draft'),core.indexOf('function sf_image_gen_approve_prompt'));
  assert.match(draft,/\$maskAssetId>0&&\$sourceAssetId===0/);
  assert.match(draft,/\$maskAssetId>0&&empty\(\$input\['mask_consent'\]\)/);
  assert.match(draft,/sf_image_gen_mask_candidate\(\$maskAssetId,\$reference\)/);
  assert.match(ui,/name="mask_consent"/);
  assert.match(ui,/mask_asset_id=Number\(f\.get\('mask_asset_id'\)/);
});

test('Changed masks are rejected before locking a billed daily attempt',()=>{
  const reserve=core.slice(core.indexOf('function sf_image_gen_reserve_job'),core.indexOf('function sf_image_gen_request'));
  assert.match(reserve,/sf_image_gen_options\(\$id\)/);
  assert.match(reserve,/sf_image_gen_mask_candidate\(\(int\)\$options\['mask_asset_id'\],\$verified,\(string\)\$options\['mask_sha256'\]\)/);
  assert.ok(reserve.indexOf('sf_image_gen_mask_candidate')<reserve.indexOf('$pdo->beginTransaction()'));
  assert.match(media,/SELECT COUNT\(\*\) FROM image_generation_options WHERE mask_asset_id=\?/);
});

test('Provider request supports transparent output and a single edit-region mask without automatic retries',()=>{
  const request=core.slice(core.indexOf('function sf_image_gen_payload'),core.indexOf('function sf_image_gen_reconcile_cost'));
  assert.match(request,/sf_image_gen_payload\(\$job,\$adminId,\(string\)\(\$options\['background'\]/);
  assert.match(request,/'background'=>\$background/);
  assert.match(request,/'output_format'=>'webp'/);
  assert.match(request,/v1\/images\/edits/);
  assert.match(request,/\$payload\['mask'\]=\['image_url'=>'data:image\/png;base64,'\.base64_encode\(\$maskBytes\)\]/);
  assert.match(request,/'n'=>1/);
  assert.doesNotMatch(request,/retry\(|CURLOPT_FOLLOWLOCATION=>true/);
});

test('Source consent never automatically transfers after switching references or masks',()=>{
  const referenceSwitch=ui.slice(ui.indexOf("$('#imagegenReferenceSelect').addEventListener('change'"),ui.indexOf("$('#imagegenReferenceSearch').oninput"));
  assert.match(referenceSwitch,/imagegenReferenceConsent'\)\.checked=false/);
  assert.match(referenceSwitch,/imagegenMaskConsent'\)\.checked=false/);
  assert.match(referenceSwitch,/imagegenMaskSelect'\)\.addEventListener\('change'/);
  assert.match(ui,/pendingDraft\.mask_asset_id!==mask_asset_id/);
});

test('Gallery groups variations by parent job, with source search and approval-aware reuse',()=>{
  assert.match(ui,/function galleryHtml\(/);
  assert.match(ui,/function rootFor|const rootFor=/);
  assert.match(ui,/while\(n\.parent_job_id\)/);
  assert.match(ui,/data-imagegen-gallery-group/);
  assert.match(ui,/data-imagegen-gallery-card/);
  assert.match(ui,/imagegenGallerySearch/);
  assert.match(ui,/imagegenGalleryFilter/);
  assert.match(ui,/function selectVariationAsset/);
  assert.match(ui,/job\.status==='ready'&&job\.media_asset_id/);
});

test('Comparison slider is keyboard accessible, responsive and does not invoke the provider',()=>{
  const compare=ui.slice(ui.indexOf('function compareInGallery'),ui.indexOf('function selectVariationAsset'));
  assert.match(compare,/role="dialog" aria-modal="true"/);
  assert.match(compare,/type="range" min="0" max="100"/);
  assert.match(compare,/setProperty\('--reveal'/);
  assert.match(compare,/e\.key==='Escape'/);
  assert.match(compare,/e\.key==='Tab'/);
  assert.doesNotMatch(compare,/action:'generate'|sf_image_gen_request/);
  assert.match(css,/\.imagegen-compare-after\{clip-path:/);
  assert.match(css,/@media\(max-width:680px\)\{\.imagegen-gallery-toolbar/);
  assert.doesNotMatch(css,/overflow-y:auto|overflow-y:scroll/);
});

test('Searchable reference chooser limits thumbnails and works without a nested scrolling panel',()=>{
  const browser=ui.slice(ui.indexOf('function renderReferenceBrowser'),ui.indexOf('updateReference();'));
  assert.match(browser,/referenceQuery\.trim\(\)\.toLowerCase\(\)/);
  assert.match(browser,/matches\.slice\(0,18\)/);
  assert.match(browser,/data-reference-choice/);
  assert.match(browser,/dispatchEvent\(new Event\('change'\)\)/);
  assert.match(css,/\.imagegen-reference-tiles\{display:grid/);
});

test('Cost audit requires a previously started terminal job, real USD input and an invoice note',()=>{
  const reconcile=core.slice(core.indexOf('function sf_image_gen_reconcile_cost'),core.indexOf('function sf_image_gen_image_bytes'));
  assert.match(reconcile,/\['ready','needs_review','failed'\]/);
  assert.match(reconcile,/empty\(\$job\['started_at'\]\)/);
  assert.match(reconcile,/\$cents<0\|\|\$cents>1000000/);
  assert.match(reconcile,/Enter an invoice ID or reconciliation note/);
  assert.match(api,/if\(\$action==='reconcile_cost'\)/);
  assert.match(api,/empty\(\$body\['confirmed'\]\)/);
  assert.match(api,/image_cost_reconciled/);
  assert.doesNotMatch(reconcile,/sf_consume_ai|sf_image_gen_request|sf_media_attach/);
});

test('Cost review table supports durable manual invoice revisions without duplicate job rows',()=>{
  const match=core.match(/if\(\$sqlite\)\$pdo->exec\('CREATE TABLE IF NOT EXISTS image_generation_cost_reviews \(([\s\S]*?)\)'\);/);
  assert.ok(match,'Cost review SQLite DDL required');
  const db=new DatabaseSync(':memory:');db.exec('CREATE TABLE image_generation_cost_reviews ('+match[1].replaceAll("\\'","'")+')');
  db.prepare('INSERT INTO image_generation_cost_reviews(job_id,actual_cost_cents,currency,verified_by,verified_at,invoice_reference,updated_at) VALUES(?,?,?,?,?,?,?)')
    .run(3,99,'USD',1,'2026-11-02T12:00:00Z','Invoice NOV-02','2026-11-02T12:00:00Z');
  assert.throws(()=>db.prepare('INSERT INTO image_generation_cost_reviews(job_id,actual_cost_cents,currency,verified_by,verified_at,invoice_reference,updated_at) VALUES(?,?,?,?,?,?,?)').run(3,2,'USD',1,'x','x','x'),/UNIQUE constraint failed/);
  db.prepare('UPDATE image_generation_cost_reviews SET actual_cost_cents=? WHERE job_id=?').run(79,3);
  assert.equal(db.prepare('SELECT actual_cost_cents FROM image_generation_cost_reviews WHERE job_id=3').get().actual_cost_cents,79);
  db.close();
});

test('Billing dashboard distinguishes manually recorded invoices from provider-reported token totals',()=>{
  assert.match(dash,/cost_reconciled_jobs/);
  assert.match(dash,/cost_unreconciled_jobs/);
  assert.match(dash,/cost_recorded_cents/);
  assert.match(ui,/not a provider-sourced total/);
  assert.match(ui,/not fabricated dollar amounts/);
  assert.match(ui,/imagegenExportAudit/);
  assert.match(ui,/stonefellow-image-generation-audit\.csv/);
});

test('Invoice CSV escapes spreadsheet formulas and does not reveal keys or image bytes',()=>{
  const csv=ui.slice(ui.indexOf("$('#imagegenExportAudit').onclick"),ui.indexOf("$$('[data-imagegen-preset]'"));
  assert.match(csv,/\^\[\\s\]\*\[=\+\\-@\]/);
  assert.match(csv,/replaceAll\('"','""'\)/);
  assert.match(csv,/new Blob\(\[csv\]/);
  assert.doesNotMatch(csv,/secret_payload|Authorization: Bearer|b64_json|mask_sha256/);
});

test('Existing prompt approval, paid generation, asset approval and private placement remain distinct',()=>{
  assert.match(ui,/Approve exact prompt/);
  assert.match(ui,/Generate · paid API request/);
  assert.match(ui,/Approve image for Media Library use/);
  assert.match(api,/if\(\$action==='stage_placement'\)/);
  assert.match(api,/'public_visible'=>false/);
  assert.doesNotMatch(core,/sf_media_attach\(/);
});
