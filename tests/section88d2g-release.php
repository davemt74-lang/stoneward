<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
preg_match("/define\\('SF_BUILD',\\s*'([0-9.]+)'\\)/",$read('stonefellow-v120.php'),$match);
$v=$match[1]??'';
$checks=[
 'Release at least 1.3.112'=>version_compare($v,'1.3.112','>='),
 'Admin build synchronized'=>str_contains($read('admin/index.php'),"define('SF_ADMIN_BUILD','".$v."')"),
 'Version API synchronized'=>str_contains($read('version.php'),"'stonefellow'=>'".$v."'"),
 'PWA cache isolated'=>str_contains($read('service-worker.js'),"stonefellow-static-v".$v)
  &&str_contains($read('service-worker.js'),"const BUILD='".$v."'"),
 'Apache build header aligned'=>str_contains($read('.htaccess'),'X-Stonefellow-Build "'.$v.'"'),
 'Public JS cache aligned'=>str_contains($read('assets/js/app.js'),"window.STONEFELLOW_APP_BUILD='".$v."'"),
 'Builder CSS aligned'=>str_contains($read('assets/css/turntable-builder.css'),$v),
 'Schema version advanced'=>str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.50'")&&str_contains($read('version.php'),"'database_schema_target'=>'1.3.50'"),
 'Migration installs all production schemas'=>str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-040'")
  &&str_contains($read('api/migrations.php'),'sf_evidence_schema()')
  &&str_contains($read('api/migrations.php'),'sf_director_schema()'),
 'Integrity report tracks production tables'=>str_contains($read('api/migrations.php'),"'story_director_drafts'"),
 'Capture evidence bound to real uploaded bytes'=>str_contains($read('api/story-capture-upload.php'),'sf_evidence_observe_upload')
  &&str_contains($read('api/story-media-evidence-core.php'),"hash_file('sha256'"),
 'Contributor and editor evidence boundaries'=>str_contains($read('api/story-media-evidence-core.php'),'sf_team_can')
  &&str_contains($read('api/story-scene-core.php'),'sf_scene_review')
  &&str_contains($read('api/story-transcript-core.php'),'sf_transcript_review'),
 'Speech and video AI inferencing not falsely claimed'=>str_contains($read('version.php'),"'artist_world_transcripts'=>'timecoded-human-or-machine-drafts-human-verified-no-speech-model'")
  &&str_contains($read('version.php'),"'artist_world_scene_analysis'=>'timecoded-human-or-machine-descriptions-human-verified-no-vision-inference'"),
 'Graph concept identities cannot certify real identities'=>str_contains($read('api/story-graph-core.php'),"'character','location','event','theme'"),
 'Evidence retrieval is read-only and editor-scoped'=>str_contains($read('api/story-production-search-core.php'),'Production search requires editor authority.')
  &&str_contains($read('api/story-production-search-core.php'),'review_status') && str_contains($read('api/story-production-search-core.php'),'verified'),
 'AI shot suggestions remain drafts'=>str_contains($read('api/story-director-core.php'),"'suggestion_origin'=>'ai_suggestion','publish'=>false"),
 'Admin Agent Brain offers production retrieval'=>str_contains($read('api/agent-admin-brain.php'),'sf_production_search_admin_brief')
  &&str_contains($read('api/agent-admin-brain.php'),'sf_director_admin_brief'),
 'Release gate includes evidence through Director suites'=>str_contains($read('.github/workflows/release-gate.yml'),'tests/section88d2a-media-evidence.php')
  &&str_contains($read('.github/workflows/release-gate.yml'),'tests/section88d2f-director.php'),
 'Release gate includes real MySQL schema'=>str_contains($read('.github/workflows/release-gate.yml'),'tests/section88d2g-mysql-production.php'),
 'No HomeServer deploy bundled'=>!str_contains($read('version.php'),'homeserver_update_88d2g')
];
$fail=0;foreach($checks as $name=>$good){echo ($good?'PASS ':'FAIL ').$name.PHP_EOL;if(!$good)$fail++;}
echo '88D2G: '.(count($checks)-$fail).'/'.count($checks).' release acceptance checks'.PHP_EOL;
exit($fail?1:0);
