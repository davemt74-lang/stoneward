<?php
declare(strict_types=1); require __DIR__.'/bootstrap.php'; sf_admin_require_auth(true);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST') sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
if(!isset($_FILES['file'])||!is_array($_FILES['file']))sf_json_response(['ok'=>false,'message'=>'No audio file was received.'],422);
$f=$_FILES['file'];if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){$code=(int)($f['error']??-1);sf_json_response(['ok'=>false,'message'=>'Upload failed with PHP upload code '.$code.'. Check upload_max_filesize and post_max_size.'],422);}
$batch=sf_admin_slug((string)($_POST['batch_id']??''),'');$trackKey=sf_admin_slug((string)($_POST['track_key']??''),'track');if($batch==='')sf_json_response(['ok'=>false,'message'=>'Batch id is required.'],422);
$name=basename((string)($f['name']??'audio'));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,['mp3','wav','wave'],true))sf_json_response(['ok'=>false,'message'=>'Only MP3 and WAV files are accepted.'],422);
$tmp=(string)($f['tmp_name']??'');if(!is_uploaded_file($tmp))sf_json_response(['ok'=>false,'message'=>'Invalid uploaded file.'],422);
$size=(int)($f['size']??0);$hash=hash_file('sha256',$tmp);if(!$hash)throw new RuntimeException('Could not hash upload.');
$index=sf_admin_media_index();$existing=$index['files'][$hash]??null;$duplicate=false;$stored='';
if(is_array($existing)&&!empty($existing['stored_path'])&&is_file(SF_ROOT.'/'.ltrim((string)$existing['stored_path'],'/'))){$duplicate=true;$stored=(string)$existing['stored_path'];}
else{
  $dir=SF_ROOT.'/storage/media/originals/'.$trackKey;if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create media storage.');
  $safe=sf_admin_slug(pathinfo($name,PATHINFO_FILENAME),'audio').'-'.substr($hash,0,10).'.'.$ext;$abs=$dir.'/'.$safe;
  if(!move_uploaded_file($tmp,$abs))throw new RuntimeException('Could not store uploaded file.');
  $stored='storage/media/originals/'.$trackKey.'/'.$safe;$index['files'][$hash]=['sha256'=>$hash,'stored_path'=>$stored,'size'=>$size,'extension'=>$ext,'first_seen_at'=>gmdate('c')];sf_admin_write_media_index($index);
}
$uploadId='UP-'.strtoupper(bin2hex(random_bytes(6)));$record=['schema'=>'stonefellow.upload.v1','id'=>$uploadId,'batch_id'=>$batch,'track_key'=>$trackKey,'original_name'=>$name,'relative_path'=>sf_clean_text($_POST['relative_path']??$name,500),'extension'=>$ext,'size'=>$size,'sha256'=>$hash,'stored_path'=>$stored,'duplicate'=>$duplicate,'created_at'=>gmdate('c')];
$dir=SF_ROOT.'/storage/imports/'.$batch.'/uploads';if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create import storage.');sf_write_json($dir.'/'.$uploadId.'.json',$record);
sf_json_response(['ok'=>true,'upload'=>array_diff_key($record,['stored_path'=>true])]);
