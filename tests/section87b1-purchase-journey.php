<?php
declare(strict_types=1);
$GLOBALS['catalog']=[
 ['id'=>'a','title'=>'Available Song','release'=>'Open Road','price'=>3.5,'mood'=>['driving'],'themes'=>['road'],'energy'=>4,'podEligible'=>true,'private_master_audio'=>''],
 ['id'=>'b','title'=>'Owned Song','release'=>'Open Road','price'=>2.5,'mood'=>['driving'],'themes'=>['road'],'energy'=>4,'podEligible'=>true,'audio_publishing_mode'=>'preview_master_split','private_master_audio'=>'private-master.wav'],
 ['id'=>'secret','title'=>'Private Song','state'=>'private','private_master_audio'=>'secret.wav'],
 ['id'=>'no-vinyl','title'=>'No vinyl','mood'=>['quiet'],'themes'=>['home'],'podEligible'=>false],
];
function sf_catalog():array{return $GLOBALS['catalog'];}
function sf_track_map():array{$out=[];foreach(sf_catalog() as $t)$out[$t['id']]=$t;return $out;}
function sf_seo_public_track(string $id):?array{foreach(sf_catalog() as $t)if($t['id']===$id&&($t['state']??'published')!=='private')return $t;return null;}
function sf_privacy_personalization_enabled(int $id):bool{return false;}
function sf_library_purchase_rows(int $id):array {
 if($id===8)return [['item_type'=>'track','track_id'=>'b','metadata'=>['track_id'=>'b']]];
 if($id===9)return [['item_type'=>'release','metadata'=>['track_ids'=>['a','b']]]];
 return [];
}
require dirname(__DIR__).'/api/fan-purchase-journey-core.php';
$checks=0;
function ok87b(bool $yes,string $why):void{global $checks;if(!$yes)throw new RuntimeException('87B1 FAILED '.$why);$checks++;echo "PASS $why\n";}
function error87b(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException){return true;}}
$guest=sf_fan_purchase_paths(null,'open_road',12);
ok87b(count($guest['items'])===3,'Guests see only public songs');
ok87b(!array_filter($guest['items'],fn($r)=>$r['track_id']==='secret'),'Hidden recordings never become offers');
ok87b(count(array_filter($guest['items'],fn($r)=>$r['owned']))===0,'Guest ownership is never guessed');
ok87b($guest['items'][0]['digital']['price_source']==='cart_quote_only','No fabricated or stale checkout price');
ok87b($guest['items'][0]['digital']['action']==='add_to_cart','Guest can move to canonical cart review');
$user=sf_fan_purchase_paths(8,'open_road');
$byId=array_column($user['items'],null,'track_id');
ok87b($byId['b']['owned']===true,'Verified purchased track recognized');
ok87b($byId['b']['digital']['action']==='open_library'&&!$byId['b']['digital']['requires_checkout'],'Purchased song is never upsold as a new digital sale');
ok87b($byId['a']['digital']['action']==='add_to_cart','Unowned song offers cart review, not payment');
ok87b($byId['b']['vinyl']['eligible']===true,'Source-backed vinyl links to existing licensed builder');
ok87b($byId['a']['vinyl']['eligible']===true,'Legacy standard source supports vinyl eligibility');
ok87b($byId['no-vinyl']['vinyl']['eligible']===false,'Ineligible songs cannot be promoted into vinyl');
ok87b($byId['b']['vinyl']['license_balance']==='not_checked','Vinyl print rights are never invented');
$album=sf_fan_purchase_paths(9,'open_road');
ok87b(count(array_filter($album['items'],fn($r)=>$r['owned']))===2,'Verified album purchase covers listed songs');
ok87b(error87b(fn()=>sf_fan_purchase_paths(null,'unknown')),'Unknown journey rejected');
ok87b(!str_contains(json_encode($user),'private_master_audio')&&!str_contains(json_encode($user),'private-master.wav'),'No original audio path or internal secret in fan response');
ok87b(!str_contains(json_encode($user),'source_order_id'),'No private order ID in discovery projection');
ok87b(count(sf_fan_purchase_paths(8,'open_road',1)['items'])===1,'Server enforces bounded response');
$endpoint=file_get_contents(dirname(__DIR__).'/api/fan-purchase-journey.php');
ok87b(str_contains($endpoint,'sf_current_user()')&&str_contains($endpoint,'read-only'),'Own-session identity and no mutation');
ok87b(!str_contains($endpoint,'sf_quote(')&&!str_contains($endpoint,'sf_agent_action_execute'),'Read model cannot create charges or agent approvals');
echo "87B1: $checks acceptance assertions green\n";
