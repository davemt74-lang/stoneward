<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_artist_home_ensure_schema();$method=$_SERVER['REQUEST_METHOD']??'GET';$user=sf_current_user();$uid=(int)($user['id']??0);
if($method==='GET'){sf_json_response(['ok'=>true,'homepage'=>sf_artist_home_payload($uid)]);}
if($method==='POST'){$b=sf_request_json();$event=(string)($b['event_type']??'');sf_artist_home_event($event,(string)($b['section_key']??''),(string)($b['entity_type']??''),(string)($b['entity_id']??''),$uid,(array)($b['detail']??[]));sf_json_response(['ok'=>true]);}
sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
