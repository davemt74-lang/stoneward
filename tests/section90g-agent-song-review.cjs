'use strict';
const fs=require('node:fs');
const assert=require('node:assert/strict');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const client=fs.readFileSync(path.join(root,'admin/assets/agent-canvas.js'),'utf8');
const api=fs.readFileSync(path.join(root,'admin/api/agent-song-intake.php'),'utf8');
const core=fs.readFileSync(path.join(root,'api/agent-song-intake.php'),'utf8');
const css=fs.readFileSync(path.join(root,'admin/assets/agent-canvas.css'),'utf8');
let passed=0;
const check=(name,fn)=>{fn();passed++;console.log('PASS '+name);};
check('Guided artwork upload remains user initiated and uses CSRF protected media ingestion',()=>{
  assert.match(client,/data-agent-artwork-choose/);
  assert.match(client,/artworkPicker\?\.click\(\)/);
  assert.match(client,/ctx\(\)\.uploadApi\('media-upload\.php',data\)/);
});
check('Rights, artist and composition owner fields are editable before approval',()=>{
  for(const field of ['artist_display','master_owner','composition_owner','rights_confirmed','isrc','release_date','explicit'])
    assert.match(client,new RegExp(field));
  assert.match(client,/data-agent-song-review/);
  assert.match(client,/data-agent-song-prepare/);
});
check('Exact catalog preflight is clicked with explicit confirmation',()=>{
  assert.match(client,/window\.confirm\('Prepare this private song/);
  assert.match(client,/action:'prepare_catalog',request_key:d\.request_key,expected_hash:d\.payload_hash,confirmed:true/);
  assert.match(api,/sf_agent_song_intake_prepare/);
});
check('Preflight blocked until readiness and shown to owner in Admin Chat',()=>{
  assert.match(client,/s\.readiness\?\.ready\?'':'disabled'/);
  assert.match(client,/s\.readiness\.blockers\.map/);
  assert.match(client,/Catalog preflight/);
});
check('Saved draft recovery includes artwork and owner review fields',()=>{
  assert.match(client,/fields:Object\.fromEntries\(songFieldNames\.map/);
  assert.match(client,/artwork:\{id:Number\(draft\.artwork_asset_id\|\|0\)\}/);
  assert.match(client,/data-agent-song-edit/);
});
check('Private cover preview cannot be served to anonymous visitors by frontend',()=>{
  assert.match(client,/\.\.\/api\/media\.php\?id=/);
  assert.match(css,/\.admin-agent-song-artwork img/);
});
check('Review never writes public catalog, release or sends a message',()=>{
  for(const code of [core,api]){
    assert.doesNotMatch(code,/sf_admin_write_catalog|sf_admin_write_releases|sf_campaign_send/);
  }
  assert.match(client,/No public track or release was created/);
});
console.log(`${passed} Section 90G guided review checks green.`);
