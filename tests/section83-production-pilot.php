<?php
declare(strict_types=1);
/**
 * Section 83 non-destructive integration pilot:
 * customer order -> exact POD handoff -> Admin triage -> marketing opt-out ->
 * cross-system release report. All fixtures are ephemeral; NO external calls.
 */
$root=dirname(__DIR__);
require $root.'/api/production-pilot.php';
require $root.'/api/customer-commerce-view.php';
require $root.'/api/vinyl-production-gates.php';
require $root.'/api/agent-commerce-triage.php';
require $root.'/api/campaign-core.php';
$pass=0;$fail=0;
function p83(bool $ok,string $label):void {global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;}
function reject83(callable $cb):bool {try{$cb();return false;}catch(Throwable $e){return true;}}
$tmp=sys_get_temp_dir().'/stonefellow-section83-'.bin2hex(random_bytes(8));
if(!mkdir($tmp.'/pod',0700,true))throw new RuntimeException('Fixture directory unavailable.');
$id='SF-PILOT-83';
$build=['title'=>'Limited Vinyl','coverFront'=>'desert','coverBack'=>'analog','customNote'=>'For the fan',
 'sides'=>[
  'A'=>['duration'=>180,'tracks'=>[['id'=>'release1','title'=>'Track One','duration'=>180,'audio_master'=>'master-a.wav']]],
  'B'=>['duration'=>190,'tracks'=>[['id'=>'release2','title'=>'Track Two','duration'=>190,'audio_master'=>'master-b.wav']]]
 ]];
$order=['id'=>$id,'user_id'=>83,'status'=>'paid','created_at'=>'2026-10-14T12:00:00+00:00',
 'customer'=>['email'=>'owner@example.test','shipping'=>['line1'=>'123 Private Drive','city'=>'Phoenix','country'=>'US']],
 'quote'=>['currency'=>'USD','total_cents'=>3899,'physical'=>true,'items'=>[
  ['type'=>'custom_media','format'=>'vinyl','label'=>'Limited Vinyl','price_cents'=>3899,'builder'=>$build]
 ]],
 'payment'=>['provider'=>'stripe','status'=>'paid','postpayment_done'=>['pod'=>true],
  'payment_intent'=>'SECRET_PROVIDER_ID','checkout_session_id'=>'SECRET_SESSION'],
 'fulfillment'=>['status'=>'pending','note'=>'SECRET_STAFF_NOTE'],
 'timeline'=>[['type'=>'paid','label'=>'Order confirmed','created_at'=>'2026-10-14T12:00:00+00:00',
  'private_payload'=>'SECRET_INTERNAL_EVENT']]
];
$owner=['id'=>83,'email'=>'owner@example.test'];$other=['id'=>84,'email'=>'other@example.test'];
$public=sf_customer_commerce_order($order,$owner);
p83($public['id']===$id&&$public['quote']['total_cents']===3899,'Paid custom vinyl order projects to existing customer view');
p83(!str_contains(json_encode($public,JSON_THROW_ON_ERROR),'SECRET_'),'Private payment, staff and timeline data cannot reach customer');
p83(reject83(fn()=>sf_customer_commerce_order($order,$other)),'Second account cannot view pilot order');
p83(!sf_customer_commerce_owns(['id'=>'SF-CERT-PILOT','user_id'=>83],$owner),'Internal Stripe certification probes excluded from customer view');
$handoff=['schema'=>'stonefellow.pod-handoff.v1','provider'=>'file_handoff',
 'order_id'=>$id,'item_index'=>0,'format'=>'vinyl',
 'title'=>$build['title'],'artwork_front'=>$build['coverFront'],
 'artwork_back'=>$build['coverBack'],'dedication_note'=>$build['customNote'],
 'sides'=>$build['sides'],'customer_player_preview'=>['visual_only'=>true],
 'recipient'=>['line1'=>'123 Private Drive'],'status'=>'ready_for_provider'];
$path=$tmp.'/pod/'.$id.'-0.json';
file_put_contents($path,json_encode($handoff,JSON_THROW_ON_ERROR));
$proof=sf_vinyl_production_proof($order,$tmp);
p83($proof['ready']&&preg_match('/^[a-f0-9]{64}$/D',$proof['fingerprint'])===1,
 'Paid order matches exact protected vinyl handoff');
$approval=sf_vinyl_production_approval(['status'=>'awaiting_artwork_review'],
 $proof,['confirmed'=>true,'proof_sha256'=>$proof['fingerprint']],'artwork_approved');
p83($approval['proof_sha256']===$proof['fingerprint'],'Manual artwork approval binds exact fingerprint');
p83(reject83(fn()=>sf_vinyl_production_approval(['proof_sha256'=>$proof['fingerprint']],
 $proof,['confirmed'=>true,'qc_confirmed'=>false],'production_complete')),
 'Vinyl cannot complete QC without explicit inspection');
$modified=$handoff;$modified['artwork_front']='midnight';
file_put_contents($path,json_encode($modified,JSON_THROW_ON_ERROR));
p83(!sf_vinyl_production_proof($order,$tmp)['ready'],
 'Tampered manufacturing handoff blocks subsequent approval');
