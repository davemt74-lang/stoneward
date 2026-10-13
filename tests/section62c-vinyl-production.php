<?php
declare(strict_types=1);
$api=file_get_contents(__DIR__.'/../admin/api/vinyl-production.php');
$js=file_get_contents(__DIR__.'/../admin/assets/fulfillment.js');
$checks=[
  'Production API checks admin authorization'=>str_contains($api,'sf_admin_require_auth'),
  'Production API checks real orders'=>str_contains($api,'sf_fulfillment_order_snapshot'),
  'Production API only handles custom vinyl'=>str_contains($api,'custom_media'),
  'Production API prevents stale state writes'=>str_contains($api,'revision')&&str_contains($api,'409'),
  'Production API requires explicit approval'=>str_contains($api,'confirmed'),
  'Production API restricts unpaid orders'=>str_contains($api,'Only confirmed paid orders'),
  'Production API enforces ordered stages'=>str_contains($api,'$stages[$currentIndex+1]!==$next'),
  'Production API records admin event history'=>str_contains($api,"'admin_id'"),
  'Production API uses exclusive lock'=>str_contains($api,'flock($lock,LOCK_EX)'),
  'Fulfillment view contains production management'=>str_contains($js,'vinylProductionPanel'),
  'Production stage submit uses request revision'=>str_contains($js,'revision:Number(r.revision)'),
  'Production UI requires confirmation'=>str_contains($js,"confirm('Advance this vinyl order"),
];
foreach($checks as $name=>$ok){if(!$ok){fwrite(STDERR,"FAIL ".$name."\n");exit(1);}echo "PASS ".$name."\n";}
echo "Section 62C: ".count($checks)."/".count($checks)." checks passed\n";
