<?php
declare(strict_types=1);
/** Section 79: bounded, non-writing agent commerce triage and reply drafts. */
$root=dirname(__DIR__);
require_once $root.'/api/agent-commerce-triage.php';
$pass=0;$fail=0;
function ok79(bool $yes,string $name):void {
    global $pass,$fail;
    $yes?$pass++:$fail++;
    echo ($yes?'PASS ':'FAIL ').$name.PHP_EOL;
}
function bad79(callable $fn):bool {
    try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return true;}
}
$notReady=sf_agent_commerce_triage(['available'=>false,'attention_orders'=>[['id'=>'SF-TEST-1']]]);
ok79(!$notReady['available']&&$notReady['count']===null&&$notReady['items']===[],'Missing Operations data never fabricates a cleared queue');
$orders=[
 ['id'=>'SF-SHIP-001','flags'=>['fulfillment_pending']],
 ['id'=>'SF-PAY-002','flags'=>['payment_pending_review']],
 ['id'=>'SF-POST-003','flags'=>['postpayment_incomplete','fulfillment_pending']],
 ['id'=>'SF-REF-004','flags'=>['refund_pending']],
 ['id'=>'SF-CERT-FAKE','flags'=>['postpayment_incomplete']],
 ['id'=>'SF-SAFE-005','flags'=>['refund_completed']],
 ['id'=>'SF-BROKEN/006','flags'=>['refund_pending']],
 ['id'=>'SF-NOFLAG-007','flags'=>[]]
];
$triage=sf_agent_commerce_triage(['available'=>true,'attention_orders'=>$orders]);
ok79($triage['available']&&$triage['count']===5&&count($triage['items'])===5,'Only supported customer-order flags become review suggestions');
ok79($triage['items'][0]['order_id']==='SF-POST-003'&&$triage['items'][0]['severity']==='critical','Incomplete paid-order side effects rank highest');
ok79($triage['items'][1]['order_id']==='SF-REF-004'&&$triage['items'][1]['severity']==='high','Pending refunds are prioritized without claiming they succeeded');
ok79($triage['items'][2]['order_id']==='SF-PAY-002','Overdue payment review ranks above fulfillment');
ok79(!str_contains(json_encode($triage,JSON_THROW_ON_ERROR),'SF-CERT')&&!str_contains(json_encode($triage),'SF-BROKEN'),'Stripe probes and malformed IDs rejected');
ok79($triage['read_only']&&array_reduce($triage['items'],fn($carry,$x)=>$carry&&$x['requires_review']&&$x['action']==='review_existing_order'&&$x['next_view']==='commerce-ops',true),'Every triage result requires manual Commerce Operations review');
ok79(count(sf_agent_commerce_triage(['available'=>true,'attention_orders'=>$orders],2)['items'])===2,'List is bounded for Agent Brain');
$cmd=sf_agent_commerce_reply_request('Draft reply for order SF-POST-003 about shipping and tracking');
ok79($cmd!==null&&$cmd['order_id']==='SF-POST-003'&&$cmd['topic']==='shipping','Admin Chat shipping draft intent is deterministic');
ok79(sf_agent_commerce_reply_request('Show payment totals')===null,'Unrelated research cannot open reply drafting');
ok79(sf_agent_commerce_reply_request('Draft reply without an order')===null,'No fabricated order IDs for incomplete draft prompts');
$ref=sf_agent_commerce_reply_request('Prepare a reply for order SF-REF-004 about refunds');
ok79($ref!==null&&$ref['topic']==='refund','Refund-specific reply draft intent supported');
$order=['id'=>'SF-POST-003','user_id'=>7,'customer'=>['email'=>'PRIVATE@example.com',
    'name'=>'PRIVATE NAME'],'payment'=>['payment_intent'=>'PRIVATE_PI'],
    'fulfillment'=>['tracking_number'=>'PRIVATE_TRACKING']];
$reply=sf_agent_commerce_reply_draft($order,'shipping');
ok79($reply['state']==='unsent_draft'&&!$reply['delivery_enabled']
  &&!$reply['may_execute']&&$reply['requires_human_review'],'Reply draft is never an executable message');
ok79(str_contains($reply['text'],"\n\n")&&str_contains($reply['text'],'Stonefellow Support'),'Support draft is formatted for review');
ok79(!str_contains(json_encode($reply,JSON_THROW_ON_ERROR),'PRIVATE'),'Support draft excludes customer names, email, provider and tracking details');
ok79(bad79(fn()=>sf_agent_commerce_reply_draft(['id'=>'SF-CERT-123','certification_probe'=>true],'shipping')),'Stripe certification orders never receive drafts');
ok79(bad79(fn()=>sf_agent_commerce_reply_draft($order,'charge_customer')),'Unknown reply topics cannot trigger payment behavior');
$bridge=file_get_contents($root.'/api/agent-commerce-bridge.php');
$admin=file_get_contents($root.'/api/agent-admin-brain.php');
$canvas=file_get_contents($root.'/admin/assets/agent-canvas.js');
ok79(str_contains($bridge,"'triage'=>sf_agent_commerce_triage")&&str_contains($bridge,"'available'=>false"),'Existing Admin Brain supplies triage without new Agent runtime');
ok79(str_contains($admin,"'draft_commerce_reply'")&&str_contains($admin,"sf_agent_commerce_reply_draft")&&str_contains($admin,"sf_read_order"),'Admin Chat verifies actual order before drafting');
ok79(str_contains($canvas,'data-agent-copy-reply')&&str_contains($canvas,'clipboard.writeText'),'Chat exposes copy-only draft, not a send button');
ok79(str_contains($canvas,'Priority commerce reviews')&&str_contains($canvas,'commerceTriage.available'),'Agent Brain shows ordered reviews and unavailable-state warnings');
$code=$bridge.$admin.$canvas.file_get_contents($root.'/api/agent-commerce-triage.php');
ok79(!str_contains($code,'sf_store_refund_request(')&&!str_contains($code,'sf_store_stripe_checkout_start('),
    'New triage path has no Stripe charge or refund invocations');
echo 'Section 79 agent commerce triage: '.$pass.'/'.($pass+$fail).' passed'.PHP_EOL;
exit($fail?1:0);
