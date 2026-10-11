<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_story_ensure_schema();$method=$_SERVER['REQUEST_METHOD']??'GET';$user=sf_current_user();$uid=(int)($user['id']??0);
if($method==='GET'){
    $slug=sf_clean_text($_GET['slug']??'',160);$mode=sf_clean_text($_GET['mode']??'',40);$entityType=sf_clean_text($_GET['entity_type']??'',40);$entityId=sf_clean_text($_GET['entity_id']??'',160);
    if($slug!==''){$e=sf_story_get($slug,true);if(!$e)sf_json_response(['ok'=>false,'message'=>'Story not found.'],404);sf_story_event((int)$e['id'],'view',$uid);sf_json_response(['ok'=>true,'story'=>$e]);}
    if($mode==='about')sf_json_response(['ok'=>true,'storytelling'=>sf_story_about_payload()]);
    if($entityType!==''&&$entityId!=='')sf_json_response(['ok'=>true,'stories'=>sf_story_for_entity($entityType,$entityId,true)]);
    sf_json_response(['ok'=>true,'stories'=>sf_story_list(true,'',100)]);
}
if($method==='POST'){
    $now=time();$attempts=array_values(array_filter((array)($_SESSION['sf_story_events']??[]),fn($t)=>(int)$t>$now-600));if(count($attempts)>=60)sf_json_response(['ok'=>false,'message'=>'Too many story events.'],429);$attempts[]=$now;$_SESSION['sf_story_events']=$attempts;
    $b=sf_request_json();sf_story_event((int)($b['story_id']??0),(string)($b['event_type']??''),$uid,(array)($b['detail']??[]));sf_json_response(['ok'=>true]);
}
sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
