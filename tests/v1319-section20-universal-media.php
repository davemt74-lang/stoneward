<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($p){global $root;return (string)file_get_contents($root.'/'.$p);}
if(!defined('SF_ROOT'))define('SF_ROOT',$root);
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {return substr(trim((string)$v),0,$max);}}
require_once $root.'/api/media-core.php';

$core=src('api/media-core.php');$mediaApi=src('admin/api/media.php');$mediaJs=src('admin/assets/media.js');$mediaCss=src('admin/assets/media.css');$adminJs=src('admin/assets/admin.js');$campaignJs=src('admin/assets/campaigns.js');$campaignCore=src('api/campaign-core.php');$store=src('api/storefront.php');$app=src('assets/js/app.js');$site=src('api/site-settings.php');$shell=src('stonefellow-v120.php');$siteCss=src('assets/css/site.css');$mig=src('api/migrations.php');$version=src('version.php');$wf=src('.github/workflows/release-gate.yml');

ok(sf_media_managed_url(['uuid'=>'MTEST'],'large')==='api/media.php?id=MTEST&variant=large','managed media URL helper produces controlled delivery URL');

ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.16'"),'database schema advances to 1.3.16');
ok(str_contains($mig,"'id'=>'2026-10-09-017'")&&str_contains($mig,'sf_media_backfill_universal'),'migration 017 backfills universal media relationships');
ok(str_contains($core,'function sf_media_backfill_universal')&&str_contains($core,"'release'")&&str_contains($core,"'show'")&&str_contains($core,"'campaign'"),'universal backfill covers releases, shows and campaigns');
ok(str_contains($core,"['cover','back','label_a','label_b','social_square','social_story']"),'release artwork package is backfilled into Media Library roles');
ok(str_contains($core,"'poster'")&&str_contains($core,"['media']"),'show poster and legacy archive media are backfilled');

ok(str_contains($adminJs,"entitySection?.('release',id")&&str_contains($adminJs,"bindEntityMedia?.('release',id"),'release editor embeds the reusable Media Library panel');
ok(str_contains($adminJs,"roles:['cover','back','label_a','label_b','social_square','social_story','photo','video','document','archive']"),'release Media panel covers artwork, photos, video, documents and archive roles');
ok(!str_contains($adminJs,'id="releaseArchiveMedia"'),'release editor no longer uses manual archive-media path entry');
preg_match("/function renderReleaseForm\(id=''\)\{(.*?)function renderReleaseTrackLists/s",$adminJs,$releaseBlock);
ok(isset($releaseBlock[1])&&!str_contains($releaseBlock[1],'releaseArtField('),'live release editor no longer uses legacy direct artwork uploader fields');

ok(str_contains($adminJs,"entitySection?.('show',id")&&str_contains($adminJs,"bindEntityMedia?.('show',id"),'show editor embeds the reusable Media Library panel');
ok(str_contains($adminJs,"roles:['poster','photo','alternate_audio','video','document','archive']"),'show Media panel covers poster, photos, live audio, video, scans and archive files');
ok(!str_contains($adminJs,'id="showPoster"')&&!str_contains($adminJs,'id="showMedia"'),'show editor no longer uses poster/media path text fields');

ok(str_contains($campaignJs,"['builder','Builder'],['media','Media']"),'Campaign Builder has a dedicated Media tab');
ok(str_contains($campaignJs,"entitySection('campaign'")&&str_contains($campaignJs,"roles:['hero','background','offer_artwork','video','document','archive']"),'campaign Media tab covers hero, background, offer artwork, video and documents');
ok(!str_contains($campaignJs,'Artwork / image path'),'campaign editor no longer asks Admin to type an artwork path');
ok(str_contains($campaignCore,"'media'=>$media")&&str_contains($campaignCore,"sf_media_public_links('campaign'"),'public campaign payload carries explicitly public Media Library assets');
ok(str_contains($app,"publicEntityMediaHtml(c.media||[],'CAMPAIGN MEDIA')"),'public campaign page renders public supporting campaign media');

ok(str_contains($mediaJs,'Store product media')&&str_contains($mediaJs,"openEntityManager('store'"),'Media Library provides store-product media management');
ok(str_contains($store,"sf_media_public_links('store'")&&str_contains($store,"'image'=>")&&str_contains($store,"primary['url']"),'storefront API resolves product imagery from Media Library');
ok(str_contains($app,'p.image')&&str_contains($app,'data-store-format'),'public store cards render managed product imagery');

