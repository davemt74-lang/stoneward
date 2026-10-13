<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$admin=sf_admin_require_auth($write);
if(!$write)sf_json_response(['ok'=>true,'modules'=>sf_turntable_catalog(true),'csrf'=>sf_admin_csrf()]);
$b=sf_request_json();
if(($b['action']??'')!=='save')sf_json_response(['ok'=>false,'message'=>'Unsupported action'],422);
try{
    $saved=sf_turntable_save_custom((array)($b['module']??[]));
    sf_log_admin_action((int)$admin['id'],'turntable_module_saved','turntable_module',$saved['id'],['slot'=>$saved['slot'],'price_cents'=>$saved['price_cents'],'active'=>$saved['active']]);
    sf_json_response(['ok'=>true,'module'=>$saved,'modules'=>sf_turntable_catalog(true)]);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
