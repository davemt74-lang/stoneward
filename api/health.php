<?php
declare(strict_types=1); require __DIR__.'/bootstrap.php';
$dirs=['orders'=>SF_ROOT.'/storage/orders','pod'=>SF_ROOT.'/storage/pod'];$storage=[];foreach($dirs as $k=>$d)$storage[$k]=is_dir($d)&&is_writable($d);$cfg=sf_store_config();sf_json_response(['ok'=>!in_array(false,$storage,true),'mode'=>$cfg['mode'],'payment_provider'=>$cfg['payment_provider'],'pod_provider'=>$cfg['pod_provider'],'storage'=>$storage,'php'=>PHP_VERSION]);
