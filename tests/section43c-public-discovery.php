<?php
declare(strict_types=1);
/** Section 43C: isolated public track eligibility regression. */
function sf_catalog(): array {
 return [
  ['id'=>'pub','title'=>'Desert Sky','energy'=>2,'state'=>'published','public_visible'=>true],
  ['id'=>'private','title'=>'Secret Session','energy'=>2,'state'=>'private','public_visible'=>false],
  ['id'=>'draft','title'=>'Unreleased Mix','energy'=>4,'state'=>'draft','public_visible'=>false],
 ];
}
function sf_seo_public_track(string $id): ?array {
 foreach(sf_catalog() as $row)if(($row['id']??'')===$id) {
  if(($row['public_visible']??true)===false||in_array($row['state']??'', ['private','draft','archived'],true))return null;
  return $row;
 }
 return null;
}
require dirname(__DIR__).'/api/agent-runtime.php';
$checks=[
 'only public catalog remains'=>count(sf_agent_public_catalog())===1,
 'public song is playable'=>((string)(sf_agent_find_track('play Desert Sky')['id']??''))==='pub',
 'private title not resolvable'=>sf_agent_find_track('play Secret Session')===null,
 'draft title not resolvable'=>sf_agent_find_track('play Unreleased Mix')===null,
 'private active track blocked'=>sf_agent_find_track('play this','private')===null,
 'recommended music public'=>((string)(sf_agent_recommend_track('quiet mellow')['id']??''))==='pub',
 'excluded public track not replaced by private'=>sf_agent_recommend_track('anything','pub')===null,
];
foreach($checks as $name=>$ok)echo ($ok?'PASS':'FAIL').': '.$name.PHP_EOL;
if(in_array(false,$checks,true))exit(1);
