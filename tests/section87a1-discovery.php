<?php
declare(strict_types=1);
$GLOBALS['catalog87']=[
 ['id'=>'a','title'=>'Night Sky','release'=>'Published','mood'=>['desert night'],'themes'=>['night'],'energy'=>2,'duration'=>180],
 ['id'=>'b','title'=>'Road Song','release'=>'Published','mood'=>['driving'],'themes'=>['road'],'energy'=>4,'duration'=>190],
 ['id'=>'c','title'=>'Gentle Song','release'=>'Published','mood'=>['quiet'],'themes'=>['home'],'energy'=>1,'duration'=>200],
 ['id'=>'secret','title'=>'Hidden Master','state'=>'private','mood'=>['dark'],'private_master_audio'=>'secret.wav'],
 ['id'=>'draft','title'=>'Unreleased','state'=>'draft','mood'=>['quiet']],
 ['id'=>'release-hidden','title'=>'Embargoed','release_id'=>'private-release','mood'=>['desert night']],
 ];
$GLOBALS['privacy87']=true;
function sf_catalog():array{return $GLOBALS['catalog87'];}
function sf_seo_public_track(string $id):?array{
 foreach(sf_catalog() as $r)if($r['id']===$id){
  return in_array($id,['secret','draft','release-hidden'],true)?null:$r;
 }
 return null;
}
function sf_privacy_personalization_enabled(int $userId):bool{return $GLOBALS['privacy87'];}
function sf_personalization_recommendation_profile(int $userId):array{
 return ['personalized'=>true,'feedback'=>['b'=>['sentiment'=>'dislike']],
 'seen_counts'=>['a'=>8,'b'=>3,'c'=>0]];
}
function sf_personalization_rank_catalog(array $catalog,array $p,int $n):array{
 return [['track_id'=>'a'],['track_id'=>'b'],['track_id'=>'c']];
}
require dirname(__DIR__).'/api/fan-discovery-paths.php';
$count=0;
function yes87(bool $v,string $label):void{global $count;if(!$v)throw new RuntimeException($label);$count++;echo "PASS ".$label."\n";}
function fails87(callable $f):bool{try{$f();return false;}catch(InvalidArgumentException){return true;}}
yes87(count(sf_fan_discovery_paths())===5,'Five integrated discovery paths');
$guest=sf_fan_discovery_state(null,'after_dark');
yes87($guest['items'][0]['track_id']==='a','Guest sees mood-matched published song');
yes87(count($guest['items'])===3,'Private, draft and embargoed tracks never surface');
yes87(!$guest['personalized']&&!str_contains(json_encode($guest),'private_master_audio'),'Guest payload contains no private file or profile');
$user=sf_fan_discovery_state(7,'open_road');
yes87($user['personalized'],'Existing personalization consent respected');
yes87(!in_array('b',array_column($user['items'],'track_id'),true),'Disliked song excluded across discovery paths');
yes87(count($user['items'])===2,'Dislike suppression never backfills unsafe tracks');
$deep=sf_fan_discovery_state(7,'deep_cuts');
yes87($deep['items'][0]['track_id']==='c','Deep cuts favors less-heard song');
yes87(count($deep['items'])===2,'Deep cuts remains single-artist and dislikes-aware');
$GLOBALS['privacy87']=false;
$optout=sf_fan_discovery_state(7,'open_road');
yes87(!$optout['personalized'],'Privacy opt-out disables personal ranking');
yes87($optout['items'][0]['track_id']==='b','Opt-out does not use historical dislike signal');
yes87($optout['summary']!==$user['summary'],'Opt-out gets transparent generic explanation');
yes87(fails87(fn()=>sf_fan_discovery_state(null,'invalid')),'Unknown path rejected');
yes87(count(sf_fan_discovery_rank(sf_catalog(),'start_here',null,99))===3,'Limit cannot exceed public catalog');
yes87(sf_fan_discovery_rank(sf_catalog(),'after_dark',null,3)===sf_fan_discovery_rank(sf_catalog(),'after_dark',null,3),'Discovery ordering deterministic');
yes87(array_keys($guest['items'][0])===['track_id','title','release','artwork','duration','reason'],'Result allowlist prevents accidental internal data leaks');
$endpoint=file_get_contents(dirname(__DIR__).'/api/fan-discovery.php');
yes87(str_contains($endpoint,'sf_current_user()')&&str_contains($endpoint,'Cache-Control: private, no-store'),'API uses session identity and disables caching');
yes87(!str_contains($endpoint,'sf_crm_newsletter_signup')&&!str_contains($endpoint,'sf_agent_action_execute'),'Discovery does not enroll, send or execute');
echo "87A1: $count assertions green\n";
