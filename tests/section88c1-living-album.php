<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/living-album-core.php';
$rel=['id'=>'album-one','title'=>'Public Album','state'=>'published','public_visible'=>true,
 'description'=>'A new chapter','credits'=>['Stonefellow'], 'track_ids'=>['a','b','hidden','a']];
$tracks=['a'=>['id'=>'a','title'=>'First Song','duration'=>180,'story'=>'Something true',
 'metadata'=>['recording_notes'=>'Recorded live']],
 'b'=>['id'=>'b','title'=>'Second Song','duration'=>215],
 'hidden'=>['id'=>'hidden','title'=>'HIDDEN SONG','state'=>'draft']];
$stories=[
 ['id'=>11,'status'=>'published','readiness'=>['ready'=>true],'slug'=>'true-story','title'=>'A true story',
   'body_text'=>'Storytelling', 'entity_type'=>'release','entity_id'=>'album-one','media'=>[]],
 ['id'=>12,'status'=>'draft','readiness'=>['ready'=>true],'slug'=>'secret-story','title'=>'HIDDEN STORY',
   'entity_type'=>'track','entity_id'=>'a'],
 ['id'=>13,'status'=>'published','readiness'=>['ready'=>false],'slug'=>'unready-story','title'=>'HIDDEN UNREADY',
   'entity_type'=>'track','entity_id'=>'a'],
 ['id'=>14,'status'=>'published','readiness'=>['ready'=>true],'slug'=>'song-story','title'=>'Song memory',
   'entity_type'=>'track','entity_id'=>'a','media'=>[]],
 ['id'=>15,'status'=>'published','readiness'=>['ready'=>true],'slug'=>'hidden-track-story','title'=>'HIDDEN TRACK STORY',
   'entity_type'=>'track','entity_id'=>'hidden']];
$videos=[['id'=>3,'slug'=>'live-session','title'=>'Session','entities'=>[['type'=>'track','id'=>'a']]],
 ['id'=>5,'slug'=>'private-session','title'=>'HIDDEN VIDEO','entities'=>[['type'=>'track','id'=>'hidden']]]];
$galleries=[['id'=>4,'slug'=>'photos','title'=>'Photos','entities'=>[['type'=>'release','id'=>'album-one']]],
 ['id'=>6,'slug'=>'private-gallery','title'=>'HIDDEN GALLERY','entities'=>[['type'=>'release','id'=>'private']]]];
$a=sf_living_album_assemble($rel,$tracks,$stories,$videos,$galleries);
$checked=0;
function ok88c(bool $pass,string $why):void{global $checked;if(!$pass)throw new RuntimeException('88C1 FAIL '.$why);$checked++;echo "PASS $why\n";}
ok88c($a['counts']===['songs'=>2,'stories'=>2,'videos'=>1,'photos'=>1],'Only eligible public, related material');
ok88c($a['songs'][0]['id']==='a'&&$a['songs'][1]['id']==='b','Track listing preserves album order and deduplication');
ok88c(!str_contains(json_encode($a),'HIDDEN'),'No private, draft or unready names disclosed');
ok88c($a['stories'][1]['linked_track_id']==='a','Published track story is connected to song');
ok88c($a['videos'][0]['params']['slug']==='live-session','Video opens original public content');
ok88c($a['photos'][0]['params']['slug']==='photos','Gallery opens original public content');
ok88c(!$a['private_media_included']&&!$a['actions_executed'],'Projection is read-only');
ok88c(sf_living_url('javascript:alert(1)')===''&&sf_living_url('//evil.example')==='','Dangerous media URLs rejected');
ok88c(sf_living_url('api/media.php?id=M'.str_repeat('A',20))!=='','Server media URL remains supported');
ok88c(sf_living_story_card(['id'=>99,'status'=>'published','readiness'=>['ready'=>true],'slug'=>'../../private'])===null,'Unsafe story links rejected');
try{sf_living_album_assemble(['id'=>'private','state'=>'draft'],[],[],[],[]);throw new RuntimeException('draft accepted');}
catch(InvalidArgumentException $e){ok88c(true,'Draft release rejected');}
$endpoint=(string)file_get_contents(dirname(__DIR__).'/api/living-album.php');
ok88c(str_contains($endpoint,'sf_living_album_public($id)')&&str_contains($endpoint,"method_not_allowed"),'Endpoint uses only published resolver and GET');
echo "88C1: $checked assertions passed\n";
