<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';sf_membership_ensure_schema();$u=sf_current_user();$uid=$u?(int)$u['id']:null;
$plans=[];foreach(sf_packages(true) as $p){$pub=sf_package_public($p);if(!empty($pub['membership']['enabled']))$plans[]=$pub;}
$state=$uid?sf_membership_state($uid,true):['active'=>false,'status'=>'none','package_id'=>0,'package_name'=>'','rank'=>0,'badge'=>'','benefits'=>sf_membership_benefits([])];
sf_json_response(['ok'=>true,'authenticated'=>(bool)$u,'state'=>$state,'content'=>sf_membership_public_content($uid),'plans'=>$plans]);
