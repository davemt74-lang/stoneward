<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$catalog=sf_catalog();
$releasePath=SF_ROOT.'/data/releases.json';
$releases=[];
if(is_file($releasePath)){
  $raw=json_decode((string)file_get_contents($releasePath),true);
  if(is_array($raw)) $releases=array_values(array_filter($raw,function($r){
    return is_array($r) && (($r['state']??'')==='published') && (($r['public_visible']??true)!==false);
  }));
}
$archive=sf_archive_payload($catalog,$releases);
$shows=sf_live_public_shows();
sf_json_response(['ok'=>true,'catalog'=>$catalog,'releases'=>$releases,'archive'=>$archive,'shows'=>$shows,'generated_at'=>gmdate('c')]);
