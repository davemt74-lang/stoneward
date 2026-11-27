<?php
declare(strict_types=1);
// Deliberately independent of accounts, sessions, database migrations and request maintenance.
if(!defined('SF_ROOT'))define('SF_ROOT',dirname(__DIR__));
require_once __DIR__.'/vp3-music-core.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'||$_GET){http_response_code(405);echo '{"ok":false,"code":"method_not_allowed"}';exit;}
try{$proof=sf_vp3_music_site_proof();if(!$proof){http_response_code(404);echo '{"ok":false,"code":"site_not_configured"}';exit;}echo sf_vp3_music_json($proof);}
catch(Throwable){http_response_code(503);echo '{"ok":false,"code":"site_unavailable"}';}
