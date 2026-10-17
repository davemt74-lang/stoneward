<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/artist-world-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
header('Cache-Control: public, max-age=60');
sf_json_response(['ok'=>true,'world'=>sf_world_public_graph()]);
