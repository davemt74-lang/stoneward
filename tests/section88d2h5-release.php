<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$checks=[
 'Version API baseline'=>preg_match("/'stonefellow'=>'([0-9.]+)'/",$read('version.php'),$apiVersion)
   &&version_compare($apiVersion[1],'1.3.113','>='),
 'Public runtime baseline'=>preg_match("/define\\('SF_BUILD', '([0-9.]+)'\\)/",$read('stonefellow-v120.php'),$releaseBuild)
   &&version_compare($releaseBuild[1],'1.3.113','>='),
 'Admin build synchronized'=>str_contains($read('admin/index.php'),"define('SF_ADMIN_BUILD','".$releaseBuild[1]."')"),
 'PWA cache bumped'=>str_contains($read('service-worker.js'),$releaseBuild[1]),
 'Application and builder assets bumped'=>str_contains($read('assets/js/app.js'),$releaseBuild[1])
   &&str_contains($read('assets/css/turntable-builder.css'),$releaseBuild[1]),
 'Schema additive baseline'=>preg_match("/SF_DB_SCHEMA_TARGET = '([0-9.]+)'/",$read('api/migrations.php'),$schema)
   &&version_compare($schema[1],'1.3.50','>=')
   &&str_contains($read('version.php'),"'database_schema_target'=>'".$schema[1]."'"),
 'Additive migration 041'=>str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-041'")
   &&str_contains($read('api/migrations.php'),'sf_capture_rights_schema()'),
 'Integrity report includes scoped rights'=>str_contains($read('api/migrations.php'),"'story_capture_rights_reviews'"),
 'External LLM history isolated'=>str_contains($read('api/agent-admin-brain.php'),'sf_admin_agent_external_facts')
   &&str_contains($read('api/agent-admin-brain.php'),'$history')===false,
 'Atomic capture and evidence'=>str_contains($read('api/story-capture-upload.php'),'sf_capture_store_with_evidence'),
 'Verified-only graph links'=>str_contains($read('api/story-graph-core.php'),'Graph provenance requires verified source evidence.'),
 'Rights independent and revocable'=>str_contains($read('api/story-capture-rights-core.php'),"'external_ai','publication'")
   &&str_contains($read('api/story-capture-rights-core.php'),"'cleared','restricted'"),
 'Admin rights review routed'=>str_contains($read('admin/api/story-capture.php'),"'review_rights'"),
 'Director does not trust self report'=>str_contains($read('api/story-director-core.php'),'independent AI rights clearance is separate'),
 'All sections in release gate'=>str_contains($read('.github/workflows/release-gate.yml'),'section88d2h1-admin-provider-privacy.php')
   &&str_contains($read('.github/workflows/release-gate.yml'),'section88d2h4-rights-clearance.php')
];
$failed=0;foreach($checks as $name=>$ok){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$failed++;}
echo '88D2H5: '.(count($checks)-$failed).'/'.count($checks).' release checks'.PHP_EOL;
exit($failed>0?1:0);
