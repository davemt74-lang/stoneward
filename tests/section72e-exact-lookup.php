<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf-exact72e-'.bin2hex(random_bytes(5));
mkdir($root.'/storage/orders',0770,true);mkdir($root.'/storage/fulfillment',0770,true);
define('SF_ROOT',$root);
for($i=1;$i<=31;$i++){
  $id='SF-20261013-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
  $order=['id'=>$id,'user_id'=>7,'created_at'=>sprintf('2026-10-13T%02d:00:00Z',$i%24),
    'customer'=>['email'=>'owner@example.com'],'quote'=>['items'=>[['type'=>'custom_media','format'=>'vinyl']]],
    'fulfillment'=>['status'=>'pending']];
  file_put_contents($root.'/storage/orders/'.$id.'.json',json_encode($order));
}
$other=['id'=>'SF-OTHER-099','user_id'=>8,'created_at'=>'2026-10-13T00:00:00Z',
'customer'=>['email'=>'owner@example.com'],'quote'=>['items'=>[['type'=>'custom_media','format'=>'vinyl']]]];
file_put_contents($root.'/storage/orders/SF-OTHER-099.json',json_encode($other));
file_put_contents($root.'/storage/fulfillment/vinyl-production.json',json_encode([
 'SF-20261013-001'=>['status'=>'quality_check','updated_at'=>'2026-10-13T19:00:00Z',
 'events'=>[['at'=>'2026-10-13T18:00:00Z','to'=>'quality_check','note'=>'PRIVATE ADMIN NOTE','admin_id'=>44]]]
]));
require __DIR__.'/../api/vinyl-customer-status.php';
function pass72e(bool $ok,string $what):void{if(!$ok){fwrite(STDERR,"FAIL $what\n");exit(1);}echo "PASS $what\n";}
$latest=sf_vinyl_customer_status(7,'owner@example.com',25);
pass72e(count($latest)===25,'Overview bounded to 25 vinyl orders');
$old=sf_vinyl_customer_status(7,'owner@example.com',1,'SF-20261013-001');
pass72e(count($old)===1,'Exact lookup retrieves selected order independent of result cutoff');
pass72e($old[0]['status']==='quality_check','Admin production stage included');
pass72e(count($old[0]['events'])===1,'Selected order milestones preserved');
pass72e(!str_contains(json_encode($old),'PRIVATE')&&!str_contains(json_encode($old),'admin_id'),'Private Admin notes excluded');
pass72e(sf_vinyl_customer_status(8,'other@example.com',1,'SF-20261013-001')===[],'Another user cannot open selected order');
pass72e(sf_vinyl_customer_status(7,'owner@example.com',1,'SF-OTHER-099')===[],'Owner mismatch cannot be bypassed through email');
pass72e(sf_vinyl_customer_status(7,'owner@example.com',1,'../../storage/config')===[],'Invalid order path rejected');
pass72e(sf_vinyl_customer_status(0,'owner@example.com',1,'SF-20261013-001')===[],'Anonymous access denied');
$endpoint=file_get_contents(__DIR__.'/../api/customer-order.php');
pass72e(str_contains($endpoint,"sf_vinyl_customer_status((int)$"."u['id'],(string)$"."u['email'],1,$"."id)"),'Order endpoint requests exact authenticated order');
foreach(glob($root.'/storage/orders/*.json') as $f)unlink($f);unlink($root.'/storage/fulfillment/vinyl-production.json');
rmdir($root.'/storage/orders');rmdir($root.'/storage/fulfillment');rmdir($root.'/storage');rmdir($root);
echo "Section 72E exact order: 10/10 checks passed\n";
