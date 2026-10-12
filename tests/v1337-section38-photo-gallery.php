<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok38($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/gallery-core.php';

ok38(sf_gallery_slug('Live in Phoenix — 2026!')==='live-in-phoenix-2026','gallery slugs normalize safely');
$core=file_get_contents($root.'/api/gallery-core.php');
$publicApi=file_get_contents($root.'/api/galleries.php');
$adminApi=file_get_contents($root.'/admin/api/galleries.php');
$galleryJs=file_get_contents($root.'/admin/assets/gallery.js');
$galleryCss=file_get_contents($root.'/admin/assets/gallery.css');
$mediaCore=file_get_contents($root.'/api/media-core.php');
$mediaLinks=file_get_contents($root.'/api/media-links.php');
$mediaApi=file_get_contents($root.'/admin/api/media.php');
$mediaJs=file_get_contents($root.'/admin/assets/media.js');
$adminMain=file_get_contents($root.'/admin/assets/admin.js');
$adminShell=file_get_contents($root.'/admin/index.php');
$app=file_get_contents($root.'/assets/js/app.js');
$css=file_get_contents($root.'/assets/css/site.css');
$shell=file_get_contents($root.'/stonefellow-v120.php');
$ht=file_get_contents($root.'/.htaccess');
$seo=file_get_contents($root.'/api/seo-core.php');
$agent=file_get_contents($root.'/api/agent-runtime.php');
$boot=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

foreach(['photo_galleries','photo_gallery_events'] as $table)ok38(substr_count($core,'CREATE TABLE IF NOT EXISTS '.$table)===2,'gallery schema supports SQLite and MySQL for '.$table);
ok38(str_contains($core,"status,['draft','published','archived']")||str_contains($core,"['draft','published','archived'],true"),'gallery lifecycle is bounded to Draft, Published and Archived');
ok38(str_contains($core,'Save this gallery as Draft before publishing'),'gallery must exist before publication so managed media can be attached');
ok38(str_contains($core,'Publish exactly one public cover image.')&&str_contains($core,'Publish at least three gallery photos.'),'gallery readiness requires one cover and at least three public photos');
ok38(str_contains($core,'1200×600')&&str_contains($core,'Every public cover/photo requires alt text.'),'gallery readiness enforces usable image dimensions and alt text');
ok38(str_contains($core,'photographer/creator credit'),'missing photographer credit is surfaced as a warning');
ok38(str_contains($core,'Linked release, show and story must currently be public.'),'gallery linked context must be currently public');
ok38(str_contains($core,"if(\$publicOnly&&((\$r['status']??'')!=='published'||empty(\$r['readiness']['ready'])))return null"),'public gallery resolver blocks draft/archived/unready galleries');
ok38(str_contains($core,"\$allowed=['view','photo_open','entity_open']"),'gallery analytics only record real view/photo/entity interactions');
ok38(!str_contains($core,'impression_score')&&!str_contains($core,'email_open'),'gallery module invents no engagement signals');
ok38(str_contains($core,'sf_gallery_agent_context')&&str_contains($core,'sf_gallery_public_list()'),'Agent gallery context is built only from public galleries');

ok38(str_contains($publicApi,'count($attempts)>=90')&&str_contains($publicApi,'sf_gallery_public($id)'),'public gallery events are rate-limited and require a public gallery');
ok38(str_contains($adminApi,'Changing gallery visibility requires explicit Admin confirmation.'),'gallery publication/archive transitions require Admin confirmation');
ok38(str_contains($adminApi,'sf_log_admin_action')&&str_contains($adminApi,'sf_agent_brain_log'),'gallery Admin changes feed audit and Agent Brain');
ok38(!str_contains($adminApi,"'notes'=>\$")&&!str_contains($adminApi,"summary'=>\$raw"),'Admin audit/Brain does not copy private gallery notes');

ok38(str_contains($mediaCore,"'photo_gallery'"),'central Media Library accepts photo-gallery relationships');
ok38(str_contains($mediaCore,"['cover','photo']")&&str_contains($mediaCore,"category']??'')!=='image'"),'gallery Media Library relationships accept only image assets in cover/photo roles');
ok38(str_contains($mediaCore,'Photo gallery not found.'),'Media Library refuses orphan gallery relationships');
ok38(str_contains($mediaLinks,"entityType==='photo_gallery'")&&str_contains($mediaLinks,'sf_gallery_get((int)$entityId,true)'),'public media-links refuses draft/unready gallery media');
ok38(str_contains($mediaApi,"'photo_gallery'=>['cover']"),'gallery cover publication uses the universal primary-media path');
ok38(str_contains($mediaApi,"['video_feature','photo_gallery']")&&str_contains($mediaApi,'Changing governed public media visibility requires explicit Admin confirmation.'),'gallery public/download visibility is confirmation-governed');
ok38(str_contains($mediaApi,'Photo gallery media role must be cover or photo.'),'Admin media API prevents arbitrary gallery roles');
ok38(str_contains($mediaJs,"$$('[data-store-media]',canvas).forEach")&&str_contains($mediaJs,"$$('[data-generic-public]',box).forEach")&&str_contains($mediaJs,"$$('[data-generic-download]',box).forEach"),'universal Media Library collection selectors use the collection helper');
ok38(str_contains($mediaJs,"['video_feature','photo_gallery'].includes(entityType)")&&str_contains($mediaJs,'confirmed'),'gallery visibility confirmation reaches the server');
ok38(str_contains($mediaJs,'photoGalleriesPanel')&&str_contains($mediaJs,'SFPhotoGalleriesAdmin'),'Photo Galleries are embedded in Media Library');

ok38(str_contains($galleryJs,'New gallery')&&str_contains($galleryJs,'Manage photos'),'Admin gallery editor creates galleries and opens universal media manager');
ok38(str_contains($galleryJs,"roles:['cover','photo']")&&str_contains($galleryJs,"publishRoles:['cover']"),'gallery Admin reuses cover/photo Media Library roles');
ok38(str_contains($galleryJs,'READY TO PUBLISH')&&str_contains($galleryJs,'NEEDS ATTENTION'),'gallery Admin visibly surfaces readiness');
ok38(str_contains($galleryJs,"confirm('Change this gallery status to"),'gallery Admin confirms visibility-state changes');
ok38(str_contains($galleryCss,'.gallery-admin-row')&&str_contains($galleryCss,'.gallery-readiness'),'gallery Admin has dedicated responsive styling');
ok38(substr_count($adminShell,'data-view="')===29,'Photo Galleries do not add another Admin sidebar item');
ok38(str_contains($adminShell,'assets/gallery.css')&&str_contains($adminShell,'assets/gallery.js'),'gallery Admin assets load from the shared shell');
ok38(str_contains($adminMain,'photo galler|photo archive|visual archive'),'Admin Agent routes gallery work into Media Library');

ok38(str_contains($app,'async function renderPhotos')&&str_contains($app,'async function renderPhotoGallery'),'public app has Photo hub and gallery detail renderers');
ok38(str_contains($app,"view==='photo'&&slug")&&str_contains($app,"new URL('photos/'+slug"),'SPA uses canonical /photos/{slug} route');
ok38(str_contains($app,"['music','releases','shows','store','about','archive','videos','photos']"),'SPA treats /photos as a canonical public hub');
ok38(str_contains($app,'openGalleryLightbox')&&str_contains($app,"aria-modal','true")&&str_contains($app,"e.key==='Escape'"),'photo detail has keyboard-aware modal lightbox');
ok38(str_contains($app,'alt_text')&&str_contains($app,'Photo: ')&&str_contains($app,'copyright'),'public gallery renders accessibility text, credits and copyright metadata');
ok38(str_contains($app,"galleryEvent(Number(g.id),'view')")&&str_contains($app,"'photo_open'")&&str_contains($app,"'entity_open'"),'public gallery records truthful gallery interactions');
ok38(str_contains($app,"shareButton('photo'"),'published gallery detail exposes canonical sharing');
ok38(str_contains($css,'.photo-gallery-grid')&&str_contains($css,'.photo-lightbox'),'public Photo Hub/detail/lightbox have dedicated responsive styling');

ok38(str_contains($shell,"define('SF_BUILD', '1.3.37')")&&str_contains($shell,'href="photos" data-view="photos"'),'public shell advances 1.3.37 and exposes Photos navigation');
ok38(str_contains($shell,"'video','photo','track'")&&str_contains($shell,"'videos','photos','contact'"),'server pretty-route handoff recognizes Photo detail and Photos hub');
ok38(str_contains($ht,'^photos/([A-Za-z0-9_-]+)')&&str_contains($ht,'^photos/?$')&&str_contains($ht,'X-Stonefellow-Build "1.3.37"'),'web server routes gallery hub/detail and reports build 1.3.37');

ok38(str_contains($seo,"\$view==='photo'")&&str_contains($seo,"'@type'=>'ImageGallery'")&&str_contains($seo,"'@type'=>'ImageObject'"),'gallery detail emits ImageGallery/ImageObject structured data');
ok38(str_contains($seo,"'photos'=>'Photos'")&&str_contains($seo,"'photos'=>'0.7'"),'Photos hub has route metadata and sitemap priority');
ok38(str_contains($seo,'sf_gallery_public_list')&&str_contains($seo,"\$base.'/photos/'"),'sitemap includes only gallery-public-list detail URLs');
ok38(str_contains($agent,'sf_gallery_agent_context')&&str_contains($agent,"return 'photos_browse'")&&str_contains($agent,"case 'photos_browse'"),'public Agent has deterministic published Photo Gallery route');
ok38(!str_contains($agent,'sf_gallery_list(false)')&&!str_contains($agent,'sf_gallery_get('),'public Agent runtime has no draft-gallery read path');

ok38(str_contains($boot,"require_once __DIR__ . '/gallery-core.php'"),'gallery core loads from canonical bootstrap');
ok38(str_contains($mig,"'id'=>'2026-10-11-033'")&&str_contains($mig,'sf_gallery_ensure_schema'),'migration 033 installs photo gallery schema');
ok38(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.32'"),'database schema advances to 1.3.32');
ok38(str_contains($mig,"'photo_galleries'")&&str_contains($mig,"'photo_gallery_events'"),'upgrade integrity requires both gallery tables');
ok38(str_contains($version,"'stonefellow'=>'1.3.37'")&&str_contains($version,"'database_schema_target'=>'1.3.32'"),'version endpoint reports app 1.3.37 and schema 1.3.32');
ok38(str_contains($version,"'photo_gallery'=>'single-artist-public-gallery-managed-media-visual-archive'"),'version endpoint advertises the single-artist photo gallery');
ok38(str_contains($wf,'node --check admin/assets/gallery.js')&&str_contains($wf,'php tests/v1337-section38-photo-gallery.php'),'release gate includes Gallery Admin JS and Section 38 suite');

echo "Stonefellow v1.3.37 Section 38 Photo Gallery & Visual Archive audit: PASS\n";
)&&str_contains($ht,'X-Stonefellow-Build "1.3.37"'),'web server routes gallery hub/detail and reports build 1.3.37');

