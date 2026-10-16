<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/fan-conversion-intent-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
sf_require_csrf();
try {
    $u=sf_current_user();if(!$u)sf_json_response(['ok'=>true,'recorded'=>false]);
    $b=sf_request_json();$cart=is_array($b['cart']??null)?$b['cart']:[];
    if(count($cart)>20)sf_json_response(['ok'=>false,'error'=>'invalid_cart'],422);
    $uid=(int)$u['id'];
    if(!sf_privacy_personalization_enabled($uid)){
        sf_87d_intent_clear($uid);sf_json_response(['ok'=>true,'recorded'=>false]);
    }
    $quote=sf_quote($cart,'',$uid);
    if(!$quote['ok'])sf_json_response(['ok'=>true,'recorded'=>false]);
    sf_json_response(['ok'=>true,'recorded'=>sf_87d_intent_record($uid,(string)($b['stage']??''),$quote)]);
}catch(Throwable $e){
    error_log('[Stonefellow conversion interest] '.get_class($e));
    sf_json_response(['ok'=>false,'error'=>'conversion_tracking_unavailable'],503);
}
