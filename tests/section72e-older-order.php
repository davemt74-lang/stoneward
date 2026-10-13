<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf72e-'.bin2hex(random_bytes(6));
mkdir($root.'/storage/orders',0770,true);
mkdir($root.'/storage/fulfillment',0770,true);
define('SF_ROOT',$root);
require __DIR__.'/../api/vinyl-customer-status.php';
function assertion72e(bool $ok,string $name): void {
  if(!$ok){fwrite(STDERR,"FAIL ".$name.PHP_EOL);exit(1);}
  echo "PASS ".$name.PHP_EOL;
}
$ids=[];
for($i=0;$i<31;$i++){
    $id='SF-VINYL-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
    $ids[]=$id;
    $order=[
      'id'=>$id,'user_id'=>7,'created_at'=>sprintf('2026-10-13T12:%02d:00Z',$i),
      'customer'=>['email'=>'owner@example.com'],
      'quote'=>['items'=>[['type'=>'custom_media','format'=>'vinyl']]],
      'fulfillment'=>['status'=>'pending']
    ];
    file_put_contents($root.'/storage/orders/'.$id.'.json',json_encode($order));
}
$oldest=$ids[0];
$state=[$oldest=>['status'=>'artwork_approved','updated_at'=>'2026-10-13T12:00:00Z','events'=>[
  ['at'=>'2026-10-13T12:00:00Z','to'=>'artwork_approved','admin_id'=>42,'note'=>'INTERNAL ONLY']
]]];
file_put_contents($root.'/storage/fulfillment/vinyl-production.json',json_encode($state));
$recent=sf_vinyl_customer_status(7,'owner@example.com',25);
assertion72e(count($recent)===25,'Account summary remains bounded');
assertion72e(!in_array($oldest,array_column($recent,'order_id'),true),'Older vinyl order excluded from recent summary as expected');
$detail=sf_vinyl_customer_status(7,'owner@example.com',1,$oldest);
assertion72e(count($detail)===1,'Exact order lookup works beyond most recent 25');
assertion72e($detail[0]['order_id']===$oldest,'Detail identifies requested order only');
assertion72e($detail[0]['status']==='artwork_approved','Existing manufacturing stage retained');
assertion72e($detail[0]['events'][0]['status']==='artwork_approved','Event history retained');
assertion72e(!str_contains(json_encode($detail),'INTERNAL ONLY'),'Admin notes not disclosed');
assertion72e(!str_contains(json_encode($detail),'admin_id'),'Admin identity not disclosed');
assertion72e(sf_vinyl_customer_status(8,'other@example.com',1,$oldest)===[],'Another user cannot fetch private production details');
assertion72e(sf_vinyl_customer_status(0,'owner@example.com',1,$oldest)===[],'Anonymous exact-order request denied');
assertion72e(sf_vinyl_customer_status(7,'owner@example.com',1,'../storage/orders/'.$oldest)===[],'Path traversal identifier rejected');
assertion72e(sf_vinyl_customer_status(7,'owner@example.com',1,'SF-NOTFOUND')===[],'Missing order fails closed');
assertion72e(count(sf_vinyl_customer_status(7,'owner@example.com',1,$ids[1]))===1,'No production event still returns awaiting-review stage');
assertion72e(sf_vinyl_customer_status(7,'owner@example.com',1,$ids[1])[0]['status']==='awaiting_artwork_review','Unstarted order displays correct initial stage');
$controller=file_get_contents(__DIR__.'/../api/customer-order.php');
assertion72e(str_contains($controller,"sf_vinyl_customer_status((int)$"."u['id'],(string)$"."u['email'],1,$"."id)"),'Customer detail calls exact-order scoped reader');
foreach(glob($root.'/storage/orders/*.json')?:[] as $path)unlink($path);
unlink($root.'/storage/fulfillment/vinyl-production.json');
rmdir($root.'/storage/orders');rmdir($root.'/storage/fulfillment');rmdir($root.'/storage');rmdir($root);
echo 'Section 72E: 15/15 runtime order-history tests passed'.PHP_EOL;
