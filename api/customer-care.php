<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$u=$method==='POST'?sf_require_user(false,true):sf_require_user();sf_fulfillment_ensure_schema();
function sf_customer_order_owned(array $u,string $id): array {$o=sf_read_order($id);if(!$o)throw new InvalidArgumentException('Order not found.');$uid=(int)($o['user_id']??0);$email=strtolower((string)($o['customer']['email']??''));if($uid!==(int)$u['id']&&!($uid===0&&$email===strtolower((string)$u['email'])))throw new RuntimeException('Order not found.');return $o;}
if($method==='GET'){
  $orderId=sf_clean_text($_GET['order_id']??'',100);if($orderId==='')sf_json_response(['ok'=>true,'cases'=>sf_fulfillment_cases('',(int)$u['id'],100)]);
  try{$o=sf_customer_order_owned($u,$orderId);$snap=sf_fulfillment_order_snapshot($orderId);$snap['cases']=array_values(array_filter($snap['cases'],fn($c)=>(int)($c['user_id']??0)===(int)$u['id']));sf_json_response(['ok'=>true]+$snap);}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);}
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
  if($action==='open_case'){$case=sf_fulfillment_open_case($u,(array)($b['case']??[]),'fan');sf_log_user_activity((int)$u['id'],'support_case','Opened support case '.$case['case_key'],'support_case',(string)$case['id'],['order_id'=>$case['order_id'],'issue_type'=>$case['issue_type']]);sf_json_response(['ok'=>true,'case'=>$case]);}
  if($action==='reply_case'){$case=sf_fulfillment_case((int)($b['case_id']??0));if(!$case||(int)($case['user_id']??0)!==(int)$u['id'])throw new RuntimeException('Support case not found.');if(in_array((string)$case['status'],['resolved','closed'],true))throw new RuntimeException('This case is closed.');$case=sf_fulfillment_add_case_message((int)$case['id'],'fan',(int)$u['id'],(string)($b['message']??''),sf_clean_text($b['request_key']??'',190));sf_log_user_activity((int)$u['id'],'support_reply','Replied to support case '.$case['case_key'],'support_case',(string)$case['id'],['order_id'=>$case['order_id']]);sf_json_response(['ok'=>true,'case'=>$case]);}
  if($action==='close_case'){$case=sf_fulfillment_case((int)($b['case_id']??0));if(!$case||(int)($case['user_id']??0)!==(int)$u['id'])throw new RuntimeException('Support case not found.');$case=sf_fulfillment_update_case((int)$case['id'],['status'=>'closed','priority'=>$case['priority']],(int)$u['id']);sf_log_user_activity((int)$u['id'],'support_case_closed','Closed support case '.$case['case_key'],'support_case',(string)$case['id'],['order_id'=>$case['order_id']]);sf_json_response(['ok'=>true,'case'=>$case]);}
  sf_json_response(['ok'=>false,'message'=>'Unsupported support action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
