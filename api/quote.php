<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);

/**
 * Pricing only needs a verified customer ID; never run account email/lifecycle
 * migrations while quoting a cart. The account's status is checked read-only.
 */
function sf_quote_current_user_id(): ?int {
    if(!sf_installed())return null;
    sf_user_session();
    $id=(int)($_SESSION['sf_user_id']??0);
    if($id<1)$id=sf_auth_restore_user_id();
    if($id<1)return null;
    $q=sf_db()->prepare('SELECT id,status FROM users WHERE id=? LIMIT 1');
    $q->execute([$id]);
    $row=$q->fetch();
    if(!$row||($row['status']??'')!=='active')return null;
    return (int)$row['id'];
}

try{
    $body=sf_request_json();
    $cart=is_array($body['cart']??null)?$body['cart']:[];
    $userId=sf_quote_current_user_id();
    $quote=sf_quote($cart,sf_clean_text($body['campaign_code']??'',80),$userId);
    if(!$quote['ok'])sf_json_response($quote,422);
    sf_json_response($quote);
}catch(Throwable $e){
    $ref='CART-'.strtoupper(substr(bin2hex(random_bytes(5)),0,10));
    error_log('[Stonefellow quote '.$ref.'] '.get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
    sf_json_response(['ok'=>false,'error'=>'quote_temporarily_unavailable',
        'message'=>'Your cart is saved, but the total could not be calculated. Please retry. If it continues, contact support with reference '.$ref.'.',
        'reference'=>$ref],503);
}
