<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';sf_ticketing_ensure_schema();$method=$_SERVER['REQUEST_METHOD']??'GET';$user=sf_current_user();
if($method==='GET'){$showId=sf_clean_text($_GET['show_id']??'',160);sf_json_response(['ok'=>true,'offers'=>sf_ticket_public_offers($user?(int)$user['id']:null,$showId),'reservations'=>$user?sf_ticket_reservations_for_user((int)$user['id']):[],'membership'=>$user&&function_exists('sf_membership_state')?sf_membership_state((int)$user['id'],false):null]);}
$u=sf_require_user(false,true);$b=sf_request_json();$action=(string)($b['action']??'');
try{
 if($action==='reserve'){$r=sf_ticket_reserve((int)($b['offer_id']??0),(int)$u['id'],(int)($b['quantity']??1),sf_clean_text($b['request_id']??'',190),sf_clean_text($b['source']??'direct',80));sf_json_response(['ok'=>true,'reservation'=>$r,'reservations'=>sf_ticket_reservations_for_user((int)$u['id'])]);}
 if($action==='cancel'){$r=sf_ticket_cancel((int)($b['reservation_id']??0),(int)$u['id'],false);sf_json_response(['ok'=>true,'reservation'=>$r,'reservations'=>sf_ticket_reservations_for_user((int)$u['id'])]);}
 sf_json_response(['ok'=>false,'message'=>'Unsupported ticket action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
