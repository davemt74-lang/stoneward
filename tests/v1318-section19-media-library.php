<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(!defined('SF_ROOT'))define('SF_ROOT',$root);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=120): string {$s=trim((string)$v);$s=preg_replace('/[\x00-\x1F\x7F]/u','',$s)??'';return substr($s,0,$max);}}
require $root.'/api/media-core.php';

$tmp=sys_get_temp_dir().'/sf-media-test-'.bin2hex(random_bytes(4));mkdir($tmp,0770,true);

// One-second 44.1kHz, 16-bit, stereo PCM WAV.
$data=str_repeat("\0",176400);
$wav='RIFF'.pack('V',36+strlen($data)).'WAVE'.'fmt '.pack('V',16).pack('vvVVvv',1,2,44100,176400,4,16).'data'.pack('V',strlen($data)).$data;
$wavPath=$tmp.'/one-second.wav';file_put_contents($wavPath,$wav);
$wa=sf_media_wav_fallback($wavPath);
ok(abs((float)$wa['duration']-1.0)<0.01,'WAV fallback extracts one-second duration');
ok((int)$wa['sample_rate']===44100,'WAV fallback extracts 44.1 kHz sample rate');
ok((int)$wa['channels']===2,'WAV fallback extracts stereo channel count');
ok((int)$wa['bits']===16,'WAV fallback extracts 16-bit depth');
ok((int)$wa['bitrate']===1411200,'WAV fallback derives PCM bitrate');
$wd=sf_media_detect_type($wavPath,'one-second.wav');
ok($wd['category']==='audio'&&$wd['extension']==='wav','WAV signature/MIME validation accepts real RIFF WAV');

// Synthetic constant-bitrate MPEG-1 Layer III stream, about one second, plus ID3v1.
$mp3Audio="\xFF\xFB\x90\x64".str_repeat("\0",15996);
$id3='TAG'.str_pad('Test Song',30,"\0").str_pad('Stonefellow',30,"\0").str_pad('Test Album',30,"\0").str_pad('2026',4,"\0").str_pad('fallback parser',30,"\0").chr(0);
$mp3Path=$tmp.'/one-second.mp3';file_put_contents($mp3Path,$mp3Audio.$id3);
$ma=sf_media_mp3_fallback($mp3Path);
ok(abs((float)$ma['duration']-1.0)<0.05,'MP3 fallback estimates duration from MPEG frame bitrate');
ok((int)$ma['bitrate']===128000,'MP3 fallback extracts 128 kbps bitrate');
ok((int)$ma['sample_rate']===44100,'MP3 fallback extracts 44.1 kHz sample rate');
ok((int)$ma['channels']===2,'MP3 fallback extracts channel mode');
ok(($ma['technical']['normalized_tags']['title']??'')==='Test Song','MP3 fallback reads ID3v1 title');
ok(($ma['technical']['normalized_tags']['artist']??'')==='Stonefellow','MP3 fallback reads ID3v1 artist');
$md=sf_media_detect_type($mp3Path,'one-second.mp3');
ok($md['category']==='audio'&&$md['extension']==='mp3','MP3 signature/MIME validation accepts MPEG audio');

