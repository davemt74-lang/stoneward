<?php
declare(strict_types=1);
require_once __DIR__.'/agent-scene-core.php';
require_once __DIR__.'/ai-token-reservations.php';

final class SfCanvasBuildWorkflowConflict extends RuntimeException {}

function sf_canvas_build_workflow_table_exists(string $table): bool {
    if(!in_array($table,['canvas_build_jobs','canvas_build_steps','agent_scene_history'],true))return false;
    $db=sf_db();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);}
    else{$q=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);}
    $found=$q->fetchColumn()!==false;$q->closeCursor();return $found;
}
function sf_canvas_build_workflow_ensure_schema(): void {
    static $ready=false;if($ready)return;$db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize canvas workflows before opening a transaction.');
    sf_agent_scene_history_ensure_schema();sf_agent_scene_request_ensure_schema();sf_canvas_project_ensure_schema();sf_privacy_ensure_schema();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec("CREATE TABLE IF NOT EXISTS canvas_build_jobs(user_id INTEGER NOT NULL,request_id TEXT NOT NULL,proposal_id INTEGER NULL,original_scene_id INTEGER NULL,candidate_scene_id INTEGER NULL,project_json TEXT NULL,history_epoch INTEGER NOT NULL,state TEXT NOT NULL,current_step_id TEXT NULL,review_id TEXT NULL,message TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(user_id,request_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $db->exec("CREATE TABLE IF NOT EXISTS canvas_build_steps(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,request_id TEXT NOT NULL,step_id TEXT NOT NULL,role TEXT NOT NULL,state TEXT NOT NULL,candidate_scene_id INTEGER NULL,result_scene_id INTEGER NULL,repair_request_id TEXT NULL,evidence_json TEXT NULL,report_json TEXT NULL,usage_json TEXT NULL,reservation_key TEXT NULL,active_tokens_used INTEGER NOT NULL DEFAULT 0,active_tokens_remaining INTEGER NULL,fingerprint TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,UNIQUE(user_id,request_id,step_id),FOREIGN KEY(user_id,request_id) REFERENCES canvas_build_jobs(user_id,request_id) ON DELETE CASCADE)");
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS canvas_build_jobs(user_id BIGINT UNSIGNED NOT NULL,request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,proposal_id BIGINT UNSIGNED NULL,original_scene_id BIGINT UNSIGNED NULL,candidate_scene_id BIGINT UNSIGNED NULL,project_json MEDIUMTEXT NULL,history_epoch BIGINT UNSIGNED NOT NULL,state VARCHAR(24) NOT NULL,current_step_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,review_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,message TEXT NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,PRIMARY KEY(user_id,request_id),CONSTRAINT fk_canvas_build_jobs_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS canvas_build_steps(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,step_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,role VARCHAR(12) NOT NULL,state VARCHAR(16) NOT NULL,candidate_scene_id BIGINT UNSIGNED NULL,result_scene_id BIGINT UNSIGNED NULL,repair_request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,evidence_json MEDIUMTEXT NULL,report_json MEDIUMTEXT NULL,usage_json TEXT NULL,reservation_key CHAR(32) NULL,active_tokens_used BIGINT UNSIGNED NOT NULL DEFAULT 0,active_tokens_remaining BIGINT NULL,fingerprint CHAR(64) NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,UNIQUE KEY idx_canvas_build_step(user_id,request_id,step_id),CONSTRAINT fk_canvas_build_steps_job FOREIGN KEY(user_id,request_id) REFERENCES canvas_build_jobs(user_id,request_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready=true;
}
function sf_canvas_build_workflow_json(mixed $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function sf_canvas_build_workflow_row(int $uid,string $requestId,bool $lock=false): ?array {
    $db=sf_db();$q=$db->prepare('SELECT * FROM canvas_build_jobs WHERE user_id=? AND request_id=?'.($lock&&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$uid,$requestId]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}
function sf_canvas_build_workflow_step(int $uid,string $requestId,string $stepId): ?array {
    $q=sf_db()->prepare('SELECT * FROM canvas_build_steps WHERE user_id=? AND request_id=? AND step_id=?');$q->execute([$uid,$requestId,$stepId]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}
function sf_canvas_build_workflow_usage(array $usage): array {
    $result=[];
    foreach(['provider','model'] as $field)if(is_string($usage[$field]??null))$result[$field]=substr($usage[$field],0,160);
    foreach(['input_tokens','output_tokens'] as $field)if(is_int($usage[$field]??null)&&$usage[$field]>=0)$result[$field]=$usage[$field];
    return $result;
}
function sf_canvas_build_workflow_public(array $row): array {
    $uid=(int)$row['user_id'];$requestId=$row['request_id'];$q=sf_db()->prepare('SELECT * FROM canvas_build_steps WHERE user_id=? AND request_id=? ORDER BY id ASC');$q->execute([$uid,$requestId]);$steps=[];$total=0;$remaining=null;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $step){
        $used=(int)$step['active_tokens_used'];$total+=$used;
        if($step['state']==='completed'&&$step['active_tokens_remaining']!==null)$remaining=(int)$step['active_tokens_remaining'];
        $item=['id'=>$step['step_id'],'step_id'=>$step['step_id'],'role'=>$step['role'],'state'=>$step['state'],'candidate_id'=>$step['candidate_scene_id']===null?null:(int)$step['candidate_scene_id'],
            'scene_id'=>$step['result_scene_id']===null?null:(int)$step['result_scene_id'],'request_id'=>$step['repair_request_id'],
            'active_tokens_used'=>$used,'active_tokens_remaining'=>$step['active_tokens_remaining']===null?null:(int)$step['active_tokens_remaining'],
            'created_at'=>$step['created_at'],'updated_at'=>$step['updated_at']];
        if($step['evidence_json']!==null)$item['evidence']=json_decode($step['evidence_json'],true,16,JSON_THROW_ON_ERROR);
        if($step['report_json']!==null)$item['report']=json_decode($step['report_json'],true,16,JSON_THROW_ON_ERROR);
        if($step['usage_json']!==null)$item['usage']=json_decode($step['usage_json'],true,8,JSON_THROW_ON_ERROR);
        $steps[]=$item;
    }$q->closeCursor();
    return ['request_id'=>$requestId,'proposal_id'=>$row['proposal_id']===null?null:(int)$row['proposal_id'],
        'original_scene_id'=>$row['original_scene_id']===null?null:(int)$row['original_scene_id'],
        'candidate_id'=>$row['candidate_scene_id']===null?null:(int)$row['candidate_scene_id'],
        'candidate_scene_id'=>$row['candidate_scene_id']===null?null:(int)$row['candidate_scene_id'],
        'state'=>$row['state'],'current_step_id'=>$row['current_step_id'],'review_id'=>$row['review_id'],'message'=>$row['message'],
        'history_epoch'=>(int)$row['history_epoch'],'project'=>$row['project_json']===null?null:sf_canvas_project_normalize(json_decode($row['project_json'],true,12,JSON_THROW_ON_ERROR),false),
        'steps'=>$steps,'total_active_tokens_used'=>$total,'active_tokens_remaining'=>$remaining,'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']];
}
function sf_canvas_build_workflow_get(int $uid,string $requestId): ?array {
    sf_agent_scene_request_id($requestId);if($uid<1)return null;sf_canvas_build_workflow_ensure_schema();$row=sf_canvas_build_workflow_row($uid,$requestId);return $row?sf_canvas_build_workflow_public($row):null;
}
/** Read saved role logs without initializing storage or making a saved candidate executable. */
function sf_canvas_build_workflow_for_scene(int $uid,int $sceneId): ?array {
    if($uid<1||$sceneId<1||!sf_canvas_build_workflow_table_exists('canvas_build_jobs')||!sf_canvas_build_workflow_table_exists('canvas_build_steps'))return null;
    $q=sf_db()->prepare('SELECT j.* FROM canvas_build_jobs j WHERE j.user_id=?
        AND EXISTS(SELECT 1 FROM agent_scene_history h WHERE h.user_id=j.user_id AND h.id=?)
        AND (j.original_scene_id=? OR j.candidate_scene_id=? OR EXISTS(
            SELECT 1 FROM canvas_build_steps s WHERE s.user_id=j.user_id AND s.request_id=j.request_id AND (s.candidate_scene_id=? OR s.result_scene_id=?)))
        ORDER BY j.updated_at DESC,j.created_at DESC,j.request_id DESC LIMIT 1');
    $q->execute([$uid,$sceneId,$sceneId,$sceneId,$sceneId,$sceneId]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    return $row?sf_canvas_build_workflow_public($row):null;
}
/** Only host-linked, code-identical derivatives can reuse an ancestor's verification. No writes or inference. */
function sf_canvas_build_workflow_verified_derivative(int $uid,array $scene): ?array {
    $id=$scene['id']??null;
    if($uid<1||!is_int($id)||$id<1||!sf_canvas_build_workflow_table_exists('agent_scene_history'))return null;
    $db=sf_db();$sqlite=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $columns=$db->query($sqlite?'PRAGMA table_info(agent_scene_history)':'SHOW COLUMNS FROM agent_scene_history')->fetchAll(PDO::FETCH_COLUMN,$sqlite?1:0);
    foreach(['verification_parent_id','workflow_required','previous_id','html','css','js'] as $column)if(!in_array($column,$columns,true))return null;
    $q=$db->prepare('SELECT id,previous_id,verification_parent_id,workflow_required,html,css,js FROM agent_scene_history WHERE user_id=? AND id=?');
    $read=static function(int $sceneId)use($q,$uid):?array {$q->execute([$uid,$sceneId]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;};
    $normalize=static fn(string $code):string=>preg_replace('/sf-image-(?:ref-)?[a-f0-9]{32}(?![A-Za-z0-9_-])/','sf-image-__verified_asset__',$code);
    $current=$read($id);$visited=[];
    while($current!==null){
        $currentId=(int)$current['id'];if(isset($visited[$currentId]))return null;$visited[$currentId]=true;
        $parentId=(int)($current['verification_parent_id']??0);
        if($parentId<1||(int)($current['previous_id']??0)!==$parentId||isset($visited[$parentId]))return null;
        $parent=$read($parentId);if($parent===null||($current['js']??'')!==($parent['js']??'')||
            $normalize($current['html'])!==$normalize($parent['html'])||$normalize($current['css'])!==$normalize($parent['css']))return null;
        $workflow=sf_canvas_build_workflow_for_scene($uid,$parentId);
        if($workflow!==null&&$workflow['candidate_id']===$parentId&&in_array($workflow['state'],['ready','applied'],true))
            return ['verified'=>true,'source_scene_id'=>$parentId,'request_id'=>$workflow['request_id']];
        if($workflow!==null)return null;
        if($workflow===null&&(int)$parent['workflow_required']!==1)return ['verified'=>true,'source_scene_id'=>$parentId];
        $current=$parent;
    }
    return null;
}
function sf_canvas_build_workflow_account(PDO $db,int $uid): void {
    sf_agent_scene_history_lock_account($db,$uid);$q=$db->prepare("SELECT id FROM users WHERE id=? AND status='active'");$q->execute([$uid]);$active=$q->fetchColumn()!==false;$q->closeCursor();if(!$active)throw new SfAgentSceneBuildCanceled();
}
/** Only a saved result from the account's approved main project can seed this workflow. */
function sf_canvas_build_workflow_start(array $user,string $requestId,int $sceneId): array {
    $uid=(int)($user['id']??0);sf_agent_scene_request_id($requestId);if($uid<1||$sceneId<1)throw new InvalidArgumentException('Choose an owned approved canvas build.');sf_canvas_build_workflow_ensure_schema();$db=sf_db();$db->beginTransaction();
    try{
        sf_canvas_build_workflow_account($db,$uid);$epoch=sf_agent_scene_history_epoch($uid);
        $q=$db->prepare("SELECT p.id,p.project_json,p.scene_id,r.history_epoch,r.active_tokens_used,r.active_tokens_remaining FROM canvas_projects p JOIN agent_scene_requests r ON r.user_id=p.user_id AND r.request_id=p.request_id WHERE p.user_id=? AND p.request_id=? AND p.status='built' AND p.scene_id=? AND r.state='ready' AND r.scene_id=p.scene_id");$q->execute([$uid,$requestId,$sceneId]);$approved=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        $candidate=sf_agent_scene_history_get($uid,$sceneId);
        if(!$approved||!$candidate||$candidate['canvas_kind']!=='custom'||(int)$approved['history_epoch']!==$epoch)throw new InvalidArgumentException('This workflow requires an owned approved custom canvas result.');
        $project=sf_canvas_project_normalize(json_decode($approved['project_json'],true,12,JSON_THROW_ON_ERROR),false);
        $existing=sf_canvas_build_workflow_row($uid,$requestId,true);$now=gmdate('c');
        if($existing!==null){
            if((int)$existing['history_epoch']!==$epoch||($existing['original_scene_id']!==null&&(int)$existing['original_scene_id']!==$sceneId))throw new SfCanvasBuildWorkflowConflict('This workflow belongs to a different candidate.');
            if($existing['original_scene_id']===null)$db->prepare('UPDATE canvas_build_jobs SET proposal_id=?,original_scene_id=?,candidate_scene_id=?,project_json=?,updated_at=? WHERE user_id=? AND request_id=?')->execute([(int)$approved['id'],$sceneId,$sceneId,sf_canvas_build_workflow_json($project),$now,$uid,$requestId]);
        }else $db->prepare("INSERT INTO canvas_build_jobs(user_id,request_id,proposal_id,original_scene_id,candidate_scene_id,project_json,history_epoch,state,message,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'testing','',?,?)")->execute([$uid,$requestId,(int)$approved['id'],$sceneId,$sceneId,sf_canvas_build_workflow_json($project),$epoch,$now,$now]);
        if(sf_canvas_build_workflow_step($uid,$requestId,$requestId)===null)$db->prepare("INSERT INTO canvas_build_steps(user_id,request_id,step_id,role,state,candidate_scene_id,result_scene_id,active_tokens_used,active_tokens_remaining,fingerprint,created_at,updated_at) VALUES(?,?,?,'coding','completed',?,?,?,?,?,?,?)")->execute([$uid,$requestId,$requestId,$sceneId,$sceneId,max(0,(int)$approved['active_tokens_used']),$approved['active_tokens_remaining'],hash('sha256','original:'.$sceneId),$now,$now]);
        $db->commit();return sf_canvas_build_workflow_get($uid,$requestId);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
/** Poll/settlement guard: no state or private content is reconstructed after Stop or privacy clearing. */
function sf_canvas_build_workflow_assert_active(int $uid,string $requestId,string $stepId,?int $expectedEpoch=null,?int $expectedCandidateId=null): void {
    if(!sf_canvas_build_workflow_table_exists('canvas_build_jobs'))throw new SfAgentSceneBuildCanceled();
    $row=sf_canvas_build_workflow_row($uid,$requestId);$step=$row?sf_canvas_build_workflow_step($uid,$requestId,$stepId):null;
    if(!$row||!$step||$row['current_step_id']!==$stepId||$step['state']!=='running'||!in_array($row['state'],['reviewing','repairing'],true)||
        (int)$step['candidate_scene_id']!==(int)$row['candidate_scene_id']||(int)$row['history_epoch']!==sf_agent_scene_history_epoch($uid)||
        ($expectedEpoch!==null&&(int)$row['history_epoch']!==$expectedEpoch)||($expectedCandidateId!==null&&(int)$row['candidate_scene_id']!==$expectedCandidateId))throw new SfAgentSceneBuildCanceled();
    $q=sf_db()->prepare("SELECT id FROM users WHERE id=? AND status='active'");$q->execute([$uid]);$active=$q->fetchColumn()!==false;$q->closeCursor();if(!$active)throw new SfAgentSceneBuildCanceled();
    $q=sf_db()->prepare('SELECT 1 FROM agent_scene_history WHERE user_id=? AND id=?');$q->execute([$uid,(int)$row['candidate_scene_id']]);$available=$q->fetchColumn()!==false;$q->closeCursor();if(!$available)throw new SfAgentSceneBuildCanceled();
}
function sf_canvas_build_workflow_claim(array $user,string $requestId,string $stepId,string $role,array $payload): array {
    $uid=(int)($user['id']??0);sf_agent_scene_request_id($requestId);sf_agent_scene_request_id($stepId);sf_canvas_build_workflow_ensure_schema();
    if($uid<1||!in_array($role,['testing','repair'],true))throw new InvalidArgumentException('Choose a canvas workflow step.');
    $fingerprint=hash('sha256',sf_canvas_build_workflow_json([$role,$payload]));$db=sf_db();$db->beginTransaction();
    try{
        sf_canvas_build_workflow_account($db,$uid);$job=sf_canvas_build_workflow_row($uid,$requestId,true);
        if(!$job||!$job['project_json']||(int)$job['history_epoch']!==sf_agent_scene_history_epoch($uid))throw new SfAgentSceneBuildCanceled();
        $existing=sf_canvas_build_workflow_step($uid,$requestId,$stepId);
        if($existing){if($existing['role']!==$role||!hash_equals($existing['fingerprint'],$fingerprint))throw new SfCanvasBuildWorkflowConflict('This step ID belongs to different observations.');$db->commit();return ['claimed'=>false,'workflow'=>sf_canvas_build_workflow_get($uid,$requestId)];}
        $allowed=$role==='testing'?['testing','failed']:['needs_repair','failed'];
        if(!in_array($job['state'],$allowed,true))throw new SfCanvasBuildWorkflowConflict('This workflow is not ready for that agent step.');
        if($role==='testing'&&(!is_int($payload['candidate_scene_id']??null)||$payload['candidate_scene_id']!==(int)$job['candidate_scene_id']))throw new SfCanvasBuildWorkflowConflict('These browser observations belong to a different candidate.');
        $candidate=sf_agent_scene_history_get($uid,(int)$job['candidate_scene_id']);
        if(!$candidate){
            $db->prepare("UPDATE canvas_build_jobs SET state='needs_attention',current_step_id=NULL,message=?,updated_at=? WHERE user_id=? AND request_id=?")->execute(['The saved candidate is no longer available.',gmdate('c'),$uid,$requestId]);
            $db->commit();return ['claimed'=>false,'workflow'=>sf_canvas_build_workflow_get($uid,$requestId)];
        }
        $report=null;$evidence=null;
        if($role==='repair'){
            $reviewId=$payload['review_id']??'';if($job['review_id']!==$reviewId)throw new SfCanvasBuildWorkflowConflict('Use the current Testing report.');
            $review=sf_canvas_build_workflow_step($uid,$requestId,$reviewId);
            if(!$review||$review['role']!=='testing'||$review['state']!=='completed'||(int)$review['candidate_scene_id']!==(int)$job['candidate_scene_id'])throw new SfCanvasBuildWorkflowConflict('That report does not describe this candidate.');
            $report=json_decode($review['report_json'],true,16,JSON_THROW_ON_ERROR);$evidence=json_decode($review['evidence_json'],true,16,JSON_THROW_ON_ERROR);
            if(($report['verdict']??null)!=='repair')throw new SfCanvasBuildWorkflowConflict('This candidate does not have an approved repair assessment.');
        }
        $repairRequest=$role==='repair'?bin2hex(random_bytes(16)):null;$now=gmdate('c');
        $db->prepare("INSERT INTO canvas_build_steps(user_id,request_id,step_id,role,state,candidate_scene_id,repair_request_id,evidence_json,fingerprint,created_at,updated_at) VALUES(?,?,?,?,'running',?,?,?,?,?,?)")->execute([$uid,$requestId,$stepId,$role,(int)$job['candidate_scene_id'],$repairRequest,$role==='testing'?sf_canvas_build_workflow_json($payload['evidence']):null,$fingerprint,$now,$now]);
        $db->prepare('UPDATE canvas_build_jobs SET state=?,current_step_id=?,message=?,updated_at=? WHERE user_id=? AND request_id=?')->execute([$role==='testing'?'reviewing':'repairing',$stepId,'',$now,$uid,$requestId]);
        $db->commit();$public=sf_canvas_build_workflow_get($uid,$requestId);$public['step_id']=$stepId;if($evidence!==null)$public['evidence']=$evidence;
        return ['claimed'=>true,'workflow'=>$public,'candidate'=>$candidate,'report'=>$report,'repair_request_id'=>$repairRequest];
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function sf_canvas_build_workflow_register_reservation(int $uid,string $requestId,string $stepId,?string $key,?int $expectedEpoch=null,?int $expectedCandidateId=null): void {
    if($key!==null&&preg_match('/\A[a-f0-9]{32}\z/',$key)!==1)throw new InvalidArgumentException('Use a valid workflow reservation.');
    $db=sf_db();$own=!$db->inTransaction();if($own)$db->beginTransaction();
    try{sf_canvas_build_workflow_account($db,$uid);sf_canvas_build_workflow_assert_active($uid,$requestId,$stepId,$expectedEpoch,$expectedCandidateId);
        $db->prepare("UPDATE canvas_build_steps SET reservation_key=?,updated_at=? WHERE user_id=? AND request_id=? AND step_id=? AND state='running'")->execute([$key,gmdate('c'),$uid,$requestId,$stepId]);if($own)$db->commit();
    }catch(Throwable $error){if($own&&$db->inTransaction())$db->rollBack();throw $error;}
}
function sf_canvas_build_workflow_record_review(int $uid,string $requestId,string $stepId,array $report,array $usage,int $charged,?int $balance,?int $expectedEpoch=null,?int $expectedCandidateId=null): void {
    if(!sf_db()->inTransaction())throw new LogicException('Save Testing results in the token settlement transaction.');sf_canvas_build_workflow_assert_active($uid,$requestId,$stepId,$expectedEpoch,$expectedCandidateId);
    $step=sf_canvas_build_workflow_step($uid,$requestId,$stepId);if($step['role']!=='testing'||!in_array($report['verdict']??null,['pass','repair','manual'],true))throw new InvalidArgumentException('Use the current structured Testing assessment.');
    $now=gmdate('c');sf_db()->prepare("UPDATE canvas_build_steps SET state='completed',report_json=?,usage_json=?,active_tokens_used=?,active_tokens_remaining=?,updated_at=? WHERE user_id=? AND request_id=? AND step_id=? AND state='running'")->execute([sf_canvas_build_workflow_json($report),sf_canvas_build_workflow_json(sf_canvas_build_workflow_usage($usage)),max(0,$charged),$balance,$now,$uid,$requestId,$stepId]);
    $state=match($report['verdict']){'pass'=>'ready','repair'=>'needs_repair',default=>'needs_attention'};
    sf_db()->prepare('UPDATE canvas_build_jobs SET state=?,current_step_id=NULL,review_id=?,message=?,updated_at=? WHERE user_id=? AND request_id=?')->execute([$state,$stepId,$report['summary']??'',$now,$uid,$requestId]);
}
function sf_canvas_build_workflow_record_repair(int $uid,string $requestId,string $stepId,array $scene,array $usage,int $charged,?int $balance,?int $expectedEpoch=null,?int $expectedCandidateId=null): void {
    if(!sf_db()->inTransaction())throw new LogicException('Save repairs in the existing scene settlement transaction.');sf_canvas_build_workflow_assert_active($uid,$requestId,$stepId,$expectedEpoch,$expectedCandidateId);
    $step=sf_canvas_build_workflow_step($uid,$requestId,$stepId);if($step['role']!=='repair'||!is_int($scene['id']??null)||sf_agent_scene_history_get($uid,$scene['id'])===null)throw new InvalidArgumentException('Use an owned Repair result.');
    $old=sf_agent_scene_history_get($uid,(int)$step['candidate_scene_id']);if($old===null)throw new SfAgentSceneBuildCanceled();$changed=[];
    foreach(['html','css','js'] as $field)if(($old[$field]??'')!==($scene[$field]??''))$changed[]=$field;
    foreach(['agent_design','agent_palette'] as $field)if(($old[$field]??null)!==($scene[$field]??null))$changed[]=$field;
    $same=$old!==null&&$changed===[];$labels=['html'=>'HTML','css'=>'CSS','js'=>'JavaScript','agent_design'=>'agent layout','agent_palette'=>'agent colors'];
    $summary=$same?'The repair did not change the candidate code or presentation.':'Changed '.implode(', ',array_map(static fn(string $field):string=>$labels[$field],$changed)).'; retest pending.';
    $now=gmdate('c');$report=['no_progress'=>$same,'changed_fields'=>$changed,'summary'=>$summary];
    sf_db()->prepare("UPDATE canvas_build_steps SET state='completed',result_scene_id=?,report_json=?,usage_json=?,active_tokens_used=?,active_tokens_remaining=?,updated_at=? WHERE user_id=? AND request_id=? AND step_id=? AND state='running'")->execute([$scene['id'],sf_canvas_build_workflow_json($report),sf_canvas_build_workflow_json(sf_canvas_build_workflow_usage($usage)),max(0,$charged),$balance,$now,$uid,$requestId,$stepId]);
    sf_db()->prepare('UPDATE canvas_build_jobs SET state=?,candidate_scene_id=?,current_step_id=NULL,review_id=NULL,message=?,updated_at=? WHERE user_id=? AND request_id=?')->execute([$same?'needs_attention':'testing',$scene['id'],$report['summary'],$now,$uid,$requestId]);
}
function sf_canvas_build_workflow_fail(int $uid,string $requestId,string $stepId,bool $canceled=false,?int $expectedEpoch=null,?int $expectedCandidateId=null): void {
    if(!sf_canvas_build_workflow_table_exists('canvas_build_jobs'))return;$db=sf_db();$db->beginTransaction();
    try{sf_canvas_build_workflow_account($db,$uid);$job=sf_canvas_build_workflow_row($uid,$requestId,true);
        if($job&&$job['current_step_id']===$stepId&&in_array($job['state'],['reviewing','repairing'],true)&&(int)$job['history_epoch']===sf_agent_scene_history_epoch($uid)&&
            ($expectedEpoch===null||(int)$job['history_epoch']===$expectedEpoch)&&($expectedCandidateId===null||(int)$job['candidate_scene_id']===$expectedCandidateId)){
            $now=gmdate('c');$db->prepare("UPDATE canvas_build_steps SET state=?,updated_at=? WHERE user_id=? AND request_id=? AND step_id=? AND state='running'")->execute([$canceled?'canceled':'failed',$now,$uid,$requestId,$stepId]);
            $db->prepare('UPDATE canvas_build_jobs SET state=?,current_step_id=NULL,message=?,updated_at=? WHERE user_id=? AND request_id=?')->execute([$canceled?'canceled':'failed',$canceled?'The canvas workflow was stopped.':'This agent step did not finish. The previous canvas remains available.',$now,$uid,$requestId]);
        }$db->commit();
    }catch(SfAgentSceneBuildCanceled){if($db->inTransaction())$db->rollBack();}catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function sf_canvas_build_workflow_review(array $user,string $requestId,string $stepId,int $candidateId,array $evidence,?callable $infer=null): array {
    require_once __DIR__.'/canvas-build-agents.php';$evidence=sf_canvas_build_evidence($evidence);
    if(strlen(sf_canvas_build_workflow_json($evidence))>65536)throw new InvalidArgumentException('Keep browser observations within the supported limit.');
    $claim=sf_canvas_build_workflow_claim($user,$requestId,$stepId,'testing',['candidate_scene_id'=>$candidateId,'evidence'=>$evidence]);if(!$claim['claimed'])return $claim['workflow'];
    try{sf_canvas_build_review($user,$claim['workflow'],$claim['candidate'],$evidence,$stepId,$infer);return sf_canvas_build_workflow_get((int)$user['id'],$requestId);}
    catch(Throwable $error){sf_canvas_build_workflow_fail((int)$user['id'],$requestId,$stepId,$error instanceof SfAgentSceneBuildCanceled,$claim['workflow']['history_epoch'],$claim['candidate']['id']);throw $error;}
}
function sf_canvas_build_workflow_repair(array $user,string $requestId,string $stepId,string $reviewId,?callable $onDelta=null,?callable $onRetry=null,?callable $repairWorker=null): array {
    require_once __DIR__.'/canvas-build-agents.php';sf_agent_scene_request_id($reviewId);
    $claim=sf_canvas_build_workflow_claim($user,$requestId,$stepId,'repair',['review_id'=>$reviewId]);if(!$claim['claimed'])return $claim['workflow'];
    try{
        if($repairWorker!==null)$repairWorker($user,$claim['workflow'],$claim['candidate'],$claim['report'],$claim['repair_request_id'],$onDelta,$onRetry);
        else sf_canvas_build_repair($user,$claim['workflow'],$claim['candidate'],$claim['report'],$claim['repair_request_id'],$onDelta,$onRetry);
        return sf_canvas_build_workflow_get((int)$user['id'],$requestId);
    }catch(Throwable $error){sf_canvas_build_workflow_fail((int)$user['id'],$requestId,$stepId,$error instanceof SfAgentSceneBuildCanceled,$claim['workflow']['history_epoch'],$claim['candidate']['id']);throw $error;}
}
/** Stop is addressable by the already known original request, including the interval before start. */
function sf_canvas_build_workflow_cancel(array $user,string $requestId): array {
    $uid=(int)($user['id']??0);sf_agent_scene_request_id($requestId);if($uid<1)throw new InvalidArgumentException('Sign in to stop this canvas workflow.');sf_canvas_build_workflow_ensure_schema();$db=sf_db();$db->beginTransaction();$repairId=null;
    try{
        sf_canvas_build_workflow_account($db,$uid);$job=sf_canvas_build_workflow_row($uid,$requestId,true);$epoch=sf_agent_scene_history_epoch($uid);$now=gmdate('c');
        if(!$job){
            $q=$db->prepare('SELECT history_epoch FROM agent_scene_requests WHERE user_id=? AND request_id=?');$q->execute([$uid,$requestId]);$owned=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$owned||(int)$owned['history_epoch']!==$epoch){$db->commit();return ['request_id'=>$requestId,'state'=>'canceled','candidate_id'=>null,'steps'=>[],'total_active_tokens_used'=>0,'message'=>'The canvas workflow was stopped.'];}
            $db->prepare("INSERT INTO canvas_build_jobs(user_id,request_id,history_epoch,state,message,created_at,updated_at) VALUES(?,?,?,'canceled',?,?,?)")->execute([$uid,$requestId,$epoch,'The canvas workflow was stopped.',$now,$now]);
        }elseif($job['state']!=='applied'){
            if($job['current_step_id'])$repairId=sf_canvas_build_workflow_step($uid,$requestId,$job['current_step_id'])['repair_request_id']??null;
            $db->prepare("UPDATE canvas_build_steps SET state='canceled',updated_at=? WHERE user_id=? AND request_id=? AND state='running'")->execute([$now,$uid,$requestId]);
            $db->prepare("UPDATE canvas_build_jobs SET state='canceled',current_step_id=NULL,message=?,updated_at=? WHERE user_id=? AND request_id=?")->execute(['The canvas workflow was stopped.',$now,$uid,$requestId]);
        }$db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    if(is_string($repairId))sf_agent_scene_request_cancel($uid,$repairId);
    $current=sf_canvas_build_workflow_get($uid,$requestId);
    if($current!==null&&$current['original_scene_id']===null){
        $q=$db->prepare("SELECT p.scene_id FROM canvas_projects p JOIN agent_scene_requests r ON r.user_id=p.user_id AND r.request_id=p.request_id WHERE p.user_id=? AND p.request_id=? AND p.status='built' AND r.state='ready' AND r.scene_id=p.scene_id");$q->execute([$uid,$requestId]);$finished=$q->fetchColumn();$q->closeCursor();
        if($finished!==false){try{return sf_canvas_build_workflow_start($user,$requestId,(int)$finished);}catch(InvalidArgumentException|SfAgentSceneBuildCanceled){}}
    }
    return $current??['request_id'=>$requestId,'state'=>'canceled','candidate_id'=>null,'steps'=>[],'total_active_tokens_used'=>0];
}
function sf_canvas_build_workflow_apply(array $user,string $requestId,int $sceneId): array {
    $uid=(int)($user['id']??0);sf_agent_scene_request_id($requestId);sf_canvas_build_workflow_ensure_schema();$db=sf_db();$db->beginTransaction();
    try{sf_canvas_build_workflow_account($db,$uid);$job=sf_canvas_build_workflow_row($uid,$requestId,true);
        if(!$job||(int)$job['history_epoch']!==sf_agent_scene_history_epoch($uid)||!in_array($job['state'],['ready','applied'],true)||(int)$job['candidate_scene_id']!==$sceneId||sf_agent_scene_history_get($uid,$sceneId)===null)throw new SfCanvasBuildWorkflowConflict('Only the current tested candidate can be marked applied.');
        $db->prepare("UPDATE canvas_build_jobs SET state='applied',updated_at=? WHERE user_id=? AND request_id=?")->execute([gmdate('c'),$uid,$requestId]);$db->commit();return sf_canvas_build_workflow_get($uid,$requestId);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function sf_canvas_build_workflow_scene(array $user,array $workflow,string $currentTrackId=''): ?array {
    $id=$workflow['candidate_id']??null;if(!is_int($id)||$id<1)return null;$scene=sf_agent_scene_saved((int)$user['id'],$id);if(!$scene)return null;
    $images=sf_agent_canvas_image_assets((int)$user['id'],$scene);if($images)$scene['image_assets']=$images;
    $data=sf_agent_canvas_data_for_scene($user,$scene['html'],$currentTrackId,$scene['music_inputs']??null,$scene['artist_inputs']??null,['scene_id'=>$id]);
    $scene['canvas_access']=sf_canvas_access_summary($user,['scene_id'=>$id]);
    if($data){$scene['datasets']=$data;foreach($data as $dataset)if(array_key_exists('current_rows',$dataset)){$scene['dataset_track_id']=$currentTrackId;break;}}
    return $scene;
}
/** Privacy operations never create the optional workflow tables. */
function sf_canvas_build_workflow_export(int $uid): array {
    if($uid<1||!sf_canvas_build_workflow_table_exists('canvas_build_jobs'))return [];$q=sf_db()->prepare('SELECT * FROM canvas_build_jobs WHERE user_id=? ORDER BY created_at,request_id');$q->execute([$uid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();return array_map('sf_canvas_build_workflow_public',$rows);
}
function sf_canvas_build_workflow_clear(int $uid): array {
    $counts=['canvas_build_jobs'=>0,'canvas_build_steps'=>0];
    foreach(['canvas_build_steps','canvas_build_jobs'] as $table)if(sf_canvas_build_workflow_table_exists($table)){$q=sf_db()->prepare('DELETE FROM '.$table.' WHERE user_id=?');$q->execute([$uid]);$counts[$table]=$q->rowCount();}
    return $counts;
}
