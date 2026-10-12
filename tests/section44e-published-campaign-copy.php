<?php
declare(strict_types=1);
function sf_clean_text(mixed $value,int $max=120):string{return substr(trim((string)$value),0,$max);}
function sf_seo_release_rows():array{return [
 ['id'=>'pubrel','title'=>'Evening Release','description'=>'Published music','state'=>'published'],
 ['id'=>'secretrel','title'=>'Secret Album','description'=>'Private liner notes','state'=>'private']
];}
function sf_seo_public_release(string $id):?array{foreach(sf_seo_release_rows() as $r)if($r['id']===$id&&$r['state']==='published')return $r;return null;}
function sf_catalog():array{return [
 ['id'=>'pubtrack','title'=>'Desert Light','story'=>'A published song story'],
 ['id'=>'secrettrack','title'=>'Secret Session','story'=>'Private material']
];}
function sf_seo_public_track(string $id):?array{foreach(sf_catalog() as $t)if($t['id']===$id&&$id==='pubtrack')return $t;return null;}
function sf_live_public_shows():array{return [['id'=>'show1','title'=>'Stonefellow Live']];}
require dirname(__DIR__).'/api/agent-campaign-sources.php';
$items=sf_agent_campaign_sources();$json=json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$endpoint=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-campaign-sources.php');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-actions.js');
$checks=[
 'Only public released album supplied'=>str_contains($json,'Evening Release')&&!str_contains($json,'Secret Album'),
 'Only public songs supplied'=>str_contains($json,'Desert Light')&&!str_contains($json,'Secret Session'),
 'Only published story text supplied'=>!str_contains($json,'Private material'),
 'Show suggestion uses public show route'=>str_contains($json,'/show/show1'),
 'No invented show date'=>!str_contains($json,'2026-')&&!str_contains($json,'2027-'),
 'Private content never exposed'=>count($items)===3,
 'Admin authentication enforced'=>str_contains($endpoint,'sf_admin_require_auth(false)'),
 'Draft sources come from server API'=>str_contains($ui,"api('agent-campaign-sources.php')"),
 'UI binds server-suggested copy into reviewable draft'=>str_contains($ui,'source.body_text'),
];
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
exit($fail?1:0);
