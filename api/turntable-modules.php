<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'message'=>'Method not allowed'],405);
$user=sf_current_user();$id=(int)($user['id']??0);
sf_json_response(['ok'=>true,'modules'=>sf_turntable_catalog(),'owned'=>sf_turntable_owned($id),'defaults'=>sf_turntable_module_defaults()]);
