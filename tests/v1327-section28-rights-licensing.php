<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/rights-core.php';

ok(sf_rights_bp(50)===5000,'50 percent is stored as 5000 basis points');
ok(sf_rights_bp(33.333)===3333,'fractional ownership is rounded deterministically to basis points');
ok(sf_rights_pct(3333)===33.33,'basis points convert back to a two-decimal percentage');
ok(sf_rights_normalize_iswc('T-123.456.789-0')==='T1234567890','ISWC normalizer keeps T plus ten digits');
ok(sf_rights_normalize_iswc('bad-iswc')==='','invalid ISWC is rejected');

$ready=sf_rights_readiness_eval(10000,10000,[]);
ok($ready['ready']===true,'exact 100 percent composition and master ownership is rights-ready');
ok($ready['composition_percent']===100.0&&$ready['master_percent']===100.0,'readiness reports exact split percentages');

$under=sf_rights_readiness_eval(7500,10000,[]);
ok($under['ready']===false&&str_contains(implode(' ',$under['blockers']),'75%'),'under-allocated composition ownership blocks readiness');

$over=sf_rights_readiness_eval(10500,10000,[]);
ok($over['ready']===false&&str_contains(implode(' ',$over['blockers']),'105%'),'over-allocated composition ownership blocks readiness');

$pending=sf_rights_readiness_eval(10000,10000,[['license_type'=>'sample','status'=>'pending','ends_at'=>'']]);
ok($pending['ready']===false&&str_contains(implode(' ',$pending['blockers']),'pending'),'pending license blocks readiness');

$restricted=sf_rights_readiness_eval(10000,10000,[['license_type'=>'master_use','status'=>'restricted','ends_at'=>'']]);
ok($restricted['ready']===false&&str_contains(implode(' ',$restricted['blockers']),'restricted'),'restricted license blocks readiness');

$soon=gmdate('Y-m-d',time()+30*86400);
$warning=sf_rights_readiness_eval(10000,10000,[['license_type'=>'sync','status'=>'cleared','ends_at'=>$soon]]);
ok($warning['ready']===true&&count($warning['warnings'])===1,'cleared license expiring within 60 days warns without blocking current readiness');

$core=file_get_contents($root.'/api/rights-core.php');
$api=file_get_contents($root.'/admin/api/rights.php');
$admin=file_get_contents($root.'/admin/assets/rights.js');
$css=file_get_contents($root.'/admin/assets/rights.css');
$shell=file_get_contents($root.'/admin/index.php');
$bootstrap=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$adminMain=file_get_contents($root.'/admin/assets/admin.js');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok(substr_count($core,'CREATE TABLE IF NOT EXISTS rights_parties')===2,'rights parties schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS rights_works')===2,'rights works schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS rights_splits')===2,'rights split schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS rights_licenses')===2,'rights license schema supports SQLite and MySQL');
ok(str_contains($core,'percent_bp'),'ownership is stored as integer basis points');
ok(str_contains($core,"in_array($type,['composition','master'],true)"),'ownership split types are constrained to composition and master');
ok(str_contains($core,'function sf_rights_release_readiness'),'release readiness rolls up track rights');
ok(str_contains($core,'function sf_rights_agent_brief'),'rights registry produces a deterministic Admin Agent brief');
ok(str_contains($core,'sf_rights_backfill_catalog'),'migration can initialize rights works from catalog metadata');
ok(!str_contains($core,"sf_rights_save_split($id")&&!str_contains($core,"words_by']??''),50"),'catalog backfill does not invent ownership splits from writer metadata');

ok(str_contains($api,"action==='save_split'")&&str_contains($api,'requires_confirmation'=>true),'ownership split writes are treated as consequential');
ok(str_contains($api,"action==='delete_split'")&&str_contains($api,"confirmed"),'split deletion requires explicit confirmation');
ok(str_contains($api,"action==='delete_license'")&&str_contains($api,"confirmed"),'license deletion requires explicit confirmation');
ok(str_contains($api,'sf_agent_brain_log'),'rights writes feed Admin Agent Brain');
ok(str_contains($api,'sf_log_admin_action'),'rights writes feed Admin audit');

ok(str_contains($admin,'Catalog rights readiness')&&str_contains($admin,'Release clearance'),'Admin rights workspace exposes work and release readiness');
ok(str_contains($admin,'Composition ownership')&&str_contains($admin,'Master ownership'),'Admin editor separates composition and master ownership');
ok(str_contains($admin,'Licenses & clearances'),'Admin editor manages license records');
ok(str_contains($admin,'New rights party'),'Admin supports reusable rights parties');
ok(str_contains($css,'.rights-readiness')&&str_contains($css,'.rights-split-add'),'Rights Registry has dedicated responsive styling');

ok(str_contains($bootstrap,"require_once __DIR__ . '/rights-core.php'"),'rights core loads from canonical bootstrap');
ok(str_contains($mig,"'id'=>'2026-10-10-024'")&&str_contains($mig,'sf_rights_backfill_catalog'),'migration 024 installs/backfills rights registry');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.23'"),'database schema advances to 1.3.23');
ok(str_contains($version,"'stonefellow'=>'1.3.27'")&&str_contains($version,"'database_schema_target'=>'1.3.23'"),'version endpoint reports app 1.3.27 and schema 1.3.23');
ok(str_contains($version,"'rights_registry'=>'works-parties-composition-master-splits-licenses-readiness'"),'version endpoint advertises rights registry capability');
ok(str_contains($shell,'data-view="rights"')&&str_contains($shell,'assets/rights.js'),'Rights + Licensing is a first-class Admin module');
ok(str_contains($adminMain,"rights:'Rights + Licensing'")&&str_contains($adminMain,"openView('rights')"),'Admin shell and Agent route into Rights + Licensing');
ok(str_contains($wf,'node --check admin/assets/rights.js')&&str_contains($wf,'php tests/v1327-section28-rights-licensing.php'),'release gate includes Rights JS and Section 28 test suite');

echo "Stonefellow v1.3.27 Section 28 Rights, Credits & Licensing Registry audit: PASS\n";
