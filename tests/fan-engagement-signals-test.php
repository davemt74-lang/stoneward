<?php
declare(strict_types=1);
require_once __DIR__.'/../api/fan-engagement-signals.php';
function check87(bool $ok, string $name): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
}
$now=gmdate('c');
$events=[
 ['user_id'=>7,'type'=>'listen','track_id'=>'a','event_id'=>'1','occurred_at'=>$now],
 ['user_id'=>7,'type'=>'listen','track_id'=>'a','event_id'=>'1','occurred_at'=>$now],
 ['user_id'=>8,'type'=>'paid_purchase','track_id'=>'a','event_id'=>'other','payment_verified'=>true,'occurred_at'=>$now],
 ['user_id'=>7,'type'=>'paid_purchase','track_id'=>'a','event_id'=>'pending','payment_verified'=>false,'occurred_at'=>$now],
 ['user_id'=>7,'type'=>'paid_purchase','track_id'=>'a','event_id'=>'paid','payment_verified'=>true,'occurred_at'=>$now],
 ['user_id'=>7,'type'=>'save','track_id'=>'secret','event_id'=>'hidden','occurred_at'=>$now],
 ['user_id'=>7,'type'=>'cart','track_id'=>'a','event_id'=>'cart','occurred_at'=>$now]
];
$a=sf_engagement_normalize_events($events,['a'],7,true);
check87(count($a)===3,'dedup, owner, public, payment evidence');
$s=sf_engagement_summary($a);
check87($s['tracks']['a']['listen']===1 && $s['tracks']['a']['paid_purchase']===1,'counts');
$b=sf_engagement_normalize_events($events,['a'],7,false);
check87(count($b)===2,'consent gates listening');
check87(sf_engagement_normalize_events($events,['a'],null,true)===[],'guest isolation');
$other=sf_engagement_normalize_events($events,['a'],8,true);
check87(count($other)===1 && $other[0]['type']==='paid_purchase' && $other[0]['track_id']==='a','cross-fan isolation');
echo "87C1 signal normalization: PASS\n";
