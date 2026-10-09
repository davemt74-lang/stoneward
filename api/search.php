<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_search_ensure_schema();
$method=$_SERVER['REQUEST_METHOD']??'GET';$u=sf_current_user();$uid=$u?(int)$u['id']:null;
if($method==='GET'){
    $result=sf_catalog_search($_GET,$uid);
    $result['recent_searches']=$uid?sf_search_recent_for_user($uid):[];
    sf_json_response(['ok'=>true,'search'=>$result,'csrf'=>$uid?sf_user_csrf():null]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
if($uid)sf_require_csrf();
$b=sf_request_json();$action=(string)($b['action']??'search');$sessionKey=sf_clean_text((string)($b['session_key']??''),160);
if($action==='search'){
    $query=is_array($b['query']??null)?$b['query']:$b;$result=sf_catalog_search($query,$uid);sf_search_record_event($uid,$sessionKey,'search',['query'=>$result['query'],'result_count'=>$result['result_count']]);$result['recent_searches']=$uid?sf_search_recent_for_user($uid):[];
    sf_json_response(['ok'=>true,'search'=>$result,'csrf'=>$uid?sf_user_csrf():null]);
}
if($action==='click'){
    $type=(string)($b['result_type']??'');$id=sf_clean_text((string)($b['result_id']??''),180);$valid=$type==='track'?isset(sf_track_map()[$id]):($type==='release'?isset(sf_release_map()[$id]):false);if(!$valid)sf_json_response(['ok'=>false,'message'=>'Search result is no longer available.'],404);
    sf_search_record_event($uid,$sessionKey,'click',['query'=>is_array($b['query']??null)?$b['query']:[],'result_type'=>$type,'result_id'=>$id,'result_count'=>max(0,(int)($b['result_count']??0))]);sf_json_response(['ok'=>true]);
}
if($action==='clear_history'){
    if(!$uid)sf_json_response(['ok'=>false,'error'=>'authentication_required'],401);$deleted=sf_search_clear_user_history($uid);sf_json_response(['ok'=>true,'deleted'=>$deleted,'recent_searches'=>[]]);
}
sf_json_response(['ok'=>false,'message'=>'Unsupported search action.'],422);
