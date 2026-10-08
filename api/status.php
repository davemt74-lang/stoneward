<?php
declare(strict_types=1); require __DIR__.'/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='GET') sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$id=(string)($_GET['id']??'');$token=(string)($_GET['token']??'');$o=sf_read_order($id);if(!$o||$token===''||!hash_equals((string)($o['confirmation_token_hash']??''),hash('sha256',$token)))sf_json_response(['ok'=>false,'error'=>'not_found'],404);
unset($o['confirmation_token_hash']);sf_json_response(['ok'=>true,'order'=>$o]);
