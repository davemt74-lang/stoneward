<?php
declare(strict_types=1);
require_once __DIR__.'/store-order-visibility.php';
/**
 * Section 79: commerce triage is a read-only view of authorized operational
 * facts. No Stripe API requests, customer messages, or tool executions.
 */
function sf_agent_commerce_triage(array $snapshot,int $limit=6):array {
    if(empty($snapshot['available']))return [
        'available'=>false,'items'=>[],'count'=>null,'read_only'=>true,
        'message'=>'Commerce Operations is unavailable. Do not assume the queue is clear.'
    ];
    $priorities=[
        'postpayment_incomplete'=>['severity'=>'critical','weight'=>100,
            'reason'=>'A confirmed payment has unfinished order side effects. Review recovery checkpoints.'],
        'refund_pending'=>['severity'=>'high','weight'=>85,
            'reason'=>'A refund request has not reached a confirmed provider outcome.'],
        'payment_pending_review'=>['severity'=>'high','weight'=>80,
            'reason'=>'Payment is pending beyond the review threshold. Reconcile with the provider.'],
        'fulfillment_pending'=>['severity'=>'medium','weight'=>50,
            'reason'=>'Paid physical merchandise is awaiting fulfillment review.'],
        'refund_completed'=>['severity'=>'low','weight'=>10,
            'reason'=>'Refund is recorded; check any remaining customer-care obligations.']
    ];
    $items=[];
    foreach(array_slice((array)($snapshot['attention_orders']??[]),0,60) as $order){
        if(!is_array($order))continue;
        $id=(string)($order['id']??'');
        if(!preg_match('/^SF-[A-Z0-9-]{1,96}$/D',$id)||str_starts_with($id,'SF-CERT-'))continue;
        $flags=array_values(array_intersect(array_keys($priorities),(array)($order['flags']??[])));
        if(!$flags)continue;
        usort($flags,static fn(string $a,string $b):int=>$priorities[$b]['weight']<=>$priorities[$a]['weight']);
        $primary=$flags[0];
        $items[]=[
            'order_id'=>$id,'severity'=>$priorities[$primary]['severity'],
            'score'=>$priorities[$primary]['weight'],
            'signal'=>$primary,
            'reason'=>$priorities[$primary]['reason'],
            'flags'=>$flags,'next_view'=>'commerce-ops',
            'action'=>'review_existing_order','requires_review'=>true
        ];
    }
    usort($items,static fn(array $a,array $b):int=>$b['score']<=>$a['score']
        ?:strcmp($a['order_id'],$b['order_id']));
    return [
        'available'=>true,'items'=>array_slice($items,0,max(1,min(10,$limit))),
        'count'=>count($items),'read_only'=>true,
        'message'=>'Suggested next steps are review links only; no refunds, emails or fulfillment changes are initiated.'
    ];
}
/** Parse a strictly bounded Admin request; no LLM output grants action authority. */
function sf_agent_commerce_reply_request(string $message):?array {
    if(!preg_match('/\b(?:draft|prepare|write)\b.{0,100}\b(?:reply|response)\b.{0,100}\b(?:order\s+)(SF-[A-Z0-9-]{1,96})\b/iu',$message,$m))
        return null;
    $id=strtoupper((string)$m[1]);
    $s=strtolower($message);
    $topic='general';
    if(preg_match('/\b(refund|return|cancellation)\b/',$s))$topic='refund';
    elseif(preg_match('/\b(shipping|delivery|tracking|shipment)\b/',$s))$topic='shipping';
    elseif(preg_match('/\b(payment|declined|charged|billing)\b/',$s))$topic='payment';
    elseif(preg_match('/\b(download|digital|library|access)\b/',$s))$topic='digital';
    return ['order_id'=>$id,'topic'=>$topic];
}
/** Unsent, template-only customer-service draft; no status claims or personal data. */
function sf_agent_commerce_reply_draft(array $order,string $topic):array {
    $id=(string)($order['id']??'');
    if(!sf_store_customer_order_visible($order)
        ||!preg_match('/^SF-[A-Z0-9-]{1,96}$/D',$id))
        throw new RuntimeException('Customer order not found.');
    $templates=[
        'shipping'=>'Thank you for contacting Stonefellow. We will review the shipping and tracking details for your order and share an update after they are confirmed.',
        'refund'=>'Thank you for contacting Stonefellow. We will review the refund or return request for your order. No refund is confirmed until the payment record has been verified.',
        'payment'=>'Thank you for contacting Stonefellow. We will check the payment status for your order. Please do not send card details or repeat a payment based only on this message.',
        'digital'=>'Thank you for contacting Stonefellow. We will review the digital access associated with your order and confirm the next steps.',
        'general'=>'Thank you for contacting Stonefellow. We will review your order and follow up once the details have been confirmed.'
    ];
    if(!array_key_exists($topic,$templates))throw new InvalidArgumentException('Unrecognized support reply topic.');
    return [
        'order_id'=>$id,'topic'=>$topic,
        'subject'=>'Stonefellow order '.$id.' — support follow-up',
        'text'=>"Hello,\n\n".$templates[$topic]."\n\nStonefellow Support",
        'state'=>'unsent_draft','delivery_enabled'=>false,
        'may_execute'=>false,'requires_human_review'=>true
    ];
}
