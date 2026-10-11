<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_songbook_ensure_schema();$method=$_SERVER['REQUEST_METHOD']??'GET';$user=sf_current_user();$uid=(int)($user['id']??0);
if($method==='GET'){$trackId=sf_clean_text($_GET['track_id']??'',160);$book=sf_songbook_public_payload($trackId);if(!$book)sf_json_response(['ok'=>false,'message'=>'Songbook not found.'],404);sf_json_response(['ok'=>true,'songbook'=>$book]);}
if($method==='POST'){$now=time();$attempts=array_values(array_filter((array)($_SESSION['sf_songbook_events']??[]),fn($t)=>(int)$t>$now-600));if(count($attempts)>=60)sf_json_response(['ok'=>false,'message'=>'Too many Songbook events.'],429);$attempts[]=$now;$_SESSION['sf_songbook_events']=$attempts;$b=sf_request_json();$trackId=sf_clean_text($b['track_id']??'',160);if(!sf_songbook_public_payload($trackId))sf_json_response(['ok'=>false,'message'=>'Songbook not found.'],404);sf_songbook_event($trackId,(string)($b['event_type']??''),$uid,(array)($b['detail']??[]));sf_json_response(['ok'=>true]);}
sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
