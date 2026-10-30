<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';
require_once __DIR__.'/agent-song-annotations-core.php';
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true))sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$user=sf_require_user(false,$method==='POST');
$userId=(int)$user['id'];
header('Cache-Control: no-store, private');
try{
    if($method==='GET'){
        sf_json_response(['ok'=>true,'annotations'=>sf_song_annotation_list($userId)]);
    }
    $request=sf_request_json();
    $action=(string)($request['action']??'');
    if($action==='save'){
        $entry=sf_song_annotation_save($userId,$request,sf_agent_public_catalog());
        sf_json_response(['ok'=>true,'annotation'=>$entry]);
    }
    if($action==='delete'){
        $id=filter_var($request['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)throw new InvalidArgumentException('A valid annotation ID is required.');
        sf_json_response(['ok'=>true,'deleted'=>sf_song_annotation_delete($userId,(int)$id)]);
    }
    sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'error'=>'invalid_song_annotation','message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
    sf_json_response(['ok'=>false,'error'=>'song_annotation_unavailable','message'=>$e->getMessage()],409);
}catch(Throwable $ignored){
    sf_json_response(['ok'=>false,'error'=>'song_annotation_failed','message'=>'The private song annotation operation did not complete.'],503);
}