file_put_contents($path,json_encode($handoff,JSON_THROW_ON_ERROR));
$triage=sf_agent_commerce_triage(['available'=>true,'attention_orders'=>[
 ['id'=>$id,'flags'=>['fulfillment_pending','postpayment_incomplete']],
 ['id'=>'SF-CERT-PILOT','flags'=>['payment_pending_review']]
]]);
p83($triage['count']===1&&$triage['items'][0]['order_id']===$id
 &&$triage['items'][0]['severity']==='critical',
 'Admin Brain flags incomplete paid-order checkpoint without exposing certification probes');
p83($triage['read_only']&&$triage['items'][0]['requires_review'],
 'Admin Agent only proposes review and cannot execute fulfillment');
$recipient=['id'=>83,'email'=>'owner@example.test','marketing_opt_in'=>1,'unsubscribed_at'=>null];
p83(sf_campaign_send_recipient_allowed($recipient,$recipient),'Opted-in fan is initially eligible for approved campaign');
$optedOut=$recipient;$optedOut['unsubscribed_at']='2026-10-14T18:00:00+00:00';
p83(!sf_campaign_send_recipient_allowed($recipient,$optedOut),
 'Late unsubscribe blocks outreach to customer before actual send');
$validChecks=[];
foreach(['merchant','environment','charges','https','signing','sandbox','webhook_queue'] as $k)
 $validChecks[]=['id'=>$k,'state'=>'pass'];
$release=['test_activation_ready'=>true,'test_checkout_active'=>true,'operations_clear'=>true,
 'bounded_webhook_sample'=>['window_days'=>7,'sample_limit'=>100,'failed'=>0,'processing'=>0],
 'checks'=>$validChecks];
$agent=['available'=>true,'reads_only'=>true,'triage'=>['read_only'=>true]];
$modules=array_fill_keys(['customer_order','fulfillment','vinyl_proof','fan_consent','notifications','upgrade'],true);
$report=sf_pilot_report($release,$agent,$modules);
p83($report['total']===10&&$report['passed']===10,'Full simulated source+runtime evidence satisfies ten technical gates');
p83($report['sandbox_rehearsal_ready']&&$report['technical_components_present'],
 'Successful isolated fixture eligible for TEST-only pilot rehearsal');
p83(!$report['production_activation_authorized']&&!$report['live_payments_available']
 &&!$report['manual_verification_complete'],'Even 10/10 technical gates NEVER authorize production Stripe');
p83(count($report['manual_verification_required'])>=6,'External browser, carrier, mailer, upgrade and Stripe acceptance remain manual');
p83(!str_contains(json_encode($report,JSON_THROW_ON_ERROR),'owner@example.test')
 &&!str_contains(json_encode($report),'123 Private Drive'),'Readiness report contains no customer private information');
$unknown=sf_pilot_report(null,null,[]);
p83($unknown['passed']===0&&!$unknown['sandbox_rehearsal_ready'],
 'Missing dependencies fail closed, not green by default');
$badRelease=$release;$badRelease['bounded_webhook_sample']['failed']=1;
p83(!sf_pilot_report($badRelease,$agent,$modules)['sandbox_rehearsal_ready'],
 'Failed signed webhook evidence blocks rehearsal');
$badRelease=$release;$badRelease['checks'][5]['state']='blocked';
p83(!sf_pilot_report($badRelease,$agent,$modules)['sandbox_rehearsal_ready'],
 'Unsigned or unverified certification cannot be represented as ready');
$badAgent=$agent;$badAgent['reads_only']=false;
p83(!sf_pilot_report($release,$badAgent,$modules)['sandbox_rehearsal_ready'],
 'Agent action write-enabled snapshot blocks safe pilot rehearsal');
$badModules=$modules;$badModules['upgrade']=false;
p83(!sf_pilot_report($release,$agent,$badModules)['sandbox_rehearsal_ready'],
 'Missing upgrade/backup tooling blocks rehearsal');
p83(reject83(fn()=>sf_pilot_snapshot(0)),'Anonymous pilot snapshot refused');
$api=file_get_contents($root.'/admin/api/stripe-connect.php');
$ui=file_get_contents($root.'/admin/assets/stripe-connect.js');
$workflow=file_get_contents($root.'/.github/workflows/release-gate.yml');
p83(str_contains($api,'sf_admin_require_auth($write)')
 &&str_contains($api,'sf_pilot_snapshot((int)$admin'),
 'Pilot report is gated behind existing Admin read authentication');
p83(str_contains($ui,'stripePilotPanel')&&str_contains($ui,'pilotPanel(pilot||{})'),
 'Existing Payments canvas includes pilot checklist, not a new payment page');
p83(str_contains($ui,'Production Stripe remains disabled.')
 &&!str_contains($ui,'id="pilotActivateLive"'),
 'Pilot UI has no live Stripe activation control');
foreach(['section72b-vinyl-snapshot.php','section75e7-sandbox-certification.php',
 'section76b-dual-agent-commerce.php','section78-customer-commerce.php',
 'section79-agent-commerce-triage.php','section81-vinyl-proof.php',
 'section82-marketing-consent.php'] as $suite)
 p83(str_contains($workflow,'php tests/'.$suite),'Cross-system release CI retains '.$suite);
unlink($path);rmdir($tmp.'/pod');rmdir($tmp);
echo 'Section 83 end-to-end safety pilot: '.$pass.'/'.($pass+$fail).' checks passed'.PHP_EOL;
exit($fail?1:0);
