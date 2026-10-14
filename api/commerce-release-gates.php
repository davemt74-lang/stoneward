<?php
declare(strict_types=1);
/**
 * Section 77 — read-only release gates for the EXISTING Stripe/commerce stack.
 * This is operational evidence, not permission to charge or change payout accounts.
 * In particular, this module deliberately has NO production activation action.
 */
function sf_commerce_release_report(array $merchant, array $cert, ?array $health, ?array $operations): array {
    $checks=[];
    $add=static function(string $id, string $label, bool $ok, string $detail, string $group='payment')use(&$checks):void {
        $checks[]=['id'=>$id,'label'=>$label,'state'=>$ok?'pass':'blocked','detail'=>$detail,'group'=>$group];
    };
    $provider=(string)($merchant['provider']??'none');
    $storeMode=(string)($merchant['store_mode']??'');
    $add('merchant','Single-artist merchant verified',
        $provider==='direct'&&!empty($merchant['direct_ready'])&&!empty($merchant['connected'])
        &&preg_match('/^acct_[A-Za-z0-9]+$/D',(string)($merchant['account_id']??''))===1,
        'Verify the same Stripe account with the direct test-key wizard.');
    $add('environment','Isolated test environment',
        $storeMode==='stripe_test'&&(string)($merchant['environment']??'')==='test',
        'Use STONEFELLOW_STORE_MODE=stripe_test. Never use live credentials for a sandbox run.');
    $add('charges','Stripe confirms charge capability',
        !empty($merchant['charges_enabled']),
        'Refresh Stripe account verification; Stripe must report charges enabled.');
    $add('https','Public HTTPS webhook address',
        sf_commerce_release_https_webhook((string)($merchant['webhook_url']??'')),
        'Set a publicly reachable HTTPS Base URL without credentials, query or fragment.');
    $add('signing','Webhook endpoint and signing secret bound',
        !empty($merchant['webhook_ready']),
        'Register and store the signed Stripe webhook endpoint before accepting payments.');
    $add('sandbox','Real signed sandbox certification',
        !empty($cert['complete'])&&!empty($cert['binding_valid'])
        &&!empty($merchant['sandbox_certified']),
        'Reverify paid, declined, expired and refunded $1 test cases against Stripe and signed webhook events.');
    $counts=is_array($health)?(array)($health['counts']??[]):[];
    $healthOk=is_array($health)
        &&(int)($health['window_days']??0)===7
        &&(int)($counts['failed']??0)===0
        &&(int)($counts['processing']??0)===0;
    $add('webhook_queue','No unresolved webhooks in recent sample',$healthOk,
        'Inspect recent Stripe webhook failures/processing events. This checks up to 100 events from seven days, not the entire ledger.');
    $summary=is_array($operations)?(array)($operations['summary']??[]):[];
    $opsValid=is_array($operations)
        &&isset($summary['customer_orders'],$summary['payment_attention'],$summary['refund_attention'],
                 $summary['fulfillment_pending'],$summary['open_followups']);
    $add('operations','Commerce Operations audit available',$opsValid,
        'Restore Orders/Commerce Operations data access before making a release decision.','operations');
    foreach([
        'payment_attention'=>'Unresolved payment exceptions',
        'refund_attention'=>'Unresolved refund requests',
        'fulfillment_pending'=>'Outstanding fulfillment',
        'open_followups'=>'Open customer follow-ups'
    ] as $key=>$label){
        $add($key,$label,$opsValid&&(int)($summary[$key]??0)===0,
            'Review the existing Commerce Operations queue; open items require an operator decision.','operations');
    }
    $testGate=['merchant','environment','charges','https','signing','sandbox','webhook_queue'];
    $pass=static function(array $ids)use(&$checks):bool {
        foreach($checks as $c)if(in_array($c['id'],$ids,true)&&$c['state']!=='pass')return false;
        return true;
    };
    $testReady=$pass($testGate);
    $opsReady=$pass(['operations','payment_attention','refund_attention','fulfillment_pending','open_followups']);
    return [
        'schema'=>'stonefellow.section77.release-gates.v1',
        'generated_at'=>gmdate('c'),
        'mode'=>$storeMode,
        'checks'=>$checks,
        'passed'=>count(array_filter($checks,static fn(array $x):bool=>$x['state']==='pass')),
        'total'=>count($checks),
        'test_activation_ready'=>$testReady,
        'test_checkout_active'=>$testReady&&!empty($merchant['payments_enabled']),
        'operations_clear'=>$opsReady,
        // Do not conflate sandbox/source certification with authorization to charge real money.
        'live_activation_available'=>false,
        'live_activation_reason'=>'Production Stripe activation is not implemented. Requires separate live-account verification, real sandbox acceptance, operator approval, and host staging tests.',
        'bounded_webhook_sample'=>is_array($health)?[
            'window_days'=>7,
            'sample_limit'=>100,
            'processed'=>(int)($counts['processed']??0),
            'failed'=>(int)($counts['failed']??0),
            'processing'=>(int)($counts['processing']??0)
        ]:null,
        'queue_counts'=>$opsValid?[
            'customer_orders'=>(int)$summary['customer_orders'],
            'payment_attention'=>(int)$summary['payment_attention'],
            'refund_attention'=>(int)$summary['refund_attention'],
            'fulfillment_pending'=>(int)$summary['fulfillment_pending'],
            'open_followups'=>(int)$summary['open_followups']
        ]:null
    ];
}
function sf_commerce_release_https_webhook(string $url):bool {
    if(!filter_var($url,FILTER_VALIDATE_URL))return false;
    $parts=parse_url($url);
    return is_array($parts)
        &&strtolower((string)($parts['scheme']??''))==='https'
        &&!empty($parts['host'])
        &&!isset($parts['user'])&&!isset($parts['pass'])
        &&!isset($parts['query'])&&!isset($parts['fragment'])
        &&(string)($parts['path']??'')!=='';
}
/** The caller MUST already have passed Admin read authentication. */
function sf_commerce_release_snapshot(int $adminId):array {
    if($adminId<=0)throw new InvalidArgumentException('Administrator authentication required.');
    $health=null;$operations=null;
    try{$health=sf_cert_webhook_health($adminId);}
    catch(Throwable $e){error_log('Section 77 webhook health unavailable: '.get_class($e));}
    try{$operations=sf_commerce_ops_snapshot(sf_admin_orders());}
    catch(Throwable $e){error_log('Section 77 commerce audit unavailable: '.get_class($e));}
    return sf_commerce_release_report(sf_merchant_status(),sf_cert_status(),$health,$operations);
}
