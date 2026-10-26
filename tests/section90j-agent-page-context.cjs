'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const runtime=read('api/agent-runtime.php');
const navigation=read('api/agent-navigation.php');
const publicUi=read('assets/js/app.js');
const adminUi=read('admin/assets/agent-canvas.js');
const adminBrain=read('api/agent-admin-brain.php');
const adminShell=read('admin/assets/admin.js');
const skills=read('api/agent-skill-registry.php');
const adminApi=read('admin/api/agent-chat.php');
let passed=0;
function check(label,fn){fn();passed++;console.log('PASS '+label);}
check('Explicit context and details override advisory LLM route',()=>{
  assert.match(runtime,/sf_agent_page_context_request\(\$message\)\?'page_context'/);
  assert.match(runtime,/sf_agent_detail_request\(\$message\)!==null\?'page_detail'/);
});
check('Public and Admin skills declare read-only contextual intents',()=>{
  assert.match(skills,/'page_navigate','page_detail','page_context'/);
  assert.match(skills,/'navigate_admin_page','open_catalog_record','page_context'/);
});
check('Only publicly published track and release records are eligible',()=>{
  assert.match(runtime,/sf_agent_public_catalog\(\):sf_agent_public_releases_for_navigation\(\)/);
  assert.match(runtime,/sf_seo_public_release\(\$id\)/);
});
check('Detail navigation uses an existing internal view and validated ID',()=>{
  assert.match(runtime,/\['type'=>'open_view','view'=>\$detail\['kind'\],'id'=>\$detail\['id'\]\]/);
  assert.match(publicUi,/case'open_view':navigate\(a\.view\|\|'home'/);
});
check('Client forwards route identifier without full page contents or private data',()=>{
  assert.match(publicUi,/route_id:history\.state\?\.params\?\.id\|\|u\.searchParams\.get\('id'\)\|\|''/);
  assert.match(runtime,/\['active_track_id'=>180,'route_id'=>180,'view'=>40/);
});
check('Unknown titles never fall through to unguarded external navigation',()=>{
  assert.match(runtime,/case 'page_detail':/);
  assert.match(runtime,/\$detail\['status'\]/);
  assert.doesNotMatch(navigation,/window\.location|header\('Location|eval\(/);
});
check('Admin page context flows through authenticated request only',()=>{
  assert.match(adminUi,/client_state:\{view:c\.app\?\.view\|\|'agent-chat'\}/);
  assert.match(adminApi,/sf_admin_require_auth\(\$write\)/);
  assert.match(adminApi,/\$b\['client_state'\]/);
  assert.match(adminBrain,/sf_agent_admin_page_context\(\$client\)/);
});
check('Admin page context performs no mutation and uses installed allowlist',()=>{
  assert.match(adminBrain,/\$action=\['type'=>'none','view'=>\$page\['view'\]/);
  assert.match(navigation,/sf_agent_admin_page_destinations\(\)/);
});
check('Admin exact song opens the existing editor with contract and no save',()=>{
  assert.match(adminBrain,/sf_agent_admin_catalog_editor_request\(\$message\)/);
  assert.match(adminBrain,/sf_agent_exact_title_matches\(\$catalogTitle,sf_catalog\(\)\)/);
  assert.match(adminBrain,/\$contract\['tool_id'\]==='admin_module_navigate'/);
  assert.match(adminBrain,/'type'=>'open_catalog_record'/);
  assert.match(adminUi,/c\.openCatalogTrack\?\.\(r\.action\.track_id\)/);
  assert.match(adminShell,/function openCatalogTrack\(id\)/);
  assert.match(adminShell,/renderCatalogEditor\(track\.id\)/);
});
console.log(`${passed} Section 90J browser checks green.`);
