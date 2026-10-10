<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_fulfillment_ensure_schema();
if($method==='GET'){
  $orderId=sf_clean_text($_GET['order_id']??'',100);$caseId=max(0,(int)($_GET['case_id']??0));
  if($caseId){$case=sf_fulfillment_case($caseId);if(!$case)sf_json_response(['ok'=>false,'message'=>'Support case not found.'],404);sf_json_response(['ok'=>true,'case'=>$case,'csrf'=>sf_admin_csrf()]);}
  if($orderId!==''){try{$snap=sf_fulfillment_order_snapshot($orderId);sf_json_response(['ok'=>true]+$snap+['csrf'=>sf_admin_csrf()]);}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],404);}}
  sf_json_response(['ok'=>true,'summary'=>sf_fulfillment_summary(),'orders'=>sf_fulfillment_recent_orders(200),'cases'=>sf_fulfillment_cases('',null,200),'csrf'=>sf_admin_csrf()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
  if($action==='save_shipment'){
    $id=sf_clean_text($b['order_id']??'',100);$shipment=sf_fulfillment_save_shipment($id,(array)($b['shipment']??[]),(int)$me['id']);
    sf_log_admin_action((int)$me['id'],'shipment_saved','order',$id,['shipment_id'=>(int)$shipment['id'],'status'=>$shipment['status'],'carrier'=>$shipment['carrier'],'tracking_number'=>$shipment['tracking_number']]);
    if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_fulfillment','shipment_update',['route'=>'fulfillment_care','action'=>'save_shipment','context_profile'=>'commerce','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Update shipment for '.$id,'response'=>'Shipment '.$shipment['id'].' saved as '.$shipment['status'].'.']);
    sf_json_response(['ok'=>true,'shipment'=>$shipment,'snapshot'=>sf_fulfillment_order_snapshot($id),'csrf'=>sf_admin_csrf()]);
  }
  if($action==='request_refund'){
    $id=sf_clean_text($b['order_id']??'',100);$refund=sf_fulfillment_request_refund($id,(array)($b['refund']??[]),(int)$me['id']);sf_log_admin_action((int)$me['id'],'refund_requested','order',$id,['refund_id'=>(int)$refund['id'],'amount_cents'=>(int)$refund['amount_cents']]);sf_json_response(['ok'=>true,'refund'=>$refund,'snapshot'=>sf_fulfillment_order_snapshot($id),'csrf'=>sf_admin_csrf()]);
  }
  if($action==='confirm_refund'){
    if(empty($b['confirmed']))sf_json_response(['ok'=>false,'message'=>'Refund confirmation requires explicit Admin confirmation.'],422);
    $refund=sf_fulfillment_confirm_refund((int)($b['refund_id']??0),(int)$me['id'],(string)($b['provider_reference']??''),!empty($b['restock']));
    sf_log_admin_action((int)$me['id'],'refund_confirmed','order',(string)$refund['order_id'],['refund_id'=>(int)$refund['id'],'amount_cents'=>(int)$refund['amount_cents'],'restock_status'=>$refund['restock_status']]);
    if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_fulfillment','refund_confirm',['route'=>'fulfillment_care','action'=>'confirm_refund','context_profile'=>'commerce','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Confirm refund '.$refund['id'],'response'=>'Refund confirmed for '.sf_money((int)$refund['amount_cents']).'.']);
    sf_json_response(['ok'=>true,'refund'=>$refund,'snapshot'=>sf_fulfillment_order_snapshot((string)$refund['order_id']),'csrf'=>sf_admin_csrf()]);
  }
  if($action==='reject_refund'){
    $refund=sf_fulfillment_reject_refund((int)($b['refund_id']??0),(int)$me['id'],(string)($b['reason']??''));sf_log_admin_action((int)$me['id'],'refund_rejected','order',(string)$refund['order_id'],['refund_id'=>(int)$refund['id']]);sf_json_response(['ok'=>true,'refund'=>$refund,'snapshot'=>sf_fulfillment_order_snapshot((string)$refund['order_id']),'csrf'=>sf_admin_csrf()]);
  }
  if($action==='restock_return'){
    if(empty($b['confirmed']))sf_json_response(['ok'=>false,'message'=>'Return restock requires explicit Admin confirmation.'],422);
    $id=sf_clean_text($b['order_id']??'',100);$units=sf_fulfillment_restock_return($id,(int)$me['id'],sf_clean_text($b['reason']??'return_received',120));sf_log_admin_action((int)$me['id'],'return_restocked','order',$id,['units'=>$units]);sf_json_response(['ok'=>true,'units'=>$units,'snapshot'=>sf_fulfillment_order_snapshot($id),'csrf'=>sf_admin_csrf()]);
  }
  if($action==='case_reply'){
    $case=sf_fulfillment_add_case_message((int)($b['case_id']??0),'admin',(int)$me['id'],(string)($b['message']??''),sf_clean_text($b['request_key']??'',190));sf_log_admin_action((int)$me['id'],'support_reply','support_case',(string)$case['id'],['case_key'=>$case['case_key']]);sf_json_response(['ok'=>true,'case'=>$case,'csrf'=>sf_admin_csrf()]);
  }
  if($action==='case_update'){
    $case=sf_fulfillment_update_case((int)($b['case_id']??0),(array)($b['case']??[]),(int)$me['id']);sf_log_admin_action((int)$me['id'],'support_case_updated','support_case',(string)$case['id'],['case_key'=>$case['case_key'],'status'=>$case['status'],'priority'=>$case['priority']]);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_fulfillment','support_case',['route'=>'fulfillment_care','action'=>'update_support_case','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Update support case '.$case['case_key'],'response'=>'Case is '.$case['status'].' at '.$case['priority'].' priority.']);sf_json_response(['ok'=>true,'case'=>$case,'csrf'=>sf_admin_csrf()]);
  }
  sf_json_response(['ok'=>false,'message'=>'Unsupported fulfillment action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
