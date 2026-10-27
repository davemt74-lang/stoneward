<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if(!in_array($method,['GET','POST'],true)){
    header('Allow: GET, POST');
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
}
$user=sf_admin_require_auth($method==='POST');
$userId=(int)$user['id'];
$dir=SF_ROOT.'/storage/admin-profile-photos';
$path=$dir.'/avatar-'.$userId;

if($method==='POST'){
    $file=$_FILES['photo']??null;
    if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
        sf_json_response(['ok'=>false,'error'=>'invalid_upload','message'=>'Select a JPEG, PNG or WebP photo.'],422);
    }
    $size=(int)($file['size']??0);
    $tmp=(string)($file['tmp_name']??'');
    if($size<1||$size>2*1024*1024||!is_uploaded_file($tmp)){
        sf_json_response(['ok'=>false,'error'=>'invalid_upload','message'=>'The photo must be smaller than 2 MB.'],422);
    }
    $allowed=['image/jpeg'=>true,'image/png'=>true,'image/webp'=>true];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $dimensions=@getimagesize($tmp);
    if(!isset($allowed[$mime])||!is_array($dimensions)
       ||(int)$dimensions[0]<1||(int)$dimensions[1]<1
       ||(int)$dimensions[0]>4096||(int)$dimensions[1]>4096
       ||((int)$dimensions[0]*(int)$dimensions[1])>12000000
       ||($dimensions['mime']??'')!==$mime){
        sf_json_response(['ok'=>false,'error'=>'invalid_image','message'=>'Use a valid JPEG, PNG or WebP image up to 4096 pixels per side.'],422);
    }
    if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir)){
        sf_json_response(['ok'=>false,'error'=>'storage_unavailable','message'=>'Profile photo storage is unavailable.'],500);
    }
    $staging=$dir.'/upload-'.$userId.'-'.bin2hex(random_bytes(8));
    if(!move_uploaded_file($tmp,$staging)){
        sf_json_response(['ok'=>false,'error'=>'upload_failed','message'=>'The profile photo could not be saved.'],500);
    }
    @chmod($staging,0640);
    if(!@rename($staging,$path)){
        @unlink($staging);
        sf_json_response(['ok'=>false,'error'=>'upload_failed','message'=>'The profile photo could not be finalized.'],500);
    }
    sf_json_response(['ok'=>true,'avatar_url'=>'api/profile-photo.php?v='.rawurlencode(bin2hex(random_bytes(5)))]);
}

header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if(is_file($path)&&is_readable($path)){
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if(in_array($mime,['image/jpeg','image/png','image/webp'],true)){
        header('Content-Type: '.$mime);
        header('Content-Disposition: inline; filename="profile-photo"');
        header('Content-Length: '.(string)filesize($path));
        readfile($path);
        exit;
    }
}

// A polished identity avatar is always available, even for a new account.
$name=trim((string)($user['display_name']??''));
$initials='SF';
if($name!==''){
    $parts=preg_split('/\s+/u',$name)?:[];
    $letters=[];
    foreach([$parts[0]??'',count($parts)>1?end($parts):''] as $part){
        if(preg_match('/[A-Za-z0-9]/',$part,$match))$letters[]=strtoupper($match[0]);
    }
    if($letters)$initials=implode('',array_slice($letters,0,2));
}
header('Content-Type: image/svg+xml; charset=UTF-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" role="img" aria-label="User avatar">';
echo '<rect width="96" height="96" rx="48" fill="#35291a"/><circle cx="48" cy="48" r="45" fill="none" stroke="#caa96d" stroke-width="2"/>';
echo '<text x="48" y="60" fill="#f1e4c6" font-size="34" font-family="Georgia,serif" text-anchor="middle">'.htmlspecialchars($initials,ENT_XML1|ENT_QUOTES,'UTF-8').'</text></svg>';
