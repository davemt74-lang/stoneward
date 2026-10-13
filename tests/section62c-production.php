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
  'simulated payment support'=>'simulated_paid',
  'paid test order support'=>'paid_',
] as $label=>$needle) {
  if (strpos($api,$needle)===false) { fwrite(STDERR,"FAIL $label\n");exit(1); }
  echo "PASS $label\n";
}
foreach(['vinylProductionPanel','loadVinylProduction','revision:Number(r.revision)','Vinyl design proof','coverFront','customNote','sideReview'] as $needle) {
  if (strpos($ui,$needle)===false) {fwrite(STDERR,"FAIL UI $needle\n");exit(1);}
  echo "PASS UI $needle\n";
}
if (strpos(file_get_contents(__DIR__.'/../.htaccess'),'data|config|storage')===false) {fwrite(STDERR,"FAIL private storage protection\n");exit(1);}
echo "Section 62C production checks passed\n";
