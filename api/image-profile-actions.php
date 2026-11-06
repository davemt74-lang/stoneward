<?php
declare(strict_types=1);
@ini_set('display_errors','0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/image-profile.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){
    header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
}
$user=sf_require_user(false,$method==='POST');$userId=(int)$user['id'];
header('Cache-Control: private, no-store');
try{
    if($method==='GET')sf_json_response(['ok'=>true,'suggestions'=>sf_image_profile_list($userId,($_GET['include_dismissed']??null)==='1'),
        'personalization_enabled'=>sf_privacy_personalization_enabled($userId),'csrf'=>sf_user_csrf()]);
    $body=sf_request_json();$id=$body['id']??null;$action=$body['action']??null;
    if(!is_int($id)||!is_string($action))throw new InvalidArgumentException('Choose a photo preference to review.');
    sf_json_response(['ok'=>true,'suggestion'=>sf_image_profile_update($userId,$id,$action),'csrf'=>sf_user_csrf()]);
}catch(OutOfBoundsException $error){
    sf_json_response(['ok'=>false,'error'=>'profile_suggestion_not_found','message'=>$error->getMessage()],404);
}catch(InvalidArgumentException $error){
    sf_json_response(['ok'=>false,'error'=>'invalid_profile_suggestion','message'=>$error->getMessage()],422);
}catch(Throwable $error){
    error_log('[Stonefellow photo preferences] '.get_class($error));
    sf_json_response(['ok'=>false,'error'=>'photo_preferences_unavailable','message'=>'Photo preferences are temporarily unavailable.'],503);
}