ok38(str_contains($seo,"\$view==='photo'")&&str_contains($seo,"'@type'=>'ImageGallery'")&&str_contains($seo,"'@type'=>'ImageObject'"),'gallery detail emits ImageGallery/ImageObject structured data');
ok38(str_contains($seo,"'photos'=>'Photos'")&&str_contains($seo,"'photos'=>'0.7'"),'Photos hub has route metadata and sitemap priority');
ok38(str_contains($seo,'sf_gallery_public_list')&&str_contains($seo,"\$base.'/photos/'"),'sitemap includes only gallery-public-list detail URLs');
ok38(str_contains($agent,'sf_gallery_agent_context')&&str_contains($agent,"return 'photos_browse'")&&str_contains($agent,"case 'photos_browse'"),'public Agent has deterministic published Photo Gallery route');
ok38(!str_contains($agent,'sf_gallery_list(false)')&&!str_contains($agent,'sf_gallery_get('),'public Agent runtime has no draft-gallery read path');

ok38(str_contains($boot,"require_once __DIR__ . '/gallery-core.php'"),'gallery core loads from canonical bootstrap');
ok38(str_contains($mig,"'id'=>'2026-10-11-033'")&&str_contains($mig,'sf_gallery_ensure_schema'),'migration 033 installs photo gallery schema');
ok38(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.32'"),'database schema advances to 1.3.32');
ok38(str_contains($mig,"'photo_galleries'")&&str_contains($mig,"'photo_gallery_events'"),'upgrade integrity requires both gallery tables');
ok38(str_contains($version,"'stonefellow'=>'1.3.37'")&&str_contains($version,"'database_schema_target'=>'1.3.32'"),'version endpoint reports app 1.3.37 and schema 1.3.32');
ok38(str_contains($version,"'photo_gallery'=>'single-artist-public-gallery-managed-media-visual-archive'"),'version endpoint advertises the single-artist photo gallery');
ok38(str_contains($wf,'node --check admin/assets/gallery.js')&&str_contains($wf,'php tests/v1337-section38-photo-gallery.php'),'release gate includes Gallery Admin JS and Section 38 suite');

echo "Stonefellow v1.3.37 Section 38 Photo Gallery & Visual Archive audit: PASS\n";
