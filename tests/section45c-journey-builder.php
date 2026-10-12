<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/admin/assets/automations.js');
$api=(string)file_get_contents($root.'/admin/api/fan-journeys.php');
$checks=[
 'Journey intelligence is a tab in existing automation UI'=>str_contains($js,"['journey','Journey Intelligence']"),
 'Existing segment and automation tabs retained'=>str_contains($js,"['automations','Automations']")&&str_contains($js,"['segments','Segments']"),
 'Existing builder is reused'=>str_contains($js,"$('#journeyBuildExisting',mount).onclick=()=>openAutomation(0)"),
 'Refresh uses authorized read-only aggregate API'=>str_contains($js,"api('fan-journeys.php?days='"),
 'Three measurement periods accessible'=>str_contains($js,'[7,30,90]'),
 'No false conversion funnel advertised'=>str_contains($js,'Independent cohorts, not a fabricated sequential funnel'),
 'Screen-reader access to measurement window'=>str_contains($js,'aria-label="Journey measurement window"'),
 'Percentages handle null denominator'=>str_contains($js,"value===null||value===undefined?'—'"),
 'CRM measurement caveats output escaped'=>str_contains($js,"esc(note)"),
 'Journey GET requires administrator'=>str_contains($api,'sf_admin_require_auth(false)'),
 'API excludes non-GET mutations'=>str_contains($api,"$"."method==='GET'")||str_contains($api,'method_not_allowed'),
];
$fail=0;foreach($checks as $n=>$ok){echo ($ok?'PASS':'FAIL').' '.$n.PHP_EOL;if(!$ok)$fail++;}
exit($fail?1:0);
