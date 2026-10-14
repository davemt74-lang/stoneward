<?php
declare(strict_types=1);
/** 84B1: isolated Cloud-only proof, queue, worker authentication and permission tests. */
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/sf84b1-'.bin2hex(random_bytes(8));
if(!mkdir($tmp,0700,true))throw new RuntimeException('temp fixture');
define('SF_ROOT',$tmp);
require $root.'/api/store-order-visibility.php';
require $root.'/api/account-data.php';
require $root.'/api/kunaki-publishing-jobs.php';
require $root.'/api/kunaki-worker-auth.php';
require $root.'/api/agent-tool-registry.php';
require $root.'/api/agent-skill-registry.php';
$pass=0;$fail=0;
function c84b(bool $ok,string $label):void{global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;}
function blocked84b(callable $cb):bool{try{$cb();return false;}catch(Throwable $e){return true;}}
$tracks=[
 'song-one'=>['id'=>'song-one','title'=>'First Song','duration'=>180,'podEligible'=>true,'audio'=>'masters/first.wav'],
 'song-two'=>['id'=>'song-two','title'=>'Second Song','duration'=>200,'podEligible'=>true,'audio'=>'masters/second.wav']
];
$vinyl=[
 'id'=>'SF-PURCHASE-84B','user_id'=>17,'status'=>'paid','payment'=>['provider'=>'stripe','status'=>'paid','livemode'=>true,'payment_intent'=>'pi_real_fixture','postpayment_done'=>['library'=>true,'pod'=>true]],
 'quote'=>['items'=>[
  ['type'=>'custom_media','format'=>'vinyl','builder'=>[
   'title'=>'Custom Record','coverFront'=>'desert','coverBack'=>'analog',
   'sides'=>['A'=>['tracks'=>[['id'=>'song-one']]],'B'=>['tracks'=>[['id'=>'song-two']]]]
  ]]
 ]]
];
$source1=['id'=>'SF-SONG-ONE','user_id'=>17,'status'=>'paid','payment'=>['provider'=>'stripe','status'=>'paid','livemode'=>true,'payment_intent'=>'pi_real_fixture','postpayment_done'=>['library'=>true,'pod'=>true]],
 'quote'=>['items'=>[['type'=>'track','track_id'=>'song-one','price_cents'=>120]]]];
$source2=['id'=>'SF-SONG-TWO','user_id'=>17,'status'=>'paid','payment'=>['provider'=>'stripe','status'=>'paid','livemode'=>true,'payment_intent'=>'pi_real_fixture','postpayment_done'=>['library'=>true,'pod'=>true]],
 'quote'=>['items'=>[['type'=>'track','track_id'=>'song-two','price_cents'=>120]]]];
$library=[
 ['item_type'=>'track','item_key'=>'song-one','source_order_id'=>'SF-SONG-ONE'],
 ['item_type'=>'track','item_key'=>'song-two','source_order_id'=>'SF-SONG-TWO']
];
$paidSources=['SF-SONG-ONE'=>$source1,'SF-SONG-TWO'=>$source2];
$approved=sf_kunaki_publishing_entitlements($vinyl,0,$library,$paidSources,$tracks);
c84b(count($approved)===2&&$approved[0]['track_id']==='song-one',
 'Only two purchased Stonefellow catalog masters qualify');
$test=$vinyl;$test['payment']['livemode']=false;
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($test,0,$library,$paidSources,$tracks)),
 'Stripe TEST charges never authorize physical Kunaki publishing');
$simulated=$vinyl;$simulated['payment']['provider']='cash_simulation';
$simulated['payment']['status']='simulated_paid';
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($simulated,0,$library,$paidSources,$tracks)),
 'Simulated cash payments never authorize physical publishing');
$missingProof=$vinyl;$missingProof['payment']['postpayment_done']['pod']=false;
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($missingProof,0,$library,$paidSources,$tracks)),
 'Incomplete postpayment POD checkpoint blocks vendor queue');
$unsigned=$vinyl;$unsigned['payment']['payment_intent']='';
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($unsigned,0,$library,$paidSources,$tracks)),
 'Missing Stripe PaymentIntent blocks vendor queue');
