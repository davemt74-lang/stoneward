<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';sf_admin_require_auth($write);
if(!$write)sf_json_response(['ok'=>true]+sf_ai_state());
$body=sf_request_json();$action=(string)($body['action']??'save_provider');
try{
    if($action==='save_provider'){
        $provider=(string)($body['provider']??'');$row=sf_ai_save_provider($provider,$body);sf_json_response(['ok'=>true,'provider'=>$row,'state'=>sf_ai_state()]);
    }
    if($action==='save_routing'){$routing=sf_ai_save_routing($body);sf_json_response(['ok'=>true,'routing'=>$routing,'state'=>sf_ai_state()]);}
    sf_json_response(['ok'=>false,'message'=>'Unsupported AI settings action.'],422);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>'Could not save AI provider settings. '.$e->getMessage()],500);}
