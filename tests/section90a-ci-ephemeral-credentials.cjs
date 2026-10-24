'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const workflow=fs.readFileSync(path.join(__dirname,'../.github/workflows/release-gate.yml'),'utf8');
const job=workflow.split('  mysql-certification:')[1]||'';
assert.ok(job,'Isolated MySQL certification job must exist');
assert.ok(job.includes('openssl rand -hex 24'),'Both CI credentials must be generated per job');
assert.ok(job.includes('sf_ci_db_password="$(openssl rand -hex 24)"'));
assert.ok(job.includes('sf_ci_root_password="$(openssl rand -hex 24)"'));
assert.ok(job.includes('::add-mask::$sf_ci_db_password'));
assert.ok(job.includes('::add-mask::$sf_ci_root_password'));
assert.ok(job.includes('--publish 127.0.0.1:3306:3306'),'Disposable database port is loopback-only');
assert.ok(job.includes('>> "$GITHUB_ENV"'),'Tests receive ephemeral job-scoped credentials');
assert.ok(job.includes('docker rm --force stonefellow-ci-mysql'),'Disposable service is cleaned up');
assert.ok(!/^[ \t]*(?:MYSQL_PASSWORD|MYSQL_ROOT_PASSWORD|SF_E2_DB_PASS):[ \t]*[^$\s][^\r\n]*$/m.test(job),
  'No hardcoded workflow database credentials may be committed');
console.log('Section 90A CI credentials: 10/10 checks passed');
