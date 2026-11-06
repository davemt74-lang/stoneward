<?php
declare(strict_types=1);

require_once __DIR__.'/image-analysis-batch-privacy.php';
require_once __DIR__.'/media-image-analysis.php';
require_once __DIR__.'/agent-canvas-image-analysis-core.php';

const SF_IMAGE_ANALYSIS_BATCH_MAX_ITEMS=200;
const SF_IMAGE_ANALYSIS_BATCH_STATES=['pending','processing','completed','failed','skipped','reviewed'];

function sf_image_analysis_batch_id(mixed $id): bool {
    return is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/',$id)===1;
}

function sf_image_analysis_batch_schema(): void {
    static $ready=null;
    $ready??=new WeakMap();$db=sf_db();
    if(isset($ready[$db]))return;
    if($db->inTransaction())throw new LogicException('Initialize analysis batches before a transaction.');
    sf_media_image_analysis_ensure_schema();sf_agent_canvas_image_analysis_schema();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS image_analysis_batch_jobs (
            user_id INTEGER NOT NULL,id TEXT NOT NULL,scope TEXT NOT NULL,mode TEXT NOT NULL,fingerprint TEXT NOT NULL,
            status TEXT NOT NULL,remaining_count INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
            PRIMARY KEY(user_id,id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
        $db->exec("CREATE TABLE IF NOT EXISTS image_analysis_batch_items (
            user_id INTEGER NOT NULL,job_id TEXT NOT NULL,position INTEGER NOT NULL,image_id TEXT NOT NULL,
            request_id TEXT NOT NULL,source_sha256 TEXT NOT NULL,status TEXT NOT NULL,title TEXT NOT NULL,image_url TEXT NOT NULL,
            error TEXT NOT NULL DEFAULT '',result_json TEXT NOT NULL DEFAULT '{}',
            PRIMARY KEY(user_id,job_id,position),UNIQUE(user_id,request_id),
            FOREIGN KEY(user_id,job_id) REFERENCES image_analysis_batch_jobs(user_id,id) ON DELETE CASCADE)");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_analysis_batch_owner ON image_analysis_batch_jobs(user_id,scope,created_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_analysis_batch_processing ON image_analysis_batch_items(user_id,status)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS image_analysis_batch_jobs (
            user_id BIGINT UNSIGNED NOT NULL,id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            scope VARCHAR(16) NOT NULL,mode VARCHAR(16) NOT NULL,fingerprint CHAR(64) NOT NULL,status VARCHAR(16) NOT NULL,
            remaining_count INT UNSIGNED NOT NULL DEFAULT 0,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
            PRIMARY KEY(user_id,id),INDEX idx_analysis_batch_owner(user_id,scope,created_at),
            CONSTRAINT fk_analysis_batch_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS image_analysis_batch_items (
            user_id BIGINT UNSIGNED NOT NULL,job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            position INT UNSIGNED NOT NULL,image_id VARCHAR(32) NOT NULL,request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            source_sha256 VARCHAR(64) NOT NULL,status VARCHAR(16) NOT NULL,title VARCHAR(180) NOT NULL,image_url VARCHAR(600) NOT NULL,
            error VARCHAR(400) NOT NULL,result_json LONGTEXT NOT NULL,
            PRIMARY KEY(user_id,job_id,position),UNIQUE KEY uq_analysis_batch_request(user_id,request_id),
            INDEX idx_analysis_batch_processing(user_id,status),
            CONSTRAINT fk_analysis_batch_item_job FOREIGN KEY(user_id,job_id) REFERENCES image_analysis_batch_jobs(user_id,id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready[$db]=true;
}

/** Never trust a client role or owner when dispatching another paid item. */
function sf_image_analysis_batch_actor(array $user,string $scope,bool $lock=false): array {
    if(!in_array($scope,['media','private'],true)||(int)($user['id']??0)<1)
        throw new InvalidArgumentException('Choose an available image analysis batch.');
    $db=sf_db();$id=(int)$user['id'];
    if($lock)$db->prepare("UPDATE users SET status=status WHERE id=? AND status='active'")->execute([$id]);
    $suffix=$lock&&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $q=$db->prepare('SELECT id,role,status FROM users WHERE id=?'.$suffix);$q->execute([$id]);$actor=$q->fetch();
    if(!$actor||$actor['status']!=='active'||($scope==='media'&&$actor['role']!=='admin'))
        throw new InvalidArgumentException('This image analysis batch is unavailable for your account.');
    return ['id'=>(int)$actor['id'],'role'=>$actor['role']];
}

function sf_image_analysis_batch_row(int $owner,string $scope,string $id): ?array {
    if(!sf_image_analysis_batch_id($id))throw new InvalidArgumentException('Choose a saved image analysis batch.');
    $q=sf_db()->prepare('SELECT * FROM image_analysis_batch_jobs WHERE user_id=? AND scope=? AND id=?');
    $q->execute([$owner,$scope,$id]);return $q->fetch()?:null;
}

function sf_image_analysis_batch_source(int $owner,string $scope,string $id): ?array {
    try{
        if($scope==='media'){
            $asset=sf_media_asset((int)$id);
            if(!$asset)return null;
            $file=sf_media_image_analysis_source((int)$id);
            return ['sha256'=>$file['sha256'],'file'=>$file,'title'=>sf_clean_text($asset['title']?:'Image',180),
                'image_url'=>'api/media.php?id='.rawurlencode($asset['uuid'])];
        }
        $asset=sf_agent_canvas_image_get($owner,$id);
        if(!$asset)return null;
        $file=sf_agent_canvas_image_analysis_file($owner,$id);
        sf_image_analysis_read_file($file);
        return ['sha256'=>$file['sha256'],'file'=>$file,'title'=>$asset['title'],'image_url'=>$asset['url']];
    }catch(InvalidArgumentException|RuntimeException $error){return null;}
}

/** Read the exact saved attempt. A recovery request never invokes its provider again. */
function sf_image_analysis_batch_attempt(int $owner,string $scope,array $item): ?array {
    if($scope==='media'){
        $q=sf_db()->prepare('SELECT * FROM media_image_analyses WHERE request_id=? AND asset_id=?');
        $q->execute([$item['request_id'],(int)$item['image_id']]);$row=$q->fetch();
        if(!$row)return null;
        $state=$row['status'];$version=(int)$row['version'];$tokens=0;
    }else{
        $row=sf_agent_canvas_image_analysis_row($owner,$item['request_id']);
        if(!$row||$row['image_id']!==$item['image_id'])return null;
        $state=$row['state'];$version=(int)$row['revision'];$tokens=(int)$row['active_tokens_used'];
    }
    if(!hash_equals($item['source_sha256'],$row['source_sha256']))return null;
    $analysis=null;
    if(in_array($state,['draft','pending_review','approved'],true))
        $analysis=sf_image_analysis_validate(json_decode($row['analysis_json'],true,16,JSON_THROW_ON_ERROR));
    return ['analysis_id'=>$item['request_id'],'analysis_status'=>$state,'version'=>$version,'analysis'=>$analysis,
        'usage'=>['input_tokens'=>(int)$row['input_tokens'],'output_tokens'=>(int)$row['output_tokens'],'active_tokens_used'=>$tokens]];
}

function sf_image_analysis_batch_store_result(int $owner,string $jobId,array $item,?array $result,string $error=''): void {
    $status=$item['status'];
    $stored=$result??[];
    if($result===null&&isset($item['result_json'])){
        $previous=json_decode($item['result_json'],true,16,JSON_THROW_ON_ERROR);
        if(isset($previous['usage']))$stored=['analysis_id'=>$previous['analysis_id']??$item['request_id'],
            'analysis_status'=>'stale','version'=>(int)($previous['version']??0),'analysis'=>null,'usage'=>$previous['usage']];
    }
    if($result!==null){
        $state=$result['analysis_status'];
        $status=match($state){'draft','pending_review'=>'completed','approved'=>'reviewed','running','processing'=>'processing',default=>'failed'};
        if($status==='failed'&&$error==='')$error='Analysis did not finish. Open this image to review its analysis status.';
    }
    $q=sf_db()->prepare('UPDATE image_analysis_batch_items SET status=?,error=?,result_json=? WHERE user_id=? AND job_id=? AND request_id=?');
    $q->execute([$status,$error,json_encode($stored,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $owner,$jobId,$item['request_id']]);
}

/** Reconcile settled calls and independent per-image reviews without redispatching them. */
function sf_image_analysis_batch_refresh(int $owner,string $scope,string $id): void {
    $q=sf_db()->prepare("SELECT * FROM image_analysis_batch_items WHERE user_id=? AND job_id=? AND status IN ('processing','completed','reviewed','failed')");
    $q->execute([$owner,$id]);$items=$q->fetchAll();$q->closeCursor();
    foreach($items as $item){
        $result=sf_image_analysis_batch_attempt($owner,$scope,$item);
        $source=sf_image_analysis_batch_source($owner,$scope,$item['image_id']);
        if($source===null||!hash_equals($item['source_sha256'],$source['sha256'])){
            if($result!==null)$item['result_json']=json_encode($result,JSON_THROW_ON_ERROR);
            $item['status']='skipped';
            sf_image_analysis_batch_store_result($owner,$id,$item,null,'The image was changed, removed or is no longer supported.');
            continue;
        }
        if($item['status']==='failed'&&in_array($result['analysis_status']??null,['running','processing'],true))continue;
        if($result!==null)sf_image_analysis_batch_store_result($owner,$id,$item,$result);
    }
    $q=sf_db()->prepare("SELECT COUNT(*) FROM image_analysis_batch_items WHERE user_id=? AND job_id=? AND status IN ('pending','processing')");
    $q->execute([$owner,$id]);$remaining=(int)$q->fetchColumn();
    if($remaining===0)sf_db()->prepare("UPDATE image_analysis_batch_jobs SET status='completed',updated_at=?
        WHERE user_id=? AND id=? AND status IN ('running','paused')")->execute([gmdate('c'),$owner,$id]);
}

/** A settled older queue must not hold another queue's account dispatch lock. */
function sf_image_analysis_batch_reconcile_owner(int $owner): void {
    $q=sf_db()->prepare("SELECT DISTINCT j.scope,j.id FROM image_analysis_batch_jobs j
        JOIN image_analysis_batch_items i ON i.user_id=j.user_id AND i.job_id=j.id
        WHERE j.user_id=? AND i.status='processing'");
    $q->execute([$owner]);$jobs=$q->fetchAll();$q->closeCursor();
    foreach($jobs as $job)sf_image_analysis_batch_refresh($owner,$job['scope'],$job['id']);
}

function sf_image_analysis_batch_get(array $user,string $scope,string $id): array {
    $actor=sf_image_analysis_batch_actor($user,$scope);sf_image_analysis_batch_schema();$owner=$actor['id'];
    if(!sf_image_analysis_batch_row($owner,$scope,$id))throw new OutOfBoundsException('Image analysis batch not found.');
    sf_image_analysis_batch_refresh($owner,$scope,$id);
    $job=sf_image_analysis_batch_row($owner,$scope,$id);
    $q=sf_db()->prepare('SELECT * FROM image_analysis_batch_items WHERE user_id=? AND job_id=? ORDER BY position');
    $q->execute([$owner,$id]);$items=[];$counts=array_fill_keys(SF_IMAGE_ANALYSIS_BATCH_STATES,0);
    $usage=['input_tokens'=>0,'output_tokens'=>0,'active_tokens_used'=>0];
    foreach($q->fetchAll() as $row){
        $result=json_decode($row['result_json'],true,16,JSON_THROW_ON_ERROR);
        $itemUsage=$result['usage']??['input_tokens'=>0,'output_tokens'=>0,'active_tokens_used'=>0];
        foreach($usage as $key=>$unused)$usage[$key]+=(int)$itemUsage[$key];
        $counts[$row['status']]++;
        $items[]=['image_id'=>$scope==='media'?(int)$row['image_id']:$row['image_id'],'request_id'=>$row['request_id'],
            'status'=>$row['status'],'title'=>$row['title'],'image_url'=>$row['image_url'],
            'analysis_id'=>$result['analysis_id']??null,'analysis_status'=>$result['analysis_status']??null,
            'version'=>(int)($result['version']??0),'analysis'=>$result['analysis']??null,'error'=>$row['error'],'usage'=>$itemUsage];
    }
    return ['id'=>$id,'scope'=>$scope,'mode'=>$job['mode'],'status'=>$job['status'],'counts'=>['total'=>count($items)]+$counts,
        'items'=>$items,'usage'=>$usage,'remaining_count'=>(int)$job['remaining_count'],'created_at'=>$job['created_at'],'updated_at'=>$job['updated_at']];
}

function sf_image_analysis_batch_list(array $user,string $scope): array {
    $actor=sf_image_analysis_batch_actor($user,$scope);sf_image_analysis_batch_schema();
    $q=sf_db()->prepare('SELECT id FROM image_analysis_batch_jobs WHERE user_id=? AND scope=? ORDER BY created_at DESC,id DESC LIMIT 20');
    $q->execute([$actor['id'],$scope]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
    $jobs=[];$latest=null;
    foreach($ids as $id){
        $job=sf_image_analysis_batch_get($actor,$scope,$id);$latest??=$job;unset($job['items']);$jobs[]=$job;
    }
    return ['jobs'=>$jobs,'job'=>$latest];
}

function sf_image_analysis_batch_create(array $user,string $scope,string $requestId,string $mode,array $ids=[]): array {
    $actor=sf_image_analysis_batch_actor($user,$scope);sf_image_analysis_batch_schema();$owner=$actor['id'];
    if(!sf_image_analysis_batch_id($requestId)||!in_array($mode,['selected','missing'],true)||!array_is_list($ids)||count($ids)>SF_IMAGE_ANALYSIS_BATCH_MAX_ITEMS)
        throw new InvalidArgumentException('Select up to 200 images or choose images missing analysis.');
    $selected=[];
    foreach($ids as $id){
        if($scope==='media'&&(!is_int($id)||$id<1)||$scope==='private'&&!sf_agent_canvas_image_id($id))
            throw new InvalidArgumentException('Choose image IDs from your available library.');
        $selected[]=(string)$id;
    }
    $selected=array_values(array_unique($selected));
    if($mode==='selected'&&!$selected||$mode==='missing'&&$selected)
        throw new InvalidArgumentException('Choose selected images or the missing-analysis inventory.');
    $fingerprint=hash('sha256',json_encode([$scope,$mode,$selected],JSON_THROW_ON_ERROR));
    $existing=sf_image_analysis_batch_row($owner,$scope,$requestId);
    if($existing){
        if(!hash_equals($existing['fingerprint'],$fingerprint))throw new InvalidArgumentException('This batch request has different images.');
        return sf_image_analysis_batch_get($actor,$scope,$requestId);
    }
    if($mode==='missing'){
        $q=$scope==='media'?sf_db()->query("SELECT id FROM media_assets WHERE category='image' ORDER BY id DESC")
            :sf_db()->prepare('SELECT id FROM agent_canvas_images WHERE user_id=? ORDER BY created_at DESC,id DESC');
        if($scope==='private')$q->execute([$owner]);
        $selected=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));$q->closeCursor();
    }
    $items=[];$remaining=0;
    foreach($selected as $id){
        $source=sf_image_analysis_batch_source($owner,$scope,$id);
        if($mode==='missing'){
            if($source===null)continue;
            if($scope==='media'){
                $q=sf_db()->prepare("SELECT status FROM media_image_analyses WHERE asset_id=? AND source_sha256=?
                    AND status IN ('pending_review','approved') ORDER BY record_id DESC LIMIT 1");
                $q->execute([(int)$id,$source['sha256']]);$complete=$q->fetch()?:null;$q->closeCursor();
            }
            else{
                $q=sf_db()->prepare("SELECT state status FROM agent_canvas_image_analyses WHERE user_id=? AND image_id=? AND source_sha256=?
                    AND state IN ('draft','approved') ORDER BY created_at DESC LIMIT 1");
                $q->execute([$owner,$id,$source['sha256']]);$complete=$q->fetch()?:null;$q->closeCursor();
            }
            if(in_array($complete['status']??null,['draft','pending_review','approved'],true))continue;
            if(count($items)>=SF_IMAGE_ANALYSIS_BATCH_MAX_ITEMS){$remaining++;continue;}
        }
        $items[]=['image_id'=>$id,'request_id'=>bin2hex(random_bytes(16)),'source_sha256'=>$source['sha256']??'',
            'status'=>$source===null?'skipped':'pending','title'=>$source['title']??'Image unavailable','image_url'=>$source['image_url']??'',
            'error'=>$source===null?'This image is unavailable or unsupported.':''];
    }
    $db=sf_db();$db->beginTransaction();
    try{
        sf_image_analysis_batch_actor($actor,$scope,true);
        $q=$db->prepare('SELECT scope,fingerprint FROM image_analysis_batch_jobs WHERE user_id=? AND id=?');$q->execute([$owner,$requestId]);
        $old=$q->fetch();$q->closeCursor();
        if($old){
            if($old['scope']!==$scope||!hash_equals($old['fingerprint'],$fingerprint))throw new InvalidArgumentException('This batch request has different images.');
        }else{
            $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
            $db->prepare('INSERT INTO image_analysis_batch_jobs(user_id,id,scope,mode,fingerprint,status,remaining_count,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$owner,$requestId,$scope,$mode,$fingerprint,$items?'running':'completed',$remaining,$now,$now]);
            $insert=$db->prepare("INSERT INTO image_analysis_batch_items(user_id,job_id,position,image_id,request_id,source_sha256,status,title,image_url,error,result_json)
                VALUES(?,?,?,?,?,?,?,?,?,?,'{}')");
            foreach($items as $position=>$item)$insert->execute([$owner,$requestId,$position,$item['image_id'],$item['request_id'],$item['source_sha256'],
                $item['status'],$item['title'],$item['image_url'],$item['error']]);
        }
        $db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    return sf_image_analysis_batch_get($actor,$scope,$requestId);
}

function sf_image_analysis_batch_step(array $user,string $scope,string $id,?callable $transport=null,?array $provider=null,?string $itemRequestId=null): array {
    $actor=sf_image_analysis_batch_actor($user,$scope);sf_image_analysis_batch_schema();$owner=$actor['id'];
    if($itemRequestId!==null&&!sf_image_analysis_batch_id($itemRequestId))throw new InvalidArgumentException('Choose an available batch item.');
    sf_image_analysis_batch_reconcile_owner($owner);
    $job=sf_image_analysis_batch_get($actor,$scope,$id);
    if($job['status']!=='running')return $job;
    if($scope==='private'&&$actor['role']!=='admin'){
        foreach($job['items'] as $candidate){
            if($candidate['status']!=='pending'||($itemRequestId!==null&&$candidate['request_id']!==$itemRequestId))continue;
            $source=sf_image_analysis_batch_source($owner,$scope,$candidate['image_id']);
            if($source!==null){
                $provider??=sf_image_analysis_provider();
                $reserve=sf_agent_canvas_image_analysis_reserve($source['file'],$provider);
                if(sf_token_balance($owner)<$reserve){
                    sf_db()->prepare("UPDATE image_analysis_batch_items SET error='More Active Tokens are required. Add tokens and resume this batch.'
                        WHERE user_id=? AND job_id=? AND request_id=? AND status='pending'")->execute([$owner,$id,$candidate['request_id']]);
                    return sf_image_analysis_batch_action($actor,$scope,$id,'pause');
                }
            }
            break;
        }
    }
    $db=sf_db();$db->beginTransaction();$item=null;
    try{
        sf_image_analysis_batch_actor($actor,$scope,true);
        $row=sf_image_analysis_batch_row($owner,$scope,$id);
        $q=$db->prepare("SELECT 1 FROM image_analysis_batch_items WHERE user_id=? AND status='processing' LIMIT 1");$q->execute([$owner]);
        $processing=(bool)$q->fetchColumn();$q->closeCursor();
        if($row['status']==='running'&&!$processing){
            $query="SELECT * FROM image_analysis_batch_items WHERE user_id=? AND job_id=? AND status='pending'";
            $params=[$owner,$id];
            if($itemRequestId!==null){$query.=' AND request_id=?';$params[]=$itemRequestId;}
            $q=$db->prepare($query.' ORDER BY position LIMIT 1');$q->execute($params);$item=$q->fetch()?:null;$q->closeCursor();
            if($item)$db->prepare("UPDATE image_analysis_batch_items SET status='processing' WHERE user_id=? AND job_id=? AND position=? AND status='pending'")
                ->execute([$owner,$id,$item['position']]);
        }
        $db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    if($item===null)return sf_image_analysis_batch_get($actor,$scope,$id);
    $item['status']='processing';$source=sf_image_analysis_batch_source($owner,$scope,$item['image_id']);
    if($source===null||!hash_equals($item['source_sha256'],$source['sha256'])){
        $item['status']='skipped';
        sf_image_analysis_batch_store_result($owner,$id,$item,null,'The image was changed, removed or is no longer supported.');
        return sf_image_analysis_batch_get($actor,$scope,$id);
    }
    try{
        if($scope==='media')sf_media_image_analysis_analyze($owner,(int)$item['image_id'],$item['request_id'],$transport,$provider,$item['source_sha256']);
        else sf_agent_canvas_image_analyze($actor,$item['image_id'],$item['request_id'],false,$transport,$provider,$item['source_sha256']);
        $result=sf_image_analysis_batch_attempt($owner,$scope,$item);
        if($result===null){$item['status']='failed';sf_image_analysis_batch_store_result($owner,$id,$item,null,'Analysis outcome is unavailable. Open the image to review its status.');}
        else sf_image_analysis_batch_store_result($owner,$id,$item,$result);
    }catch(Throwable $error){
        if($scope==='private'&&$error->getMessage()==='ACTIVE_TOKENS_REQUIRED'){
            $attempt=sf_agent_canvas_image_analysis_row($owner,$item['request_id']);
            if(($attempt['state']??null)==='failed'&&empty($attempt['reservation_key'])){
                $db->prepare("UPDATE image_analysis_batch_items SET request_id=?,status='pending',error='More Active Tokens are required. Add tokens and resume this batch.'
                    WHERE user_id=? AND job_id=? AND request_id=? AND status='processing'")
                    ->execute([bin2hex(random_bytes(16)),$owner,$id,$item['request_id']]);
                return sf_image_analysis_batch_action($actor,$scope,$id,'pause');
            }
        }
        $result=sf_image_analysis_batch_attempt($owner,$scope,$item);
        $item['status']='failed';
        $message=$error->getMessage()==='ACTIVE_TOKENS_REQUIRED'?'More Active Tokens are required. This item was not retried.'
            :'Analysis did not finish. Open the image to review its status.';
        sf_image_analysis_batch_store_result($owner,$id,$item,$result,$message);
    }
    $db->prepare('UPDATE image_analysis_batch_jobs SET updated_at=? WHERE user_id=? AND id=?')->execute([gmdate('c'),$owner,$id]);
    return sf_image_analysis_batch_get($actor,$scope,$id);
}

function sf_image_analysis_batch_action(array $user,string $scope,string $id,string $action): array {
    $actor=sf_image_analysis_batch_actor($user,$scope);sf_image_analysis_batch_schema();$owner=$actor['id'];
    if(!in_array($action,['pause','resume','cancel'],true))throw new InvalidArgumentException('Choose a batch action.');
    $db=sf_db();$db->beginTransaction();
    try{
        sf_image_analysis_batch_actor($actor,$scope,true);$job=sf_image_analysis_batch_row($owner,$scope,$id);
        if(!$job)throw new OutOfBoundsException('Image analysis batch not found.');
        if(!in_array($job['status'],['completed','canceled'],true)){
            $status=match($action){'pause'=>'paused','resume'=>'running','cancel'=>'canceled'};
            $db->prepare('UPDATE image_analysis_batch_jobs SET status=?,updated_at=? WHERE user_id=? AND id=?')->execute([$status,gmdate('c'),$owner,$id]);
            if($action==='cancel'){
                $db->prepare("UPDATE image_analysis_batch_items SET status='skipped',error='Batch canceled before this image was analyzed.'
                    WHERE user_id=? AND job_id=? AND status='pending'")->execute([$owner,$id]);
                $db->prepare("UPDATE image_analysis_batch_items SET status='failed',error='Batch canceled while this image analysis was in progress.'
                    WHERE user_id=? AND job_id=? AND status='processing'")->execute([$owner,$id]);
            }
        }
        $db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    return sf_image_analysis_batch_get($actor,$scope,$id);
}

function sf_image_analysis_batch_review(array $user,string $scope,string $id,array $reviews): array {
    $actor=sf_image_analysis_batch_actor($user,$scope);$job=sf_image_analysis_batch_get($actor,$scope,$id);
    if(!array_is_list($reviews)||count($reviews)>SF_IMAGE_ANALYSIS_BATCH_MAX_ITEMS)
        throw new InvalidArgumentException('Review up to 200 selected image analyses.');
    $items=array_column($job['items'],null,'image_id');$results=[];
    foreach($reviews as $review){
        $imageId=$review['image_id']??null;$item=is_int($imageId)||is_string($imageId)?($items[$imageId]??null):null;
        $result=['image_id'=>is_int($imageId)||is_string($imageId)?$imageId:null,'ok'=>false];
        try{
            if(!$item||!in_array($item['status'],['completed','reviewed'],true)||($review['analysis_id']??null)!==$item['analysis_id']||
                !is_int($review['version']??null)||$review['version']!==$item['version'])
                throw new InvalidArgumentException('The analysis changed. Refresh this image before reviewing it.');
            if($scope==='media')sf_media_image_analysis_save($actor['id'],(int)$imageId,$item['analysis_id'],$review['version'],$review['analysis']??null);
            else sf_agent_canvas_image_analysis_save($actor['id'],(string)$imageId,$item['analysis_id'],$review['version'],$review['analysis']??null);
            $result['ok']=true;
        }catch(InvalidArgumentException|SfAgentCanvasImageAnalysisConflict $error){$result['error']=$error->getMessage();}
        catch(Throwable $error){$result['error']='This image analysis could not be reviewed. Refresh and try again.';}
        $results[]=$result;
    }
    return ['job'=>sf_image_analysis_batch_get($actor,$scope,$id),'review_results'=>$results];
}
