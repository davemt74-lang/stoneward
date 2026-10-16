<?php
declare(strict_types=1);
$GLOBALS['t']=[
 ['id'=>'song-a','title'=>'Save Me','mood'=>['reflective'],'themes'=>['night'],'podEligible'=>true],
 ['id'=>'song-b','title'=>'Buy Me','mood'=>['reflective'],'themes'=>['night'],'podEligible'=>false],
 ['id'=>'hidden','title'=>'Hidden','state'=>'draft','mood'=>['reflective']]
];
function sf_catalog():array{return $GLOBALS['t'];}
function sf_track_map():array{$o=[];foreach(sf_catalog() as $t)$o[$t['id']]=$t;return $o;}
function sf_seo_public_track(string $id):?array{
 return in_array($id,['song-a','song-b'],true)?sf_track_map()[$id]:null;
}
function sf_privacy_personalization_enabled(int $userId):bool{return false;}
function sf_personalization_favorites(int $userId):array{
 return ['tracks'=>$userId===7?[['id'=>'song-a','title'=>'Save Me']]:[], 'releases'=>[]];
}
function sf_library_purchase_rows(int $userId):array{
 return $userId===8?[['item_type'=>'track','track_id'=>'song-b']]:[];
}
require dirname(__DIR__).'/api/fan-purchase-journey-core.php';
$passed=0;function ok87(bool $condition,string $label):void{
 global $passed;if(!$condition)throw new RuntimeException('87B2 FAIL '.$label);
 $passed++;echo "PASS $label\n";
}
$a=sf_fan_purchase_paths(7,'after_dark');
$indexed=array_column($a['items'],null,'track_id');
ok87($indexed['song-a']['saved']===true,'A saved favorite is correctly recognized');
ok87($indexed['song-a']['owned']===false,'Favorite does not grant purchased music entitlement');
ok87($indexed['song-a']['digital']['action']==='add_to_cart','Saved but unowned song still requires checkout');
ok87($indexed['song-a']['save_available']===true,'Signed-in user can manage saved music even with recommendation personalization off');
ok87($a['personalized']===false,'Saved status does not bypass personalized-recommendations opt-out');
$b=sf_fan_purchase_paths(8,'after_dark');
$owned=array_column($b['items'],null,'track_id');
ok87($owned['song-b']['owned']===true,'Paid entitlement status is independent of recommendation consent');
ok87($owned['song-b']['saved']===false,'Purchased music is not falsely called a favorite');
ok87($owned['song-b']['digital']['action']==='open_library','Already purchased song routes to private library');
$guest=sf_fan_purchase_paths(null,'after_dark');
$rows=$guest['items'];
ok87(count($rows)===2,'Draft music suppressed for guests');
ok87(!array_filter($rows,fn($item)=>$item['save_available']),'Guests have no ability to save private preference without signing in');
ok87(!array_filter($rows,fn($item)=>$item['saved']||$item['owned']),'No private identity data leaks to guests');
ok87(!str_contains(json_encode($a),'source_order_id')&&!str_contains(json_encode($a),'private_master_audio'),'Fan projection hides internal purchase and media storage fields');
echo "87B2 saved-vs-owned: $passed assertions green\n";
