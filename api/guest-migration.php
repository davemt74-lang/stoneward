<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/guest-migration-core.php';
$user=sf_require_user(false,true);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sf_json_response(['ok'=>false,'message'=>'POST required.'],405);
if((int)($_SERVER['CONTENT_LENGTH']??0)>160000)sf_json_response(['ok'=>false,'message'=>'Import payload is too large.'],413);
$body=sf_request_json();
try{
    if(($body['action']??'')!=='import')throw new InvalidArgumentException('Unsupported import action.');
    $result=sf_guest_migration_import((int)$user['id'],$body);
    sf_json_response(['ok'=>true,'import'=>$result,'user'=>sf_user_public(sf_current_user()),'csrf'=>sf_user_csrf()]);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>'Guest data could not be imported. No partial changes were saved.'],503);}
