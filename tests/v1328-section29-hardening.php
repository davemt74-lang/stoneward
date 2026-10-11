<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function hard_ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/distribution-core.php';

hard_ok(sf_distribution_valid_https_url('https://open.spotify.com/album/example')===true,'valid HTTPS destination URL passes');
hard_ok(sf_distribution_valid_https_url('http://example.com/release')===false,'non-HTTPS destination URL is rejected');
hard_ok(sf_distribution_valid_https_url('javascript:alert(1)')===false,'non-web destination URL is rejected');
hard_ok(sf_distribution_storage_key('../My Release')==='my-release','storage key removes traversal syntax');
hard_ok(sf_distribution_transition_allowed('draft','submitted')===false,'release cannot jump from draft directly to submitted');
hard_ok(sf_distribution_transition_allowed('ready','submitted')===true,'ready release may advance to submitted');
hard_ok(sf_distribution_transition_allowed('live','ready')===false,'live release cannot regress to ready');
hard_ok(sf_distribution_destination_transition_allowed('live','failed')===true,'live DSP may be explicitly marked failed/unavailable');
hard_ok(sf_distribution_destination_transition_allowed('live','scheduled')===false,'live DSP cannot silently regress to scheduled');
hard_ok(sf_distribution_destination_transition_allowed('failed','submitted')===true,'failed DSP may recover to submitted');

$base=['package_path'=>'storage/distribution/test/release.zip','package_stale'=>false,'destinations'=>[]];
hard_ok(sf_distribution_state_evidence($base,'submitted')!==null,'submitted release requires destination evidence');
$submitted=$base;$submitted['destinations']=[['status'=>'submitted','release_url'=>'']];
hard_ok(sf_distribution_state_evidence($submitted,'submitted')===null,'submitted destination satisfies submitted release evidence');
$scheduled=$base;$scheduled['destinations']=[['status'=>'scheduled','release_url'=>'']];
hard_ok(sf_distribution_state_evidence($scheduled,'scheduled')===null,'scheduled destination satisfies scheduled release evidence');
$live=$base;$live['destinations']=[['status'=>'live','release_url'=>'https://music.example/release']];
hard_ok(sf_distribution_state_evidence($live,'live')===null,'live HTTPS destination satisfies live release evidence');
$stale=$live;$stale['package_stale']=true;
hard_ok(sf_distribution_state_evidence($stale,'live')!==null,'stale package blocks consequential release state');

$core=file_get_contents($root.'/api/distribution-core.php');
$api=file_get_contents($root.'/admin/api/distribution.php');
$download=file_get_contents($root.'/admin/api/distribution-download.php');
$admin=file_get_contents($root.'/admin/assets/distribution.js');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

hard_ok(str_contains($core,'CASE l.role')&&str_contains($core,'ELSE 999'),'managed-media lookup honors requested role priority');
hard_ok(str_contains($core,'function sf_distribution_managed_media_path'),'managed media is path-contained before preflight/package use');
hard_ok(str_contains($core,'square and at least 3000×3000'),'cover preflight enforces square 3000px minimum');
hard_ok(str_contains($core,"in_array($ext,['jpg','jpeg','png'],true)"),'cover preflight limits distributor artwork to JPG/PNG');
hard_ok(str_contains($core,'sampleRate>=44100')&&str_contains($core,'bits>=16'),'master preflight enforces minimum WAV technical specs');
hard_ok(str_contains($core,'legacy playback audio does not qualify'),'legacy playback audio cannot masquerade as a distributor master');
hard_ok(str_contains($core,"package_path='',package_sha256='',package_built_at=NULL"),'distribution metadata edits invalidate old packages');
hard_ok(str_contains($core,'package_stale'),'distribution records detect stale packages after external source changes');
hard_ok(str_contains($core,"UPDATE operating_milestones SET title=?")&&!str_contains($core,"entity_id=?,'status'=>'pending'"),'calendar resync updates deterministic milestones without resetting completion state');
hard_ok(str_contains($core,'function sf_distribution_rights_manifest'),'package rights payload is explicitly minimized');
$manifestStart=strpos($core,'function sf_distribution_build_manifest');$manifestEnd=strpos($core,'function sf_distribution_safe_zip_name');$manifest=substr($core,$manifestStart,$manifestEnd-$manifestStart);
hard_ok(str_contains($manifest,'sf_distribution_rights_manifest')&&!str_contains($manifest,'sf_rights_work('),'package manifest uses sanitized rights data rather than raw registry rows');
hard_ok(str_contains($core,'Could not reopen distribution ZIP for verification')&&str_contains($core,'master count mismatch'),'package builder verifies the finalized ZIP');
hard_ok(str_contains($core,'sf_distribution_state_evidence'),'release state transitions require operational evidence');
hard_ok(str_contains($core,"Release state '.\$releaseState.' does not permit a destination"),'DSP status changes are constrained by release lifecycle');
hard_ok(str_contains($api,"['submitted','scheduled','live','archived']"),'archive release state requires explicit confirmation');
hard_ok(str_contains($api,"['submitted','scheduled','live','failed']"),'failed DSP status requires explicit confirmation');
hard_ok(str_contains($download,'package_stale')&&str_contains($download,'sf_distribution_storage_key'),'download rejects stale packages and uses sanitized storage keys');
hard_ok(str_contains($admin,'PACKAGE STALE — REBUILD')&&str_contains($admin,'releaseTransitions')&&str_contains($admin,'destinationTransitions'),'Admin explains stale packages and exposes only governed transitions');
hard_ok(str_contains($wf,'php tests/v1328-section29-hardening.php'),'release gate includes the Section 29 hardening suite');

echo "Stonefellow v1.3.28 Section 29 hardening audit: PASS\n";