$sourceTest=$source1;$sourceTest['payment']['livemode']=false;
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($vinyl,0,$library,
 ['SF-SONG-ONE'=>$sourceTest,'SF-SONG-TWO'=>$source2],$tracks)),
 'Sandbox-purchased catalog tracks cannot authorize live manufacturer');
c84b(!str_contains(json_encode($approved,JSON_THROW_ON_ERROR),'masters/'),
 'Worker catalog manifest does not leak private master file paths');
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($vinyl,0,[$library[0]],$paidSources,$tracks)),
 'Having vinyl printing purchase does not grant another selected track');
$unpaid=$source2;$unpaid['payment']['status']='pending';
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($vinyl,0,$library,
  ['SF-SONG-ONE'=>$source1,'SF-SONG-TWO'=>$unpaid],$tracks)),
 'Unpaid audio source order rejected');
$fraud=$source2;$fraud['user_id']=99;
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($vinyl,0,$library,
  ['SF-SONG-ONE'=>$source1,'SF-SONG-TWO'=>$fraud],$tracks)),
 'Other customer audio order rejected');
$revoked=$source2;$revoked['status']='refunded';
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($vinyl,0,$library,
  ['SF-SONG-ONE'=>$source1,'SF-SONG-TWO'=>$revoked],$tracks)),
 'Refunded track entitlement rejected');
$falseGrant=$library;$falseGrant[1]['source_order_id']='SF-PURCHASE-84B';
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($vinyl,0,$falseGrant,
 ['SF-SONG-ONE'=>$source1,'SF-PURCHASE-84B'=>$vinyl],$tracks)),
 'Purchased vinyl printing line is not a digital song purchase');
$badCatalog=$tracks;$badCatalog['song-two']['podEligible']=false;
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($vinyl,0,$library,$paidSources,$badCatalog)),
 'Unlicensed print master rejected');
$badCatalog=$tracks;$badCatalog['song-two']['audio']='';
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($vinyl,0,$library,$paidSources,$badCatalog)),
 'Missing catalog audio master rejected');
$badVinyl=$vinyl;$badVinyl['payment']['status']='pending';
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($badVinyl,0,$library,$paidSources,$tracks)),
 'Unpaid physical vinyl order rejected');
$cert=$vinyl;$cert['id']='SF-CERT-84B';
c84b(blocked84b(fn()=>sf_kunaki_publishing_entitlements($cert,0,$library,$paidSources,$tracks)),
 'Internal Stripe test probes rejected');
$proof=['ready'=>true,'fingerprint'=>hash('sha256','exact approved record')];
$record=['status'=>'artwork_approved','proof_sha256'=>$proof['fingerprint']];
$job=sf_kunaki_publishing_contract($vinyl,0,$record,$proof,$approved);
c84b(($job['schema']??'')==='stonefellow.kunaki.publish-job.v1'
 &&preg_match('/^KJP-[A-F0-9]{24}$/D',$job['job_id'])===1,
 'Stable versioned cloud-to-HomeServer job contract');
c84b(!$job['browser_upload_enabled']&&!$job['kunaki_submission_enabled']
 &&!$job['media_download_granted'],'No browser, media or vendor execution privilege');
c84b(!str_contains(json_encode($job),'masters/')&&!str_contains(json_encode($job),'password'),
 'Worker job excludes private media paths and credentials');
c84b(blocked84b(fn()=>sf_kunaki_publishing_contract($vinyl,0,
 ['status'=>'awaiting_artwork_review','proof_sha256'=>$proof['fingerprint']],$proof,$approved)),
 'Admin artwork approval required');
$altered=$record;$altered['proof_sha256']=hash('sha256','different artwork');
c84b(blocked84b(fn()=>sf_kunaki_publishing_contract($vinyl,0,$altered,$proof,$approved)),
 'Changed print design blocked');
$a=sf_kunaki_job_enqueue($job);$b=sf_kunaki_job_enqueue($job);
c84b($a['job_id']===$b['job_id']&&count(sf_kunaki_job_for_order($vinyl['id']))===1,
 'Duplicate cloud enqueue returns original job');
