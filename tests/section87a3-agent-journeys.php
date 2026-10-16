<?php
declare(strict_types=1);
$GLOBALS['data']=[
 ['id'=>'n','title'=>'Night Track','mood'=>['desert night'],'themes'=>['night'],'energy'=>2],
 ['id'=>'r','title'=>'Road Track','mood'=>['driving'],'themes'=>['road'],'energy'=>4],
 ['id'=>'q','title'=>'Quiet Track','mood'=>['quiet'],'themes'=>['home'],'energy'=>1],
 ['id'=>'hidden','state'=>'private','title'=>'Private Original','mood'=>['dark']]
];
$GLOBALS['consent']=true;
function sf_catalog():array{return $GLOBALS['data'];}
function sf_seo_public_track(string $id):?array{
 foreach(sf_catalog() as $t)if($t['id']===$id)return $id==='hidden'?null:$t;
 return null;
}
function sf_clean_text(string $s,int $max=120):string{return substr(trim($s),0,$max);}
function sf_privacy_personalization_enabled(int $uid):bool{return $GLOBALS['consent'];}
function sf_personalization_recommendation_profile(int $uid):array{
 return ['personalized'=>true,'feedback'=>['r'=>['sentiment'=>'dislike']],
   'seen_counts'=>['n'=>2,'r'=>0,'q'=>0]];
}
function sf_personalization_rank_catalog(array $catalog,array $profile,int $limit):array{
 return [['track_id'=>'n'],['track_id'=>'r'],['track_id'=>'q']];
}
require dirname(__DIR__).'/api/fan-discovery-paths.php';
$count=0;function ok87(bool $yes,string $msg):void{global $count;if(!$yes)throw new RuntimeException($msg);$count++;echo "PASS ".$msg."\n";}
foreach(['after dark'=>'after_dark','open road'=>'open_road','slow down'=>'slow_down',
 'deep cuts'=>'deep_cuts','start here'=>'start_here'] as $phrase=>$expected)
 ok87(sf_fan_discovery_path_from_message('Please play me an '.$phrase.' listening session')===$expected,
   'Explicit Agent path matches '.$phrase);
ok87(sf_fan_discovery_path_from_message('Can you recommend a song?')===null,
 'Ordinary recommendations still use original Agent behavior');
$path=sf_fan_discovery_session_plan(7,'Play an after dark session');
ok87($path['mode']==='mix'&&$path['discovery_path']==='after_dark',
 'Agent uses current listening-session mode and path context');
ok87($path['track_ids'][0]==='n','Agent session opens with same public top suggestion as Music');
ok87(!in_array('hidden',$path['track_ids'],true),'Private original never appears in guided session');
ok87(!in_array('r',$path['track_ids'],true),'Agent guided sessions respect explicit dislikes');
ok87($path['release_id']===''&&count($path['track_ids'])===2,
 'Existing listening-session contract has no invented release');
ok87(sf_fan_discovery_session_plan(7,'play any music')===null,'Unrelated chats never force path curation');
$GLOBALS['consent']=false;
$generic=sf_fan_discovery_session_plan(7,'Play an open road session');
ok87(in_array('r',$generic['track_ids'],true),'Privacy opt-out does not use listening or negative-feedback profile');
ok87(sf_fan_discovery_state(7,'open_road')['personalized']===false,'Agent context reports anonymous/generic ranking on opt-out');
$brain=file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$sessions=file_get_contents(dirname(__DIR__).'/api/listening-sessions-core.php');
$client=file_get_contents(dirname(__DIR__).'/assets/js/fan-discovery.js');
$app=file_get_contents(dirname(__DIR__).'/assets/js/app.js');
ok87(str_contains($brain,'sf_fan_discovery_path_from_message($message)')&&
 str_contains($brain,'sf_fan_discovery_state($userId'),'Existing Agent recommendation route shares discovery rules');
ok87(str_contains($sessions,'sf_fan_discovery_session_plan($userId,$message)')&&
 str_contains($sessions,'sf_agent_playlist_track_ids('),'Existing session retains generic fallback');
ok87(str_contains($client,'data-fan-agent')&&str_contains($app,'form.requestSubmit()'),
 'Guided UI invokes existing Agent Chat composer rather than a new endpoint');
ok87(!str_contains($client,'sf_crm_newsletter_signup')&&!str_contains($sessions,'send_marketing_without_consent'),
 'Listening journeys do not create email consent or send marketing');
echo "87A3: $count checks green\n";
