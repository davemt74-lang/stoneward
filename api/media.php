<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';sf_media_ensure_schema();$id=sf_clean_text($_GET['id']??'',64);$variant=sf_clean_text($_GET['variant']??'original',20);$download=!empty($_GET['download']);$asset=sf_media_asset($id);if(!$asset){http_response_code(404);exit('Media not found.');}
require_once __DIR__.'/media-stream-range.php';
// A private original must remain closed even if a stale/duplicate public
// media link exists. The website owned-download endpoint verifies purchase.
if(sf_media_protected_master_asset((int)$asset['id'])){
    $viewer=sf_current_user();
    if(!$viewer||($viewer['role']??'')!=='admin'){http_response_code(404);exit('Media not available.');}
}
$admin=false;$u=null;try{$u=sf_current_user();$admin=$u&&($u['role']??'')==='admin';}catch(Throwable $e){}
if(!$admin){$q=sf_db()->prepare('SELECT entity_type,entity_id,public_visible,download_allowed FROM media_links WHERE asset_id=? AND public_visible=1');$q->execute([(int)$asset['id']]);$links=$q->fetchAll();$viewAllowed=false;$downloadAllowed=false;foreach($links as $link){$allowed=true;if(($link['entity_type']??'')==='member_content'){$content=function_exists('sf_membership_content_get')?sf_membership_content_get((int)$link['entity_id']):null;$access=$content&&function_exists('sf_membership_content_access')?sf_membership_content_access($u?(int)$u['id']:null,$content):['allowed'=>false];$allowed=!empty($access['allowed']);}if($allowed){$viewAllowed=true;if(!empty($link['download_allowed']))$downloadAllowed=true;}}if(!$viewAllowed){http_response_code(404);exit('Media not available.');}if($download&&!$downloadAllowed){http_response_code(403);exit('Download not allowed.');}}
$rel=(string)$asset['stored_path'];if($variant!=='original'&&!empty($asset['variants'][$variant]['path']))$rel=(string)$asset['variants'][$variant]['path'];$root=realpath(SF_ROOT.'/storage/media');$file=realpath(SF_ROOT.'/'.ltrim($rel,'/'));if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);exit('Media file missing.');}$size=filesize($file)?:0;$mime=$variant==='original'?(string)$asset['mime_type']:(str_ends_with($file,'.webp')?'image/webp':'image/jpeg');if($variant==='original'&&strtolower((string)$asset['extension'])==='vtt')$mime='text/vtt';
if($size<1){http_response_code(404);exit('Media file empty.');}
try{$range=sf_media_stream_range($download?'':(string)($_SERVER['HTTP_RANGE']??''),$size);}
catch(InvalidArgumentException $e){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
http_response_code($range['partial']?206:200);
header('Content-Type: '.$mime);
header('Accept-Ranges: bytes');
// Avoid on-the-fly compression of byte-range audio, which invalidates offsets.
header('Cache-Control: '.($admin?'private, no-store':'public, max-age=3600').', no-transform');
header('Content-Length: '.$range['length']);
if($range['partial'])header('Content-Range: bytes '.$range['start'].'-'.$range['end'].'/'.$size);
if($download)header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$asset['original_name']).'"');
if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')exit;
$fh=fopen($file,'rb');if(!$fh)exit;fseek($fh,$range['start']);$left=$range['length'];
while($left>0&&!feof($fh)&&!connection_aborted()){
    $chunk=fread($fh,min(65536,$left));
    if($chunk===false||$chunk==='')break;
    echo $chunk;$left-=strlen($chunk);
}
fclose($fh);exit;
