'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const brain=read('admin/assets/agent-canvas.js');
const api=read('admin/api/activity-proof.php');
const styles=read('admin/assets/agent-canvas.css');
const shell=read('admin/index.php');
assert(brain.includes("receiptPanel.id='adminAgentProofCards'")
  &&brain.includes('transcript.appendChild(receiptPanel)')
  &&brain.includes("proof.className='admin-proof-inline'"),
  'Proof cards render within the main and popup conversation transcripts.');
assert(!brain.includes('<section class="admin-proof-preview" id="adminAgentProofCards"'),
  'The old separate pre-conversation proof preview is removed.');
assert(brain.includes("e.actor||'Administrator'")
  &&brain.includes("proposed?'Agent action · not verified as a saved record'"),
  'Agent action proposals and Admin saves use honest attribution/status labels.');
assert(brain.includes('index\\.php\\?view=(?:catalog|releases|shows|products|media|operations|brain)')
  &&brain.includes('Open verified record'),
  'Verified cards use an allowlisted link to the exact Admin record.');
assert(api.includes('sf_agent_action_recent($owner,50)')
  &&api.includes('sf_agent_proof_proposal_receipt($proposal,$catalog)')
  &&api.includes("isset($auditedProposals[$id])"),
  'Owner-scoped agent proposals join audit receipts without duplicating executions.');
assert(api.includes("(int)($entry['admin_user_id']??0)!==$owner")
  &&api.includes("'Agent tool · administrator approved'"),
  'Audit entries are owner-scoped and identify confirmed Agent tool writes.');
assert(api.includes("SUBSTR(conversation_id,1,6)='admin_'")
  &&api.includes("SUBSTR(conversation_id,1,6)='agent_'")
  &&api.includes("$decisionsQuery->execute([$owner])"),
  'Brain history selects only the signed-in administrator operational decisions.');
assert(brain.includes('function brainDecisionHtml(')
  &&brain.includes('Agent Brain decision timeline')
  &&brain.includes('these entries do not prove an action was saved.'),
  'History separates read-only Agent decisions from verified saved records.');
assert(styles.includes('.admin-proof-inline')&&styles.includes('.admin-proof-actor')
  &&shell.includes('id="adminNotificationsButton"'),
  'Conversation receipts are styled and the shared notification drawer remains accessible.');
console.log('Section 90W inline conversation history and receipt contracts: PASS');
