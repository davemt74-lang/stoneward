<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/fan-journey-diagnostics.php';
$passed=0;
function ok87c2(bool $ok,string $message):void{
 global $passed;
 if(!$ok)throw new RuntimeException('87C2 FAIL: '.$message);
 $passed++;echo "PASS $message\n";
}
$analytics=[
 'starts'=>100,'repeat_starts'=>30,'skips'=>50,
 'favorites_added'=>0,'paid_orders'=>0,'builds_created'=>6,
 'custom_media_orders'=>0,'listeners'=>20,
 'users'=>[['user_id'=>912,'email'=>'sensitive@example.invalid','orders'=>['secret']]],
 'tracks'=>[
  ['track_id'=>'live','title'=>'UNTRUSTED','listeners'=>12,'listens'=>60,'repeat_starts'=>15,'favorites'=>0,'digital_purchases'=>0],
  ['track_id'=>'hidden','title'=>'Private Master','listeners'=>24,'listens'=>40],
  ['track_id'=>'rare','title'=>'Visible But Sparse','listeners'=>2,'listens'=>3],
 ]];
$public=[
 ['id'=>'live','title'=>'Published Ballad'],
 ['id'=>'rare','title'=>'Visible But Sparse']
];
$x=sf_fan_journey_diagnostics($analytics,$public,30);
$codes=array_column($x['opportunities'],'code');
foreach(['repeat_interest','skip_events','save_discovery','purchase_path','vinyl_path'] as $code)
 ok87c2(in_array($code,$codes,true),'Evidence-linked suggestion: '.$code);
ok87c2($x['top_public_songs'][0]['title']==='Published Ballad','Published catalog title is source of truth');
ok87c2(count($x['top_public_songs'])===1,'Private and sparse song rows are suppressed');
$serialized=(string)json_encode($x);
ok87c2(!str_contains($serialized,'sensitive@example.invalid')&&!str_contains($serialized,'Private Master')
 &&!str_contains($serialized,'UNTRUSTED'),'No user identifiers or untrusted/private track titles leak');
ok87c2(!str_contains($serialized,'912')&&!str_contains($serialized,'secret'),'No private user ID or order detail leaks');
ok87c2($x['data_quality']['funnel_attribution_verified']===false
 &&$x['data_quality']['cart_events']==='not_instrumented','No fabricated funnel or cart telemetry');
ok87c2($x['changes_executed']===false
 &&count(array_filter($x['opportunities'],fn($o)=>$o['executed']!==false))===0,
 'Recommendations never execute marketing or commerce actions');
ok87c2($x['measures'][3]['count']===0 && $x['measures'][3]['unit']==='orders',
 'Paid orders are independently counted, not inferred from listens');
$small=sf_fan_journey_diagnostics(['starts'=>3,'paid_orders'=>0,'builds_created'=>10],$public,10);
ok87c2(count($small['opportunities'])===1 && $small['opportunities'][0]['code']==='sample_limited',
 'Small samples do not trigger speculative conversion advice');
$healthy=sf_fan_journey_diagnostics(['starts'=>40,'skips'=>1,'favorites_added'=>7,
 'repeat_starts'=>2,'paid_orders'=>4,'builds_created'=>2,'custom_media_orders'=>1],$public,14);
ok87c2(count($healthy['opportunities'])===1 && $healthy['opportunities'][0]['code']==='baseline',
 'Ordinary activity receives neutral observational guidance');
$negative=sf_fan_journey_diagnostics(['starts'=>-40,'favorites_added'=>-20],$public,-7);
ok87c2($negative['measures'][0]['count']===0 && $negative['days']===1,
 'Inputs clamp impossible negative counts and date bounds');
$admin=(string)file_get_contents(dirname(__DIR__).'/admin/api/analytics.php');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/analytics.js');
ok87c2(str_contains($admin,'sf_admin_require_auth(false)')
 &&str_contains($admin,'sf_fan_journey_diagnostics($overall,sf_fan_discovery_public(sf_catalog()),$days)'),
 'Existing admin-authenticated endpoint uses only aggregate and public catalog sources');
ok87c2(str_contains($admin,"'fan_journey_insights'")&&str_contains($ui,'journeyPanel(c,journey)'),
 'Existing Admin Analytics renders the aggregate diagnostics');
ok87c2(str_contains($ui,'c.esc(s.title')&&str_contains($ui,'c.esc(s.suggestion'),
 'Suggested copy and public song titles are HTML-escaped');
echo "87C2: $passed assertions green\n";