c84b(is_file($tmp.'/storage/kunaki/.htaccess'),'Queue storage has Apache access denial');
$admin=sf_kunaki_job_for_order_public($vinyl['id']);
c84b(count($admin)===1&&!isset($admin[0]['catalog_tracks'])
 &&!isset($admin[0]['lease_token']),'Admin job projection hides source orders and lease secrets');
$claimed=sf_kunaki_job_claim('HS-primary-001');
c84b(($claimed['state']??'')==='leased'&&!empty($claimed['lease_token']),
 'HomeServer identity claims one bounded lease');
c84b(sf_kunaki_job_claim('HS-secondary-001')===null,'Second worker cannot double claim an active job');
c84b(blocked84b(fn()=>sf_kunaki_job_report($job['job_id'],'HS-secondary-001',
 (string)$claimed['lease_token'],'awaiting_media_grant')),'Foreign worker cannot report');
c84b(blocked84b(fn()=>sf_kunaki_job_report($job['job_id'],'HS-primary-001',
 (string)$claimed['lease_token'],'published')),'HomeServer cannot claim vendor publication without explicit capability');
$reported=sf_kunaki_job_report($job['job_id'],'HS-primary-001',
 (string)$claimed['lease_token'],'awaiting_media_grant');
c84b($reported['state']==='awaiting_media_grant'&&!isset($reported['lease_token']),
 'Worker reports safe review state and lease secret is cleared');
c84b(blocked84b(fn()=>sf_kunaki_job_report($job['job_id'],'HS-primary-001',
 (string)$claimed['lease_token'],'awaiting_media_grant')),'Completed lease cannot replay');
$secret=str_repeat('X',48);$timestamp=(string)time();$nonce=bin2hex(random_bytes(16));$raw='{"operation":"claim"}';
$sig=sf_kunaki_worker_sign($secret,$timestamp,$nonce,'POST','/api/kunaki-worker.php',$raw);
$headers=['timestamp'=>$timestamp,'nonce'=>$nonce,'signature'=>$sig,'worker'=>'HS-primary-001'];
c84b(sf_kunaki_worker_verify($headers,$raw,$secret,time())==='HS-primary-001',
 'Timestamp-bound HMAC worker identity validated');
c84b(blocked84b(fn()=>sf_kunaki_worker_verify($headers,'{"operation":"report"}',$secret,time())),
 'Tampered request payload rejected');
c84b(blocked84b(fn()=>sf_kunaki_worker_verify($headers,$raw,$secret,time()+601)),
 'Old signed request rejected');
c84b(blocked84b(fn()=>sf_kunaki_worker_verify($headers,$raw,'short',time())),
 'Missing worker key fails closed');
sf_kunaki_worker_nonce_once($nonce);
c84b(blocked84b(fn()=>sf_kunaki_worker_nonce_once($nonce)),
 'Repeated nonce rejected');
$skill=sf_agent_skill_manifest('admin_kunaki_publishing','admin');
c84b(is_array($skill)&&sf_agent_skill_manifest('admin_kunaki_publishing','customer')===null,
 'Kunaki skill is Admin-only and discoverable');
c84b(!sf_agent_tool_executable('kunaki_publishing_queue','admin',true),
 'Agent cannot use descriptive skill to bypass Owner UI confirmation');
$api=file_get_contents($root.'/admin/api/vinyl-production.php');
$ui=file_get_contents($root.'/admin/assets/fulfillment.js');
$worker=file_get_contents($root.'/api/kunaki-worker.php');
c84b(str_contains($api,'sf_admin_require_auth')&&str_contains($api,'sf_kunaki_publishing_verified_tracks'),
 'Admin stage endpoint re-verifies track purchases from server data');
c84b(str_contains($ui,'data-kunaki-queue')&&str_contains($ui,'proofMatches'),
 'Existing Fulfillment canvas shows owner-confirmed staging');
c84b(str_contains($worker,'sf_kunaki_worker_verify')&&!str_contains($worker,'curl_exec('),
 'Worker endpoint requires signing and never contacts Kunaki');
echo 'Section 84B1 Cloud→HomeServer contract: '.$pass.'/'.($pass+$fail).' passed'.PHP_EOL;
exit($fail?1:0);
