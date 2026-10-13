<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf-audit-'.bin2hex(random_bytes(6));
mkdir($root.'/storage/orders',0770,true);mkdir($root.'/storage/fulfillment',0770,true);
define('SF_ROOT',$root);
require __DIR__.'/../api/vinyl-customer-status.php';
function vinylFixture(string $id,int $uid,string $email,string $type='custom_media'):array {
 return ['id'=>$id,'user_id'=>$uid,'customer'=>['email'=>$email],'created_at'=>'2026-10-13T08:00:00Z','quote'=>['items'=>[['type'=>$type,'format'=>'vinyl']]],'fulfillment'=>['status'=>'pending']];
}
$orders=[vinylFixture('SF-OWNED-1',7,'owner@example.com'),vinylFixture('SF-OTHER-1',8,'other@example.com'),vinylFixture('SF-LEGACY-1',0,'owner@example.com'),vinylFixture('SF-SPOOF-1',8,'owner@example.com'),vinylFixture('SF-MERCH-1',7,'owner@example.com','merch')];
foreach($orders as $o)file_put_contents($root.'/storage/orders/'.$o['id'].'.json',json_encode($o));
$records=['SF-OWNED-1'=>['status'=>'in_production','updated_at'=>'2026-10-13T10:00:00Z','events'=>[['at'=>'2026-10-13T09:00:00Z','to'=>'artwork_approved','admin_id'=>999,'note'=>'PRIVATE'],['at'=>'2026-10-13T10:00:00Z','to'=>'in_production','admin_id'=>999,'note'=>'PRIVATE']]]];
file_put_contents($root.'/storage/fulfillment/vinyl-production.json',json_encode($records));
function assertVinyl(bool $ok,string $label):void{if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
$rows=sf_vinyl_customer_status(7,'owner@example.com',25);$byId=array_column($rows,null,'order_id');
assertVinyl(count($rows)===2,'Owned vinyl, not merchandise');
assertVinyl(isset($byId['SF-OWNED-1'])&&isset($byId['SF-LEGACY-1']),'Owned and email-linked guest orders');
assertVinyl(!isset($byId['SF-OTHER-1'])&&!isset($byId['SF-SPOOF-1']),'Explicit other-user ownership wins over matching email');
assertVinyl($byId['SF-LEGACY-1']['status']==='awaiting_artwork_review','Fresh order visible before admin transition');
assertVinyl($byId['SF-OWNED-1']['status']==='in_production','Admin production status preserved');
assertVinyl(count($byId['SF-OWNED-1']['events'])===2,'Production milestones preserved');
assertVinyl(!str_contains(json_encode($rows),'PRIVATE')&&!str_contains(json_encode($rows),'admin_id'),'Private admin notes omitted');
assertVinyl($byId['SF-LEGACY-1']['updated_at']==='2026-10-13T08:00:00Z','Pre-transition order sorts by creation date');
assertVinyl(sf_vinyl_customer_status(0,'owner@example.com')===[],'Anonymous denied');
assertVinyl(count(sf_vinyl_customer_status(8,'other@example.com'))===2,'Another owner sees own orders only');
assertVinyl(count(sf_vinyl_customer_status(7,'owner@example.com',1))===1,'Results bounded');
foreach(glob($root.'/storage/orders/*.json') as $f)unlink($f);unlink($root.'/storage/fulfillment/vinyl-production.json');rmdir($root.'/storage/orders');rmdir($root.'/storage/fulfillment');rmdir($root.'/storage');rmdir($root);
echo "Section 62F ownership runtime: 11/11 passed\n";
