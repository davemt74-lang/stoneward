<?php
declare(strict_types=1); require __DIR__.'/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST') sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
try{$body=sf_request_json();$cart=is_array($body['cart']??null)?$body['cart']:[];$user=sf_current_user();$q=sf_quote($cart,sf_clean_text($body['campaign_code']??'',80),$user?(int)$user['id']:null);if(!$q['ok'])sf_json_response($q,422);sf_json_response($q);}catch(Throwable $e){sf_json_response(['ok'=>false,'error'=>'server_error'],500);}
