<?php
declare(strict_types=1);
@ini_set('display_errors','0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-canvas-photo-core.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    header('Allow: POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
}
$user=sf_require_user(false,true);
try{
    $upload=$_FILES['file']??null;
    if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||
        !is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name']))
        throw new InvalidArgumentException('Choose a photo to upload.');
    $size=@filesize($upload['tmp_name']);
    if(!is_int($size)||$size<200||$size>SF_AGENT_CANVAS_PHOTO_MAX_BYTES)throw new InvalidArgumentException('Choose a photo no larger than 10 MB.');
    $bytes=@file_get_contents($upload['tmp_name'],false,null,0,SF_AGENT_CANVAS_PHOTO_MAX_BYTES+1);
    if(!is_string($bytes)||strlen($bytes)!==$size)throw new InvalidArgumentException('The photo could not be read.');
    $image=sf_agent_canvas_photo_store((int)$user['id'],$bytes,is_string($upload['name']??null)?$upload['name']:'',is_string($_POST['title']??null)?$_POST['title']:'');
    sf_json_response(['ok'=>true,'image'=>$image],201);
}catch(InvalidArgumentException $error){sf_json_response(['ok'=>false,'error'=>'invalid_photo','message'=>$error->getMessage()],422);}
catch(Throwable $error){error_log('[Stonefellow private photo] '.get_class($error));sf_json_response(['ok'=>false,'error'=>'photo_upload_unavailable','message'=>'Your photo could not be saved.'],503);}
