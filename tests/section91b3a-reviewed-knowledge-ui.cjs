'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const modal=read('admin/assets/agent-knowledge-review.js');
const chat=read('admin/assets/agent-canvas.js');
const shell=read('admin/index.php');
const endpoint=read('admin/api/agent-actions.php');
let passed=0;function ok(label,fn){fn();passed++;console.log('PASS '+label);}
ok('Admin shell loads reviewed knowledge note component',()=>assert.match(shell,/agent-knowledge-review\.js/));
ok('Agent Chat renders a real review button from an exact draft',()=>assert.match(chat,/data-agent-knowledge-review/));
ok('Review button dispatches only to review component',()=>assert.match(chat,/SFAgentKnowledgeReview\?\.open\?\./));
ok('Modal uses registered exact-review tool',()=>assert.match(modal,/tool_id:'knowledge_note_update'/));
ok('Three separate prepare, approve and execute API steps',()=>{
  assert.match(modal,/action:'prepare'/);assert.match(modal,/action:'approve'/);assert.match(modal,/action:'execute'/);
});
ok('Owner types exact approval phrase',()=>assert.match(modal,/placeholder="APPROVE"/));
ok('Server-normalized payload and exact hash displayed before approval',()=>{
  assert.match(modal,/JSON\.stringify\(proposal\.payload,null,2\)/);
  assert.match(modal,/proposal\.payload_hash/);
});
ok('No direct Knowledge Base mutation from review component',()=>{
  assert.doesNotMatch(modal,/api\('knowledge\.php'/);
  assert.doesNotMatch(modal,/fetch\(/);
});
ok('Uncertain execution is never automatically retried',()=>assert.match(modal,/no automatic retry/));
ok('Existing action endpoint requires Admin auth',()=>assert.match(endpoint,/sf_admin_require_auth\(\$method!==\'GET\'\)/));
console.log(`Section 91B3A UI: ${passed}/10 passed`);
