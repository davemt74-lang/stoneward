<?php
declare(strict_types=1);
/**
 * Section 81 — bind staff production approvals to the immutable purchased
 * vinyl artwork and provider handoff, not to editable browser form fields.
 * All data is already server-owned and under protected storage.
 */
function sf_vinyl_production_proof(array $order,string $storageRoot):array {
    $id=(string)($order['id']??'');
    if(!preg_match('/^SF-[A-Z0-9-]{1,96}$/D',$id)||str_starts_with($id,'SF-CERT-'))
        throw new InvalidArgumentException('Valid customer order required.');
    $blockers=[];$review=[];$fingerprints=[];
    foreach((array)($order['quote']['items']??[]) as $i=>$item){
        if(!is_array($item)||($item['type']??'')!=='custom_media'
            ||($item['format']??'')!=='vinyl')continue;
        $builder=(array)($item['builder']??[]);
        $b=[
          'title'=>(string)($builder['title']??''),
          'artwork_front'=>(string)($builder['coverFront']??''),
          'artwork_back'=>(string)($builder['coverBack']??''),
          'dedication_note'=>(string)($builder['customNote']??''),
          'sides'=>(array)($builder['sides']??[])
        ];
        if($b['title']===''||$b['artwork_front']===''||$b['artwork_back']===''
            ||!isset($b['sides']['A'],$b['sides']['B'])
            ||!is_array($b['sides']['A'])||!is_array($b['sides']['B'])){
            $blockers[]='Order '.$id.' has an incomplete purchased vinyl design for item '.($i+1).'.';
            continue;
        }
        $path=rtrim($storageRoot,'/').'/pod/'.$id.'-'.$i.'.json';
        $handoff=null;
        if(is_file($path)&&!is_link($path)){
            $raw=file_get_contents($path);
            if($raw!==false&&strlen($raw)<=1048576){
                $decoded=json_decode($raw,true);
                if(is_array($decoded))$handoff=$decoded;
            }
        }
        if($handoff===null){
            $blockers[]='Production handoff missing for vinyl item '.($i+1).'; reconcile existing POD record.';
            continue;
        }
        $fields=['title','artwork_front','artwork_back','dedication_note','sides'];
        $matches=($handoff['schema']??'')==='stonefellow.pod-handoff.v1'
            &&($handoff['order_id']??'')===$id
            &&(int)($handoff['item_index']??-1)===(int)$i
            &&($handoff['format']??'')==='vinyl'
            &&($handoff['customer_player_preview']['visual_only']??null)===true;
        foreach($fields as $f)if(($handoff[$f]??null)!==$b[$f])$matches=false;
        if(!$matches){
            $blockers[]='POD handoff does not match the purchased vinyl design for item '.($i+1).';
            continue;
        }
        $fingerprints[]=['item_index'=>$i]+$b;
        $review[]=['item_index'=>$i,'title'=>$b['title'],
            'front'=>$b['artwork_front'],'back'=>$b['artwork_back'],
            'side_a_tracks'=>count((array)($b['sides']['A']['tracks']??[])),
            'side_b_tracks'=>count((array)($b['sides']['B']['tracks']??[]))];
    }
    if(!$review)$blockers[]='No matched custom vinyl handoff is ready for artwork approval.';
    $orderStatus=(string)($order['status']??'');
    $payment=(array)($order['payment']??[]);
    $pay=(string)($payment['status']??'');
    $paid=$pay==='paid'||$pay==='test_paid'||$pay==='simulated_paid'||$pay==='succeeded'
        ||str_starts_with($orderStatus,'paid_')||in_array($orderStatus,['paid','completed','fulfilled'],true);
    if(($payment['provider']??'')==='stripe'&&($pay!=='paid'
       ||empty($payment['postpayment_done']['pod'])))$paid=false;
    if(!$paid||in_array($orderStatus,['refunded','partially_refunded','canceled','cancelled'],true)
       ||in_array($pay,['refunded','partially_refunded','failed','canceled'],true))
        $blockers[]='Only confirmed paid, nonrefunded orders with completed POD handoffs may advance production.';
    return [
      'ready'=>!$blockers,'blockers'=>$blockers,
      'items'=>$review,
      'fingerprint'=>!$blockers?hash('sha256',json_encode(
        ['order_id'=>$id,'items'=>$fingerprints],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)):'',
      'needs_human_review'=>true,'provider_handoff'=>'file_handoff',
      'production_external_confirmation'=>false
    ];
}
/** A revision and explicit human acknowledgement must precede every write. */
function sf_vinyl_production_approval(
    array $record,array $proof,array $request,string $next
):array {
    if(empty($proof['ready'])||empty($proof['fingerprint']))
        throw new RuntimeException('Production proof is not ready.');
    if(empty($request['confirmed']))
        throw new RuntimeException('Explicit production approval required.');
    if($next==='artwork_approved'){
        $supplied=(string)($request['proof_sha256']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$supplied)
            ||!hash_equals((string)$proof['fingerprint'],$supplied))
            throw new RuntimeException('Artwork proof changed; inspect the current order and handoff again.');
        return ['proof_sha256'=>$supplied,'proof_reviewed_at'=>gmdate('c')];
    }
    $approved=(string)($record['proof_sha256']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$approved)
        ||!hash_equals($approved,(string)$proof['fingerprint']))
        throw new RuntimeException('Approved artwork proof is missing or changed. Reconcile before advancing.');
    if($next==='production_complete'){
        $note=trim((string)($request['note']??''));
        if(empty($request['qc_confirmed'])||mb_strlen($note)<10)
            throw new RuntimeException('QC completion requires a confirmed inspection and a note of at least 10 characters.');
    }
    return ['proof_sha256'=>$approved];
}
