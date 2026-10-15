<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/stem-studio-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_admin_require_auth($method!=='GET');
header('Cache-Control: private, no-store');
try{
    if($method==='GET'){
        $id=(string)($_GET['track_id']??'');
        $project=sf_stem_project_read($id);
        sf_json_response(['ok'=>true,'project'=>$project?sf_stem_project_with_assets($project):null]);
    }
    if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
    if(isset($_FILES['file'])){
        $id=(string)($_POST['track_id']??'');
        $file=(array)$_FILES['file'];
        if(($file['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??''))
            ||(int)($file['size']??0)>8388608||!preg_match('/\.rpp$/i',(string)($file['name']??'')))
            throw new InvalidArgumentException('Upload a REAPER .rpp project (maximum 8 MB).');
        $raw=file_get_contents((string)$file['tmp_name']);
        if(!is_string($raw))throw new RuntimeException('Cannot read REAPER project.');
        $project=sf_stem_project_import($id,$raw,(string)$file['name'],(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'stem_project_import','track',$id,['tracks'=>count($project['tracks']),'sha256'=>$project['source_sha256']]);
        sf_json_response(['ok'=>true,'project'=>sf_stem_project_with_assets($project)]);
    }
    $body=sf_request_json();$id=(string)($body['track_id']??'');
    $project=sf_stem_project_update($id,$body);
    sf_log_admin_action((int)$me['id'],'stem_project_update','track',$id,['action'=>(string)($body['action']??'')]);
    sf_json_response(['ok'=>true,'project'=>sf_stem_project_with_assets($project)]);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('Stem Studio: '.get_class($e).': '.$e->getMessage());sf_json_response(['ok'=>false,'message'=>'Stem project could not be completed.'],409);}