$core=file_get_contents($root.'/api/media-core.php');$boot=file_get_contents($root.'/api/bootstrap.php');$mig=file_get_contents($root.'/api/migrations.php');$adminUpload=file_get_contents($root.'/admin/api/media-upload.php');$adminApi=file_get_contents($root.'/admin/api/media.php');$delivery=file_get_contents($root.'/api/media.php');$linksApi=file_get_contents($root.'/api/media-links.php');$adminJs=file_get_contents($root.'/admin/assets/admin.js');$agent=file_get_contents($root.'/api/agent-runtime.php');$mediaJs=file_get_contents($root.'/admin/assets/media.js');$mediaCss=file_get_contents($root.'/admin/assets/media.css');$app=file_get_contents($root.'/assets/js/app.js');$siteCss=file_get_contents($root.'/assets/css/site.css');$finalize=file_get_contents($root.'/admin/api/finalize.php');$adminShell=file_get_contents($root.'/admin/index.php');$version=file_get_contents($root.'/version.php');$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/media-core.php'"),'Media Library core loads from canonical bootstrap');
preg_match("/SF_DB_SCHEMA_TARGET = '([0-9]+)\.([0-9]+)\.([0-9]+)'/",$mig,$db19);
ok(isset($db19[1],$db19[2],$db19[3])&&[(int)$db19[1],(int)$db19[2],(int)$db19[3]]>=[1,3,15],'database schema remains v1.3.15 or later');
ok(str_contains($mig,"'id'=>'2026-10-09-016'")&&str_contains($mig,'sf_media_ensure_schema')&&str_contains($mig,'sf_media_backfill_catalog'),'migration 016 installs Media Library schema and backfills existing catalog media');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS media_assets')===2,'media_assets schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS media_links')===2,'media_links schema supports SQLite and MySQL');
ok(str_contains($core,'UNIQUE(sha256,size_bytes)')||str_contains($core,'uq_media_hash_size'),'media assets deduplicate by SHA-256 and size');

ok(str_contains($adminUpload,'sf_media_ingest_upload')&&str_contains($adminUpload,'sf_admin_require_auth(true)'),'central media uploader is authenticated and uses the Media Library ingest pipeline');
ok(str_contains($core,'finfo')&&str_contains($core,'RIFF')&&str_contains($core,'ID3'),'uploader includes MIME plus MP3/WAV signature validation');
ok(str_contains($core,'1024*1024*1024'),'application upload guard caps individual media at 1 GB before server limits');
ok(str_contains($core,'function sf_media_capabilities')&&str_contains($core,'upload_max_filesize'),'Media Library reports analyzer and server upload capabilities');
ok(str_contains($core,'function sf_media_ffprobe')&&str_contains($core,'function sf_media_wav_fallback')&&str_contains($core,'function sf_media_mp3_fallback'),'audio extraction supports FFprobe plus WAV and MP3 fallbacks');
ok(str_contains($core,'embedded_artwork'),'audio analysis records embedded artwork presence when detectable');
ok(str_contains($core,'function sf_media_generate_variants')&&str_contains($core,"'thumb'=>320")&&str_contains($core,"'medium'=>800")&&str_contains($core,"'large'=>1600"),'image pipeline creates thumbnail, medium and large derivatives when GD is available');

ok(str_contains($delivery,'HTTP_RANGE')&&str_contains($delivery,'Accept-Ranges: bytes'),'controlled media delivery supports HTTP Range requests for seeking');
ok(str_contains($delivery,"MAX(public_visible)")&&str_contains($delivery,"MAX(download_allowed)"),'public viewing and downloading are separately permissioned');
ok(str_contains($delivery,"realpath(SF_ROOT.'/storage/media')"),'media delivery is confined to managed media storage');
ok(str_contains($linksApi,'sf_media_public_links'),'public song media endpoint returns only explicitly public links');

ok(str_contains($adminShell,'data-view="media"')&&str_contains($adminShell,'assets/media.js'),'Media Library is a first-class Admin module');
ok(str_contains($mediaJs,'Upload audio')&&str_contains($mediaJs,'Upload artwork')&&str_contains($mediaJs,'Upload photos')&&str_contains($mediaJs,'Upload video')&&str_contains($mediaJs,'Upload documents')&&str_contains($mediaJs,'Upload archive media'),'song Media workspace covers all requested media categories');
ok(str_contains($mediaJs,'Choose from Library'),'song Media workspace can reuse existing assets');
ok(str_contains($mediaJs,'Make primary audio')&&str_contains($mediaJs,'Make primary artwork'),'song Media workspace separates upload from explicit primary publish actions');
ok(str_contains($mediaJs,'data-link-public')&&str_contains($mediaJs,'data-link-download')&&str_contains($mediaJs,'data-link-featured'),'song media supports public, downloadable and featured controls');
ok(str_contains($mediaJs,'ondragstart')&&str_contains($mediaJs,'reorderDrop'),'song media supports drag reordering');
ok(str_contains($mediaJs,'Uploading ')&&str_contains($mediaJs,'xhr.upload.onprogress'),'uploader exposes real upload progress');
ok(str_contains($mediaCss,'.track-media-groups')&&str_contains($mediaCss,'.media-library-grid'),'Media Library and song Media workspace have dedicated responsive styling');

