'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const read=p=>fs.readFileSync(path.join(__dirname,'..',p),'utf8');
const admin=read('admin/assets/admin.js');
const endpoint=read('admin/api/media-catalog.php');
const writer=read('api/media-catalog-intake.php');
const css=read('admin/assets/admin.css');

assert(admin.includes('function openCatalogPublishReview(d)')
    &&admin.includes('catalogPublishOverlay')&&admin.includes('catalogPublishForm'),
    'Catalog publication opens an explicit review dialog instead of a blind confirmation.');
for(const field of ['title','artist_display','words_by','music_by','master_owner','composition_owner'])
    assert(admin.includes(`['${field}'`),'Review includes '+field);
assert(admin.includes("if(missing.length)")&&admin.includes('catalogPublishError'),
    'Missing metadata and server errors stay visibly inside the publication form.');
assert(admin.includes('catalogPublishRights')&&admin.includes('rights_confirmed:true,confirmed:true')
    &&endpoint.includes("if(empty($body['confirmed'])||empty($body['rights_confirmed']))"),
    'Publishing requires explicit rights consent in UI and server.');
assert(endpoint.includes("if($action==='review_publish_draft')")
    &&endpoint.includes('sf_agent_song_intake_normalize($fields,$audio,$owner,$artwork)')
    &&endpoint.includes('sf_agent_song_intake_update($owner,$normalized,$revision)')
    &&endpoint.includes('sf_media_catalog_publish_draft($owner,$key,'),
    'Reviewed metadata saves as exact private revision before the existing Catalog writer.');
assert(endpoint.includes('hash_equals((string)$draft[\'payload_hash\'],$revision)')
    &&endpoint.includes('sf_media_catalog_authorized_asset')
    &&endpoint.includes("'created_by']=$owner"),
    'A stale or foreign draft, audio, or artwork cannot be published.');
assert(writer.includes('function sf_media_catalog_missing_fields(array $draft)')
    &&writer.includes('Complete publication review:'),
    'Server and UI agree on actionable missing-field reasons.');
assert(admin.includes('Publication is committed. A refresh failure must never report a false publishing failure.')
    &&admin.includes('catalogPublishResult')&&admin.includes('Open published track'),
    'A successful publication has a durable visible receipt independent of optional page refresh.');
assert(endpoint.includes('function sf_media_catalog_record_success(')
    &&endpoint.includes('catch(Throwable $e){error_log('),
    'Optional Brain / audit telemetry failures cannot negate successful publishing.');
assert(css.includes('.catalog-publish-backdrop')&&css.includes('.catalog-publish-success'),
    'Publication dialog and verification receipt have dedicated responsive styling.');
console.log('Section 90V review / publish, failure reporting and success receipt: PASS');
