<?php
declare(strict_types=1);
require_once __DIR__.'/agent-canvas-photo-core.php';
require_once __DIR__.'/media-image-analysis.php';
require_once __DIR__.'/ai-token-reservations.php';

final class SfAgentCanvasImageAnalysisConflict extends RuntimeException {}

function sf_agent_canvas_image_analysis_schema(): void {
    static $ready=false;if($ready)return;sf_agent_canvas_photo_ensure_schema();$db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize private analysis before a transaction.');
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_image_analyses (
            user_id INTEGER NOT NULL,analysis_id TEXT NOT NULL,image_id TEXT NOT NULL,source_sha256 TEXT NOT NULL,
            state TEXT NOT NULL,revision INTEGER NOT NULL DEFAULT 1,analysis_json TEXT NULL,suggestions_json TEXT NOT NULL,
            provider TEXT NOT NULL,model TEXT NOT NULL,input_tokens INTEGER NOT NULL DEFAULT 0,output_tokens INTEGER NOT NULL DEFAULT 0,
            active_tokens_used INTEGER NOT NULL DEFAULT 0,active_tokens_remaining INTEGER NULL,suggest_profile INTEGER NOT NULL DEFAULT 0,reservation_key TEXT NULL,
            created_at TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(user_id,analysis_id),
            FOREIGN KEY(user_id,image_id) REFERENCES agent_canvas_images(user_id,id) ON DELETE CASCADE)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_private_image_analysis ON agent_canvas_image_analyses(user_id,image_id,created_at)');
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_image_analysis_versions (
            user_id INTEGER NOT NULL,analysis_id TEXT NOT NULL,revision INTEGER NOT NULL,analysis_json TEXT NOT NULL,
            state TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(user_id,analysis_id,revision),
            FOREIGN KEY(user_id,analysis_id) REFERENCES agent_canvas_image_analyses(user_id,analysis_id) ON DELETE CASCADE)');
    }else{
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_image_analyses (
            user_id BIGINT UNSIGNED NOT NULL,analysis_id CHAR(32) NOT NULL,image_id CHAR(32) NOT NULL,source_sha256 CHAR(64) NOT NULL,
            state VARCHAR(24) NOT NULL,revision INT UNSIGNED NOT NULL DEFAULT 1,analysis_json MEDIUMTEXT NULL,suggestions_json TEXT NOT NULL,
            provider VARCHAR(40) NOT NULL,model VARCHAR(160) NOT NULL,input_tokens INT UNSIGNED NOT NULL DEFAULT 0,output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            active_tokens_used INT UNSIGNED NOT NULL DEFAULT 0,active_tokens_remaining BIGINT NULL,suggest_profile TINYINT(1) NOT NULL DEFAULT 0,reservation_key CHAR(32) NULL,
            created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,PRIMARY KEY(user_id,analysis_id),
            INDEX idx_private_image_analysis(user_id,image_id,created_at),CONSTRAINT fk_private_image_analysis FOREIGN KEY(user_id,image_id)
            REFERENCES agent_canvas_images(user_id,id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_image_analysis_versions (
            user_id BIGINT UNSIGNED NOT NULL,analysis_id CHAR(32) NOT NULL,revision INT UNSIGNED NOT NULL,
            analysis_json MEDIUMTEXT NOT NULL,state VARCHAR(24) NOT NULL,created_at VARCHAR(40) NOT NULL,
            PRIMARY KEY(user_id,analysis_id,revision),CONSTRAINT fk_private_image_analysis_version FOREIGN KEY(user_id,analysis_id)
            REFERENCES agent_canvas_image_analyses(user_id,analysis_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    $ready=true;
}

function sf_agent_canvas_image_analysis_file(int $owner,string $id): array {
    if(!sf_agent_canvas_image_id($id)||!($file=sf_agent_canvas_image_file($owner,$id))||$file['size']>SF_AGENT_CANVAS_PHOTO_MAX_BYTES)
        throw new InvalidArgumentException('Choose an available image no larger than 10 MB from your library.');
    $hash=@hash_file('sha256',$file['path']);
    if(!is_string($hash))throw new InvalidArgumentException('The image could not be read.');
    return $file+['sha256'=>$hash];
}

/** Provider resize rules bound the refundable hold; settlement records reported usage only. */
function sf_agent_canvas_image_analysis_reserve(array $file,array $provider): int {
    $dimensions=@getimagesize($file['path']);$width=(int)($dimensions[0]??4096);$height=(int)($dimensions[1]??4096);
    if(($provider['provider']??'')==='anthropic')return min(1000000,max(16000,(int)ceil($width*$height/750)+8192));
    $scale=min(1,2048/max($width,$height));$width=max(1,(int)floor($width*$scale));$height=max(1,(int)floor($height*$scale));
    if(min($width,$height)>768){$scale=768/min($width,$height);$width=max(1,(int)floor($width*$scale));$height=max(1,(int)floor($height*$scale));}
    // The 4o-mini base/tile budget also conservatively covers other supported OpenAI vision models.
    return max(16000,2833+(int)(ceil($width/512)*ceil($height/512))*5667+8192);
}

function sf_agent_canvas_image_analysis_lock(PDO $db,int $owner): void {
    $db->prepare('UPDATE users SET status=status WHERE id=? AND status=\'active\'')->execute([$owner]);
    $q=$db->prepare('SELECT status FROM users WHERE id=?');$q->execute([$owner]);
    if($q->fetchColumn()!=='active')throw new InvalidArgumentException('Account unavailable.');
}

function sf_agent_canvas_image_analysis_row(int $owner,string $analysisId): ?array {
    $q=sf_db()->prepare('SELECT * FROM agent_canvas_image_analyses WHERE user_id=? AND analysis_id=?');$q->execute([$owner,$analysisId]);
    return $q->fetch()?:null;
}

/** Concurrent reset/recovery callers may observe the same hold; only its first settlement changes the balance. */
function sf_agent_canvas_image_analysis_release(array $user,?string $key): void {
    if($key===null||$key==='')return;
    $q=sf_db()->prepare('SELECT status FROM ai_token_reservations WHERE request_key=? AND user_id=? AND source=\'image-analysis\'');
    $q->execute([$key,(int)$user['id']]);
    if($q->fetchColumn()!=='held')return;
    try{sf_ai_finish_reservation($user,$key,0,[],'image-analysis',false);}
    catch(Throwable $error){
        $q->execute([$key,(int)$user['id']]);
        if(!in_array($q->fetchColumn(),['released','settled'],true))throw $error;
    }
}

function sf_agent_canvas_image_analysis_result(int $owner,string $id,?array $row=null): array {
    $image=sf_agent_canvas_image_get($owner,$id);
    if(!$image)throw new InvalidArgumentException('The image is unavailable.');
    $source=sf_agent_canvas_photo_metadata([$image],$owner)[$id]??['source_kind'=>'generated'];
    $analysis=null;$state=$row['state']??null;
    if($row){
        try{$current=sf_agent_canvas_image_analysis_file($owner,$id);}
        catch(InvalidArgumentException){$current=null;}
        if(!$current||!hash_equals($row['source_sha256'],$current['sha256']))$state='stale';
        elseif(in_array($state,['draft','approved'],true)&&is_string($row['analysis_json'])){
            $analysis=sf_image_analysis_validate(json_decode($row['analysis_json'],true,32,JSON_THROW_ON_ERROR));
        }
    }
    $suggestions=[];
    if($state!=='stale'&&$row&&$row['suggestions_json']!=='[]'){
        require_once __DIR__.'/image-profile.php';
        $suggestions=array_values(array_filter(sf_image_profile_list($owner,true),static fn(array $suggestion):bool=>$suggestion['source_id']===$id&&$suggestion['status']!=='removed'));
    }
    return ['image'=>$image+$source,'image_analysis'=>$analysis,'analysis_id'=>$row['analysis_id']??null,
        'analysis_status'=>$state,'revision'=>(int)($row['revision']??0),'source_sha256'=>$row['source_sha256']??null,
        'provider'=>$row['provider']??null,'model'=>$row['model']??null,'active_tokens_used'=>(int)($row['active_tokens_used']??0),
        'active_tokens_remaining'=>isset($row['active_tokens_remaining'])?(int)$row['active_tokens_remaining']:null,
        'profile_suggestions'=>$suggestions];
}

function sf_agent_canvas_image_analysis_get(int $owner,string $id): array {
    sf_agent_canvas_image_analysis_schema();sf_agent_canvas_image_analysis_file($owner,$id);
    $q=sf_db()->prepare('SELECT * FROM agent_canvas_image_analyses WHERE user_id=? AND image_id=? ORDER BY created_at DESC,analysis_id DESC LIMIT 1');
    $q->execute([$owner,$id]);return sf_agent_canvas_image_analysis_result($owner,$id,$q->fetch()?:null);
}

/** Actual provider usage is charged once, atomically with its private draft. A replay never starts inference. */
function sf_agent_canvas_image_analyze(array $user,string $id,string $requestId,bool $suggestProfile=false,?callable $transport=null,?array $configuredProvider=null,?string $expectedSha256=null): array {
    $owner=(int)($user['id']??0);
    if($owner<1||!sf_agent_canvas_image_id($requestId))throw new InvalidArgumentException('Use a valid image analysis request ID.');
    sf_agent_canvas_image_analysis_schema();$file=sf_agent_canvas_image_analysis_file($owner,$id);
    if($expectedSha256!==null&&!hash_equals($expectedSha256,$file['sha256']))
        throw new InvalidArgumentException('The image changed after this analysis was queued.');
    $uploaded=sf_agent_canvas_photo_source($owner,$id);$suggestProfile=$suggestProfile&&$uploaded!==null;
    if($row=sf_agent_canvas_image_analysis_row($owner,$requestId)){
        if($row['image_id']!==$id||!hash_equals($row['source_sha256'],$file['sha256'])||(bool)$row['suggest_profile']!==$suggestProfile)
            throw new SfAgentCanvasImageAnalysisConflict('This request belongs to a different image, source version, or profile choice.');
        return sf_agent_canvas_image_analysis_result($owner,$id,$row);
    }
    $epoch=null;
    if($suggestProfile){require_once __DIR__.'/image-profile.php';$epoch=sf_image_profile_epoch($owner);}
    $provider=$configuredProvider??sf_image_analysis_provider();$db=sf_db();
    $db->beginTransaction();
    try{
        sf_agent_canvas_image_analysis_lock($db,$owner);
        $row=sf_agent_canvas_image_analysis_row($owner,$requestId);
        if($row){
            if($row['image_id']!==$id||!hash_equals($row['source_sha256'],$file['sha256'])||(bool)$row['suggest_profile']!==$suggestProfile)
                throw new SfAgentCanvasImageAnalysisConflict('This request belongs to a different image, source version, or profile choice.');
            $db->commit();return sf_agent_canvas_image_analysis_result($owner,$id,$row);
        }
        $q=$db->prepare('SELECT * FROM agent_canvas_image_analyses WHERE user_id=? AND image_id=? AND source_sha256=? AND state=\'processing\' LIMIT 1');
        $q->execute([$owner,$id,$file['sha256']]);
        if($row=$q->fetch())throw new SfAgentCanvasImageAnalysisConflict('Another analysis is already processing this image. Reload its status before starting a new request.');
        $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $db->prepare('INSERT INTO agent_canvas_image_analyses(user_id,analysis_id,image_id,source_sha256,state,suggestions_json,
            provider,model,suggest_profile,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$owner,$requestId,$id,$file['sha256'],'processing','[]',$provider['provider']??'',$provider['model']??'',(int)$suggestProfile,$now,$now]);
        $db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    $reservation=null;$providerStarted=false;$settled=false;
    try{
        $reserve=sf_agent_canvas_image_analysis_reserve($file,$provider);
        $reservation=sf_ai_reserve_tokens($user,$reserve,'image-analysis');
        $q=$db->prepare('UPDATE agent_canvas_image_analyses SET reservation_key=?,updated_at=? WHERE user_id=? AND analysis_id=? AND state=\'processing\'');
        $q->execute([$reservation,gmdate('c'),$owner,$requestId]);
        if($q->rowCount()!==1)throw new SfAgentCanvasImageAnalysisConflict('This analysis was reset before inference started.');
        $providerStarted=true;$result=sf_image_analysis_infer($file,$provider,$transport,$suggestProfile);
        $analysis=sf_image_analysis_validate($result['analysis']??null);
        $usage=['provider'=>$result['provider'],'model'=>$result['model'],'input_tokens'=>max(0,(int)$result['input_tokens']),
            'output_tokens'=>max(0,(int)$result['output_tokens'])];
        $amount=max(1,$usage['input_tokens']+$usage['output_tokens']);
        if($amount>$reserve)throw new RuntimeException('Image analysis usage exceeded its reserved bound.');
        $json=json_encode($analysis,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $admin=($user['role']??'')==='admin';
        sf_ai_finish_reservation($user,$reservation,$amount,$usage,'image-analysis',true,
            static function(int $balance)use($db,$owner,$requestId,$file,$usage,$json,$amount,$admin):void {
                sf_agent_canvas_image_analysis_lock($db,$owner);
                $row=sf_agent_canvas_image_analysis_row($owner,$requestId);
                if(!$row||!hash_equals($file['sha256'],sf_agent_canvas_image_analysis_file($owner,$row['image_id'])['sha256']))
                    throw new SfAgentCanvasImageAnalysisConflict('The image source changed during analysis.');
                $now=gmdate('c');
                $q=$db->prepare('UPDATE agent_canvas_image_analyses SET state=\'draft\',analysis_json=?,provider=?,model=?,input_tokens=?,
                    output_tokens=?,active_tokens_used=?,active_tokens_remaining=?,updated_at=? WHERE user_id=? AND analysis_id=? AND state=\'processing\' AND source_sha256=?');
                $q->execute([$json,$usage['provider'],$usage['model'],$usage['input_tokens'],$usage['output_tokens'],$admin?0:$amount,$admin?null:$balance,$now,$owner,$requestId,$file['sha256']]);
                if($q->rowCount()!==1)throw new SfAgentCanvasImageAnalysisConflict('This private analysis was cleared while it was running.');
                $db->prepare('INSERT INTO agent_canvas_image_analysis_versions(user_id,analysis_id,revision,analysis_json,state,created_at) VALUES(?,?,1,?,?,?)')
                    ->execute([$owner,$requestId,$json,'draft',$now]);
            });
        $settled=true;
        if($suggestProfile&&($result['profile_suggestions']??[])){
            try{
                $suggestions=sf_image_profile_propose($owner,$id,$file['sha256'],$result['profile_suggestions'],$epoch);
                $db->prepare('UPDATE agent_canvas_image_analyses SET suggestions_json=? WHERE user_id=? AND analysis_id=? AND source_sha256=?')
                    ->execute([json_encode(array_column($suggestions,'id'),JSON_THROW_ON_ERROR),$owner,$requestId,$file['sha256']]);
            }catch(Throwable $error){error_log('[Stonefellow private photo suggestions] '.get_class($error));}
        }
    }catch(Throwable $error){
        if(!$settled&&$reservation!==null){
            try{sf_agent_canvas_image_analysis_release($user,$reservation);}
            catch(Throwable $releaseError){error_log('[Stonefellow image analysis escrow] '.get_class($releaseError));}
        }
        $db->prepare('UPDATE agent_canvas_image_analyses SET state=?,updated_at=? WHERE user_id=? AND analysis_id=? AND state=\'processing\'')
            ->execute([$providerStarted?'unknown':'failed',gmdate('c'),$owner,$requestId]);
        if($error->getMessage()==='ACTIVE_TOKENS_REQUIRED')throw $error;
        error_log('[Stonefellow private image analysis] '.get_class($error));
    }
    return sf_agent_canvas_image_analysis_result($owner,$id,sf_agent_canvas_image_analysis_row($owner,$requestId));
}

/** Explicit reset retires an uncertain attempt and releases its hold; it never calls a provider. */
function sf_agent_canvas_image_analysis_reset(array $user,string $id,string $analysisId): array {
    $owner=(int)($user['id']??0);
    if(!sf_agent_canvas_image_id($id)||!sf_agent_canvas_image_id($analysisId))throw new InvalidArgumentException('Choose an analysis to reset.');
    sf_agent_canvas_image_analysis_schema();$db=sf_db();$db->beginTransaction();
    try{
        sf_agent_canvas_image_analysis_lock($db,$owner);$row=sf_agent_canvas_image_analysis_row($owner,$analysisId);
        if(!$row||$row['image_id']!==$id||!in_array($row['state'],['processing','unknown','failed','canceled'],true))
            throw new SfAgentCanvasImageAnalysisConflict('Only a pending or unavailable analysis can be reset.');
        $db->prepare('UPDATE agent_canvas_image_analyses SET state=\'canceled\',revision=revision+1,updated_at=? WHERE user_id=? AND analysis_id=? AND state<>\'canceled\'')
            ->execute([gmdate('c'),$owner,$analysisId]);$db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    sf_agent_canvas_image_analysis_release($user,$row['reservation_key']);
    return sf_agent_canvas_image_analysis_result($owner,$id,sf_agent_canvas_image_analysis_row($owner,$analysisId));
}

function sf_agent_canvas_image_analysis_save(int $owner,string $id,string $analysisId,int $revision,mixed $analysis): array {
    if(!sf_agent_canvas_image_id($analysisId)||$revision<1)throw new InvalidArgumentException('Choose the current analysis revision.');
    $analysis=sf_image_analysis_validate($analysis);sf_agent_canvas_image_analysis_schema();$file=sf_agent_canvas_image_analysis_file($owner,$id);
    $db=sf_db();$db->beginTransaction();
    try{
        sf_agent_canvas_image_analysis_lock($db,$owner);$row=sf_agent_canvas_image_analysis_row($owner,$analysisId);
        if(!$row||$row['image_id']!==$id||!hash_equals($row['source_sha256'],$file['sha256'])||
            !in_array($row['state'],['draft','approved'],true)||(int)$row['revision']!==$revision)
            throw new SfAgentCanvasImageAnalysisConflict('This analysis changed. Reload it before saving your review.');
        $json=json_encode($analysis,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$now=gmdate('c');
        $q=$db->prepare('UPDATE agent_canvas_image_analyses SET state=\'approved\',analysis_json=?,revision=revision+1,updated_at=?
            WHERE user_id=? AND analysis_id=? AND revision=? AND source_sha256=?');
        $q->execute([$json,$now,$owner,$analysisId,$revision,$file['sha256']]);
        if($q->rowCount()!==1)throw new SfAgentCanvasImageAnalysisConflict('This analysis changed. Reload it before saving your review.');
        $db->prepare('INSERT INTO agent_canvas_image_analysis_versions(user_id,analysis_id,revision,analysis_json,state,created_at) VALUES(?,?,?,?,?,?)')
            ->execute([$owner,$analysisId,$revision+1,$json,'approved',$now]);
        $db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    return sf_agent_canvas_image_analysis_result($owner,$id,sf_agent_canvas_image_analysis_row($owner,$analysisId));
}

/** Search and canvas context see only explicitly reviewed metadata for the current private file. */
function sf_agent_canvas_image_analysis_approved(array $images,int $owner): array {
    if($owner<1||!$images)return [];sf_agent_canvas_image_analysis_schema();$ids=[];
    foreach(array_slice($images,0,200) as $image)if(sf_agent_canvas_image_id($image['id']??null))$ids[]=$image['id'];
    if(!$ids)return [];$ids=array_values(array_unique($ids));
    $q=sf_db()->prepare('SELECT * FROM agent_canvas_image_analyses WHERE user_id=? AND state=\'approved\' AND image_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY created_at DESC,analysis_id DESC');
    $q->execute([$owner,...$ids]);$out=[];$checked=[];
    foreach($q->fetchAll() as $row){
        $id=$row['image_id'];if(isset($out[$id]))continue;
        try{
            $file=$checked[$id]??=sf_agent_canvas_image_analysis_file($owner,$id);
            if(hash_equals($row['source_sha256'],$file['sha256']))$out[$id]=sf_image_analysis_validate(json_decode($row['analysis_json'],true,32,JSON_THROW_ON_ERROR));
        }catch(InvalidArgumentException|JsonException $error){continue;}
    }
    return $out;
}
