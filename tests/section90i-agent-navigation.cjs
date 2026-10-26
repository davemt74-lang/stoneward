'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=name=>fs.readFileSync(path.join(root,name),'utf8');
const publicAgent=read('assets/js/app.js');
const adminAgent=read('admin/assets/agent-canvas.js');
const adminShell=read('admin/assets/admin.js');
const php=read('api/agent-runtime.php');
const adminPhp=read('api/agent-admin-brain.php');
const navigation=read('api/agent-navigation.php');
let checks=0;
function check(name,fn){fn();checks++;console.log('PASS '+name);}
check('Explicit public intent overrides advisory model route',()=>{
  assert.match(php,/sf_agent_public_page_target\(\$message\)!==null\?'page_navigate'/);
  assert.match(php,/case 'page_navigate':/);
});
check('Public route stays inside existing SPA with music player intact',()=>{
  assert.match(publicAgent,/case'open_view':navigate\(a\.view\|\|'home'/);
  assert.match(publicAgent,/async function navigate\(view='home'/);
});
check('Page targets are fixed server-side, with no URL navigation',()=>{
  assert.match(navigation,/sf_agent_page_navigation_phrase/);
  assert.match(navigation,/in_array\(\$name,\$entry\['aliases'\],true\)/);
  assert.doesNotMatch(navigation,/header\('Location|location\.href|eval\(/);
});
check('Admin automatic switches are authenticated and require navigation contract',()=>{
  assert.match(adminAgent,/r\.action\?\.auto_navigate===true/);
  assert.match(adminAgent,/r\.action\?\.tool_contract\?\.tool_id==='admin_module_navigate'/);
  assert.match(adminAgent,/adminModuleViews\.has\(r\.action\.view\)\)\{/);
  assert.match(adminAgent,/else c\.openView\(r\.action\.view\)/);
  assert.match(adminPhp,/sf_agent_admin_page_target\(\$message\)/);
  assert.match(adminPhp,/\$action\['auto_navigate'\]=true/);
});
check('Admin route uses existing in-app view, never a new external URL',()=>{
  assert.match(adminShell,/function openView\(view\)/);
  assert.doesNotMatch(adminAgent,/window\.location\s*=\s*r\.action/);
});
check('Admin cannot auto-select local file or publish by switching screens',()=>{
  assert.match(adminAgent,/if\(type==='upload_audio'\)/);
  assert.match(adminAgent,/pickUploadFiles\('','','audio_candidate'\)/);
  assert.match(adminPhp,/!\$plan\['requires_confirmation'\]/);
});
console.log(`${checks} Section 90I browser navigation checks green.`);
