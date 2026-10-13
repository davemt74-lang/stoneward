<?php
declare(strict_types=1);
/**
 * Section 47C: bounded, owner-isolated, restart-safe mission plans.
 * Aggregate read stages may be completed from trusted Admin facts already
 * fetched; proposed writes NEVER execute here and retain exact owner review.
 */
require_once __DIR__.'/agent-orchestration.php';

function sf_agent_mission_plan(string $scope,string $message,string $primaryIntent=''): ?array {
    if(!in_array($scope,['customer','admin'],true))return null;
    $message=trim($message);
    if($message===''||strlen($message)>4000)return null;
    $intents=[];
    if($scope==='admin'){
        if($primaryIntent==='prepare_campaign')$intents=['crm_report','journey_report','prepare_campaign'];
        elseif($primaryIntent==='prepare_journey')$intents=['crm_report','journey_report','prepare_journey'];
        elseif(preg_match('/\\b(crm|fan engagement|campaign performance|journey performance)\\b/i',$message)
            && preg_match('/\\b(compare|analy[sz]e|research|investigate|review)\\b/i',$message))
            $intents=['crm_report','journey_report'];
    } else {
        if(preg_match('/\\bplaylist(s)?\\b/i',$message))$intents=['public_catalog_search','player_recommend','create_playlist'];
        elseif(preg_match('/\\bfavou?rite(s)?\\b/i',$message))$intents=['player_recommend','favorite_save'];
        elseif(preg_match('/\\b(recommend|discover)\\b/i',$message))$intents=['public_catalog_search','player_recommend'];
    }
    if(count($intents)<2||count($intents)>4)return null;
    $steps=[];
    foreach($intents as $i=>$intent){
        $contract=sf_agent_orchestration_contract($scope,$intent);
        if(!$contract['registered'] || !$contract['skill_id'] || $contract['may_execute_directly'])return null;
        if($contract['requires_exact_approval'] && $scope==='admin' && !$contract['may_prepare_reviewed_proposal'])return null;
        $steps[]=[
            'order'=>$i+1,
            'intent'=>$intent,
            'skill_id'=>$contract['skill_id'],
            'tool_id'=>$contract['tool_id'],
            'status'=>$contract['requires_exact_approval']?'approval_required':'read_available',
            'requires_exact_approval'=>$contract['requires_exact_approval'],
            'may_execute_directly'=>false
        ];
    }
    return ['schema'=>'stonefellow.mission-plan.v1','scope'=>$scope,'status'=>'planned_review_required',
        'steps'=>$steps,'step_limit'=>4,'writes_executed'=>0];
}

/** Read-only reconciliation against already authorized Admin aggregate context. */
function sf_agent_mission_apply_admin_facts(array $plan,array $facts): array {
    if(($plan['scope']??'')!=='admin')return $plan;
    foreach($plan['steps']??[] as &$step){
        if(($step['status']??'')!=='read_available')continue;
        if($step['intent']==='crm_report' && isset($facts['crm_contacts'],$facts['newsletter_opted_in'])){
            $step['status']='read_completed';
            $step['evidence']=['source'=>'admin_aggregate','crm_contacts'=>max(0,(int)$facts['crm_contacts']),
                'newsletter_opted_in'=>max(0,(int)$facts['newsletter_opted_in'])];
        } elseif($step['intent']==='journey_report' && isset($facts['journey_counts']) && is_array($facts['journey_counts'])){
            $step['status']='read_completed';
            $step['evidence']=['source'=>'admin_aggregate','journey_counts'=>array_map('intval',array_intersect_key($facts['journey_counts'],array_flip(['journeys_started','journeys_completed','journeys_failed'])))];
        }
    }
    unset($step);
    return $plan;
}

