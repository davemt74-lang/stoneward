<?php
declare(strict_types=1);
/** 87D3: bounded read-only findings; never claims that a cart caused a payment. */
function sf_87d_admin_conversion_diagnostics(array $payments,array $interest):array {
    $o=(array)($payments['orders']??[]);$k=(array)($payments['order_categories']??[]);
    $intents=[];foreach((array)($interest['segments']??[]) as $s){
        if(!is_array($s))continue;
        $stage=(string)($s['stage']??'');$kind=(string)($s['kind']??'');
        if(!in_array($stage,['cart_review','checkout_review'],true)
          ||!in_array($kind,['digital','vinyl','mixed','other'],true))continue;
        $events=$s['events']??null;$accounts=$s['accounts']??null;
        $intents[]=['stage'=>$stage,'kind'=>$kind,
            'accounts'=>is_int($accounts)&&$accounts>=5?$accounts:null,
            'events'=>is_int($events)&&$accounts!==null&&$accounts>=5?max(0,$events):null,
            'insufficient_sample'=>($accounts===null||$accounts<5)];
    }
    $count=static fn(string $field):int=>max(0,(int)($o[$field]??0));
    $live=$count('verified_live_orders');$test=$count('stripe_test_or_simulated_orders');
    $pending=$count('pending_or_unverified_orders');
    $refund=$count('live_fully_refunded_orders')+$count('live_partially_refunded_orders');
    $codes=[];
    if($pending>0)$codes[]='review_payment_exceptions';
    if($refund>0)$codes[]='review_refund_experience';
    if($live===0&&$test>0)$codes[]='test_only_payment_evidence';
    $vinylCheckout=0;
    foreach($intents as $s)if($s['stage']==='checkout_review'
      &&in_array($s['kind'],['vinyl','mixed'],true)&&$s['accounts']!==null)$vinylCheckout+=$s['accounts'];
    $vinylPaid=max(0,(int)($k['custom_media']??0))+max(0,(int)($k['album_vinyl']??0));
    if($vinylCheckout>=5&&$vinylPaid===0)$codes[]='review_vinyl_handoff';
    if(!$codes)$codes[]='continue_measurement';
    $recs=[
        'review_payment_exceptions'=>'Inspect pending and unverified order states in Commerce Operations; never retry a charge automatically.',
        'review_refund_experience'=>'Review confirmed refund reasons and checkout expectations without contacting fans automatically.',
        'test_only_payment_evidence'=>'Only simulated or test payments are visible in this period; do not report live revenue.',
        'review_vinyl_handoff'=>'Review vinyl licensing and Builder checkout clarity. Interest events cannot prove an abandoned purchase.',
        'continue_measurement'=>'Continue measuring independently observed interest and verified Stripe outcomes before changing promotions.'
    ];
    $suggestions=[];foreach($codes as $code)$suggestions[]=[
        'code'=>$code,'evidence_type'=>'verified_server_order_plus_independent_interest',
        'suggestion'=>$recs[$code],'review_only'=>true,'executed'=>false];
    return ['days'=>max(1,min(365,(int)($payments['days']??30))),
        'verified_orders'=>[
          'live_paid'=>$live,'gross_cents'=>$count('verified_live_gross_cents'),
          'refund_cents'=>$count('verified_live_refund_cents'),'net_cents'=>$count('verified_live_net_cents'),
          'test_or_simulated'=>$test,'pending_or_unverified'=>$pending,
          'refunded_orders'=>$refund
        ],
        'verified_order_categories'=>array_intersect_key($k,array_fill_keys(['track','release','custom_media','album_vinyl','vinyl_license_pack','album_vinyl_license_pack','other'],true)),
        'observed_intent'=>$intents,'insight_codes'=>$codes,'suggestions'=>$suggestions,
        'verified_checkout_conversion_rate'=>null,'marketing_consent_inferred'=>false,
        'changes_executed'=>false,'limits'=>'Only signed-in, currently consenting, privacy-thresholded cart reviews and confirmed live Stripe orders. Observations are not joined; conversion and abandonment rates are unavailable.'];
}
function sf_87d_admin_agent_projection(array $r):array {
    $allowed=['review_payment_exceptions','review_refund_experience','test_only_payment_evidence',
        'review_vinyl_handoff','continue_measurement'];
    $codes=[];foreach((array)($r['insight_codes']??[]) as $code)
        if(in_array($code,$allowed,true)&&!in_array($code,$codes,true))$codes[]=$code;
    $numbers=[];foreach(['live_paid','gross_cents','refund_cents','net_cents','test_or_simulated',
      'pending_or_unverified','refunded_orders'] as $key){
        $value=$r['verified_orders'][$key]??null;
        if(is_int($value)&&$value>=0&&$value<=100000000000)$numbers[$key]=$value;
    }
    return ['source'=>'verified_server_payment_and_independent_interest',
        'live_orders'=>$numbers['live_paid']??null,
        'net_cents'=>$numbers['net_cents']??null,
        'pending_or_unverified'=>$numbers['pending_or_unverified']??null,
        'codes'=>$codes,'attribution_verified'=>false,'actions_executed'=>false];
}
function sf_87d_admin_agent_message(array $brief):string {
    if(($brief['live_orders']??null)===null)return 'Verified conversion data is unavailable. Do not guess at paid orders or attribution.';
    $n=(int)$brief['live_orders'];$net=(int)($brief['net_cents']??0);
    $message='Verified live Stripe payments: '.$n.' order(s), with recorded net $'.number_format($net/100,2).'. ';
    $actions=[
       'review_payment_exceptions'=>'Review pending payment exceptions in Commerce Operations.',
       'review_refund_experience'=>'Inspect refund reasons before optimizing checkout.',
       'test_only_payment_evidence'=>'Only test transactions were observed outside these verified live orders.',
       'review_vinyl_handoff'=>'Review the licensed vinyl Builder handoff without assuming cart abandonment.',
       'continue_measurement'=>'Continue measuring separate intent and verified payment events.'
    ];
    $lines=[];foreach((array)($brief['codes']??[]) as $code)
        if(isset($actions[$code]))$lines[]=$actions[$code];
    return $message.implode(' ',$lines).' There is no validated end-to-end conversion rate. No payment, campaign or customer record was changed.';
}
