<?php
declare(strict_types=1);
require __DIR__.'/section88d2c-scenes.php';
if(!defined('SF_ROOT'))define('SF_ROOT',dirname(__DIR__));
require_once dirname(__DIR__).'/api/story-chat-footage-preview-core.php';
$checks=0;
function pvPass(bool $ok,string $label):void{
 global $checks;
 if(!$ok)throw new RuntimeException('G5A preview FAIL: '.$label);
 echo 'G5A PASS '.$label.PHP_EOL;$checks++;
}
function pvReject(callable $fn,string $label):void{
 $rejected=false;try{$fn();}catch(Throwable $e){$rejected=true;}
 pvPass($rejected,$label);
}
$db->exec('ALTER TABLE media_assets ADD COLUMN mime_type TEXT');
$db->exec('ALTER TABLE media_assets ADD COLUMN stored_path TEXT');
$folder=SF_ROOT.'/storage/media/g5a-ci-preview';
if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))
 throw new RuntimeException('Cannot create preview fixture directory.');
$name='test-'.bin2hex(random_bytes(5)).'.mp4';
$file=$folder.'/'.$name;
if(file_put_contents($file,str_repeat('x',4096))!==4096)
 throw new RuntimeException('Cannot write preview test fixture.');
register_shutdown_function(static function()use($file,$folder):void{@unlink($file);@rmdir($folder);});
$db->prepare('UPDATE media_assets SET mime_type=?,stored_path=? WHERE id=4')
 ->execute(['video/mp4','storage/media/g5a-ci-preview/'.$name]);
$source=sf_chat_footage_resolve(1,$v);
pvPass($source['mime']==='video/mp4'&&$source['size']===4096&&$source['file']===$file,
 'Authorized editor resolves only their bound private video capture.');
pvReject(fn()=>sf_chat_footage_resolve(2,$v),'Unprivileged member denied private capture bytes.');
pvReject(fn()=>sf_chat_footage_resolve(1,999999),'Unknown capture ID does not resolve.');
pvPass(sf_chat_footage_range('',4096)['status']===200,
 'Ordinary request resolves full source.');
$r=sf_chat_footage_range('bytes=100-199',4096);
pvPass($r['status']===206&&$r['start']===100&&$r['end']===199,
 'Video seek requests return exact bounded ranges.');
$r=sf_chat_footage_range('bytes=-50',4096);
pvPass($r['start']===4046&&$r['end']===4095,
 'Suffix byte ranges support native browser video playback.');
$r=sf_chat_footage_range('bytes=4090-',4096);
pvPass($r['start']===4090&&$r['end']===4095,
 'Open-ended ranges clamp at the actual file boundary.');
pvReject(fn()=>sf_chat_footage_range('bytes=4096-',4096),'Past-EOF seeks fail.');
pvReject(fn()=>sf_chat_footage_range('bytes=0-10,20-30',4096),
 'Multipart ranges cannot bypass single-range validation.');
pvReject(fn()=>sf_chat_footage_range('bytes=-0',4096),'Zero-length suffix denied.');
$db->prepare('UPDATE media_assets SET stored_path=? WHERE id=4')->execute(['api/bootstrap.php']);
pvReject(fn()=>sf_chat_footage_resolve(1,$v),
 'File outside storage/media is rejected even when it exists.');
$db->prepare('UPDATE media_assets SET stored_path=?,mime_type=? WHERE id=4')
 ->execute(['storage/media/g5a-ci-preview/'.$name,'text/html']);
pvReject(fn()=>sf_chat_footage_resolve(1,$v),
 'Active content MIME cannot be rendered as contributor preview.');
$endpoint=file_get_contents(dirname(__DIR__).'/admin/api/story-footage-preview.php');
$client=file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
pvPass(str_contains($endpoint,'sf_admin_require_auth(false)') &&
 str_contains($endpoint,'sf_chat_footage_resolve(') &&
 str_contains($endpoint,"'HEAD'") &&
 str_contains($endpoint,'Cache-Control: private, no-store') &&
 str_contains($client,'admin-agent-footage-preview') &&
 str_contains($client,'story-footage-preview.php?submission_id='),
 'Authenticated private server route and inline chat media are connected.');
echo "G5A PRIVATE PREVIEW: $checks assertions passed".PHP_EOL;
