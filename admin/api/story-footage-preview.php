<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
require_once SF_ROOT.'/api/story-capture-core.php';
require_once SF_ROOT.'/api/story-chat-footage-preview-core.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header('Referrer-Policy: no-referrer');
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','HEAD'],true)){header('Allow: GET, HEAD');http_response_code(405);exit;}
try{
 $me=sf_admin_require_auth(false);
 $raw=$_GET['submission_id']??'';
 if(!is_scalar($raw)||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$raw))
  throw new InvalidArgumentException('Invalid submission.');
 $preview=sf_chat_footage_resolve((int)$me['id'],(int)$raw);
}catch(Throwable $e){http_response_code(404);exit;}
$size=$preview['size'];$file=$preview['file'];
try{$range=sf_chat_footage_range((string)($_SERVER['HTTP_RANGE']??''),$size);}
catch(Throwable $e){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
http_response_code($range['status']);
header('Content-Type: '.$preview['mime']);
header('Accept-Ranges: bytes');
header('Content-Disposition: inline');
$length=$range['end']-$range['start']+1;
if($range['status']===206)
 header('Content-Range: bytes '.$range['start'].'-'.$range['end'].'/'.$size);
header('Content-Length: '.$length);
if($method==='HEAD')exit;
$handle=@fopen($file,'rb');if(!$handle){http_response_code(404);exit;}
fseek($handle,$range['start']);
$left=$length;
while($left>0&&!feof($handle)){
 $chunk=fread($handle,min(262144,$left));
 if($chunk===false||$chunk==='')break;
 echo $chunk;
 $left-=strlen($chunk);
 if(connection_aborted())break;
}
fclose($handle);
