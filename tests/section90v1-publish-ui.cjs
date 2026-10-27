'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const read=p=>fs.readFileSync(path.join(__dirname,'..',p),'utf8');
const admin=read('admin/assets/admin.js');
const core=read('api/media-catalog-intake.php');
const endpoint=read('admin/api/media-catalog.php');
const gallery=read('admin/assets/media.js');
const shell=read('admin/index.php');
assert(!shell.includes('id="openAdminAgentChat"')&&!admin.includes("$('#openAdminAgentChat').onclick"),
  'Redundant header chat button is absent; bottom chat controls remain.');
assert(shell.includes('id="adminChatPopupToggle"')&&shell.includes('id="adminNotificationsButton"'),
  'Bottom Agent Chat and header notifications are preserved.');
assert(admin.includes('catalogPublishDigital')&&admin.includes('catalogPublishVinyl')
  &&admin.includes('catalogPublishListening'),
  'One Catalog review offers explicit streaming, digital, vinyl, and listening-copy controls.');
assert(admin.includes("uploadApi('media-upload.php',upload)")
  &&admin.includes('preview_asset_id:previewId'),
  'Review can upload the public MP3 and publish without a second page.');
assert(admin.includes('d.payload_hash=current.payload_hash'),
  'Failed public media preflight refreshes the saved revision for a same-dialog retry.');
assert(endpoint.includes('preview_asset_id')&&endpoint.includes('digital_purchase_enabled')
  &&endpoint.includes("$fields['pod_eligible']"),
  'Server receives reviewed usage choices and the separate preview.');
assert(core.includes('sf_media_catalog_transcoded_preview')
  &&core.includes("'-b:a','128k'")&&core.includes('sf_media_protected_master_asset'),
  'Protected sources require a separately transcoded or uploaded listening copy.');
assert(gallery.includes('digital_purchase_enabled')&&gallery.includes('auto_publish'),
  'Media Gallery intake exposes usage and opt-in publishing.');
console.log('Section 90V1 unified publishing UI and privacy: PASS');
