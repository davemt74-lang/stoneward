<?php
declare(strict_types=1);
function sf_catalog():array{return [['id'=>'pub','title'=>'Desert Sky','state'=>'published','public_visible'=>true],['id'=>'private','title'=>'Secret Session','state'=>'private','public_visible'=>false]];}
function sf_seo_public_track(string $id):?array{foreach(sf_catalog() as $t)if($t['id']===$id)return !empty($t['public_visible'])?$t:null;return null;}
require dirname(__DIR__).'/api/agent-runtime.php';
$tests=[
 'save favorite intent routes'=>sf_agent_local_route('add Desert Sky to favorites')==='favorite_save',
 'remove favorite intent routes'=>sf_agent_local_route('remove Desert Sky from favorites')==='favorite_remove',
 'current favorite routes'=>sf_agent_local_route('favorite this song')==='favorite_save',
 'save policy declares explicit desired state'=>sf_agent_policy('favorite_save','add Desert Sky to favorites')['action']['favorite']===true,
 'remove policy declares explicit desired state'=>sf_agent_policy('favorite_remove','remove Desert Sky from favorites')['action']['favorite']===false,
 'unpublished song cannot be saved'=>sf_agent_policy('favorite_save','favorite Secret Session')['action']['type']==='open_library',
];
foreach($tests as $name=>$ok)echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
if(in_array(false,$tests,true))exit(1);
$js=(string)file_get_contents(dirname(__DIR__).'/assets/js/app.js');
$checks=[
 'frontend supports favorite actions'=>str_contains($js,"case'set_favorite_track'"),
 'server favorite API used'=>str_contains($js,"action:'set_favorite'"),
 'user-facing confirmation follows response'=>str_contains($js,"st.personalization={loaded:true,...(j.personalization||{})};st.home.loaded=false;say("),
];
foreach($checks as $name=>$ok)echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
if(in_array(false,$checks,true))exit(1);
