'use strict';
const fs=require('node:fs');
const assert=require('node:assert/strict');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const client=fs.readFileSync(path.join(root,'admin/assets/agent-canvas.js'),'utf8');
const endpoint=fs.readFileSync(path.join(root,'admin/api/agent-song-intake.php'),'utf8');
const core=fs.readFileSync(path.join(root,'api/agent-song-intake.php'),'utf8');
const css=fs.readFileSync(path.join(root,'admin/assets/agent-canvas.css'),'utf8');
let count=0;
function check(name,fn){fn();count++;process.stdout.write('PASS '+name+'\n');}
check('Agent offers a one-click guided audio intake alongside original Media Library action',()=>{
  assert.match(client,/data-agent-song-start/);
  assert.match(client,/Choose WAV \/ MP3 file/);
  assert.match(client,/Open Media Library/);
});
check('Browser-owned file picker is opened by click, not by model',()=>{
  assert.match(client,/\[data-agent-song-choose\]'\)\?\.addEventListener\('click',\(\)=>picker\?\.click\(\)/);
  assert.match(client,/accept="\.wav,\.mp3,audio\/wav,audio\/mpeg"/);
});
check('Uploading reuses CSRF authenticated existing media ingestion',()=>{
  assert.match(client,/ctx\(\)\.uploadApi\('media-upload\.php',form\)/);
  assert.match(client,/form\.append\('file',file,file\.name\)/);
});
check('Metadata is reviewed, not immediately published',()=>{
  assert.match(client,/technical\?\.normalized_tags/);
  assert.match(client,/data-agent-song-form/);
  assert.match(client,/Save private song draft/);
  assert.match(client,/ctx\(\)\.api\('agent-song-intake\.php'/);
  assert.doesNotMatch(client,/ctx\(\)\.api\('finalize\.php'/);
});
check('Form fields are escaped and admin pages remain accessible',()=>{
  assert.match(client,/esc\(details\[name\]\|\|''\)/);
  assert.match(client,/function action\(type\)/);
  assert.match(client,/c\.openView\('media'\)/);
});
check('Recoverable drafts are reloaded by admin API',()=>{
  assert.match(client,/function loadSongDrafts\(\)/);
  assert.match(client,/c\.api\('agent-song-intake\.php'\)/);
  assert.match(client,/id="adminAgentSongDrafts"/);
  assert.match(client,/if\(songIntake\)showSongIntake\(\)/);
});
check('Save persists key for safe repeat and shows private receipt',()=>{
  assert.match(client,/key:newSongKey\(\)/);
  assert.match(client,/request_key:songIntake\.key/);
  assert.match(client,/No release, public catalog change, or publication occurred/);
});
check('Previously saved private drafts can be resumed with exact revision guard',()=>{
  assert.match(client,/data-agent-song-edit/);
  assert.match(client,/expectedHash:draft\.payload_hash/);
  assert.match(client,/update_draft/);
  assert.match(core,/function sf_agent_song_intake_update/);
  assert.match(core,/hash_equals\(\(string\)\(\$existing\['payload_hash'\]/);
});
check('Backend enforces authentication and never performs auto-publication',()=>{
  assert.match(endpoint,/sf_admin_require_auth\(\$method==='POST'\)/);
  assert.match(core,/status'=>'draft'/);
  assert.doesNotMatch(endpoint,/sf_admin_write_catalog|sf_admin_write_releases/);
});
check('Uploader and draft forms remain usable on narrow mobile view',()=>{
  assert.match(css,/\.admin-agent-song-fields\{display:grid/);
  assert.match(css,/minmax\(min\(220px,100%\),1fr\)/);
});
process.stdout.write(`${count} Section 90F guided upload checks green.\n`);
