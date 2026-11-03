'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {DatabaseSync}=require('node:sqlite');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const presets=read('api/image-studio-presets.php');
const api=read('admin/api/image-generation.php');
const ui=read('admin/assets/image-generation.js');
const media=read('admin/assets/media.js');
const chat=read('api/agent-admin-brain.php');
const agentUI=read('admin/assets/agent-canvas.js');
test('Image Studio operations dashboard returns live job status, daily budget and model counts',()=>{
  assert.match(presets,/function sf_image_studio_dashboard\(/);
  assert.match(presets,/GROUP BY status/);
  assert.match(presets,/WHERE started_at>=\?/);
  assert.match(presets,/model_activity_30d/);
  assert.match(presets,/today_remaining/);
  assert.match(api,/'dashboard'=>sf_image_studio_dashboard\(\)/);
});
test('Seven named built-in presets cover music, Artist World, merch, marketing and site imagery',()=>{
  for(const slug of ['album_cover','song_moment','artist_world','social_promo','artist_portrait','merch_art','site_hero'])
    assert.match(presets,new RegExp("\\['"+slug+"'",'m'));
  assert.match(presets,/function sf_image_studio_seed_presets\(/);
  assert.match(presets,/INSERT OR IGNORE/);
  assert.match(presets,/INSERT IGNORE/);
});
test('Custom presets require Admin-authenticated CSRF and may not alter built-ins',()=>{
  assert.match(api,/\$me=sf_admin_require_auth\(\$write\)/);
  assert.match(api,/if\(\$action==='save_preset'\)/);
  assert.match(api,/if\(\$action==='archive_preset'\)/);
  assert.match(api,/if\(empty\(\$body\['confirmed'\]\)\)/);
  assert.match(presets,/UPDATE image_studio_presets SET is_active=0.*is_builtin=0 AND is_active=1/);
  assert.match(presets,/random_bytes\(8\)/);
});
test('Agent prompt refinement is text-only, manually initiated, and tracked separately',()=>{
  assert.match(api,/if\(\$action==='compose_prompt'\)/);
  assert.match(presets,/sf_ai_llm_candidates\('agent_brain'\)/);
  assert.match(presets,/sf_openai_call\(/);
  assert.match(presets,/sf_anthropic_call\(/);
  assert.match(presets,/sf_consume_ai\(\$admin,.*'image-prompt-composer'\)/);
  assert.doesNotMatch(presets,/sf_image_gen_request\(/);
  assert.doesNotMatch(presets,/sf_image_gen_complete\(/);
});
test('Local preset prompt composition makes no provider calls',()=>{
  const start=presets.indexOf('function sf_image_studio_local_prompt(');
  const end=presets.indexOf('function sf_image_studio_agent_prompt(',start);
  const fn=presets.slice(start,end);
  assert.match(fn,/Creative brief:/);
  assert.match(fn,/Visual direction:/);
  assert.doesNotMatch(fn,/sf_openai_call|sf_anthropic_call|sf_image_gen_request/);
});
test('Admin Chat image request is a proposal-only handoff before generic LLM planning',()=>{
  assert.match(chat,/require_once __DIR__\.'\/image-studio-presets.php'/);
  assert.match(chat,/sf_image_studio_chat_handoff\(\$message\)/);
  assert.match(chat,/'type'=>'image_prompt_draft'/);
  assert.match(chat,/'status'=>'proposal_only'/);
  const reply=chat.slice(chat.indexOf('function sf_admin_agent_reply('));
  assert.ok(reply.indexOf('sf_image_studio_chat_handoff(')<reply.indexOf('sf_admin_agent_llm_plan('));
  assert.doesNotMatch(reply.slice(0,reply.indexOf('sf_admin_agent_llm_plan(')),/sf_image_gen_request\(/);
});
test('Agent UI presents reviewable prompt handoff in full chat and popup',()=>{
  assert.match(agentUI,/data-agent-image-draft/);
  assert.match(agentUI,/function bindImageStudioDrafts\(/);
  assert.match(agentUI,/openWithDraft\?\.\(draft\)/);
  assert.match(ui,/function openWithDraft\(draft\)/);
  assert.match(ui,/Admin Agent handoff received/);
});
test('Media Library, track artwork and universal media editors link to same Image Studio',()=>{
  assert.match(media,/id="mediaOpenImageStudio"/);
  assert.match(media,/id="mediaGeneratedOnly"/);
  assert.match(media,/id="trackCreateAiArtwork"/);
  assert.match(media,/data-entity-media-ai/);
  assert.match(media,/openWithDraft\?\.\(/);
  assert.match(media,/state\.aiOnly\?state\.assets\.filter/);
});
test('Central Admin dashboard includes image approval stats and shortcut',()=>{
  const admin=read('admin/assets/admin.js');
  assert.match(admin,/id="sfImageStudioDashboard"/);
  assert.match(admin,/SFImageGenerationAdmin\?\.dashboard\?\.\(/);
  assert.match(ui,/id="imagegenDashboardOpen"/);
  assert.match(ui,/c\.needs_review/);
});
test('Image Studio keeps separate approval and generation gates',()=>{
  assert.match(ui,/Save prompt draft/);
  assert.match(ui,/Approve exact prompt/);
  assert.match(ui,/Generate · paid API request/);
  assert.match(ui,/Approve image for Media Library use/);
  assert.match(ui,/This does not publish it/);
  assert.match(api,/sf_image_gen_reserve_job\(/);
  assert.match(api,/sf_image_gen_approve_asset\(/);
});
test('Refreshing image history preserves unsaved concept and editable prompt',()=>{
  assert.match(ui,/let pendingDraft=null,busy=false,incomingBrief=null,composerCache=null/);
  assert.match(ui,/if\(composerCache\)\{/);
  assert.match(ui,/const rememberComposer=\(\)=>/);
  assert.match(ui,/field\.addEventListener\('input',rememberComposer\)/);
  assert.match(ui,/composerCache=null;await refresh\(\);render\(\)/);
});
test('Prompt refinement and image generation usage are distinct reporting categories',()=>{
  for(const file of ['admin/api/users.php','api/user-usage-report.php']){
    assert.match(read(file),/WHEN source='image-generation' THEN 'Image Generation'/);
    assert.match(read(file),/WHEN source='image-prompt-composer' THEN 'Image Prompt Refinement'/);
  }
  assert.match(presets,/SELECT source,COUNT\(\*\) calls/);
  assert.match(ui,/provider-reported tokens/);
});
test('SQLite preset schema enforces uniqueness and protects saved built-in records',()=>{
  const db=new DatabaseSync(':memory:');
  const schema=presets.match(/if\(\$sqlite\)\$pdo->exec\("(CREATE TABLE IF NOT EXISTS image_studio_presets \([\s\S]*?\))"\);/);
  assert.ok(schema,'Expected SQLite preset schema');
  db.exec(schema[1]);
  const insert=db.prepare('INSERT INTO image_studio_presets(slug,name,category,guidance,default_size,default_quality,is_builtin,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,1,1,?,?)');
  insert.run('album_cover','Album Cover','Music','Cinematic concept and negative space without text','1024x1024','medium','now','now');
  assert.throws(()=>insert.run('album_cover','Duplicate','Music','Duplicated builtin description 12345678','1024x1024','medium','now','now'));
  db.prepare('INSERT INTO image_studio_presets(slug,name,category,guidance,default_size,default_quality,is_builtin,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,0,1,?,?)')
    .run('custom-1234567890abcd','My look','Custom','My custom production guidelines over 30 characters.','1536x1024','medium','now','now');
  const archive=db.prepare('UPDATE image_studio_presets SET is_active=0,updated_at=? WHERE id=? AND is_builtin=0 AND is_active=1');
  assert.equal(archive.run('later',1).changes,0);
  assert.equal(archive.run('later',2).changes,1);
  assert.equal(archive.run('later',2).changes,0);
  assert.equal(db.prepare('SELECT is_active FROM image_studio_presets WHERE id=1').get().is_active,1);
  db.close();
});
test('Image Studio is responsive without introducing nested scrolling panels',()=>{
  const css=read('admin/assets/image-generation.css');
  assert.match(css,/@media\(max-width:680px\)/);
  assert.match(css,/@media\(max-width:420px\)/);
  assert.match(css,/grid-template-columns:repeat\(auto-fit/);
  assert.doesNotMatch(css,/overflow-y:scroll|overflow-y:auto/);
});
