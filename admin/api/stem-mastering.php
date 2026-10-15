<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/stem-mastering-core.php';
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
$admin=sf_admin_require_auth($method!=='GET');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try{
    if($method==='GET'){
        $id=(string)($_GET['track_id']??'');
        $project=sf_stem_project_read($id);
        if(!$project)throw new InvalidArgumentException('Private Stem Studio project not found.');
        if(($_GET['action']??'')==='reference'){
            $path=sf_stem_mastering_reference_path($id,$project);
            if(!$path)throw new InvalidArgumentException('No private reference recording stored.');
            $kind=strtolower(pathinfo($path,PATHINFO_EXTENSION));
            $size=(int)filesize($path);$start=0;$end=$size-1;
            header('Content-Type: '.($kind==='wav'?'audio/wav':'audio/mpeg'));
            header('Content-Disposition: inline; filename="stem-reference.'.$kind.'"');
            header('Accept-Ranges: bytes');
            $range=(string)($_SERVER['HTTP_RANGE']??'');
            if($range!==''){
                if(!preg_match('/^bytes=(\\d+)-(\\d*)$/D',$range,$matches))
                    sf_json_response(['ok'=>false,'message'=>'Invalid reference range.'],416);
                $start=(int)$matches[1];$end=$matches[2]===''?$end:(int)$matches[2];
                if($start>=$size||$end<$start||$end>=$size)
                    sf_json_response(['ok'=>false,'message'=>'Reference range not satisfiable.'],416);
                http_response_code(206);
                header("Content-Range: bytes $start-$end/$size");
            }
            header('Content-Length: '.($end-$start+1));
            $handle=fopen($path,'rb');if(!$handle)throw new RuntimeException('Private reference unavailable.');
            try{
                if(fseek($handle,$start)!==0)throw new RuntimeException('Cannot seek private reference.');
                $remaining=$end-$start+1;
                while($remaining>0&&!feof($handle)){
                    $chunk=fread($handle,min($remaining,65536));
                    if($chunk===false||$chunk==='')break;
                    echo $chunk;$remaining-=strlen($chunk);
                }
            }finally{fclose($handle);}
            exit;
        }
        sf_json_response(['ok'=>true,'mastering_reference'=>$project['mastering_reference']??null,
            'analyses'=>array_slice((array)($project['mastering_analyses']??[]),-12)]);
    }
    if($method!=='POST')sf_json_response(['ok'=>false,'message'=>'Unsupported method.'],405);
    if(isset($_FILES['file'])){
        $id=(string)($_POST['track_id']??'');
        $revision=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT);
        if($revision===false||$revision===null)throw new InvalidArgumentException('Project revision is required.');
        $project=sf_stem_mastering_save_reference($id,(array)$_FILES['file'],$revision);
        sf_log_admin_action((int)$admin['id'],'stem_reference_import','track',$id,
           ['revision'=>$project['revision'],'sha256'=>$project['mastering_reference']['sha256']]);
        sf_json_response(['ok'=>true,'project'=>sf_stem_project_with_assets($project)]);
    }
    $payload=sf_request_json();
    if(($payload['action']??'')!=='save_analysis')throw new InvalidArgumentException('Unsupported mastering operation.');
    $id=(string)($payload['track_id']??'');
    $project=sf_stem_mastering_save_analysis($id,$payload);
    sf_log_admin_action((int)$admin['id'],'stem_mastering_analysis','track',$id,
       ['revision'=>$project['revision'],'source'=>'browser_measured']);
    sf_json_response(['ok'=>true,'project'=>sf_stem_project_with_assets($project)]);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Stem Mastering: '.get_class($e).': '.$e->getMessage());
    sf_json_response(['ok'=>false,'message'=>'Mastering operation failed. Refresh the project and check upload limits.'],409);
}
