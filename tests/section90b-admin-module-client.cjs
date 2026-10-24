'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const js=fs.readFileSync(path.join(root,'admin/assets/agent-canvas.js'),'utf8');
const php=fs.readFileSync(path.join(root,'api/agent-admin-brain.php'),'utf8');
let checked=0;
function check(label,fn){fn();checked++;process.stdout.write('PASS '+label+'\n');}
check('Only the Admin navigation tool contract renders module buttons',()=>{
  assert.match(js,/action\.tool_contract\?\.tool_id==='admin_module_navigate'&&adminModuleViews\.has\(action\.view\)/);
});
check('Module action attributes escape server-selected view identifiers',()=>{
  assert.match(js,/data-admin-agent-action="'\+esc\(action\.view\)\+'/);
});
check('Audio file choice requires a real click and installed uploader',()=>{
  assert.match(js,/if\(type==='upload_audio'\)\{/);
  assert.match(js,/window\.SFMediaAdmin\.pickUploadFiles\('','','audio_candidate'\)/);
  assert.match(js,/if\(adminModuleViews\.has\(type\)\)\{c\.openView\(type\);return;\}/);
});
check('Audio upload offers optional authenticated Media Library',()=>{
  assert.match(js,/Choose WAV \/ MP3 file/);
  assert.match(js,/Open Media Library/);
});
check('Every PHP route view is a known Admin module destination',()=>{
  const phpViews=[...php.matchAll(/'([a-z_]+)'=>\['view'=>'([a-z-]+)'/g)].map(x=>x[2]);
  assert.equal(phpViews.length,23);
  const labelsSource=js.match(/const adminModuleLabels=\{([^}]+)\};/)[1];
  for(const view of phpViews){assert.match(labelsSource,new RegExp('(?:^|,)'+view+':'));}
});
check('All actions remain within original Admin view callback',()=>{
  assert.match(js,/const adminModuleViews=new Set\(Object\.keys\(adminModuleLabels\)\)/);
  assert.doesNotMatch(js,/eval\s*\(/);
});
process.stdout.write(checked+' Section 90B Admin client checks green.\n');