ok(str_contains($adminJs,"entitySection?.('site','public'")&&str_contains($adminJs,"roles:['logo','hero','artist_photo','social_share','app_icon']"),'Settings embeds site and artist Media Library controls');
foreach(['media_logo','media_hero','media_social_share','media_app_icon','media_artist_photo'] as $key)ok(str_contains($site,"'".$key."'"),'site settings exposes '.$key);
ok(str_contains($shell,'property="og:image"')&&str_contains($shell,'rel="icon"'),'public shell uses managed social-share artwork and app icon');
ok(str_contains($shell,'splash-logo')&&str_contains($shell,'class="wordmark"'),'managed logo can replace text wordmark and splash wordmark');
ok(str_contains($siteCss,'.has-site-hero .agent-stage'),'managed site hero is applied to public Agent stage');
ok(str_contains($app,'about-artist-photo')&&str_contains($app,"media?.artist_photo"),'managed artist photo appears on public About page');

ok(str_contains($core,'function sf_media_usage')&&str_contains($mediaApi,"'usage'=>sf_media_usage"),'asset usage intelligence resolves every relationship');
ok(str_contains($mediaJs,'Used by '),'asset editor displays where each asset is used');
ok(str_contains($core,'function sf_media_completeness'),'central media completeness report exists');
foreach(['track_audio','track_artwork','release_cover','show_poster','campaign_hero','store_media','site_roles'] as $key)ok(str_contains($core,"'".$key."'"),'completeness report includes '.$key);
ok(str_contains($core,'broken_assets')&&str_contains($core,'unused_assets')&&str_contains($core,'public_private_mismatch'),'media intelligence detects broken, unused and public/private issues');
ok(str_contains($mediaJs,'Media completeness')&&str_contains($mediaJs,'NEED ATTENTION'),'Media Library renders a completeness dashboard');
ok(str_contains($mediaJs,'Managed storage')&&str_contains($mediaJs,'Unused'),'Media Library surfaces storage and unused-asset totals');
ok(str_contains($core,'Detach this asset everywhere before deleting it.'),'linked assets remain protected from destructive deletion');

ok(str_contains($mediaApi,"action==='publish_entity_image'"),'universal publish action exists');
foreach(['release','show','campaign','site','store'] as $entity)ok(str_contains($mediaApi,"'".$entity."'"),'universal media publishing supports '.$entity);
ok(str_contains($mediaApi,'sf_admin_write_releases')&&str_contains($mediaApi,'sf_admin_write_shows'),'publishing primary release/show media updates canonical records');
ok(str_contains($mediaApi,"UPDATE campaigns SET artwork="),'campaign hero publish updates canonical campaign artwork');
ok(str_contains($mediaApi,"sf_meta_set('site.media_"),'site primary media publishing updates canonical site metadata');
ok(str_contains($mediaApi,'sf_agent_brain_log')&&str_contains($mediaApi,'media_attach')&&str_contains($mediaApi,'media_detach')&&str_contains($mediaApi,'media_publish'),'attach, detach and publish actions feed Admin Agent Brain');
ok(str_contains($adminJs,'media completeness')&&str_contains($adminJs,'unused media')&&str_contains($adminJs,'broken media'),'Admin Agent routes media-intelligence requests to Media Library');

ok(str_contains($app,"renderPublicEntityMedia('release'")&&str_contains($app,"renderPublicEntityMedia('show'"),'public release and show pages load managed public media');
ok(str_contains($app,'public-entity-media'),'universal public media renderer shares the song-media presentation system');
ok(str_contains($core,'public_visible')&&str_contains($core,'download_allowed'),'universal media continues separate public-view and download permissions');

ok(str_contains($version,"'stonefellow'=>'1.3.19'")&&str_contains($version,"'database_schema_target'=>'1.3.16'"),'version endpoint reports app 1.3.19 and schema 1.3.16');
ok(str_contains($version,"'universal_media'=>'release-show-campaign-store-site-media-publishing'"),'version endpoint advertises universal media capability');
ok(str_contains($wf,'php tests/v1319-section20-universal-media.php'),'release gate includes Section 20 regression suite');

echo "Stonefellow v1.3.19 Section 20 Universal Media Integration & Publishing audit: PASS\n";
