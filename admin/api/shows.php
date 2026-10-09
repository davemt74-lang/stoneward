<?php
declare(strict_types=1); require __DIR__.'/bootstrap.php';

if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    sf_admin_require_auth(false);
    sf_json_response(['ok'=>true,'shows'=>sf_admin_shows()]);
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
sf_admin_require_auth(true);
$b=sf_request_json();$action=(string)($b['action']??'save');$rows=sf_admin_shows();

if($action==='delete'){
    $id=sf_admin_slug((string)($b['id']??''),'');
    $rows=array_values(array_filter($rows,fn($s)=>(string)($s['id']??'')!==$id));
    sf_admin_write_shows($rows);sf_json_response(['ok'=>true]);
}

$raw=is_array($b['show']??null)?$b['show']:[];
$date=sf_clean_text($raw['date']??'',25);$venue=sf_clean_text($raw['venue']??'',180);
if($date===''||$venue==='')sf_json_response(['ok'=>false,'message'=>'Show date and venue are required.'],422);
if(!preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2})?$/',$date))sf_json_response(['ok'=>false,'message'=>'Show date must use YYYY-MM-DD or YYYY-MM-DDTHH:MM.'],422);
$id=sf_admin_slug((string)($raw['id']??($date.'-'.$venue)),'show');
$idx=null;foreach($rows as $i=>$s)if((string)($s['id']??'')===$id){$idx=$i;break;}$old=$idx===null?[]:$rows[$idx];

$status=(string)($raw['status']??'scheduled');if(!in_array($status,['scheduled','completed','cancelled','postponed','archived'],true))$status='scheduled';
$catalog=sf_catalog();$trackMap=[];foreach($catalog as $t)if(!empty($t['id']))$trackMap[(string)$t['id']]=$t;
$normalizeTrackIds=function(mixed $value,string $label)use($trackMap):array{$ids=[];foreach((array)$value as $tid){$tid=sf_clean_text($tid,100);if($tid===''||in_array($tid,$ids,true))continue;if(!isset($trackMap[$tid]))sf_json_response(['ok'=>false,'message'=>'Unknown '.$label.' track: '.$tid],422);$ids[]=$tid;}return $ids;};
$media=[];foreach((array)($raw['media']??[]) as $m){if(!is_array($m))continue;$url=sf_clean_text($m['url']??'',500);if($url==='')continue;$media[]=['type'=>sf_clean_text($m['type']??'link',40),'title'=>sf_clean_text($m['title']??'Live archive item',180),'url'=>$url,'caption'=>sf_clean_text($m['caption']??'',500)];if(count($media)>=80)break;}

$show=[
 'schema'=>'stonefellow.show.v1','id'=>$id,'title'=>sf_clean_text($raw['title']??'',180),'date'=>$date,'status'=>$status,
 'venue'=>$venue,'city'=>sf_clean_text($raw['city']??'',120),'region'=>sf_clean_text($raw['region']??'',120),'country'=>sf_clean_text($raw['country']??'',120),
 'tour'=>sf_clean_text($raw['tour']??'',180),'era'=>sf_clean_text($raw['era']??'',180),'description'=>sf_clean_text($raw['description']??'',4000),
 'archive_notes'=>sf_clean_text($raw['archive_notes']??'',10000),'ticket_url'=>sf_clean_text($raw['ticket_url']??'',500),
 'poster'=>sf_clean_text($raw['poster']??'',500),'public_visible'=>sf_admin_bool($raw['public_visible']??true),'featured'=>sf_admin_bool($raw['featured']??false),
 'setlist_track_ids'=>$normalizeTrackIds($raw['setlist_track_ids']??[],'setlist'),
 'live_recording_track_ids'=>$normalizeTrackIds($raw['live_recording_track_ids']??[],'live recording'),
 'media'=>$media,'created_at'=>$old['created_at']??gmdate('c'),'updated_at'=>gmdate('c')
];
if($idx===null)$rows[]=$show;else$rows[$idx]=$show;
sf_admin_write_shows($rows);sf_json_response(['ok'=>true,'show'=>$show]);
