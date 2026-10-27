const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');
const admin=read('admin/assets/admin.js');
const media=read('admin/assets/media.js');
const api=read('admin/api/media-catalog.php');
const core=read('api/media-catalog-intake.php');
assert(admin.includes('id="catalogQuickUpload"')&&admin.includes('function openCatalogQuickUpload()'),
  'Catalog provides a direct Upload Song flow');
for(const name of ['audio','title','artist_display','words_by','music_by','master_owner','composition_owner',
  'streaming','digital_purchase_enabled','pod_eligible','rights_confirmed']){
  assert(admin.includes('name="'+name+'"'),name+' is collected in one flow');
}
assert(admin.includes("uploadApi('media-upload.php',fd)")&&admin.includes("action:'intake_asset',simple_upload:true"),
  'Direct upload feeds the existing protected Catalog writer');
assert(admin.includes('form.elements[name].disabled=!enabled')&&admin.includes("enabled?'Publish song':'Save private song'"),
  'Private-only choice does not quietly publish audio or enable offers');
assert(admin.includes('if(result.publication===\'published\')')&&admin.includes('openCatalogPublishReview(draft)'),
  'Success shows a real receipt; recoverable failures open existing review');
assert(api.includes("'id'=>'quick-song'")&&api.includes("!empty($body['simple_upload'])"),
  'A single song does not require a preconfigured metadata template');
assert(media.includes('Quick song · no template needed')&&media.includes('simple_upload:!d.get'),
  'Media Library also routes songs through the simple Catalog path');
assert(core.includes('sf_media_catalog_remux_mp3_preview')&&core.includes('tagless-public-mp3-v1')
  &&core.includes('sf_media_protected_master_asset')
  &&core.includes('stream_copy_to_stream'),
  'Protected masters get a distinct tagless MP3 on hosts without FFmpeg');
assert(!admin.includes('window.confirm(\'Publish'),
  'New Catalog quick upload does not use a browser-native publish alert');
console.log('Section 90Z simple music publishing UI and privacy routes: PASS');
