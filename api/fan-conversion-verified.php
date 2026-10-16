<?php
declare(strict_types=1);
/** 87D1: read-only verified Stripe/live conversion intelligence. */
function sf_87d_order_payment_class(array $o): string {
    $p=(array)($o['payment']??[]);$status=(string)($o['status']??'');$payment=(string)($p['status']??'');
    if(!empty($o['certification_probe']))return 'certification_excluded';
    if(($p['provider']??'')!=='stripe')return in_array($payment,['simulated_paid','test_paid'],true)||str_starts_with($status,'paid_')?'simulation':'other';
    if(empty($p['livemode'])||!str_starts_with((string)($p['checkout_session_id']??''),'cs_live_'))return 'stripe_test_or_unverified';
    if(!in_array($status,['paid','partially_refunded','refunded'],true)
       ||!in_array($payment,['paid','partially_refunded','refunded'],true)
       ||!preg_match('/^pi_[A-Za-z0-9]+$/D',(string)($p['payment_intent']??''))
       ||!is_string($p['paid_at']??null)||strtotime((string)$p['paid_at'])===false
       ||(int)($p['amount_cents']??-1)!==(int)($o['quote']['total_cents']??-2)
       ||strtoupper((string)($p['currency']??''))!==strtoupper((string)($o['quote']['currency']??'')))
        return 'unverified';
    $confirmed=false;foreach((array)($o['timeline']??[]) as $event)
        if(is_array($event)&&($event['type']??'')==='payment_confirmed'){$confirmed=true;break;}
    if(!$confirmed)return 'unverified';
    if($status==='refunded'||$payment==='refunded')return 'live_refunded';
    if($status==='partially_refunded'||$payment==='partially_refunded')return 'live_partially_refunded';
    return 'live_paid';
}
function sf_87d_verified_conversion_report(array $orders,int $days=30,?int $now=null):array {
    $days=max(1,min(365,$days));$now=$now??time();$since=$now-$days*86400;
    $sum=['verified_live_orders'=>0,'verified_live_gross_cents'=>0,
      'verified_live_refund_cents'=>0,'verified_live_net_cents'=>0,
      'live_fully_refunded_orders'=>0,'live_partially_refunded_orders'=>0,
      'stripe_test_or_simulated_orders'=>0,'pending_or_unverified_orders'=>0];
    $cats=['track'=>0,'release'=>0,'custom_media'=>0,'album_vinyl'=>0,'vinyl_license_pack'=>0,'album_vinyl_license_pack'=>0,'other'=>0];
    $seen=[];$invalid=0;
    foreach(array_slice($orders,0,20000) as $o){
        if(!is_array($o))continue;$id=(string)($o['id']??'');
        if(!preg_match('/^SF-[A-Z0-9-]{3,96}$/D',$id)||isset($seen[$id]))continue;
        $seen[$id]=true;$class=sf_87d_order_payment_class($o);
        if($class==='certification_excluded')continue;
        $at=strtotime((string)($o['payment']['paid_at']??$o['created_at']??''));
        if($at===false||$at<$since||$at>$now+300)continue;
        if(in_array($class,['simulation','stripe_test_or_unverified'],true)){$sum['stripe_test_or_simulated_orders']++;continue;}
        if(!in_array($class,['live_paid','live_partially_refunded','live_refunded'],true)){$sum['pending_or_unverified_orders']++;continue;}
        $gross=(int)($o['quote']['total_cents']??-1);$refund=(int)($o['payment']['refunded_cents']??0);
        if($gross<0||$gross>1000000000||$refund<0||$refund>$gross){$invalid++;continue;}
        $sum['verified_live_orders']++;$sum['verified_live_gross_cents']+=$gross;
        $sum['verified_live_refund_cents']+=$refund;
        if($class==='live_refunded')$sum['live_fully_refunded_orders']++;
        if($class==='live_partially_refunded')$sum['live_partially_refunded_orders']++;
        $types=[];foreach((array)($o['quote']['items']??[]) as $item){
            if(!is_array($item))continue;$type=(string)($item['type']??'other');
            $types[isset($cats[$type])?$type:'other']=true;
        }
        foreach(array_keys($types) as $type)$cats[$type]++;
    }
    $sum['verified_live_net_cents']=$sum['verified_live_gross_cents']-$sum['verified_live_refund_cents'];
    return ['days'=>$days,'orders'=>$sum,'order_categories'=>$cats,'attribution_rate'=>null,
      'verification'=>'Server-recorded live Stripe payment confirmation with matching session, intent, amount, currency and confirmation timeline.',
      'limitations'=>['Test and simulated purchases are not live revenue','Categories count distinct paid orders, not product units','There is no verified listening-to-order funnel or cart conversion rate yet'],
      'changes_executed'=>false,'data_quality'=>['invalid_amount_rows'=>$invalid,'partial_archive'=>false,'source'=>'canonical_server_orders']];
}
function sf_87d_verified_conversion_from_disk(int $days=30):array {
    if(!defined('SF_ROOT'))throw new RuntimeException('Site root is unavailable.');
    $paths=glob(SF_ROOT.'/storage/orders/*.json')?:[];$partial=count($paths)>20000;
    $orders=[];foreach(array_slice($paths,0,20000) as $path){
        if(!is_file($path)||filesize($path)>262144)continue;
        $r=json_decode((string)@file_get_contents($path),true);
        if(is_array($r))$orders[]=$r;
    }
    $result=sf_87d_verified_conversion_report($orders,$days);
    $result['data_quality']['partial_archive']=$partial;return $result;
}
