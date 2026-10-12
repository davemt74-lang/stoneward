<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/fan-journey-intelligence.php';
require_once SF_ROOT.'/api/fan-journey-recommendations.php';
$admin=sf_admin_require_auth(false);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
header('Cache-Control: no-store, private');
sf_json_response(['ok'=>true]+sf_fan_journey_recommendations((int)($_GET['days']??30)));
