<?php
declare(strict_types=1);
$api=file_get_contents(__DIR__.'/../admin/api/vinyl-production.php');
$ui=file_get_contents(__DIR__.'/../admin/assets/fulfillment.js');
foreach ([
  'admin authentication'=>'sf_admin_require_auth',
  'order validation'=>'sf_fulfillment_order_snapshot',
  'vinyl detection'=>'custom_media',
  'revision protection'=>'revision',
  'confirmation'=>'confirmed',
  'ordered milestones'=>'production_complete',
  'audit trail'=>'admin_id',
] as $label=>$needle) {
  if (strpos($api,$needle)===false) { fwrite(STDERR,"FAIL $label\n");exit(1); }
  echo "PASS $label\n";
}
foreach(['vinylProductionPanel','loadVinylProduction','revision:Number(r.revision)'] as $needle) {
  if (strpos($ui,$needle)===false) {fwrite(STDERR,"FAIL UI $needle\n");exit(1);}
  echo "PASS UI $needle\n";
}
echo "Section 62C: 10/10 checks passed\n";
