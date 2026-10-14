<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/billing.php';
$t=time();$secret='whsec_private_test';$payload=json_encode(['id'=>'evt_verified','type'=>'checkout.session.completed']);
$sig=hash_hmac('sha256',$t.'.'.$payload,$secret);
$checks=[
 'Valid HMAC signature'=>sf_stripe_verify_signature($payload,'t='.$t.',v1='.$sig,$secret),
 'Tampered event denied'=>!sf_stripe_verify_signature($payload.'x','t='.$t.',v1='.$sig,$secret),
 'Wrong secret denied'=>!sf_stripe_verify_signature($payload,'t='.$t.',v1='.$sig,'different'),
 'Expired timestamp denied'=>!sf_stripe_verify_signature($payload,'t='.($t-310).',v1='.hash_hmac('sha256',($t-310).'.'.$payload,$secret),$secret),
 'Missing signature denied'=>!sf_stripe_verify_signature($payload,'',$secret),
 'Missing secret denied'=>!sf_stripe_verify_signature($payload,'t='.$t.',v1='.$sig,''),
];
$failed=0;foreach($checks as $label=>$ok){echo ($ok?'PASS':'FAIL').' '.$label.PHP_EOL;if(!$ok)$failed++;}
exit($failed?1:0);
