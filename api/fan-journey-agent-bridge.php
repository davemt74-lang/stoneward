<?php
declare(strict_types=1);
/**
 * 87C3: strictly allowlisted Admin Agent Brain facts. The already-authorized
 * admin aggregate is transformed into a model-safe, read-only summary.
 * Client-supplied text and per-user records cannot enter this projection.
 */
function sf_fan_journey_agent_brief(array $diagnostics):array {
    $metrics=[];
    $allowed=['Listening starts','Repeat starts','Favorites added',
        'Recorded paid-status orders','Saved builds','Recorded custom-media orders'];
    foreach((array)($diagnostics['measures']??[]) as $row) {
        if(!is_array($row))continue;
        $label=(string)($row['label']??'');
        if(!in_array($label,$allowed,true))continue;
        $value=$row['count']??null;
        if(!is_int($value)||$value<0||$value>1000000000)continue;
        $metrics[$label]=$value;
    }
    $codes=[];
    $permitted=['sample_limited','repeat_interest','skip_events',
        'save_discovery','purchase_path','vinyl_path','baseline'];
    foreach((array)($diagnostics['opportunities']??[]) as $row) {
        if(!is_array($row))continue;
        $code=(string)($row['code']??'');
        if(in_array($code,$permitted,true)&&!in_array($code,$codes,true))$codes[]=$code;
        if(count($codes)>=6)break;
    }
    return [
        'days'=>max(1,min(3650,(int)($diagnostics['days']??30))),
        'metrics'=>$metrics,'opportunity_codes'=>$codes,
        'source'=>'admin_aggregate_only','attribution_verified'=>false,
        'recommendations_are_review_only'=>true,'marketing_permission_inferred'=>false,
        'transactions_or_changes_executed'=>false,
    ];
}
function sf_fan_journey_agent_message(array $brief):string {
    $m=(array)($brief['metrics']??[]);
    if(!array_key_exists('Listening starts',$m))
        return 'Fan engagement metrics are unavailable. Open Admin Analytics to review current records. No changes were made.';
    $starts=(int)$m['Listening starts'];
    $saves=(int)($m['Favorites added']??0);
    $orders=(int)($m['Recorded paid-status orders']??0);
    $text='Stonefellow fan activity (last '.(int)($brief['days']??30).' days): '.
        $starts.' listening starts, '.$saves.' favorites and '.$orders.
        ' recorded paid-status orders. These are separate observations, not a verified purchase funnel.';
    $advice=[
        'sample_limited'=>'Gather more listening activity before drawing conclusions.',
        'repeat_interest'=>'Review heavily replayed public songs for stronger discovery placement.',
        'skip_events'=>'Check player reliability, previews and early listening experience.',
        'save_discovery'=>'Check whether fans can easily find Save while listening.',
        'purchase_path'=>'Review track, cart and checkout navigation without assuming a conversion failure.',
        'vinyl_path'=>'Review the licensed vinyl Builder and checkout handoff.',
        'baseline'=>'Continue monitoring: no threshold-based concern was identified.',
    ];
    $lines=[];
    foreach((array)($brief['opportunity_codes']??[]) as $code)
        if(isset($advice[$code]))$lines[]=$advice[$code];
    if($lines)$text.="\nSuggested reviews: ".implode(' ',$lines);
    return $text."\nAll suggestions are read-only. No fan outreach, campaign, checkout or license change was made.";
}
