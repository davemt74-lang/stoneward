<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/artist-world-core.php';
$source=[
'release'=>[['id'=>'album-one','title'=>'Public Album','state'=>'published','featured'=>true],['id'=>'secret-album','title'=>'SECRET RELEASE','state'=>'draft']],
'track'=>[['id'=>'song-one','title'=>'Public Song','release_id'=>'album-one','state'=>'published'],
 ['id'=>'secret-song','title'=>'HIDDEN TRACK','release_id'=>'album-one','public_visible'=>false],
 ['id'=>'lost-song','title'=>'Linked to private','release_id'=>'secret-album']],
'story'=>[['id'=>4,'slug'=>'story-four','title'=>'Song Story','status'=>'published','entity_type'=>'track','entity_id'=>'song-one'],
 ['id'=>8,'slug'=>'hidden-story','title'=>'SECRET STORY','status'=>'archived','entity_type'=>'release','entity_id'=>'album-one']],
'video'=>[['id'=>2,'slug'=>'live-performance','title'=>'Concert Film','entities'=>[['type'=>'track','id'=>'song-one'],['type'=>'release','id'=>'secret-album']]]],
'photo'=>[['id'=>5,'slug'=>'recording-photos','title'=>'Studio Archive','entities'=>[['type'=>'release','id'=>'album-one']]]],
'show'=>[['id'=>'phoenix-show','title'=>'Live in Phoenix','public_visible'=>true]]
];
$x=sf_world_graph_assemble($source);$nodes=array_column($x['nodes'],'key');$edges=$x['edges'];$checks=0;
function ok88b(bool $v,string $m):void{global $checks;if(!$v)throw new RuntimeException('88B1 FAIL '.$m);$checks++;echo "PASS $m\n";}
ok88b($x['total_nodes']===7,'Public nodes for six existing content domains');
ok88b(!in_array('release:secret-album',$nodes,true)&&!in_array('track:secret-song',$nodes,true)
 &&!in_array('story:8',$nodes,true),'Draft, archived and private entries excluded');
ok88b(in_array('track:lost-song',$nodes,true),'Published standalone track remains discoverable');
ok88b($x['total_connections']===4,'Only validated public-public links remain');
ok88b(count(array_filter($edges,fn($e)=>$e['to']==='release:secret-album'))===0,'No edge to private release');
ok88b(count(array_filter($edges,fn($e)=>$e['from']==='release:album-one'&&$e['to']==='track:song-one'))===1,'Album-to-song journey');
ok88b(count(array_filter($edges,fn($e)=>$e['from']==='story:4'&&$e['to']==='track:song-one'))===1,'Story linked to song');
ok88b(count(array_filter($edges,fn($e)=>$e['from']==='photo:5'&&$e['to']==='release:album-one'))===1,'Photo linked to album');
ok88b(!str_contains(json_encode($x),'SECRET')&&!str_contains(json_encode($x),'email'),'No hidden editorial or personal content');
ok88b($x['includes_private_data']===false&&$x['marketing_or_commerce_action_executed']===false,'Read-only source graph');
ok88b(sf_world_node('video',['id'=>3,'slug'=>'../../evil','title'=>'Bad'])===null,'Unsafe slug rejected');
ok88b(sf_world_node('track',['id'=>'song-ok','title'=>'Hello','artwork'=>'javascript:alert(1)'])['image']==='','Unsafe image URL removed');
$first=sf_world_node('photo',['id'=>8,'slug'=>'album-scenes','title'=>'Gallery','summary'=>'Art & Story','cover'=>['url'=>'api/media.php?id=M'.str_repeat('A',20)]]);
ok88b($first['view']==='photo'&&$first['params']['slug']==='album-scenes','Navigates to existing gallery page');
$entry=(string)file_get_contents(dirname(__DIR__).'/api/artist-world.php');
ok88b(str_contains($entry,'sf_world_public_graph()')&&str_contains($entry,"method_not_allowed"),'Public GET endpoint is read-only');
echo "88B1: $checks assertions green\n";
