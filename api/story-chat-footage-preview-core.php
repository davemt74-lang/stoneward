<?php
declare(strict_types=1);
/**
 * G5A audit: authorized, same-origin private preview of contributor captures.
 * No public media link, model upload, download entitlement or publication.
 */
function sf_chat_footage_resolve(int $editorId,int $submissionId):array{
 if($editorId<1||$submissionId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Private production editor permission required.');
 if(!function_exists('sf_capture_schema'))require_once __DIR__.'/story-capture-core.php';
 sf_capture_schema();
 $q=sf_db()->prepare('SELECT a.id AS asset_id,a.category,a.mime_type,a.stored_path
   FROM story_capture_submissions s JOIN media_assets a ON a.id=s.asset_id
   WHERE s.id=? LIMIT 1');
 $q->execute([$submissionId]);$asset=$q->fetch(PDO::FETCH_ASSOC);
 if(!$asset||sf_media_protected_master_asset((int)$asset['asset_id']))
  throw new RuntimeException('Capture preview unavailable.');
 $allowed=[
  'image'=>['image/jpeg','image/png','image/webp','image/gif'],
  'video'=>['video/mp4','video/webm','video/quicktime']
 ];
 $kind=(string)$asset['category'];$mime=strtolower((string)$asset['mime_type']);
 if(!in_array($mime,$allowed[$kind]??[],true))
  throw new RuntimeException('This capture cannot be previewed in a browser.');
 $root=realpath(SF_ROOT.'/storage/media');
 $relative=(string)$asset['stored_path'];
 if($relative===''||str_starts_with($relative,'/')||str_contains($relative,'\\'))
  throw new RuntimeException('Capture source unavailable.');
 $file=realpath(SF_ROOT.'/'.ltrim($relative,'/'));
 if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file))
  throw new RuntimeException('Capture source unavailable.');
 $size=filesize($file);
 if($size===false||$size<1)throw new RuntimeException('Capture source unavailable.');
 return ['file'=>$file,'mime'=>$mime,'category'=>$kind,'size'=>(int)$size];
}
/** RFC 7233 single byte-range: supports seeking and suffix ranges, refuses multipart. */
function sf_chat_footage_range(string $requested,int $size):array{
 if($size<1)throw new InvalidArgumentException('Empty media source.');
 if($requested==='')return ['status'=>200,'start'=>0,'end'=>$size-1];
 if(!preg_match('/^bytes=(\\d*)-(\\d*)$/D',trim($requested),$m)||
    ($m[1]===''&&$m[2]===''))
  throw new OutOfRangeException('Unsupported range.');
 if($m[1]===''){
  $suffix=(int)$m[2];
  if($suffix<1)throw new OutOfRangeException('Invalid suffix.');
  $start=max(0,$size-$suffix);$end=$size-1;
 }else{
  $start=(int)$m[1];$end=$m[2]===''?$size-1:min($size-1,(int)$m[2]);
  if($start>=$size||$end<$start)throw new OutOfRangeException('Unsatisfiable range.');
 }
 return ['status'=>206,'start'=>$start,'end'=>$end];
}
