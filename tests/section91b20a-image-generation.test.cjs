'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {DatabaseSync}=require('node:sqlite');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const core=read('api/image-generation-core.php');
const api=read('admin/api/image-generation.php');
const ui=read('admin/assets/image-generation.js');
test('Image generation requires authenticated Admin and CSRF for every write',()=>{
  assert.match(api,/\$me=sf_admin_require_auth\(\$write\)/);
  assert.match(api,/if\(empty\(\$body\['confirmed'\]\)\)/);
  assert.doesNotMatch(api,/sf_require_user\(false/);
});
test('Image generation reuses encrypted OpenAI credentials, not a client-side key',()=>{
  assert.match(core,/sf_ai_provider_secret\('openai'\)/);
  assert.match(core,/sf_ai_provider_row\('openai'\)/);
  assert.match(api,/sf_ai_provider_public\('openai'\)/);
  assert.doesNotMatch(ui,/Authorization: Bearer|api_key|secret_payload/);
});
test('OpenAI Images endpoint, current model allowlist, and supported options are explicit',()=>{
  assert.match(core,/https:\/\/api\.openai\.com\/v1\/images\/generations/);
  assert.match(core,/gpt-image-2\.5-flare/);
  assert.match(core,/gpt-image-2\.5-sunburst/);
  assert.doesNotMatch(core,/gpt-image-1'[,\]]/);
  assert.match(core,/'output_format'=>'webp'/);
  assert.match(core,/'safety_identifier'=>hash_hmac\('sha256'/);
  assert.match(core,/'moderation'=>'auto'/);
});
test('Saved prompts require a unique request key and separate paid approval',()=>{
  assert.match(core,/request_key TEXT NOT NULL UNIQUE/);
  assert.match(core,/function sf_image_gen_draft\(/);
  assert.match(core,/function sf_image_gen_approve_prompt\(/);
  assert.match(core,/status='generating'.*status='approved'/);
  assert.match(ui,/Save prompt draft/);
  assert.match(ui,/Approve exact prompt/);
  assert.match(ui,/Generate · paid API request/);
  assert.match(ui,/pendingDraft/);
});
test('The account lock and compare-and-set prevent concurrent duplicate jobs',()=>{
  assert.match(core,/SELECT role,status FROM users WHERE id=\?'.*' FOR UPDATE'/);
  assert.match(core,/UPDATE image_generation_jobs SET status='generating',started_at=\?,started_by=\?/);
  assert.match(core,/\$q->rowCount\(\)!==1/);
  assert.match(core,/Your daily paid-generation limit has been reached/);
});
test('Generated image bytes are validated, capped, and stored in private managed media',()=>{
  assert.match(core,/base64_decode\(\$base64,true\)/);
  assert.match(core,/getimagesizefromstring\(\$bytes\)/);
  assert.match(core,/strlen\(\$bytes\)>16\*1024\*1024/);
  assert.match(core,/storage\/media\/generated/);
  assert.match(core,/sf_media_register_existing_file\(/);
  assert.doesNotMatch(core,/sf_media_attach\(/);
});
test('Generated assets remain private until reviewed and are never auto-published',()=>{
  assert.match(core,/status='needs_review'/);
  assert.match(core,/function sf_image_gen_approve_asset\(/);
  assert.match(core,/status='ready',asset_approved_by=/);
  // 91B20C added a separately confirmed PRIVATE staging operation. Keep the
  // original image-generation/approval phases free of publishing actions.
  const originalPhases=api.slice(0,api.indexOf("if($action==='stage_placement')"));
  assert.doesNotMatch(originalPhases,/public_visible|publish_track|publish_release/);
  assert.match(ui,/This does not publish it/);
});
test('Existing Media Library attachment and track-publish routes enforce Image Studio approval',()=>{
  const media=read('api/media-core.php');
  assert.match(media,/function sf_media_guard_generated_image_approval\(int \$assetId\)/);
  assert.match(media,/\(\$asset\['technical'\]\['source'\]\?\?''\)!=='openai_image_generation'/);
  assert.match(media,/SELECT status FROM image_generation_jobs WHERE id=\? AND media_asset_id=\?/);
  assert.match(media,/if\(\$q->fetchColumn\(\)!=='ready'\)/);
  assert.match(media,/function sf_media_guard_link_exposure[\s\S]*?sf_media_guard_generated_image_approval\(\$assetId\)/);
  assert.match(media,/function sf_media_publish_track_artwork[\s\S]*?sf_media_guard_generated_image_approval\(\(int\)\$r\['asset_id'\]\)/);
});
test('Admin navigation, Agent page navigation and responsive layout are wired',()=>{
  assert.match(read('admin/index.php'),/data-view="image-generation"/);
  assert.match(read('admin/index.php'),/assets\/image-generation\.js/);
  assert.match(read('admin/assets/admin.js'),/SFImageGenerationAdmin\?\.open/);
  assert.match(read('api/agent-navigation.php'),/'image-generation'=>\['label'=>'AI Image Studio'/);
  assert.match(read('admin/assets/image-generation.css'),/@media\(max-width:680px\)/);
});
test('Provider usage is recorded and visible in Admin feature reporting',()=>{
  assert.match(api,/sf_consume_ai\(\$me/);
  assert.match(api,/'image-generation'\)/);
  assert.match(read('api/user-usage-report.php'),/WHEN source='image-generation' THEN 'Image Generation'/);
  assert.match(read('admin/api/users.php'),/WHEN source='image-generation' THEN 'Image Generation'/);
});
test('SQLite schema implements immutable approvals and status compare-and-set',()=>{
  const db=new DatabaseSync(':memory:');
  const schema=core.match(/if\(\$sqlite\)\$pdo->exec\("(CREATE TABLE IF NOT EXISTS image_generation_jobs \([\s\S]*?\))"\);/);
  assert.ok(schema,'SQLite job schema must be extractable');
  db.exec('CREATE TABLE users(id INTEGER PRIMARY KEY); INSERT INTO users VALUES(1),(2)');
  db.exec(schema[1]);
  db.prepare(`INSERT INTO image_generation_jobs(request_key,created_by,prompt,model,size,quality,status,usage_json,created_at,updated_at)
    VALUES(?,?,?,?,?,?,'draft','{}',?,?)`).run('b6fceea3-2c6a-491d-aa2e-fabc63271015',1,'Cinematic image concept testing strict approval','gpt-image-2.5-flare','1536x1024','medium','2026-11-02T00:00:00','2026-11-02T00:00:00');
  const approve=db.prepare("UPDATE image_generation_jobs SET status='approved',prompt_approved_by=?,updated_at=? WHERE id=? AND status='draft'");
  assert.equal(approve.run(1,'2026-11-02T00:01:00',1).changes,1);
  assert.equal(approve.run(1,'2026-11-02T00:01:01',1).changes,0);
  const run=db.prepare("UPDATE image_generation_jobs SET status='generating',started_at=?,started_by=?,updated_at=? WHERE id=? AND status='approved' AND prompt_approved_by IS NOT NULL");
  assert.equal(run.run('2026-11-02T00:02:00',2,'2026-11-02T00:02:00',1).changes,1);
  assert.equal(run.run('2026-11-02T00:02:00',1,'2026-11-02T00:02:00',1).changes,0);
  assert.throws(()=>db.prepare(`INSERT INTO image_generation_jobs(request_key,created_by,prompt,model,size,quality,status,usage_json,created_at,updated_at) VALUES(?,?,?,?,?,?,'draft','{}',?,?)`)
    .run('b6fceea3-2c6a-491d-aa2e-fabc63271015',1,'Duplicate','gpt-image-2.5-flare','1536x1024','medium','x','x'));
  const count=db.prepare('SELECT COUNT(*) n FROM image_generation_jobs WHERE started_by=? AND started_at>=?').get(2,'2026-11-02T00:00:00');
  assert.equal(count.n,1);db.close();
});
