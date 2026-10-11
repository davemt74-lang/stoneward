<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok34($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/songbook-core.php';

ok34(sf_songbook_lines("Verse one\nLine two")===['Verse one','Line two'],'Songbook line splitter preserves canonical lyric lines');
$sections=sf_songbook_clean_sections([['label'=>'Verse 1','start_line'=>1,'end_line'=>2],['label'=>'Chorus','start_line'=>3,'end_line'=>4]],4);
ok34(count($sections)===2&&$sections[1]['label']==='Chorus','Songbook section normalization keeps valid ordered ranges');
ok34(sf_songbook_clean_sections([['label'=>'Bad','start_line'=>5,'end_line'=>6]],4)===[],'Songbook section normalization rejects ranges outside canonical lyrics');
$anns=sf_songbook_clean_annotations([['line'=>2,'note'=>'First image'],['line'=>8,'note'=>'Outside']],4);
ok34(count($anns)===1&&$anns[0]['line']===2,'Songbook annotations reject lines outside canonical lyrics');
$public=sf_songbook_public_catalog_rows([['id'=>'a','title'=>'Song','lyrics'=>'private canonical text','story'=>'public story']]);
ok34(!array_key_exists('lyrics',$public[0])&&$public[0]['story']==='public story','generated public catalog strips canonical lyrics without stripping public story');

$core=file_get_contents($root.'/api/songbook-core.php');
$publicApi=file_get_contents($root.'/api/songbook.php');
$adminApi=file_get_contents($root.'/admin/api/songbook.php');
$adminBoot=file_get_contents($root.'/admin/api/bootstrap.php');
$adminJs=file_get_contents($root.'/admin/assets/admin.js');
$adminShell=file_get_contents($root.'/admin/index.php');
$adminModule=file_get_contents($root.'/admin/assets/songbook.js');
$adminCss=file_get_contents($root.'/admin/assets/songbook.css');
$app=file_get_contents($root.'/assets/js/app.js');
$siteCss=file_get_contents($root.'/assets/css/site.css');
$search=file_get_contents($root.'/api/search-core.php');
$agent=file_get_contents($root.'/api/agent-runtime.php');
$boot=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$ht=file_get_contents($root.'/.htaccess');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok34(substr_count($core,'CREATE TABLE IF NOT EXISTS songbooks')===2,'Songbook metadata schema supports SQLite and MySQL');
ok34(substr_count($core,'CREATE TABLE IF NOT EXISTS songbook_events')===2,'Songbook event schema supports SQLite and MySQL');
ok34(!preg_match('/CREATE TABLE IF NOT EXISTS songbooks \([^)]*lyrics/i',$core),'Songbook database does not duplicate canonical lyric text');
ok34(str_contains($core,"['draft','published','archived']"),'Songbook lifecycle is explicit');
ok34(str_contains($core,'Canonical Catalog lyrics are required.'),'publication readiness requires canonical Catalog lyrics');
ok34(str_contains($core,'Track must be public before Songbook publication.'),'Songbook cannot publish for a private track');
ok34(str_contains($core,'Songbook sections may not overlap.'),'publication readiness blocks overlapping sections');
ok34(str_contains($core,'Annotations must reference a lyric line included in a Songbook section.'),'publication readiness blocks orphan annotations');
ok34(str_contains($core,"preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/"),'Songbook validates normalized language codes');
ok34(str_contains($core,'sf_songbook_public_payload')&&str_contains($core,"(\$r['status']??'')!=='published'"),'public payload is gated by Published status and readiness');
ok34(str_contains($core,"\$allowed=['view','section_open','annotation_open','share']"),'Songbook events are restricted to truthful interaction types');
ok34(str_contains($core,'!sf_songbook_get($trackId,true)'),'Songbook events require a currently public Songbook');
ok34(str_contains($core,'function sf_songbook_agent_context'),'Songbook exposes bounded published lyric context for Agent use');

ok34(str_contains($adminApi,'explicit Admin confirmation')&&str_contains($adminApi,"\$existing===null?in_array(\$target,['published','archived'],true)"),'initial and later publish/archive transitions require Admin confirmation');
ok34(str_contains($adminApi,'sf_log_admin_action')&&str_contains($adminApi,'sf_agent_brain_log'),'Songbook Admin mutations feed audit and Agent Brain');
ok34(str_contains($publicApi,'count($attempts)>=60'),'public Songbook event writes are rate-limited');
ok34(substr_count($publicApi,'sf_songbook_event(')===1,'Songbook GET is side-effect free; all event writes use the rate-limited POST path');
ok34(str_contains($publicApi,'sf_songbook_public_payload($trackId)'),'public API never returns an ungoverned canonical lyric record');

ok34(str_contains($adminBoot,'sf_songbook_public_catalog_rows')&&str_contains($adminBoot,"unset(\$track['lyrics'])"),'Catalog writer generates a lyric-free public catalog');
ok34(str_contains($core,'sf_songbook_refresh_public_catalog_asset'),'upgrade can sanitize an existing public catalog immediately');
ok34(str_contains($mig,'sf_songbook_refresh_public_catalog_asset()'),'migration 030 regenerates the lyric-free public catalog');
ok34(str_contains($search,"sf_songbook_get((string)(\$t['id']??''),true)?(string)(\$t['lyrics']??''):''"),'public search indexes lyrics only through a published Songbook');
ok34(str_contains($agent,'sf_songbook_agent_context($query,4)'),'public Agent receives only published Songbook context');
ok34(str_contains($agent,"unset(\$safe['lyrics'])")&&str_contains($agent,'$publicTrackHay'),'public Agent track ranking cannot be influenced by draft lyric text');

ok34(str_contains($adminShell,'assets/songbook.css')&&str_contains($adminShell,'assets/songbook.js'),'Admin shell loads Songbook editor assets');
ok34(substr_count($adminShell,'data-view="')===29,'Songbook stays inside Catalog and adds no sidebar item');
ok34(str_contains($adminJs,'Canonical lyrics')&&str_contains($adminJs,'remain private until the Songbook below is explicitly published'),'Catalog clearly separates canonical lyrics from public Songbook publication');
ok34(str_contains($adminJs,'SFSongbookAdmin?.section')&&str_contains($adminJs,'SFSongbookAdmin?.bindTrack'),'Songbook editor is embedded in each Catalog track workspace');
ok34(str_contains($adminJs,'songbook|publish.*lyrics|lyrics.*publish|verse|chorus|bridge'),'Admin Agent routes lyric publishing work to Songbook');
ok34(str_contains($adminModule,'Detect verse / chorus structure')&&str_contains($adminModule,'Sections')&&str_contains($adminModule,'Annotations'),'Songbook editor supports structure detection, sections and annotations');
ok34(str_contains($adminModule,'confirm(')&&str_contains($adminModule,'Save Songbook'),'Songbook editor confirms consequential status changes');
ok34(str_contains($adminCss,'.songbook-readiness')&&str_contains($adminCss,'.songbook-admin-metrics'),'Songbook editor has readiness and metrics presentation');

ok34(!str_contains($app,'class="lyrics-panel"'),'track page no longer renders raw Catalog lyrics directly');
ok34(str_contains($app,'id="publicSongbookPanel"')&&str_contains($app,'renderPublicSongbook(t.id)'),'track page loads governed Songbook instead of raw lyrics');
ok34(str_contains($app,'songbookSectionHtml')&&str_contains($app,'data-songbook-annotation'),'public Songbook renders sections and line annotations');
ok34(str_contains($app,"songbookEvent(trackId,'view'")&&str_contains($app,"songbookEvent(trackId,'section_open'")&&str_contains($app,"songbookEvent(trackId,'annotation_open'"),'public Songbook records views, section opens and annotation opens through the bounded event path');
ok34(str_contains($app,"$('.songbook-section',host).forEach")&&str_contains($app,"$('[data-songbook-annotation]',host).forEach"),'Songbook collection controls use the collection selector helper');
ok34(str_contains($siteCss,'.public-songbook-panel')&&str_contains($siteCss,'.songbook-section')&&str_contains($siteCss,'.songbook-annotation'),'public Songbook has responsive dedicated styling');

ok34(str_contains($boot,"require_once __DIR__ . '/songbook-core.php'"),'Songbook core loads from canonical bootstrap');
ok34(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.29'")&&str_contains($mig,"'id'=>'2026-10-11-030'")&&str_contains($mig,"'app_version'=>'1.3.33'"),'database target 1.3.29 includes migration 030 for v1.3.33');
ok34(str_contains($mig,"'songbooks','songbook_events'"),'upgrade integrity requires Songbook tables');
ok34(str_contains($version,"'stonefellow'=>'1.3.33'")&&str_contains($version,"'database_schema_target'=>'1.3.29'"),'version endpoint reports v1.3.33 and DB 1.3.29');
ok34(str_contains($version,"'songbook_privacy'=>'draft-lyrics-excluded-public-page-search-agent'"),'version endpoint advertises Songbook privacy boundary');
ok34(str_contains($ht,'X-Stonefellow-Build "1.3.33"'),'web build header advances to v1.3.33');
ok34(str_contains($ht,'RewriteRule ^(?:data|config)/ - [F,L,NC]'),'web server blocks direct access to private catalog and configuration directories');
ok34(str_contains($wf,'node --check admin/assets/songbook.js')&&str_contains($wf,'php tests/v1333-section34-songbook.php'),'release gate includes Songbook Admin JS and Section 34 certification');

echo "Stonefellow v1.3.33 Section 34 Lyrics, Songbook & Track Notes audit: PASS\n";
