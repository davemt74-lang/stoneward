<?php
declare(strict_types=1);

@ini_set('display_errors','0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-canvas-images.php';
require_once __DIR__.'/agent-canvas-image-references.php';
require_once __DIR__.'/agent-canvas-image-search.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
if(!in_array($method,['GET','HEAD'],true)){
    header('Allow: GET, HEAD');
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
}

try{
    $id=$_GET['id']??null;
    $referenceRequest=sf_agent_canvas_reference_id($id)||($_GET['scope']??'')==='reference';
    $user=$referenceRequest?null:sf_require_user();
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $userId=(int)($user['id']??0);
    if(!isset($_GET['id'])){
        $scope=$_GET['scope']??'saved';
        $query=$_GET['q']??'';
        if(!is_string($scope)||!in_array($scope,['saved','reference','all'],true)||!is_string($query)||strlen($query)>1800)
            sf_json_response(['ok'=>false,'error'=>'invalid_canvas_image_query'],400);
        $limit=filter_var($_GET['limit']??24,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        $before=$_GET['before']??null;
        $validCursor=$scope==='all'?sf_agent_canvas_image_asset_id($before):($referenceRequest?sf_agent_canvas_reference_id($before):sf_agent_canvas_image_id($before));
        if($before!==null&&!$validCursor)
            sf_json_response(['ok'=>false,'error'=>'invalid_canvas_image_cursor'],400);
        $page=sf_agent_canvas_image_search($userId,$query,$limit===false?24:$limit,$before,$scope);
        sf_json_response(['ok'=>true]+$page);
    }
    $image=sf_agent_canvas_reference_id($id)?sf_agent_canvas_reference_file($id)
        :(sf_agent_canvas_image_id($id)?sf_agent_canvas_image_file($userId,$id):null);
    $file=$image===null?false:@fopen($image['path'],'rb');
    if($file===false)sf_json_response(['ok'=>false,'error'=>'canvas_image_not_found'],404);
    header('Cache-Control: private, no-store, no-transform');
    header('Content-Type: '.$image['mime']);
    header('Content-Length: '.$image['size']);
    if($method==='GET')fpassthru($file);
    fclose($file);
}catch(Throwable $error){
    error_log('[Stonefellow canvas images] '.get_class($error));
    sf_json_response(['ok'=>false,'error'=>'canvas_images_unavailable'],503);
}
