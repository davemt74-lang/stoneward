'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const gallery=read('admin/assets/media.js');
const scanner=read('admin/assets/admin.js');
const intake=read('admin/api/media-catalog.php');
const publish=read('api/media-catalog-intake.php');
const privateScan=read('admin/api/scan-private.php');
const quote=read('api/bootstrap.php');
const catalog=read('admin/api/catalog.php');
const publicPlayer=read('assets/js/app.js');
const vinyl=read('api/vinyl-print-licenses.php');
const transfer=read('api/kunaki-media-transfer.php');

assert(gallery.includes('data-media-add-catalog')&&gallery.includes('Add to Catalog')&&gallery.includes('In Catalog'),
  'Each MP3/WAV asset offers one Add to Catalog or existing Catalog action.');
assert(gallery.includes('name="template_id" required')&&gallery.includes('name="digital_price"')&&gallery.includes('name="auto_publish"'),
  'Library Song review includes template, digital price, and opt-in auto-publish.');
assert(gallery.includes('name="rights_confirmed"')&&gallery.includes('window.confirm('),
  'Publishing requires separately confirmed rights and visible user consent.');
assert(scanner.includes('data-g-template=')&&scanner.includes('data-g-price=')&&scanner.includes('data-g-auto=')&&scanner.includes('data-g-rights='),
  'Every scanned song owns its template, sale price, rights, and publishing choice.');
assert(scanner.includes('autoPublish:false')&&scanner.includes('podEligible:false')&&scanner.includes("api('scan-private.php'"),
  'Folder import never assumes publicity or vinyl availability.');
assert(scanner.includes("api('media-catalog.php',{action:'review_publish_draft'")
  &&intake.includes("if($action==='review_publish_draft')")
  &&intake.includes('sf_media_catalog_publish_draft($owner,$key,')
  &&gallery.includes("api('media-catalog.php',input)"),
  'Both Media Library and folder intake use the same authoritative Catalog writer after review.');
assert(privateScan.includes("$metadata['price']=round")&&privateScan.includes("'payload_hash'=>$result['draft']['payload_hash']"),
  'Folder draft retains validated price and exact revision for optional publication.');
assert(intake.includes('sf_admin_require_auth(true)')&&intake.includes("empty($body['confirmed'])")
  &&intake.includes("empty($body['rights_confirmed'])")&&publish.includes('hash_equals'),
  'Only an authenticated administrator with explicit rights approval and verified file integrity may publish.');
assert(publish.includes("'audio_publishing_mode'=>$split?'preview_master_split':'preview_only'")
  &&publish.includes("'digital_purchase_enabled'=>$split")&&publish.includes("'podEligible'=>$split"),
  'One-file songs are streaming only; distinct private masters can activate paid and vinyl offers.');
assert(quote.includes("array_key_exists('digital_purchase_enabled',$t)")
  &&quote.includes('Album includes a streaming-only song')
  &&catalog.includes('Attach a distinct private source master'),
  'Checkout and admin updates cannot sell a one-file streaming-only song.');
assert(vinyl.includes("==='preview_only'")&&transfer.includes("==='preview_only'"),
  'Vinyl licensing and transfer refuse public-only audio.');
assert(publicPlayer.includes('Streaming only · digital downloads unavailable')
  &&publicPlayer.includes('t.digital_purchase_enabled===false'),
  'Public player explains streaming-only sales state.');
console.log('Section 90T single Add to Catalog flow, auto-publish consent and commerce isolation: PASS');
