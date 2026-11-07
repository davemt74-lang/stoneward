<?php
declare(strict_types=1);

@ini_set('display_errors','0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/canvas-resource-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
    header('Allow: GET');
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
}
try{
    $kind=$_GET['kind']??'';
    $user=$kind==='playlists'?(sf_current_user()??[]):sf_require_user(false,false);
    if(!is_string($kind))throw new InvalidArgumentException('Choose canvas, merchandise, or ticket resources.');
    sf_json_response(['ok'=>true,'kind'=>$kind,'items'=>sf_canvas_resource_inventory((int)($user['id']??0),$kind)]);
}catch(InvalidArgumentException $error){
    sf_json_response(['ok'=>false,'error'=>'invalid_canvas_resources','message'=>$error->getMessage()],422);
}catch(Throwable $error){
    error_log('[Stonefellow canvas resources] '.get_class($error));
    sf_json_response(['ok'=>false,'error'=>'canvas_resources_unavailable','message'=>'Canvas resources are temporarily unavailable.'],503);
}
