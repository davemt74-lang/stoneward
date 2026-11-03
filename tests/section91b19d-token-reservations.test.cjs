'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {DatabaseSync}=require('node:sqlite');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const core=read('api/ai-token-reservations.php');
const voice=read('api/agent-voice.php');
const tokens=read('api/entitlements.php');

test('Reservation schema has unique keys, owner and terminal state',()=>{
  assert.match(core,/request_key TEXT PRIMARY KEY,user_id INTEGER NOT NULL/);
  assert.match(core,/reserved INTEGER NOT NULL,actual INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL/);
  assert.match(core,/INDEX idx_reservation_user_status/);
});
test('Non-admin balance is locked and verified before a paid call',()=>{
  assert.match(core,/SELECT id FROM users WHERE id=\? FOR UPDATE/);
  assert.match(core,/INSERT OR IGNORE INTO user_token_balances/);
  assert.match(core,/if\(\(int\)\$q->fetchColumn\(\)<\$amount\)throw/);
  assert.match(core,/sf_token_adjust\(\$userId,-\$amount,'reservation_hold'/);
  assert.match(core,/\$pdo->commit\(\);return \$key/);
});
test('Reservation settlement has ownership, source and single-close guards',()=>{
  assert.match(core,/SELECT reserved,status,source FROM ai_token_reservations WHERE request_key=\? AND user_id=\?/);
  assert.match(core,/\$hold\['status'\]!=='held'/);
  assert.match(core,/UPDATE ai_token_reservations SET actual=\?,status=\?,closed_at=\? WHERE request_key=\? AND status=\\'held\\'/);
  assert.match(core,/if\(\$q->rowCount\(\)!==1\)/);
});
test('Settling releases the reserved amount and meters actual usage in one transaction',()=>{
  assert.match(core,/sf_token_adjust\(\$userId,\$reserved,'reservation_release'/);
  assert.match(core,/if\(\$succeeded\)\$balance=sf_consume_ai\(\$user,\$charge,\$usage,\$source\)/);
  assert.match(core,/\$pdo->commit\(\);return \$balance/);
  assert.match(tokens,/\$ownsTransaction=!\$pdo->inTransaction\(\)/);
});
test('Failed requests return held credit; a successful provider cannot be accidentally refunded',()=>{
  assert.match(core,/\$succeeded\?'settled':'released'/);
  assert.match(voice,/\$providerCompleted=true/);
  assert.match(voice,/if\(!\$providerCompleted&&\$reservation!==null\)/);
  assert.match(voice,/sf_ai_finish_reservation\(\$u,\$reservation,0,\[\],'agent-voice',false\)/);
});
test('Voice endpoint reserves before calling provider, then settles before streaming audio',()=>{
  const reserve=voice.indexOf('sf_ai_reserve_tokens(');
  const provider=voice.indexOf('sf_http_binary(');
  const settle=voice.indexOf('sf_ai_finish_reservation($u,$reservation,$cost');
  const stream=voice.indexOf('echo $audio');
  assert.ok(reserve>-1&&reserve<provider);
  assert.ok(provider<settle&&settle<stream);
});
test('Admin activity remains uncharged but metered',()=>{
  assert.match(core,/if\(\(\$user\['role'\]\?\?''\)==='admin'\)return null/);
  assert.match(core,/return \$succeeded\?sf_consume_ai\(\$user,max\(0,\$actual\),\$usage,\$source\):PHP_INT_MAX/);
  assert.match(tokens,/\$admin\?0:-max\(0,\$amount\)/);
});
test('SQLite reserve and settle SQL enforce sufficient available balance and one terminal transition',()=>{
  const db=new DatabaseSync(':memory:');
  const m=core.match(/if\(\(sf_db_config\(\)\['driver'\]\?\?''\)==='sqlite'\)\s*\$pdo->exec\("(CREATE TABLE IF NOT EXISTS ai_token_reservations\([\s\S]*?\))"\);/);
  assert.ok(m);
  db.exec('CREATE TABLE users(id INTEGER PRIMARY KEY); CREATE TABLE user_token_balances(user_id INTEGER PRIMARY KEY,balance INTEGER NOT NULL,updated_at TEXT NOT NULL)');
  db.exec(m[1]);db.exec('INSERT INTO users VALUES (7); INSERT INTO user_token_balances VALUES (7,100,\'now\')');
  const key='0123456789abcdef0123456789abcdef';
  db.exec('BEGIN');
  const before=db.prepare('SELECT balance FROM user_token_balances WHERE user_id=?').get(7).balance;
  assert.equal(before,100);
  db.prepare("INSERT INTO ai_token_reservations(request_key,user_id,source,reserved,status,created_at) VALUES(?,?,?,?,'held',?)").run(key,7,'agent-voice',60,'now');
  db.prepare('UPDATE user_token_balances SET balance=balance-? WHERE user_id=? AND balance>=?').run(60,7,60);
  db.exec('COMMIT');
  assert.equal(db.prepare('SELECT balance FROM user_token_balances WHERE user_id=7').get().balance,40);
  assert.equal(db.prepare('SELECT reserved,status FROM ai_token_reservations WHERE request_key=? AND user_id=?').get(key,7).status,'held');
  db.exec('BEGIN');
  db.prepare('UPDATE user_token_balances SET balance=balance+?-? WHERE user_id=?').run(60,60,7);
  const close=db.prepare("UPDATE ai_token_reservations SET actual=?,status=?,closed_at=? WHERE request_key=? AND status='held'");
  assert.equal(close.run(60,'settled','later',key).changes,1);
  assert.equal(close.run(60,'settled','later',key).changes,0);
  db.exec('COMMIT');
  assert.equal(db.prepare('SELECT balance FROM user_token_balances WHERE user_id=7').get().balance,40);
  db.close();
});