ok(str_contains($adminJs,'id="trackMediaPanel"')&&str_contains($adminJs,'bindTrackMedia'),'catalog song editor embeds the real Media Library workspace');
ok(!str_contains($adminJs,'data-archive-media placeholder='),'legacy archive media path-entry form is removed from new song editing');
ok(str_contains($core,'function sf_media_publish_track_audio')&&str_contains($core,"role='audio_candidate'"),'primary audio publish demotes the previous primary rather than deleting it');
ok(str_contains($core,'function sf_media_publish_track_artwork')&&str_contains($core,"role='primary_artwork'"),'primary artwork publish is explicit and managed');
ok(str_contains($core,"'duration']=(int)round")||str_contains($core,"['duration']=(int)round"),'primary audio publish refreshes catalog duration from extracted metadata');
ok(str_contains($finalize,'sf_media_register_existing_file')&&str_contains($finalize,"'master_audio'")&&str_contains($finalize,"'primary_audio'"),'existing folder import pipeline now registers MP3/WAV files into the central Media Library');
ok(str_contains($core,'function sf_media_backfill_catalog')&&str_contains($core,'master_sha256')&&str_contains($core,'preview_sha256'),'upgrade backfill recovers prior importer master/preview assets when hashes are available');
ok(str_contains($core,'function sf_media_register_local_copy'),'upgrade backfill can copy legacy public song artwork/audio into managed Media Library storage');

ok(str_contains($app,'function renderPublicTrackMedia')&&str_contains($app,'id="publicTrackMedia"'),'public song detail loads its explicitly published attached media');
ok(str_contains($app,'public-song-media-grid')&&str_contains($app,'public-song-video-grid')&&str_contains($app,'public-song-audio-list'),'public song pages support image, video and alternate-audio media displays');
ok(str_contains($siteCss,'.public-track-media'),'public song media has responsive presentation styling');

ok(str_contains($adminApi,'track_primary_audio_published')&&str_contains($adminApi,'track_primary_artwork_published'),'primary media changes are timestamped in Admin audit');
ok(str_contains($adminUpload,'sf_agent_brain_log')&&str_contains($adminApi,'sf_agent_brain_log'),'media uploads and primary publishes feed Admin Agent Brain');
ok(str_contains($agent,'sf_media_public_links')&&str_contains($agent,'public media'),'public Agent context includes only explicitly public song-media relationships');

preg_match("/'stonefellow'=>'([0-9]+)\.([0-9]+)\.([0-9]+)'/",$version,$app19);
preg_match("/'database_schema_target'=>'([0-9]+)\.([0-9]+)\.([0-9]+)'/",$version,$schema19);
ok(isset($app19[1],$app19[2],$app19[3],$schema19[1],$schema19[2],$schema19[3])&&[(int)$app19[1],(int)$app19[2],(int)$app19[3]]>=[1,3,18]&&[(int)$schema19[1],(int)$schema19[2],(int)$schema19[3]]>=[1,3,15],'version endpoint reports app v1.3.18 or later and schema v1.3.15 or later');
ok(str_contains($version,"'audio_metadata'=>'ffprobe-wav-riff-mp3-frame-id3-fallback'"),'version endpoint advertises audio metadata extraction capability');
ok(str_contains($wf,'node --check admin/assets/media.js')&&str_contains($wf,'php tests/v1318-section19-media-library.php'),'release gate includes Media Library JavaScript and Section 19 regression tests');

@unlink($wavPath);@unlink($mp3Path);@rmdir($tmp);
echo "Stonefellow v1.3.18 Section 19 Media Uploads & Asset Library audit: PASS\n";
