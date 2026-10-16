<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/fan-engagement-source.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$user=sf_require_user(false,false);
$uid=(int)$user['id']; // Authenticated session only; never trust query-supplied user_id.
try {
    sf_json_response(['ok'=>true,'signals'=>sf_fan_engagement_owner_signals($uid,(int)($_GET['days']??30))]);
} catch(Throwable $e) {
    error_log('[stonefellow] owner engagement signals unavailable: '.get_class($e));
    sf_json_response(['ok'=>false,'error'=>'engagement_signals_unavailable'],503);
}
