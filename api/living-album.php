<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/living-album-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$id=sf_clean_text($_GET['release_id']??'',160);
if(!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id))sf_json_response(['ok'=>false,'error'=>'invalid_release'],422);
try{$album=sf_living_album_public($id);
    if(!$album)sf_json_response(['ok'=>false,'error'=>'not_found'],404);
    sf_json_response(['ok'=>true,'album'=>$album]);
}catch(Throwable $e){error_log('[Living Albums] '.get_class($e));sf_json_response(['ok'=>false,'error'=>'temporarily_unavailable'],503);}
