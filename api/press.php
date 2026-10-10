<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_press_ensure_schema();$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){$slug=sf_clean_text($_GET['slug']??'',160);$token=(string)($_GET['token']??'');$kit=sf_press_public_kit($slug,$token);if(!$kit)sf_json_response(['ok'=>false,'message'=>'Press kit not found or access is invalid.'],404);sf_press_event((int)$kit['id'],'view',[]);sf_json_response(['ok'=>true,'kit'=>$kit]);}
if($method==='POST'){$b=sf_request_json();$slug=sf_clean_text($b['slug']??'',160);$token=(string)($b['token']??'');$kit=sf_press_public_kit($slug,$token);if(!$kit)sf_json_response(['ok'=>false,'message'=>'Press kit not found or access is invalid.'],404);$event=(string)($b['event_type']??'');sf_press_event((int)$kit['id'],$event,(array)($b['detail']??[]));sf_json_response(['ok'=>true]);}
sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
