<?php
declare(strict_types=1);
/** Section 81: manufacture only the purchased and explicitly reviewed design. */
$root=dirname(__DIR__);
require_once $root.'/api/vinyl-production-gates.php';
$pass=0;$fail=0;
function ok81(bool $yes,string $name):void {global $pass,$fail;$yes?$pass++:$fail++;echo ($yes?'PASS ':'FAIL ').$name.PHP_EOL;}
function deny81(callable $fn):bool {try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return true;}}
$tmp=sys_get_temp_dir().'/sf-proof81-'.bin2hex(random_bytes(8));
mkdir($tmp.'/pod',0700,true);
$id='SF-PROOF-0081';
$b=['title'=>'Favorite Songs','coverFront'=>'midnight','coverBack'=>'analog',
    'customNote'=>'Favorite songs','sides'=>[
      'A'=>['duration'=>180,'tracks'=>[['id'=>'song1','audio_master'=>'master1.wav']]],
      'B'=>['duration'=>200,'tracks'=>[['id'=>'song2','audio_master'=>'master2.wav']]],
    ]];
$order=['id'=>$id,'status'=>'paid','payment'=>['provider'=>'stripe','status'=>'paid',
    'postpayment_done'=>['pod'=>true]],'quote'=>['items'=>[
      ['type'=>'custom_media','format'=>'vinyl','builder'=>$b]
    ]]];
$handoff=['schema'=>'stonefellow.pod-handoff.v1','provider'=>'file_handoff','order_id'=>$id,
    'item_index'=>0,'format'=>'vinyl','title'=>$b['title'],
    'artwork_front'=>$b['coverFront'],'artwork_back'=>$b['coverBack'],
    'dedication_note'=>$b['customNote'],'sides'=>$b['sides'],
    'customer_player_preview'=>['visual_only'=>true],
    'recipient'=>['line1'=>'Private Home 123'],'created_at'=>gmdate('c')];
$path=$tmp.'/pod/'.$id.'-0.json';
file_put_contents($path,json_encode($handoff,JSON_THROW_ON_ERROR));
$proof=sf_vinyl_production_proof($order,$tmp);
ok81($proof['ready']&&preg_match('/^[a-f0-9]{64}$/D',$proof['fingerprint'])===1,'Exact paid vinyl quote and POD handoff produce stable fingerprint');
ok81(($proof['items'][0]['side_a_tracks']??-1)===1&&($proof['items'][0]['side_b_tracks']??-1)===1,'Admin can review purchased track counts');
ok81(!str_contains(json_encode($proof,JSON_THROW_ON_ERROR),'Private Home 123')
    &&!str_contains(json_encode($proof),'master1.wav'),'Proof summary never includes shipping address or audio master paths');
$again=sf_vinyl_production_proof($order,$tmp);
ok81($proof['fingerprint']===$again['fingerprint'],'Created-at and metadata do not change immutable artwork digest');
$first=sf_vinyl_production_approval(['revision'=>0,'status'=>'awaiting_artwork_review'],
    $proof,['confirmed'=>true,'proof_sha256'=>$proof['fingerprint']],'artwork_approved');
ok81($first['proof_sha256']===$proof['fingerprint']&&!empty($first['proof_reviewed_at']),'First artwork approval records exact design fingerprint');
ok81(deny81(fn()=>sf_vinyl_production_approval([], $proof,['confirmed'=>false,'proof_sha256'=>$proof['fingerprint']],'artwork_approved')),'Human confirmation cannot be skipped');
ok81(deny81(fn()=>sf_vinyl_production_approval([], $proof,['confirmed'=>true,'proof_sha256'=>str_repeat('0',64)],'artwork_approved')),'Changed browser proof rejected');
ok81(sf_vinyl_production_approval(['proof_sha256'=>$first['proof_sha256']],
    $proof,['confirmed'=>true],'in_production')['proof_sha256']===$first['proof_sha256'],'Next manufacturing milestone must use original approved proof');
