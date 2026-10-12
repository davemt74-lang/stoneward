<?php
declare(strict_types=1);
/** Section 44D: aggregate-only research. Never expose individual fan records. */
require __DIR__.'/bootstrap.php';
$admin=sf_admin_require_auth(false);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$segmentId=max(0,(int)($_GET['segment_id']??0));
if($segmentId>0){
    $segment=sf_segment_get($segmentId);
    if(!$segment)sf_json_response(['ok'=>false,'error'=>'segment_not_found'],404);
    $contacts=sf_campaign_audience_contacts(['audience'=>['segment_id'=>$segmentId]],5000);
    $matched=count($contacts);$eligible=0;
    foreach($contacts as $contact)if(!empty($contact['marketing_opt_in']))$eligible++;
    sf_json_response([
        'ok'=>true,'segment'=>['id'=>$segmentId,'name'=>(string)$segment['name']],
        'matched'=>$matched,'email_eligible'=>$eligible,'suppressed'=>$matched-$eligible,
        'capped'=>$matched>=5000,'consent_required'=>true,
        'note'=>'Preview counts only; final eligibility is rechecked when a confirmed send is executed.'
    ]);
}
$summary=sf_crm_admin_summary();
$segments=[];
foreach(sf_segment_list() as $segment){
    $segments[]=['id'=>(int)$segment['id'],'name'=>(string)$segment['name']];
    if(count($segments)>=100)break;
}
sf_json_response([
    'ok'=>true,'totals'=>['contacts'=>(int)($summary['contacts']??0),'email_eligible'=>(int)($summary['newsletter']??0)],
    'segments'=>$segments,'consent_required'=>true
]);
