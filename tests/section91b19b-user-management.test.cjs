'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {DatabaseSync}=require('node:sqlite');
const root=path.resolve(__dirname,'..');
const read=name=>fs.readFileSync(path.join(root,name),'utf8');
const users=read('admin/api/users.php');
const ent=read('api/entitlements.php');
const report=read('api/user-usage-report.php');
const ui=read('admin/assets/admin.js');
const brain=read('api/agent-admin-brain.php');
const tools=read('api/agent-tool-registry.php');
const skills=read('api/agent-skill-registry.php');
test('User Management API retains administrator authentication and CSRF path',()=>{
  assert.match(users,/sf_admin_require_auth\(\$method !== 'GET'\)/);
  assert.match(read('admin/api/bootstrap.php'),/sf_require_user\(true,\$write\)/);
});
test('Admin-created users require validated unique emails',()=>{
  assert.match(users,/FILTER_VALIDATE_EMAIL/);
  assert.match(users,/sf_user_by_email\(\$email\)/);
  assert.match(users,/password_hash\(bin2hex\(random_bytes\(32\)\)/);
});
test('Invitations use existing expiring one-time password setup tokens',()=>{
  assert.match(users,/sf_account_action_token_issue\(\(int\)\$user\['id'\], 'password_reset', 3600\)/);
  assert.match(users,/sf_transactional_email/);
  assert.doesNotMatch(users,/\['password'\]\s*=>/);
});
test('Administrator elevation requires explicit confirmation server-side',()=>{
  assert.match(users,/\$role === 'admin' && empty\(\$body\['confirm_admin'\]\)/);
  assert.match(users,/\$target\['role'\] !== 'admin' && empty\(\$body\['confirm_admin'\]\)/);
});
test('Disable revokes sessions and last admin remains protected',()=>{
  assert.match(users,/sf_count_active_admins\(\)<=1/);
  assert.match(users,/if \(\$status === 'disabled'\) sf_auth_revoke_all_user_sessions/);
});
test('Complimentary token grants are independent ledger events with reasons',()=>{
  assert.match(users,/\$note === ''/);
  assert.match(users,/sf_token_adjust\(\$id,\$amount,'admin_grant'/);
  assert.match(users,/\['amount'=>\$amount,'balance'=>\$balance,'reason'=>\$note,'request_id'=>\$requestId\]/);
  assert.match(users,/if\(\$applied\)\{/);
  assert.match(ent,/CREATE TABLE IF NOT EXISTS token_ledger/);
});
test('Stripe-managed subscriptions remain protected from local reassignment',()=>{
  assert.match(users,/\$managed\['provider'\].*'stripe'/);
  assert.match(users,/Change the plan through Stripe billing/);
});
test('Per-user reporting has bounded pagination and grouped provider usage',()=>{
  assert.match(users,/ORDER BY id DESC LIMIT 100 OFFSET/);
  assert.match(users,/GROUP BY provider,model/);
  assert.match(users,/SELECT id,delta,balance_after,entry_type/);
});
test('Aggregate reports are bounded, use the actual ledger, and contain no identity columns',()=>{
  assert.match(report,/in_array\(\$days,\[7,30,90\],true\)/);
  assert.match(report,/FROM token_ledger WHERE created_at>=\?/);
  assert.match(report,/low_balance/);
  assert.match(report,/read_only'=>true/);
  assert.doesNotMatch(report,/SELECT.*(?:email|display_name|password_hash)/i);
});
test('The Admin UI exposes create/manage, grants, search and user usage periods',()=>{
  assert.match(ui,/function renderCreateUser\(/);
  assert.match(ui,/function renderUserDetail\(/);
  assert.match(ui,/id="userUsageDays"/);
  assert.match(ui,/data-manage-user/);
  assert.match(ui,/Grant free tokens/);
});
test('Admin Agent user token reporting is registered and read-only',()=>{
  assert.match(tools,/admin_user_usage_summary.*'kind'=>'read'.*'scope'=>'admin'/);
  assert.match(skills,/user_usage_summary_read/);
  assert.match(brain,/sf_agent_orchestration_contract\('admin','user_usage_summary_read'\)/);
  assert.match(brain,/sf_agent_admin_user_usage_brief\(\$read\)/);
});
test('Create User voice/chat request only navigates to the protected form',()=>{
  assert.match(brain,/'create_user'=>\['view'=>'user-create'/);
  assert.match(brain,/no user was created by this conversation/);
  assert.match(read('api/agent-skill-registry.php'),/'create_user'/);
  assert.match(read('api/agent-navigation.php'),/'user-create'=>\['label'=>'Create User'/);
  assert.match(read('admin/assets/agent-canvas.js'),/'user-create':'Create User'/);
});
test('Aggregate reporting is reachable only through the existing Admin API gate',()=>{
  assert.match(users,/if \(isset\(\$_GET\['summary'\]\)\)/);
  assert.match(users,/sf_admin_user_usage_report\(\$days\)/);
  assert.match(users,/sf_admin_require_auth\(\$method !== 'GET'\)/);
  assert.match(report,/source'=>'stonefellow.admin.token-ledger'/);
});
test('Individual customer details are not forwarded to the external Admin LLM facts allowlist',()=>{
  const src=brain.slice(brain.indexOf('function sf_admin_agent_external_facts'),brain.indexOf('function sf_admin_agent_research_reply'));
  assert.doesNotMatch(src,/email|display_name|token_ledger|user_id/);
  assert.match(brain,/Across all Stonefellow accounts|user_usage_summary_read/);
});
test('Aggregate report SQL calculates grants, consumption and low balances correctly on SQLite',()=>{
  const db=new DatabaseSync(':memory:');
  db.exec(`CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);
    CREATE TABLE user_token_balances(user_id INTEGER,balance INTEGER);
    CREATE TABLE token_ledger(user_id INTEGER,delta INTEGER,entry_type TEXT,created_at TEXT,source TEXT,provider TEXT,model TEXT,input_tokens INTEGER,output_tokens INTEGER,voice_chars INTEGER);`);
  db.exec(`INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active'),(4,'user','disabled');
    INSERT INTO user_token_balances VALUES(2,250),(3,9000),(4,0);
    INSERT INTO token_ledger VALUES
    (2,500,'admin_grant','2026-11-02T00:00:00Z','admin:1:x','','',0,0,0),
    (2,1000,'monthly_grant','2026-11-02T00:00:00Z','monthly:2','','',0,0,0),
    (2,-120,'usage','2026-11-02T00:00:00Z','agent-chat','anthropic','haiku',80,40,0),
    (3,-50,'usage','2026-11-02T00:00:00Z','agent-voice','elevenlabs','voice',0,0,200);`);
  const reads=[...report.matchAll(/\$pdo->query\("([^"]+)"\)/g)].map(x=>x[1]);
  const prepares=[...report.matchAll(/\$pdo->prepare\("([\s\S]*?)"\)/g)].map(x=>x[1]);
  assert.equal(reads.length,2);assert.equal(prepares.length,3);
  const accounts=db.prepare(reads[0]).get(),low=db.prepare(reads[1]).get();
  const totals=db.prepare(prepares[0]).get('2026-10-01');
  const providers=db.prepare(prepares[1]).all('2026-10-01');
  const features=db.prepare(prepares[2]).all('2026-10-01');
  assert.equal(accounts.total,4);assert.equal(accounts.active,3);assert.equal(low['COUNT(*)'],1);
  assert.equal(totals.tokens_consumed,170);assert.equal(totals.free_tokens_granted,500);
  assert.equal(totals.package_tokens_granted,1000);assert.equal(totals.input_tokens,80);
  assert.equal(totals.voice_chars,200);assert.equal(providers.length,2);
  assert.equal(features.length,2);assert.equal(features[0].feature,'Agent Chat + Routing');assert.equal(features[0].consumed,120);
  assert.equal(features[1].feature,'Voice Synthesis');assert.equal(features[1].consumed,50);
  db.close();
});
test('Concurrent ledger writes lock the account before the balance read',()=>{
  const fn=ent.slice(ent.indexOf('function sf_token_adjust('),ent.indexOf('function sf_enroll_user('));
  assert.ok(fn.indexOf('FOR UPDATE')<fn.indexOf('SELECT balance FROM user_token_balances'));
  assert.ok(fn.indexOf('INSERT OR IGNORE INTO user_token_balances')<fn.indexOf('SELECT balance FROM user_token_balances'));
  assert.match(fn,/\$credit=in_array\(\$type,\['trial_grant','monthly_grant','admin_grant'\]/);
  assert.match(fn,/if\(\$old\)/);
  assert.match(fn,/\$applied=false/);
});
test('Token grant retries carry stable request identifiers and conflict checks',()=>{
  assert.match(users,/preg_match\('\/\^\[0-9a-f\]/);
  assert.match(users,/\$applied=false/);
  assert.match(ui,/pendingUserGrants=new Map\(\)/);
  assert.match(ui,/request_id:requestId/);
  assert.match(ui,/same\?prior\.requestId:crypto\.randomUUID\(\)/);
  assert.match(ent,/Grant request conflicts with a previous credit/);
});
test('Package assignment and monthly credit settle in a single transaction',()=>{
  const fn=ent.slice(ent.indexOf('function sf_activate_package('));
  assert.match(fn,/\$pdo->beginTransaction\(\)/);
  assert.match(fn,/\$alreadyActive=/);
  assert.match(fn,/sf_token_adjust\(\$userId,\$tokens,'monthly_grant'/);
  assert.ok(fn.indexOf('sf_token_adjust($userId,$tokens')<fn.indexOf('$pdo->commit()'));
});
test('Admin AI use is measured without decrementing unlimited balances',()=>{
  assert.match(ent,/\$admin\?0:-max\(0,\$amount\)/);
  assert.match(ent,/Unlimited admin AI interaction/);
  assert.match(read('api/agent-runtime.php'),/\$sharedSongNotes===''&&\(\$isAdmin\|\|sf_can_use_ai\(\$user,128\)\['ok'\]\)/);
});
test('Feature reporting exposes safe categories, not raw usage sources',()=>{
  assert.match(report,/Song Transcription/);
  assert.match(report,/Voice Synthesis/);
  assert.match(report,/GROUP BY feature/);
  assert.match(ui,/Usage by feature/);
  assert.match(ui,/features=j\.by_feature\|\|\[\]/);
});
test('Insufficient tokens retain local catalog and account navigation without a paid model request',()=>{
  const runtime=read('api/agent-runtime.php');
  assert.match(runtime,/if\(!\$allow\['ok'\]\)\{/);
  assert.match(runtime,/provider'=>'local-entitlement'/);
  assert.match(runtime,/browse the published catalog, use the local player controls/);
  assert.match(runtime,/decision'=>'local_tokens_required','action'=>\['type'=>'none'\]/);
});
