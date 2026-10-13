<?php
declare(strict_types=1);
/**
 * Section 47I: explicit feedback on one's own Agent replies.
 * Metadata only: never copies message content, provider payloads or secrets.
 * One row per scoped reply; does not train models or grant tool permissions.
 */
function sf_agent_feedback_ensure_schema(): void {
    $db=sf_db();$driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if($driver==='sqlite'){
        $db->exec("CREATE TABLE IF NOT EXISTS agent_response_feedback (
          id INTEGER PRIMARY KEY AUTOINCREMENT,scope TEXT NOT NULL,owner_user_id INTEGER NOT NULL,
          response_message_id INTEGER NOT NULL,rating TEXT NOT NULL,
          created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
          UNIQUE(scope,owner_user_id,response_message_id))");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_feedback_owner ON agent_response_feedback(scope,owner_user_id,updated_at)');
    }elseif($driver==='mysql'){
        $db->exec("CREATE TABLE IF NOT EXISTS agent_response_feedback (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,scope VARCHAR(16) NOT NULL,
          owner_user_id BIGINT UNSIGNED NOT NULL,response_message_id BIGINT UNSIGNED NOT NULL,
          rating VARCHAR(16) NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
          UNIQUE KEY uq_agent_response_feedback(scope,owner_user_id,response_message_id),
          INDEX idx_agent_feedback_owner(scope,owner_user_id,updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }else throw new RuntimeException('Unsupported Agent feedback database.');
}
function sf_agent_feedback_valid_scope(string $scope,int $ownerId): bool {
    return in_array($scope,['customer','admin'],true)&&$ownerId>0;
}
function sf_agent_feedback_save(string $scope,int $ownerId,int $messageId,string $rating): array {
    if(!sf_agent_feedback_valid_scope($scope,$ownerId)||$messageId<1 || !in_array($rating,['helpful','not_helpful'],true))
        throw new InvalidArgumentException('Invalid Agent response feedback.');
    $db=sf_db();
    $table=$scope==='admin'?'admin_agent_messages':'agent_messages';
    $q=$db->prepare('SELECT 1 FROM '.$table.' WHERE id=? AND '.($scope==='admin'?'admin_user_id':'user_id').'=? AND role=? LIMIT 1');
    $q->execute([$messageId,$ownerId,'assistant']);
    if(!$q->fetchColumn())throw new RuntimeException('Response not found in this Agent Brain.');
    sf_agent_feedback_ensure_schema();
    $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $now=gmdate('c');
    $sql=($driver==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE')
      .' INTO agent_response_feedback(scope,owner_user_id,response_message_id,rating,created_at,updated_at) VALUES(?,?,?,?,?,?)';
    $db->prepare($sql)->execute([$scope,$ownerId,$messageId,$rating,$now,$now]);
    $db->prepare('UPDATE agent_response_feedback SET rating=?,updated_at=? WHERE scope=? AND owner_user_id=? AND response_message_id=?')
       ->execute([$rating,$now,$scope,$ownerId,$messageId]);
    return ['response_message_id'=>$messageId,'rating'=>$rating,'saved'=>true];
}
function sf_agent_feedback_recent(string $scope,int $ownerId,int $limit=50): array {
    if(!sf_agent_feedback_valid_scope($scope,$ownerId))return [];
    sf_agent_feedback_ensure_schema();
    $q=sf_db()->prepare('SELECT response_message_id,rating,updated_at FROM agent_response_feedback WHERE scope=? AND owner_user_id=? ORDER BY updated_at DESC,id DESC LIMIT '.max(1,min(100,$limit)));
    $q->execute([$scope,$ownerId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sf_agent_feedback_summary(string $scope,int $ownerId): array {
    $data=sf_agent_feedback_recent($scope,$ownerId,100);
    $helpful=0;$notHelpful=0;
    foreach($data as $row){if(($row['rating']??'')==='helpful')$helpful++;elseif(($row['rating']??'')==='not_helpful')$notHelpful++;}
    return ['total'=>count($data),'helpful'=>$helpful,'not_helpful'=>$notHelpful,
        'guidance'=>$notHelpful>0?'Earlier replies were rated not helpful. Ask for clarification rather than inventing missing details.':''];
}
/** No schema DDL inside account closure's transaction; fail on real DB errors. */
function sf_agent_feedback_clear(string $scope,int $ownerId): int {
    if(!sf_agent_feedback_valid_scope($scope,$ownerId))return 0;
    try{
        $q=sf_db()->prepare('DELETE FROM agent_response_feedback WHERE scope=? AND owner_user_id=?');
        $q->execute([$scope,$ownerId]);return $q->rowCount();
    }catch(PDOException $e){
        $code=(string)$e->getCode();
        if($code==='42S02'||str_contains(strtolower($e->getMessage()),'no such table'))return 0;
        throw $e;
    }
}
/**
 * Read-only durable mission diagnostics. This never retries an uncertain
 * action or treats a plan as approval. No customer details in Admin scope.
 */
function sf_agent_mission_recovery_summary(string $scope,int $ownerId): array {
    if(!sf_agent_feedback_valid_scope($scope,$ownerId))return ['status'=>'unavailable','planned'=>0,'pending_approval'=>0,'pending_reads'=>0,'review_required'=>0];
    $plans=function_exists('sf_agent_mission_plan_recent')?sf_agent_mission_plan_recent($scope,$ownerId,25):[];
    $pendingApproval=0;$pendingReads=0;
    foreach($plans as $plan)foreach((array)($plan['steps']??[]) as $step){
        if(($step['status']??'')==='approval_required')$pendingApproval++;
        elseif(($step['status']??'')==='read_available')$pendingReads++;
    }
    $review=0;
    if($scope==='admin' && function_exists('sf_agent_action_recent')){
        foreach(sf_agent_action_recent($ownerId,30) as $action)
            if(in_array(($action['status']??''),['review_required','executing'],true))$review++;
    }
    return ['status'=>$review>0?'review_required':($pendingApproval>0?'awaiting_owner_approval':'read_only'),
        'planned'=>count($plans),'pending_approval'=>$pendingApproval,'pending_reads'=>$pendingReads,
        'review_required'=>$review,'automatic_retry'=>false];
}
