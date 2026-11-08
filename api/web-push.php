<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/web-push-core.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method!=='GET' && $method!=='POST'){
    header('Allow: GET, POST');
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
}
$user=sf_require_user(false,$method==='POST');
$userId=(int)$user['id'];
if($method==='GET'){
    $publicKey=null;
    if(sf_web_push_supported()){
        try{
            $publicKey=sf_web_push_b64(sf_web_push_point(sf_web_push_vapid_key()));
        }catch(Throwable $e){
            error_log('Stonefellow Web Push configuration unavailable: '.get_class($e));
        }
    }
    sf_json_response(['ok'=>true,'available'=>$publicKey!==null,'public_key'=>$publicKey,'csrf'=>sf_user_csrf()]);
}

$body=sf_request_json();
$sessionHash=hash('sha256',sf_auth_cookie_value());
$action=$body['action']??null;
try{
    if($action==='subscribe'){
        if(!sf_web_push_supported()) sf_json_response(['ok'=>false,'error'=>'push_unavailable','message'=>'Phone alerts are temporarily unavailable.'],503);
        $subscription=$body['subscription']??null;
        if(!is_array($subscription)) throw new InvalidArgumentException('A browser push subscription is required.');
        sf_web_push_vapid_key();
        sf_web_push_subscribe($userId,$sessionHash,$subscription);
        sf_json_response(['ok'=>true,'subscribed'=>true]);
    }
    if($action==='status' || $action==='unsubscribe'){
        $endpoint=$body['endpoint']??null;
        if(!is_string($endpoint) || $endpoint==='' || strlen($endpoint)>2048){
            throw new InvalidArgumentException('A browser push endpoint is required.');
        }
        if($action==='unsubscribe'){
            sf_web_push_unsubscribe($userId,$sessionHash,$endpoint);
            sf_json_response(['ok'=>true,'subscribed'=>false]);
        }
        sf_json_response(['ok'=>true,'subscribed'=>sf_web_push_device_status($userId,$sessionHash,$endpoint)]);
    }
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'error'=>'invalid_push_subscription','message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Stonefellow Web Push subscription failed: '.get_class($e));
    sf_json_response(['ok'=>false,'error'=>'push_unavailable','message'=>'Phone alerts are temporarily unavailable.'],503);
}
sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
