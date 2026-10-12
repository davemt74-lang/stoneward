<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/fan-journey-intelligence.php';
$admin=sf_admin_require_auth(false);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$days=max(7,min(365,(int)($_GET['days']??30)));
header('Cache-Control: no-store, private');
sf_json_response(['ok'=>true,'journey'=>sf_fan_journey_counts($days)]);
