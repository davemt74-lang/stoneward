<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/distribution-core.php';

ok(sf_distribution_normalize_isrc('US-ABC-26-12345')==='USABC2612345','ISRC normalizer removes presentation punctuation');
ok(sf_distribution_valid_isrc('USABC2612345')===true,'valid 12-character ISRC passes');
ok(sf_distribution_valid_isrc('BAD')===false,'invalid ISRC is rejected');
ok(sf_distribution_valid_barcode('036000291452')===true,'valid UPC-A checksum passes');
ok(sf_distribution_valid_barcode('4006381333931')===true,'valid EAN-13 checksum passes');
ok(sf_distribution_valid_barcode('036000291453')===false,'invalid barcode checksum is rejected');
ok(sf_distribution_destinations()===['spotify','apple_music','youtube_music','amazon_music','bandcamp'],'default DSP destination set is deterministic');

$core=file_get_contents($root.'/api/distribution-core.php');
$api=file_get_contents($root.'/admin/api/distribution.php');
$download=file_get_contents($root.'/admin/api/distribution-download.php');
$admin=file_get_contents($root.'/admin/assets/distribution.js');
$css=file_get_contents($root.'/admin/assets/distribution.css');
$shell=file_get_contents($root.'/admin/index.php');
$adminMain=file_get_contents($root.'/admin/assets/admin.js');
$bootstrap=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok(substr_count($core,'CREATE TABLE IF NOT EXISTS distribution_releases')===2,'distribution release schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS distribution_destinations')===2,'DSP destination schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS distribution_events')===2,'distribution event history supports SQLite and MySQL');
ok(str_contains($core,"['draft','preparing','ready','submitted','scheduled','live','archived']"),'publishing lifecycle states are constrained');
ok(str_contains($core,'function sf_distribution_release_readiness'),'preflight readiness has a canonical evaluator');
ok(str_contains($core,"['cover']")&&str_contains($core,'3000'),'preflight certifies release cover presence and managed 3000px resolution');
ok(str_contains($core,"['master_audio','primary_audio']")&&str_contains($core,'valid ISRC required'),'preflight verifies track masters and ISRCs');
ok(str_contains($core,'sf_rights_release_readiness'),'preflight consumes Section 28 rights readiness');
ok(str_contains($core,'function sf_distribution_sync_calendar'),'distribution integrates with the native Operating Calendar');
ok(str_contains($core,"'distribution:'.\$releaseId.':'.\$key"),'calendar source keys prevent duplicate distribution milestones');
ok(str_contains($core,'function sf_distribution_build_package'),'distributor package builder exists');
ok(str_contains($core,"'manifest.json'")&&str_contains($core,"'credits-rights.csv'")&&str_contains($core,"'masters/'")&&str_contains($core,"'artwork/cover.'"),'package includes manifest, credits/rights, masters and cover');
ok(str_contains($core,"hash_file('sha256'"),'distribution package is SHA-256 certified');
ok(str_contains($core,'function sf_distribution_agent_brief'),'Admin Agent has a deterministic release-readiness brief');

ok(str_contains($api,"action==='save_metadata'")&&str_contains($api,"action==='build_package'"),'Admin API manages metadata and package generation');
ok(str_contains($api,"'submitted'")&&str_contains($api,"'scheduled'")&&str_contains($api,"'live'")&&str_contains($api,'explicit confirmation'),'consequential publishing statuses require explicit confirmation');
ok(str_contains($api,'sf_log_admin_action')&&str_contains($api,'sf_agent_brain_log'),'distribution actions feed audit history and Agent Brain');
ok(str_contains($download,'realpath')&&str_contains($download,"storage/distribution/"),'package download is authenticated and path-contained');

ok(str_contains($admin,'Distribution & Publishing')&&str_contains($admin,'Distribution preflight'),'Admin workspace exposes distribution operations and preflight');
ok(str_contains($admin,'DSP destinations')&&str_contains($admin,'Build package')&&str_contains($admin,'Sync calendar'),'Admin exposes DSP tracking, package generation and calendar sync');
ok(str_contains($admin,'does not claim to submit directly to DSPs'),'UI clearly distinguishes tracking/export from direct DSP submission');
ok(str_contains($css,'.distribution-readiness')&&str_contains($css,'.distribution-destinations'),'Distribution workspace has dedicated responsive styling');

ok(str_contains($bootstrap,"require_once __DIR__ . '/distribution-core.php'"),'distribution core loads from canonical bootstrap');
ok(str_contains($mig,"'id'=>'2026-10-10-025'")&&str_contains($mig,'sf_distribution_backfill'),'migration 025 installs and backfills distribution operations');
$schema='0.0.0';if(preg_match("/const SF_DB_SCHEMA_TARGET = '([^']+)'/",$mig,$mm))$schema=$mm[1];ok(version_compare($schema,'1.3.24','>='),'database schema remains compatible with Section 29 or newer');
$appVersion='0.0.0';if(preg_match("/'stonefellow'=>'([^']+)'/",$version,$vm))$appVersion=$vm[1];ok(version_compare($appVersion,'1.3.28','>=')&&version_compare($schema,'1.3.24','>='),'version endpoint remains Section 29 compatible after later releases');
ok(str_contains($version,"'release_distribution'=>'preflight-metadata-identifiers-dsp-status-package-builder'"),'version endpoint advertises distribution capability');
ok(str_contains($shell,'data-view="distribution"')&&str_contains($shell,'assets/distribution.js')&&str_contains($shell,'assets/distribution.css'),'Distribution + Publishing is a first-class Admin module');
ok(str_contains($adminMain,"distribution:'Distribution + Publishing'")&&str_contains($adminMain,"openView('distribution')"),'Admin shell and Agent route into Distribution + Publishing');
ok(str_contains($wf,'node --check admin/assets/distribution.js')&&str_contains($wf,'php tests/v1328-section29-distribution.php'),'release gate includes Distribution JS and Section 29 suite');

echo "Stonefellow v1.3.28 Section 29 Release Publishing & Distribution Operations audit: PASS\n";
