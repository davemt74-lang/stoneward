<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';$me=sf_admin_require_auth($write);sf_agent_brain_ensure_schema();$pdo=sf_db();
if(!$write){
    $limit=max(1,min(250,(int)($_GET['limit']??100)));$userId=max(0,(int)($_GET['user_id']??0));$phase=sf_clean_text($_GET['phase']??'',32);$search=sf_clean_text($_GET['q']??'',120);
    $where=[];$args=[];
    if($userId){$where[]='b.user_id=?';$args[]=$userId;}
    if($phase!==''){$where[]='b.phase=?';$args[]=$phase;}
    if($search!==''){$where[]='(b.route LIKE ? OR b.action LIKE ? OR b.request_excerpt LIKE ? OR b.response_excerpt LIKE ? OR u.email LIKE ?)';$like='%'.$search.'%';array_push($args,$like,$like,$like,$like,$like);}
    $sql='SELECT b.*,u.email AS user_email,u.display_name AS user_name FROM agent_brain_decisions b LEFT JOIN users u ON u.id=b.user_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY b.id DESC LIMIT '.$limit;
    $q=$pdo->prepare($sql);$q->execute($args);$rows=$q->fetchAll();
    $stats=['total'=>(int)$pdo->query('SELECT COUNT(*) FROM agent_brain_decisions')->fetchColumn(),'jev_routes'=>(int)$pdo->query("SELECT COUNT(*) FROM agent_brain_decisions WHERE phase='route' AND provider_status NOT IN ('local_fallback','error')")->fetchColumn(),'llm_responses'=>(int)$pdo->query("SELECT COUNT(*) FROM agent_brain_decisions WHERE phase='response'")->fetchColumn(),'withheld'=>(int)$pdo->query("SELECT COUNT(*) FROM agent_brain_decisions WHERE phase='response_eval' AND details_json LIKE '%withhold%'")->fetchColumn()];
    sf_json_response(['ok'=>true,'decisions'=>$rows,'stats'=>$stats,'filters'=>['user_id'=>$userId,'phase'=>$phase,'q'=>$search]]);
}
$body=sf_request_json();if(($body['action']??'')==='clear'){$pdo->exec('DELETE FROM agent_brain_decisions');sf_log_admin_action((int)$me['id'],'agent_brain_cleared');sf_json_response(['ok'=>true]);}
sf_json_response(['ok'=>false,'message'=>'Unsupported Agent Brain action.'],422);
