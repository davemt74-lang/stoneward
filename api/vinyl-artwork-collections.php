<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/artist-world-vinyl.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
header('Cache-Control: no-store');
try{sf_json_response(['ok'=>true,'collections'=>sf_world_vinyl_public_collections(),
 'printing_note'=>'Only approved, public high-resolution Media originals are listed; purchased pressing licenses and production proof remain required.']);}
catch(Throwable $e){error_log('Vinyl photo collections unavailable: '.get_class($e));sf_json_response(['ok'=>false,'message'=>'Curated artwork temporarily unavailable.'],503);}