ok81(deny81(fn()=>sf_vinyl_production_approval(['proof_sha256'=>''], $proof,
    ['confirmed'=>true],'in_production')),'Legacy orders missing proof fail closed pending explicit re-verification');
ok81(deny81(fn()=>sf_vinyl_production_approval(['proof_sha256'=>$first['proof_sha256']],$proof,
    ['confirmed'=>true,'note'=>'OK','qc_confirmed'=>false],'production_complete')),'Quality stage rejects empty checkbox and short note');
ok81(sf_vinyl_production_approval(['proof_sha256'=>$first['proof_sha256']],$proof,
    ['confirmed'=>true,'note'=>'Audio and artwork verified','qc_confirmed'=>true],'production_complete')['proof_sha256']===$proof['fingerprint'],'Explicit signed-off quality inspection accepted');
$fake=$order;$fake['id']='SF-CERT-PROBE';
ok81(deny81(fn()=>sf_vinyl_production_proof($fake,$tmp)),'Internal Stripe certification orders rejected');
$notPaid=$order;$notPaid['payment']['status']='pending';
ok81(!sf_vinyl_production_proof($notPaid,$tmp)['ready'],'Pending Stripe charge is not manufacturing authorization');
$noPod=$order;$noPod['payment']['postpayment_done']=[];
ok81(!sf_vinyl_production_proof($noPod,$tmp)['ready'],'Unfinished post-payment POD checkpoint blocks approval');
$refunded=$order;$refunded['status']='partially_refunded';
ok81(!sf_vinyl_production_proof($refunded,$tmp)['ready'],'Refunded order cannot start or continue pressing');
$altered=$handoff;$altered['artwork_front']='desert';
file_put_contents($path,json_encode($altered,JSON_THROW_ON_ERROR));
ok81(!sf_vinyl_production_proof($order,$tmp)['ready'],'POD artwork drift fails closed');
$altered=$handoff;$altered['sides']['A']['tracks'][0]['audio_master']='replacement.wav';
file_put_contents($path,json_encode($altered,JSON_THROW_ON_ERROR));
ok81(!sf_vinyl_production_proof($order,$tmp)['ready'],'Altered audio master files fail closed');
$altered=$handoff;$altered['customer_player_preview']['visual_only']=false;
file_put_contents($path,json_encode($altered,JSON_THROW_ON_ERROR));
ok81(!sf_vinyl_production_proof($order,$tmp)['ready'],'Visual player modules never become manufacturing instructions');
unlink($path);
ok81(!sf_vinyl_production_proof($order,$tmp)['ready'],'Missing actual POD handoff never fakes a production proof');
$api=file_get_contents($root.'/admin/api/vinyl-production.php');
$js=file_get_contents($root.'/admin/assets/fulfillment.js');
ok81(str_contains($api,'sf_admin_require_auth')&&str_contains($api,'sf_vinyl_production_approval')&&str_contains($api,"'proof_sha256'"),'Admin API requires authenticated and proof-bound production milestones');
ok81(str_contains($api,'verify_existing_proof')&&str_contains($api,'revision'),'Existing stage records require versioned explicit reconciliation');
ok81(str_contains($js,'vinylProofLegacy')&&str_contains($js,'proofNeedsReview'),'Existing Fulfillment canvas exposes proof conflict/reconciliation status');
ok81(str_contains($js,'qc_confirmed')&&str_contains($js,'proof_sha256:proof.fingerprint'),'Final inspection and proof hash submitted with exact stage');
ok81(str_contains($api,'flock($lock,LOCK_EX)')&&str_contains($api,'sf_log_admin_action'),'Stage transitions retain exclusive lock and Admin audit');
rmdir($tmp.'/pod');rmdir($tmp);
echo 'Section 81 manufacturing proof + QC: '.$pass.'/'.($pass+$fail).' passed'.PHP_EOL;
exit($fail?1:0);
