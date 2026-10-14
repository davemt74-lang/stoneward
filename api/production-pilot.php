<?php
declare(strict_types=1);
/**
 * Section 83 — non-mutating cross-system pilot acceptance. This is an
 * operator diagnostic, not a Stripe transaction, vendor verification or
 * certification of an external host. Do NOT let client-supplied data
 * authorize production payments.
 */
function sf_pilot_report(?array $release, ?array $agent, array $installed): array {
    $gates=[];
    $add=static function(string $id,string $label,bool $ok,string $explain,string $scope)use(&$gates):void {
        $gates[]=[
            'id'=>$id,'label'=>$label,'status'=>$ok?'pass':'blocked',
            'explanation'=>$explain,'scope'=>$scope
        ];
    };
    $releaseOk=is_array($release);
    $testReady=$releaseOk&&($release['test_activation_ready']??false)===true;
    $queueClear=$releaseOk&&($release['operations_clear']??false)===true;
    $webhook=false;
    if($releaseOk){
        $sample=(array)($release['bounded_webhook_sample']??[]);
        $webhook=($sample['window_days']??0)===7
          &&($sample['sample_limit']??0)===100
          &&($sample['failed']??null)===0
          &&($sample['processing']??null)===0;
        // A clean sample alone does not prove a bound Stripe merchant/webhook.
        $passed=[];
        foreach((array)($release['checks']??[]) as $item){
            if(is_array($item)&&isset($item['id'])&&($item['state']??'')==='pass')
                $passed[(string)$item['id']]=true;
        }
        $webhook=$webhook&&isset($passed['signing'],$passed['sandbox']);
    }
    $agentOk=is_array($agent)&&($agent['available']??false)===true
      &&($agent['reads_only']??false)===true
      &&(($agent['triage']['read_only']??false)===true);
    $add('sandbox_checkout','Signed Stripe sandbox checkout and refund evidence',
        $testReady,'Section 77 must verify the real provider TEST account and paid, declined, expired and refunded $1 cases.','runtime');
    $add('webhook','Signed webhook and recent reconciliation window',
        $webhook,'Signed sandbox events and the bounded seven-day webhook sample must be clear; this is not a full ledger audit.','runtime');
    $add('commerce_queue','Commerce exceptions reconciled',
        $queueClear,'Review payment, refunds, fulfillment and customer follow-up queues before attempting a pilot.','runtime');
    $add('customer_order','Authenticated customer order and entitlement routes installed',
        !empty($installed['customer_order']),'Technical presence only; test customer A/B ownership and digital delivery on staging.','technical');
    $add('fulfillment','Shipment, return and refund operations installed',
        !empty($installed['fulfillment']),'Technical presence only; operator must test physical delivery and refund recovery.','technical');
    $add('vinyl_proof','Purchased vinyl/POD fingerprint controls installed',
        !empty($installed['vinyl_proof']),'Technical presence only; confirm the real print provider accepted the exact signed-off design.','technical');
    $add('agents','Admin commerce-agent review source available and read-only',
        $agentOk,'Check actual Admin/Customer Agent Brain isolation and exact approval in staging.','runtime');
    $add('fan_consent','Marketing consent safeguards installed',
        !empty($installed['fan_consent']),'Technical presence only; test unsubscribe and consent revocation against the configured mailer.','technical');
    $add('notifications','Customer notification pipeline installed',
        !empty($installed['notifications']),'Technical presence only; validate actual email/in-app delivery and duplicate prevention.','technical');
    $add('upgrade','Authenticated upgrade and backup tooling installed',
        !empty($installed['upgrade']),'Technical presence only; run a real backup and restore on the production-like host before release.','technical');
    $passed=count(array_filter($gates,static fn(array $g):bool=>$g['status']==='pass'));
    $technical=array_filter($gates,static fn(array $g):bool=>$g['scope']==='technical');
    $technicallyPresent=count(array_filter($technical,static fn(array $g):bool=>$g['status']==='pass'))===count($technical);
    $manual=[
       ['id'=>'browser','label'=>'Guest and signed-in checkout, declined card and order ownership on real mobile/desktop browsers'],
       ['id'=>'production_host','label'=>'Staging upgrade, preserved private data, backup and tested restore'],
       ['id'=>'vendor','label'=>'Physical POD provider artwork acceptance, QC and delivered shipment'],
       ['id'=>'delivery','label'=>'Actual customer notifications, unsubscribe and mail-provider receipt'],
       ['id'=>'isolation','label'=>'Admin/Customer Agent isolation and approval/recovery rehearsal'],
       ['id'=>'payments','label'=>'Real Stripe TEST transaction and webhook verification; no live-money pilot']
    ];
    return [
      'schema'=>'stonefellow.section83.pilot-readiness.v1',
      'generated_at'=>gmdate('c'),'read_only'=>true,
      'gates'=>$gates,'passed'=>$passed,'total'=>count($gates),
      'technical_components_present'=>$technicallyPresent,
      'sandbox_rehearsal_ready'=>$testReady&&$webhook&&$queueClear&&$agentOk&&$technicallyPresent,
      'test_checkout_active'=>$testReady&&($release['test_checkout_active']??false)===true,
      'manual_verification_required'=>$manual,
      'manual_verification_complete'=>false,
      'production_activation_authorized'=>false,
      'live_payments_available'=>false,
      'summary'=>'Source checks and runtime health never substitute for a real provider, browser, mailer, upgrade or fulfillment rehearsal. Production charging remains disabled.'
    ];
}
/** The caller has already passed Admin authentication before collecting data. */
function sf_pilot_snapshot(int $adminId): array {
    if($adminId<1)throw new InvalidArgumentException('Authenticated Admin required.');
    $release=null;$agent=null;
    try{
        if(function_exists('sf_commerce_release_snapshot'))
            $release=sf_commerce_release_snapshot($adminId);
    }catch(Throwable $e){error_log('Section 83 commerce evidence unavailable: '.get_class($e));}
    try{
        if(function_exists('sf_agent_commerce_admin_snapshot'))
            $agent=sf_agent_commerce_admin_snapshot();
    }catch(Throwable $e){error_log('Section 83 Agent evidence unavailable: '.get_class($e));}
    $root=defined('SF_ROOT')?SF_ROOT:dirname(__DIR__);
    $installed=[
        'customer_order'=>is_file($root.'/api/customer-order.php')
            &&is_file($root.'/api/customer-commerce-view.php')
            &&is_file($root.'/api/entitlements.php'),
        'fulfillment'=>is_file($root.'/api/fulfillment-core.php')
            &&is_file($root.'/admin/api/fulfillment.php'),
        'vinyl_proof'=>is_file($root.'/api/vinyl-production-gates.php')
            &&is_file($root.'/admin/api/vinyl-production.php'),
        'fan_consent'=>is_file($root.'/api/crm-core.php')
            &&is_file($root.'/api/campaign-core.php')
            &&is_file($root.'/api/newsletter.php'),
        'notifications'=>is_file($root.'/api/notification-core.php')
            &&is_file($root.'/api/customer-care.php'),
        'upgrade'=>is_file($root.'/upgrade.php')
            &&is_file($root.'/api/migrations.php')
            &&is_file($root.'/api/operations.php')
    ];
    return sf_pilot_report($release,$agent,$installed);
}