function sf_agent_mission_ensure_schema(): void {
    if(!function_exists('sf_db'))throw new RuntimeException('Agent database unavailable.');
    $db=sf_db();
    $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if($driver==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS agent_mission_plans (
            mission_id TEXT PRIMARY KEY,scope TEXT NOT NULL,owner_user_id INTEGER NOT NULL,
            conversation_id TEXT NOT NULL,plan_json TEXT NOT NULL,created_at TEXT NOT NULL)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_mission_owner ON agent_mission_plans(scope,owner_user_id,created_at)');
    }elseif($driver==='mysql'){
        $db->exec('CREATE TABLE IF NOT EXISTS agent_mission_plans (
            mission_id CHAR(64) PRIMARY KEY,scope VARCHAR(16) NOT NULL,owner_user_id BIGINT UNSIGNED NOT NULL,
            conversation_id VARCHAR(80) NOT NULL,plan_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,
            INDEX idx_agent_mission_owner(scope,owner_user_id,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }else throw new RuntimeException('Unsupported Agent database.');
}

function sf_agent_mission_id(string $scope,int $ownerId,string $conversationId,string $message): string {
    return hash('sha256',implode('|',[$scope,(string)$ownerId,$conversationId,hash('sha256',trim($message))]));
}

function sf_agent_mission_plan_load(string $scope,int $ownerId,string $missionId): ?array {
    if(!in_array($scope,['customer','admin'],true)||$ownerId<1||!preg_match('/^[a-f0-9]{64}$/D',$missionId))return null;
    sf_agent_mission_ensure_schema();
    $q=sf_db()->prepare('SELECT mission_id,plan_json,created_at FROM agent_mission_plans WHERE mission_id=? AND scope=? AND owner_user_id=?');
    $q->execute([$missionId,$scope,$ownerId]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)return null;
    $plan=json_decode((string)$r['plan_json'],true);
    if(!is_array($plan))throw new RuntimeException('Mission record requires review.');
    return ['id'=>(string)$r['mission_id'],'created_at'=>(string)$r['created_at']]+$plan;
}

function sf_agent_mission_plan_save(string $scope,int $ownerId,string $conversationId,string $message,array $plan): array {
    if(!in_array($scope,['customer','admin'],true)||$ownerId<1
        ||trim($message)===''||strlen($message)>4000
        ||!preg_match('/^[a-zA-Z0-9_-]{12,80}$/D',$conversationId)
        ||($plan['scope']??null)!==$scope
        ||($plan['writes_executed']??null)!==0
        ||count($plan['steps']??[])<2||count($plan['steps']??[])>4)
        throw new InvalidArgumentException('Invalid governed mission.');
    foreach($plan['steps'] as $index=>$step){
        $contract=sf_agent_orchestration_contract($scope,(string)($step['intent']??''));
        if(!$contract['registered'] || !$contract['skill_id']
            || $contract['may_execute_directly'] || $contract['tool_id']!==($step['tool_id']??null)
            || $contract['skill_id']!==($step['skill_id']??null)
            || (int)($step['order']??0)!==$index+1
            || !in_array($step['status']??'',['read_available','read_completed','approval_required'],true)
            || ($contract['requires_exact_approval'] && ($step['status']??'')!=='approval_required')
            || (($step['status']??'')==='read_completed' && !($scope==='admin'
                && in_array($step['intent'],['crm_report','journey_report'],true)
                && ($step['evidence']['source']??'')==='admin_aggregate')))
            throw new InvalidArgumentException('Mission tool or approval mismatch.');
    }
    sf_agent_mission_ensure_schema();
    $id=sf_agent_mission_id($scope,$ownerId,$conversationId,$message);
    $existing=sf_agent_mission_plan_load($scope,$ownerId,$id);
    if($existing!==null)return $existing;
    $json=json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))throw new RuntimeException('Could not encode mission.');
    $db=sf_db();$driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql=($driver==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE')
       .' INTO agent_mission_plans(mission_id,scope,owner_user_id,conversation_id,plan_json,created_at) VALUES(?,?,?,?,?,?)';
    $db->prepare($sql)->execute([$id,$scope,$ownerId,$conversationId,$json,gmdate('c')]);
    $stored=sf_agent_mission_plan_load($scope,$ownerId,$id);
    if(!$stored)throw new RuntimeException('Mission was not persisted.');
    return $stored;
}

function sf_agent_mission_plan_recent(string $scope,int $ownerId,int $limit=10): array {
    if(!in_array($scope,['customer','admin'],true)||$ownerId<1)return [];
    sf_agent_mission_ensure_schema();
    $q=sf_db()->prepare('SELECT mission_id FROM agent_mission_plans WHERE scope=? AND owner_user_id=? ORDER BY created_at DESC,mission_id DESC LIMIT '.max(1,min(25,$limit)));
    $q->execute([$scope,$ownerId]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        $item=sf_agent_mission_plan_load($scope,$ownerId,(string)$id);
        if($item!==null)$out[]=$item;
    }
    return $out;
}

/** Complete privacy export; never mix scopes or owners. */
function sf_agent_mission_plan_export(string $scope,int $ownerId): array {
    if(!in_array($scope,['customer','admin'],true)||$ownerId<1)return [];
    sf_agent_mission_ensure_schema();
    $q=sf_db()->prepare('SELECT mission_id FROM agent_mission_plans WHERE scope=? AND owner_user_id=? ORDER BY created_at ASC,mission_id ASC');
    $q->execute([$scope,$ownerId]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        $item=sf_agent_mission_plan_load($scope,$ownerId,(string)$id);
        if($item!==null)$out[]=$item;
    }
    return $out;
}

/** Never create a MySQL table/implicit-commit while closing an account. */
function sf_agent_mission_plan_clear(string $scope,int $ownerId): int {
    if(!in_array($scope,['customer','admin'],true)||$ownerId<1)return 0;
    try{
        $q=sf_db()->prepare('DELETE FROM agent_mission_plans WHERE scope=? AND owner_user_id=?');
        $q->execute([$scope,$ownerId]);return $q->rowCount();
    }catch(PDOException $e){
        $missing=in_array((string)$e->getCode(),['42S02'],true)
            || str_contains(strtolower($e->getMessage()),'no such table');
        if($missing)return 0;
        throw $e;
    }
}
