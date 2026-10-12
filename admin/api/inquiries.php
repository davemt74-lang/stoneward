<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_inquiry_ensure_schema();
if($method==='GET'){
  $id=max(0,(int)($_GET['id']??0));if($id){$r=sf_inquiry_get($id);if(!$r)sf_json_response(['ok'=>false,'message'=>'Inquiry not found.'],404);sf_json_response(['ok'=>true,'inquiry'=>$r,'summary'=>sf_inquiry_summary(),'csrf'=>sf_admin_csrf()]);}
  $status=sf_clean_text($_GET['status']??'',32);$type=sf_clean_text($_GET['type']??'',40);$q=sf_clean_text($_GET['q']??'',120);sf_json_response(['ok'=>true,'inquiries'=>sf_inquiry_list($status,$type,$q,400),'summary'=>sf_inquiry_summary(),'types'=>array_map(fn($x)=>['value'=>$x,'label'=>sf_inquiry_type_label($x)],sf_inquiry_types()),'csrf'=>sf_admin_csrf()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
  if($action==='transition'){
    if(empty($b['confirmed']))throw new RuntimeException('Changing professional inquiry status requires explicit Admin confirmation.');$id=(int)($b['id']??0);$to=(string)($b['status']??'');$before=sf_inquiry_get($id);$r=sf_inquiry_transition($id,$to,(int)$me['id']);sf_log_admin_action((int)$me['id'],'professional_inquiry_status','professional_inquiry',(string)$id,['from'=>$before['status']??'','to'=>$r['status']??'']);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_inquiry','professional_inquiry',['route'=>'professional_inquiries','action'=>'change_status','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Change '.$r['reference'].' status','response'=>'Inquiry status changed to '.$r['status'].'.']);sf_json_response(['ok'=>true,'inquiry'=>$r,'summary'=>sf_inquiry_summary()]);
  }
  if($action==='save_notes'){
    $id=(int)($b['id']??0);$r=sf_inquiry_save_notes($id,(string)($b['notes']??''),(int)$me['id']);sf_log_admin_action((int)$me['id'],'professional_inquiry_notes','professional_inquiry',(string)$id,['length'=>strlen((string)($r['internal_notes']??''))]);sf_json_response(['ok'=>true,'inquiry'=>$r]);
  }
  if($action==='reply'){
    if(empty($b['confirmed']))throw new RuntimeException('Sending a professional inquiry reply requires explicit Admin confirmation.');$id=(int)($b['id']??0);$r=sf_inquiry_reply($id,(string)($b['subject']??''),(string)($b['body']??''),(int)$me['id']);$inq=$r['inquiry']??[];$delivery=$r['delivery']??[];$messages=(array)($inq['messages']??[]);$lastMessage=$messages?end($messages):[];sf_log_admin_action((int)$me['id'],'professional_inquiry_reply','professional_inquiry',(string)$id,['delivery_status'=>$delivery['status']??'failed','message_status'=>$lastMessage['status']??'']);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_inquiry','professional_inquiry_reply',['route'=>'professional_inquiries','action'=>'reply','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Reply to '.($inq['reference']??('inquiry '.$id)),'response'=>'Transactional reply result: '.($delivery['status']??'failed').'.']);sf_json_response(['ok'=>true]+$r);
  }
  sf_json_response(['ok'=>false,'message'=>'Unsupported professional inquiry action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
