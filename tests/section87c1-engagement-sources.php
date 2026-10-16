<?php
declare(strict_types=1);
$GLOBALS['c87']=[
 ['id'=>'public','title'=>'Public Song','state'=>'published','public_visible'=>true],
 ['id'=>'album-track','title'=>'Album Song','state'=>'published','public_visible'=>true],
 ['id'=>'hidden','title'=>'Private Song','state'=>'private','public_visible'=>false]
];
$GLOBALS['listeningConsent87']=true;
$GLOBALS['personalizationConsent87']=true;
function sf_catalog():array{return $GLOBALS['c87'];}
function sf_seo_public_track(string $id):?array {
    foreach(sf_catalog() as $t)if($t['id']===$id && ($t['public_visible']??true))return $t;
    return null;
}
function sf_privacy_listening_history_enabled(int $id):bool{return $GLOBALS['listeningConsent87'];}
function sf_privacy_personalization_enabled(int $id):bool{return $GLOBALS['personalizationConsent87'];}
function sf_ops_ensure_schema():void{}
function sf_personalization_ensure_schema():void{}
function sf_db():PDO {
    static $db=null;if($db)return $db;
    $db=new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE listening_events(id INTEGER PRIMARY KEY,user_id INTEGER,track_id TEXT,event_type TEXT,created_at TEXT)');
    $db->exec('CREATE TABLE user_favorites(id INTEGER PRIMARY KEY,user_id INTEGER,item_type TEXT,item_id TEXT,created_at TEXT)');
    return $db;
}
function sf_library_purchase_rows(int $uid):array {
    if($uid!==7)return [];
    return [
        ['id'=>'order-item-1','item_type'=>'track','track_id'=>'public','metadata'=>['track_id'=>'public'],'saved_at'=>gmdate('c')],
        ['id'=>'order-item-2','item_type'=>'release','metadata'=>['track_ids'=>['album-track','public','hidden']],'saved_at'=>gmdate('c')],
        ['id'=>'order-item-3','item_type'=>'track','track_id'=>'public','metadata'=>['track_id'=>'public'],'saved_at'=>gmdate('c',time()-100*86400)]
    ];
}
require dirname(__DIR__).'/api/fan-engagement-source.php';
$checks=0;
function ok87c1(bool $v,string $why):void {
    global $checks;
    if(!$v)throw new RuntimeException('87C1 FAIL '.$why);
    $checks++;echo "PASS $why\n";
}
$now=gmdate('c');$db=sf_db();
$listen=$db->prepare('INSERT INTO listening_events(user_id,track_id,event_type,created_at) VALUES(?,?,?,?)');
foreach([[7,'public','start',$now],[7,'public','complete',$now],[8,'public','start',$now],
 [7,'hidden','start',$now],[7,'public','start',gmdate('c',time()-91*86400)]] as $r)$listen->execute($r);
$favorite=$db->prepare('INSERT INTO user_favorites(user_id,item_type,item_id,created_at) VALUES(?,?,?,?)');
foreach([[7,'track','public',$now],[8,'track','public',$now],[7,'track','hidden',$now],
 [7,'release','public',$now]] as $r)$favorite->execute($r);
$s=sf_fan_engagement_owner_signals(7,30);
ok87c1($s['summary']['tracks']['public']['listen']===1,'Only current owner public starts count');
ok87c1($s['summary']['tracks']['public']['save']===1,'Only explicit saved public tracks count');
ok87c1($s['summary']['tracks']['public']['paid_purchase']===2,'Paid track and verified album evidence counted');
ok87c1($s['summary']['tracks']['album-track']['paid_purchase']===1,'Purchased album expands into its public songs');
ok87c1(!isset($s['summary']['tracks']['hidden']),'Private catalog removed from all signal classes');
ok87c1($s['summary']['event_count']===5,'Historical data excluded and pause/complete not duplicated');
ok87c1($s['sources']['cart']==='not_instrumented' && $s['sources']['vinyl_interest']==='not_instrumented',
    'Never infer nonexistent cart or vinyl intent events');
ok87c1($s['marketing_consent_inferred']===false,'Engagement does not infer marketing consent');
ok87c1(sf_fan_engagement_owner_signals(8,30)['summary']['event_count']===1,
    'Cross-account purchase and favorite evidence unavailable');
$GLOBALS['listeningConsent87']=false;
$off=sf_fan_engagement_owner_signals(7);
ok87c1($off['summary']['tracks']['public']['listen']===0 && $off['sources']['listen']==='disabled_by_privacy',
    'Listening consent disables listening extraction');
ok87c1($off['summary']['tracks']['public']['save']===1,'Independent personalization permission retained');
$GLOBALS['personalizationConsent87']=false;
$offBoth=sf_fan_engagement_owner_signals(7);
ok87c1($offBoth['summary']['tracks']['public']['save']===0
    && $offBoth['summary']['tracks']['public']['paid_purchase']===2,
    'No listening or favorites without permissions; paid rights remain accurate');
ok87c1(sf_engagement_normalize_events([
 ['user_id'=>8,'type'=>'listen','track_id'=>'public','event_id'=>'spoof','occurred_at'=>$now],
 ['user_id'=>7,'type'=>'paid_purchase','track_id'=>'public','event_id'=>'pending','occurred_at'=>$now,'payment_verified'=>false],
 ['user_id'=>7,'type'=>'cart','track_id'=>'hidden','event_id'=>'secret','occurred_at'=>$now],
],['public'],7,true)===[],'Normalizer rejects spoofed owners, pending payment and hidden songs');
$endpoint=(string)file_get_contents(dirname(__DIR__).'/api/fan-engagement-insights.php');
ok87c1(str_contains($endpoint,'sf_require_user(false,false)')
    &&str_contains($endpoint,"(int)$"."user['id']")
    &&!str_contains($endpoint,"_GET['user_id']"),'Endpoint uses authenticated owner, not URL user_id');
ok87c1(!str_contains($endpoint,'sf_quote(')&&!str_contains($endpoint,'sf_crm_newsletter_signup('),
    'Read endpoint does not purchase or sign users up for marketing');
echo "87C1: $checks acceptance assertions green\n";
