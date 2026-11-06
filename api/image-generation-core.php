<?php
declare(strict_types=1);

const SF_IMAGE_GEN_GENERATE_ENDPOINT='https://api.openai.com/v1/images/generations';

/** 91B20A: one explicit, administrator-approved image request at a time. */
function sf_image_gen_models(): array {
    return ['gpt-image-2.5-flare','gpt-image-2.5-sunburst','gpt-image-1.5'];
}
function sf_image_gen_default_cap(string $role): ?int {
    if($role==='admin'){
        $stored=sf_ai_meta_get('ai.image_admin_daily_cap');
        return $stored===''?null:max(1,min(50,(int)$stored));
    }
    return max(1,min(50,(int)sf_ai_meta_get('ai.image_daily_cap','5')));
}
function sf_image_gen_settings(): array {
    return ['enabled'=>sf_ai_meta_get('ai.image_enabled','0')==='1',
        'canvas_listeners_enabled'=>sf_ai_meta_get('ai.image_canvas_listeners_enabled','0')==='1',
        'model'=>sf_ai_meta_get('ai.image_model','gpt-image-2.5-flare'),
        'daily_cap'=>sf_image_gen_default_cap('user'),'admin_daily_cap'=>sf_image_gen_default_cap('admin')];
}
function sf_image_gen_save_settings(array $input): array {
    $model=(string)($input['model']??'');
    if(!in_array($model,sf_image_gen_models(),true))throw new InvalidArgumentException('Select a supported image model.');
    $cap=(int)($input['daily_cap']??0);
    if($cap<1||$cap>50)throw new InvalidArgumentException('Daily image limit must be 1–50.');
    $adminCap=$input['admin_daily_cap']??null;
    if($adminCap!==null&&(!is_int($adminCap)||$adminCap<1||$adminCap>50))
        throw new InvalidArgumentException('Administrator daily image limit must be 1–50 or unlimited.');
    sf_ai_meta_set('ai.image_model',$model);
    sf_ai_meta_set('ai.image_daily_cap',(string)$cap);
    if(array_key_exists('admin_daily_cap',$input))sf_ai_meta_set('ai.image_admin_daily_cap',(string)$adminCap);
    sf_ai_meta_set('ai.image_enabled',!empty($input['enabled'])?'1':'0');
    if(array_key_exists('canvas_listeners_enabled',$input))
        sf_ai_meta_set('ai.image_canvas_listeners_enabled',!empty($input['canvas_listeners_enabled'])?'1':'0');
    return sf_image_gen_settings();
}
function sf_image_gen_schema(): void {
    static $ready=false;
    if($ready)return;
    $pdo=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite)$pdo->exec("CREATE TABLE IF NOT EXISTS image_generation_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,request_key TEXT NOT NULL UNIQUE,created_by INTEGER NOT NULL,
        prompt TEXT NOT NULL,model TEXT NOT NULL,size TEXT NOT NULL,quality TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft',prompt_approved_by INTEGER NULL,asset_approved_by INTEGER NULL,
        media_asset_id INTEGER NULL,usage_json TEXT NOT NULL DEFAULT '{}',provider_request_id TEXT NOT NULL DEFAULT '',
        error_code TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,started_at TEXT NULL,completed_at TEXT NULL,
        started_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL)");
    else $pdo->exec("CREATE TABLE IF NOT EXISTS image_generation_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,request_key CHAR(36) NOT NULL UNIQUE,
        created_by BIGINT UNSIGNED NOT NULL,prompt TEXT NOT NULL,model VARCHAR(120) NOT NULL,
        size VARCHAR(32) NOT NULL,quality VARCHAR(20) NOT NULL,status VARCHAR(32) NOT NULL DEFAULT 'draft',
        prompt_approved_by BIGINT UNSIGNED NULL,asset_approved_by BIGINT UNSIGNED NULL,media_asset_id BIGINT UNSIGNED NULL,
        usage_json LONGTEXT NOT NULL,provider_request_id VARCHAR(180) NOT NULL DEFAULT '',error_code VARCHAR(80) NOT NULL DEFAULT '',
        created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,started_at VARCHAR(40) NULL,completed_at VARCHAR(40) NULL,
        started_by BIGINT UNSIGNED NULL,INDEX idx_image_jobs_started_by(started_by,started_at),
        INDEX idx_image_jobs_owner(created_by,created_at),INDEX idx_image_jobs_status(status,created_at),
        CONSTRAINT fk_image_jobs_started_by FOREIGN KEY(started_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if($sqlite){
        $pdo->exec('CREATE TABLE IF NOT EXISTS image_generation_canvas_attempts (
            attempt_key TEXT PRIMARY KEY, started_at TEXT NOT NULL,
            user_id INTEGER NULL REFERENCES users(id) ON DELETE CASCADE)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_canvas_image_attempts_start ON image_generation_canvas_attempts(started_at)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS image_generation_user_limits (
            user_id INTEGER PRIMARY KEY,daily_cap INTEGER NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    }else $pdo->exec('CREATE TABLE IF NOT EXISTS image_generation_canvas_attempts (
        attempt_key CHAR(64) NOT NULL PRIMARY KEY, started_at VARCHAR(40) NOT NULL,
        user_id BIGINT UNSIGNED NULL,INDEX idx_canvas_image_attempts_start(started_at),
        INDEX idx_canvas_image_attempts_owner(user_id,started_at),
        CONSTRAINT fk_canvas_image_attempts_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    if(!$sqlite)$pdo->exec('CREATE TABLE IF NOT EXISTS image_generation_user_limits (
        user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,daily_cap INT UNSIGNED NOT NULL,
        CONSTRAINT fk_image_generation_user_limit FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    // Older jobs and attempts lack a recorded initiator. Preserve their history without assigning a quota owner.
    foreach([
        ['image_generation_jobs','started_by','SET NULL','idx_image_jobs_started_by','fk_image_jobs_started_by'],
        ['image_generation_canvas_attempts','user_id','CASCADE','idx_canvas_image_attempts_owner','fk_canvas_image_attempts_owner'],
    ] as [$table,$column,$onDelete,$index,$foreignKey]){
        $columns=static fn():array=>$pdo->query($sqlite?'PRAGMA table_info('.$table.')':'SHOW COLUMNS FROM '.$table)
            ->fetchAll(PDO::FETCH_COLUMN,$sqlite?1:0);
        if(!in_array($column,$columns(),true)){
            try{
                $pdo->exec($sqlite?"ALTER TABLE $table ADD COLUMN $column INTEGER NULL REFERENCES users(id) ON DELETE $onDelete"
                    :"ALTER TABLE $table ADD COLUMN $column BIGINT UNSIGNED NULL, ADD INDEX $index($column,started_at),
                    ADD CONSTRAINT $foreignKey FOREIGN KEY($column) REFERENCES users(id) ON DELETE $onDelete");
            }catch(PDOException $error){if(!in_array($column,$columns(),true))throw $error;}
        }
        if($sqlite)$pdo->exec("CREATE INDEX IF NOT EXISTS $index ON $table($column,started_at)");
    }
    if($sqlite)$pdo->exec('CREATE TABLE IF NOT EXISTS image_generation_references (
        job_id INTEGER NOT NULL PRIMARY KEY,source_asset_id INTEGER NOT NULL,parent_job_id INTEGER NULL,
        source_sha256 TEXT NOT NULL,consent_admin_id INTEGER NOT NULL,consent_at TEXT NOT NULL,
        purpose TEXT NOT NULL DEFAULT \'reference_variation\',created_at TEXT NOT NULL)');
    else $pdo->exec("CREATE TABLE IF NOT EXISTS image_generation_references (
        job_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,source_asset_id BIGINT UNSIGNED NOT NULL,
        parent_job_id BIGINT UNSIGNED NULL,source_sha256 CHAR(64) NOT NULL,
        consent_admin_id BIGINT UNSIGNED NOT NULL,consent_at VARCHAR(40) NOT NULL,
        purpose VARCHAR(40) NOT NULL DEFAULT 'reference_variation',created_at VARCHAR(40) NOT NULL,
        INDEX idx_image_ref_source(source_asset_id),INDEX idx_image_ref_parent(parent_job_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if($sqlite)$pdo->exec('CREATE TABLE IF NOT EXISTS image_generation_options (
        job_id INTEGER NOT NULL PRIMARY KEY,background TEXT NOT NULL DEFAULT \'auto\',
        mask_asset_id INTEGER NULL,mask_sha256 TEXT NULL,mask_consent_admin_id INTEGER NULL,
        mask_consent_at TEXT NULL,created_at TEXT NOT NULL)');
    else $pdo->exec("CREATE TABLE IF NOT EXISTS image_generation_options (
        job_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,background VARCHAR(20) NOT NULL DEFAULT 'auto',
        mask_asset_id BIGINT UNSIGNED NULL,mask_sha256 CHAR(64) NULL,
        mask_consent_admin_id BIGINT UNSIGNED NULL,mask_consent_at VARCHAR(40) NULL,
        created_at VARCHAR(40) NOT NULL,INDEX idx_image_options_mask(mask_asset_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if($sqlite)$pdo->exec('CREATE TABLE IF NOT EXISTS image_generation_cost_reviews (
        job_id INTEGER NOT NULL PRIMARY KEY,actual_cost_cents INTEGER NOT NULL,
        currency TEXT NOT NULL DEFAULT \'USD\',verified_by INTEGER NOT NULL,
        verified_at TEXT NOT NULL,invoice_reference TEXT NOT NULL DEFAULT \'\',
        updated_at TEXT NOT NULL)');
    else $pdo->exec("CREATE TABLE IF NOT EXISTS image_generation_cost_reviews (
        job_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,actual_cost_cents INT UNSIGNED NOT NULL,
        currency VARCHAR(3) NOT NULL DEFAULT 'USD',verified_by BIGINT UNSIGNED NOT NULL,
        verified_at VARCHAR(40) NOT NULL,invoice_reference VARCHAR(180) NOT NULL DEFAULT '',
        updated_at VARCHAR(40) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready=true;
}
function sf_image_gen_jobs(int $limit=150): array {
    sf_image_gen_schema();require_once __DIR__.'/media-core.php';sf_media_ensure_schema();
    $q=sf_db()->query('SELECT j.id,j.request_key,j.created_by,j.prompt,j.model,j.size,j.quality,j.status,j.prompt_approved_by,j.asset_approved_by,j.media_asset_id,j.usage_json,j.provider_request_id,j.error_code,j.created_at,j.updated_at,j.started_at,j.completed_at,a.uuid asset_uuid,r.source_asset_id,r.parent_job_id,s.uuid reference_uuid,s.title reference_title,COALESCE(o.background,\'auto\') background,o.mask_asset_id,m.uuid mask_uuid,c.actual_cost_cents,c.verified_at cost_verified_at,c.invoice_reference FROM image_generation_jobs j LEFT JOIN media_assets a ON a.id=j.media_asset_id LEFT JOIN image_generation_references r ON r.job_id=j.id LEFT JOIN media_assets s ON s.id=r.source_asset_id LEFT JOIN image_generation_options o ON o.job_id=j.id LEFT JOIN media_assets m ON m.id=o.mask_asset_id LEFT JOIN image_generation_cost_reviews c ON c.job_id=j.id ORDER BY j.id DESC LIMIT '.max(1,min(300,$limit)));
    return array_map(static function(array $row):array {
        $row['id']=(int)$row['id'];$row['media_asset_id']=$row['media_asset_id']===null?null:(int)$row['media_asset_id'];
        $row['source_asset_id']=$row['source_asset_id']===null?null:(int)$row['source_asset_id'];
        $row['parent_job_id']=$row['parent_job_id']===null?null:(int)$row['parent_job_id'];
        $row['mask_asset_id']=$row['mask_asset_id']===null?null:(int)$row['mask_asset_id'];
        $row['actual_cost_cents']=$row['actual_cost_cents']===null?null:(int)$row['actual_cost_cents'];
        $row['usage']=json_decode((string)$row['usage_json'],true)?:[];unset($row['usage_json']);return $row;
    },$q->fetchAll());
}
function sf_image_gen_options(int $jobId): array {
    sf_image_gen_schema();$q=sf_db()->prepare('SELECT * FROM image_generation_options WHERE job_id=? LIMIT 1');
    $q->execute([$jobId]);return $q->fetch()?:['background'=>'auto','mask_asset_id'=>null,'mask_sha256'=>null];
}
function sf_image_gen_job(int $id): ?array {
    $q=sf_db()->prepare('SELECT * FROM image_generation_jobs WHERE id=? LIMIT 1');$q->execute([$id]);return $q->fetch()?:null;
}
function sf_image_gen_reference(int $jobId): ?array {
    sf_image_gen_schema();
    $q=sf_db()->prepare('SELECT * FROM image_generation_references WHERE job_id=? LIMIT 1');$q->execute([$jobId]);
    return $q->fetch()?:null;
}
/** Only images that can pass the generated-asset approval gate are offered to the composer. */
function sf_image_gen_available_references(): array {
    sf_image_gen_schema();require_once __DIR__.'/media-core.php';
    $approved=[];
    $q=sf_db()->query("SELECT media_asset_id FROM image_generation_jobs WHERE status='ready' AND media_asset_id IS NOT NULL");
    foreach($q->fetchAll() as $row)$approved[(int)$row['media_asset_id']]=true;
    return array_values(array_filter(sf_media_list('image','',1000),static function(array $asset)use($approved):bool {
        if(!in_array((string)$asset['mime_type'],['image/png','image/jpeg','image/webp'],true)
            ||(int)$asset['size_bytes']<200||(int)$asset['size_bytes']>10*1024*1024)return false;
        return ($asset['technical']['source']??'')!=='openai_image_generation'||isset($approved[(int)$asset['id']]);
    }));
}
/** A PNG with an alpha channel, matching the source image geometry, is required for edit-region masks. */
function sf_image_gen_mask_candidate(int $assetId,array $reference,?string $expectedHash=null): array {
    $candidate=sf_image_gen_reference_candidate($assetId,$expectedHash);
    if(($candidate['asset']['mime_type']??'')!=='image/png')
        throw new InvalidArgumentException('Edit mask must be a transparent PNG in Media Library.');
    $info=@getimagesize($candidate['path']);
    $sourceInfo=@getimagesize($reference['path']);
    if(!$info||!$sourceInfo||(int)$info[0]!==(int)$sourceInfo[0]||(int)$info[1]!==(int)$sourceInfo[1])
        throw new InvalidArgumentException('Edit mask must match the source image width and height.');
    $handle=fopen($candidate['path'],'rb');if(!$handle)throw new RuntimeException('Edit mask cannot be read.');
    $header=fread($handle,26);fclose($handle);
    if(!is_string($header)||strlen($header)<26||substr($header,0,8)!=="\x89PNG\r\n\x1a\n"
        ||!in_array(ord($header[25]),[4,6],true))
        throw new InvalidArgumentException('Edit mask PNG must include an alpha channel with transparent edit areas.');
    return $candidate;
}
/** Resolve only a hash-verified, local Media Library image; never accept remote URLs or filesystem paths from the browser. */
function sf_image_gen_reference_candidate(int $assetId,?string $expectedHash=null): array {
    require_once __DIR__.'/media-core.php';
    $asset=sf_media_asset($assetId);
    if(!$asset||($asset['category']??'')!=='image'||!in_array((string)$asset['mime_type'],['image/png','image/jpeg','image/webp'],true))
        throw new InvalidArgumentException('Choose a PNG, JPEG or WebP image in the Media Library.');
    sf_media_guard_generated_image_approval($assetId);
    $bytes=(int)($asset['size_bytes']??0);
    if($bytes<200||$bytes>10*1024*1024)throw new InvalidArgumentException('Reference images must be between 200 bytes and 10 MB.');
    $mediaRoot=realpath(SF_ROOT.'/storage/media');
    $stored=realpath(SF_ROOT.'/'.ltrim((string)($asset['stored_path']??''),'/'));
    if(!$mediaRoot||!$stored||!str_starts_with($stored,$mediaRoot.DIRECTORY_SEPARATOR)||!is_file($stored)||!is_readable($stored))
        throw new RuntimeException('Reference source is unavailable inside managed media storage.');
    if((int)filesize($stored)!==$bytes)throw new RuntimeException('Reference image size changed; choose another verified copy.');
    $sha=hash_file('sha256',$stored);
    if(!is_string($sha)||!hash_equals((string)$asset['sha256'],$sha)||($expectedHash!==null&&!hash_equals($expectedHash,$sha)))
        throw new RuntimeException('Reference image changed after selection. Create a new draft before generating.');
    return ['asset'=>$asset,'path'=>$stored,'sha256'=>$sha,'parent_job_id'=>(int)($asset['technical']['image_job_id']??0)];
}
function sf_image_gen_draft(int $adminId,array $input): array {
    sf_image_gen_schema();$s=sf_image_gen_settings();
    $prompt=sf_clean_text($input['prompt']??'',3000);
    if(strlen($prompt)<16)throw new InvalidArgumentException('Describe the image with at least 16 characters.');
    $model=$s['model'];$size=(string)($input['size']??'1536x1024');$quality=(string)($input['quality']??'medium');
    if(!in_array($size,['1024x1024','1536x1024','1024x1536'],true))throw new InvalidArgumentException('Invalid output size.');
    if(!in_array($quality,['low','medium','high'],true))throw new InvalidArgumentException('Invalid image quality.');
    $key=strtolower((string)($input['request_key']??''));
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',$key))throw new InvalidArgumentException('Missing draft request ID.');
    $sourceAssetId=max(0,(int)($input['reference_asset_id']??0));
    $background=(string)($input['background']??'auto');
    if(!in_array($background,['auto','opaque','transparent'],true))throw new InvalidArgumentException('Invalid image background.');
    $maskAssetId=max(0,(int)($input['mask_asset_id']??0));
    if($maskAssetId>0&&$sourceAssetId===0)throw new InvalidArgumentException('A mask requires a selected source image.');
    if($maskAssetId>0&&$maskAssetId===$sourceAssetId)throw new InvalidArgumentException('Choose a separate transparent PNG for the edit mask.');
    if($maskAssetId>0&&empty($input['mask_consent']))throw new InvalidArgumentException('Confirm permission to send the edit mask to OpenAI.');
    if($sourceAssetId>0&&empty($input['reference_consent']))
        throw new InvalidArgumentException('Confirm that you own or have permission to use the reference image with OpenAI.');
    $reference=$sourceAssetId>0?sf_image_gen_reference_candidate($sourceAssetId):null;
    $mask=$maskAssetId>0?sf_image_gen_mask_candidate($maskAssetId,$reference):null;
    $q=sf_db()->prepare('SELECT id,created_by FROM image_generation_jobs WHERE request_key=?');$q->execute([$key]);$old=$q->fetch();
    if($old){
        if((int)$old['created_by']!==$adminId)throw new RuntimeException('Draft request conflict.');
        $existing=sf_image_gen_job((int)$old['id'])?:[];$source=sf_image_gen_reference((int)$old['id']);
        $options=sf_image_gen_options((int)$old['id']);
        if((int)($source['source_asset_id']??0)!==$sourceAssetId||($existing['prompt']??'')!==$prompt
            ||($existing['model']??'')!==$model||($existing['size']??'')!==$size||($existing['quality']??'')!==$quality
            ||(string)$options['background']!==$background||(int)($options['mask_asset_id']??0)!==$maskAssetId)
            throw new RuntimeException('Draft retry conflicts with the already saved prompt or reference.');
        return $existing;
    }
    $pdo=sf_db();$pdo->beginTransaction();
    try{
        $now=gmdate('c');$q=$pdo->prepare("INSERT INTO image_generation_jobs(request_key,created_by,prompt,model,size,quality,status,usage_json,created_at,updated_at) VALUES(?,?,?,?,?,?,'draft','{}',?,?)");
        $q->execute([$key,$adminId,$prompt,$model,$size,$quality,$now,$now]);
        $id=(int)$pdo->lastInsertId();
        if($reference){
            $r=$pdo->prepare('INSERT INTO image_generation_references(job_id,source_asset_id,parent_job_id,source_sha256,consent_admin_id,consent_at,purpose,created_at) VALUES(?,?,?,?,?,?,?,?)');
            $r->execute([$id,$sourceAssetId,$reference['parent_job_id']?:null,$reference['sha256'],$adminId,$now,'reference_variation',$now]);
        }
        $r=$pdo->prepare('INSERT INTO image_generation_options(job_id,background,mask_asset_id,mask_sha256,mask_consent_admin_id,mask_consent_at,created_at) VALUES(?,?,?,?,?,?,?)');
        $r->execute([$id,$background,$maskAssetId?:null,$mask['sha256']??null,$mask?$adminId:null,$mask?$now:null,$now]);
        $pdo->commit();return sf_image_gen_job($id)?:[];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sf_image_gen_approve_prompt(int $id,int $adminId): void {
    $q=sf_db()->prepare("UPDATE image_generation_jobs SET status='approved',prompt_approved_by=?,updated_at=? WHERE id=? AND status='draft'");
    $q->execute([$adminId,gmdate('c'),$id]);
    if($q->rowCount()!==1)throw new RuntimeException('Only saved drafts can be approved for a paid generation.');
}
/** Lock the account before checking and recording a paid attempt or changing its override. */
function sf_image_gen_account(PDO $pdo,int $userId,bool $lock=false): array {
    $sqlite=(sf_db_config()['driver']??'')==='sqlite';
    if($lock&&$sqlite){
        // Take SQLite's write lock before any quota read, even when the account inherits the default.
        $pdo->prepare('UPDATE image_generation_user_limits SET daily_cap=daily_cap WHERE user_id=?')->execute([$userId]);
    }
    $q=$pdo->prepare('SELECT role,status FROM users WHERE id=?'.($lock&&!$sqlite?' FOR UPDATE':''));
    $q->execute([$userId]);$account=$q->fetch();$q->closeCursor();
    if($account===false)throw new InvalidArgumentException('Account unavailable.');
    return $account;
}
function sf_image_gen_daily_attempts(PDO $pdo,string $day,int $userId): int {
    $start=$day.'T00:00:00';
    $end=(new DateTimeImmutable($day,new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d').'T00:00:00';
    $q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM image_generation_jobs WHERE started_by=? AND started_at>=? AND started_at<?)+
        (SELECT COUNT(*) FROM image_generation_canvas_attempts WHERE user_id=? AND started_at>=? AND started_at<?)');
    $q->execute([$userId,$start,$end,$userId,$start,$end]);
    $count=(int)$q->fetchColumn();$q->closeCursor();
    return $count;
}
function sf_image_gen_quota_limits(PDO $pdo,int $userId,string $role): array {
    $q=$pdo->prepare('SELECT daily_cap FROM image_generation_user_limits WHERE user_id=?');
    $q->execute([$userId]);$stored=$q->fetchColumn();$q->closeCursor();
    $override=$stored===false?null:(int)$stored;
    $default=sf_image_gen_default_cap($role);
    return ['daily_cap'=>$override??$default,'default_daily_cap'=>$default,'daily_cap_override'=>$override];
}
function sf_image_gen_user_quota(int $userId): array {
    sf_image_gen_schema();$pdo=sf_db();
    $account=sf_image_gen_account($pdo,$userId);
    $limits=sf_image_gen_quota_limits($pdo,$userId,$account['role']);
    $count=sf_image_gen_daily_attempts($pdo,gmdate('Y-m-d'),$userId);
    return $limits+['today_attempts'=>$count,'today_remaining'=>$limits['daily_cap']===null?null:max(0,$limits['daily_cap']-$count)];
}
function sf_image_gen_save_user_cap(int $userId,?int $dailyCap): void {
    if($dailyCap!==null&&($dailyCap<0||$dailyCap>50))throw new InvalidArgumentException('Account daily image limit must be 0–50 or use the default.');
    sf_image_gen_schema();$pdo=sf_db();$pdo->beginTransaction();
    try{
        $account=sf_image_gen_account($pdo,$userId,true);
        if($account['status']==='closed')throw new InvalidArgumentException('Account unavailable.');
        if($dailyCap===null){
            $pdo->prepare('DELETE FROM image_generation_user_limits WHERE user_id=?')->execute([$userId]);
        }else{
            $upsert=(sf_db_config()['driver']??'')==='sqlite'?'ON CONFLICT(user_id) DO UPDATE SET daily_cap=excluded.daily_cap'
                :'ON DUPLICATE KEY UPDATE daily_cap=VALUES(daily_cap)';
            $pdo->prepare('INSERT INTO image_generation_user_limits(user_id,daily_cap) VALUES(?,?) '.$upsert)->execute([$userId,$dailyCap]);
        }
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function sf_image_gen_clear_user_quota(PDO $pdo,int $userId): array {
    $limits=$pdo->prepare('DELETE FROM image_generation_user_limits WHERE user_id=?');$limits->execute([$userId]);
    $canvas=$pdo->prepare('DELETE FROM image_generation_canvas_attempts WHERE user_id=?');$canvas->execute([$userId]);
    $studio=$pdo->prepare('UPDATE image_generation_jobs SET started_by=NULL WHERE started_by=?');$studio->execute([$userId]);
    return ['image_quota_overrides'=>$limits->rowCount(),'canvas_image_attempts'=>$canvas->rowCount(),'studio_image_attempts'=>$studio->rowCount()];
}
function sf_image_gen_reserve_job(int $id,int $adminId): array {
    sf_image_gen_schema();$s=sf_image_gen_settings();
    if(!$s['enabled'])throw new RuntimeException('Image generation is disabled in AI Providers.');
    $ai=sf_ai_provider_row('openai');
    if(empty($ai['enabled'])||empty($ai['secret_payload']))throw new RuntimeException('Enable the existing OpenAI provider and API key first.');
    // An unavailable or withdrawn reference must not consume a daily attempt.
    $reference=sf_image_gen_reference($id);
    $options=sf_image_gen_options($id);
    if($reference){
        $verified=sf_image_gen_reference_candidate((int)$reference['source_asset_id'],(string)$reference['source_sha256']);
        if((int)($options['mask_asset_id']??0)>0)
            sf_image_gen_mask_candidate((int)$options['mask_asset_id'],$verified,(string)$options['mask_sha256']);
    }elseif((int)($options['mask_asset_id']??0)>0)throw new RuntimeException('Edit mask cannot be used without a source image.');
    $pdo=sf_db();$pdo->beginTransaction();
    try{
        $account=sf_image_gen_account($pdo,$adminId,true);
        if($account['status']!=='active'||$account['role']!=='admin')throw new InvalidArgumentException('Active administrator account required.');
        $now=gmdate('c');$day=substr($now,0,10);
        $cap=sf_image_gen_quota_limits($pdo,$adminId,$account['role'])['daily_cap'];
        if($cap===0)throw new RuntimeException('Image generation is disabled for your account.');
        if($cap!==null&&sf_image_gen_daily_attempts($pdo,$day,$adminId)>=$cap)
            throw new RuntimeException('Your daily paid-generation limit has been reached. It resets at midnight UTC.');
        $q=$pdo->prepare("UPDATE image_generation_jobs SET status='generating',started_at=?,started_by=?,updated_at=? WHERE id=? AND status='approved' AND prompt_approved_by IS NOT NULL");
        $q->execute([$now,$adminId,$now,$id]);
        if($q->rowCount()!==1)throw new RuntimeException('This prompt is not approved, or its generation already started.');
        $pdo->commit();return sf_image_gen_job($id)?:[];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sf_image_gen_payload(array $design,int $userId,string $background): array {
    $payload=['model'=>(string)$design['model'],'prompt'=>(string)$design['prompt'],
        'size'=>(string)$design['size'],'quality'=>(string)$design['quality'],'n'=>1,
        'output_format'=>'webp','background'=>$background,'moderation'=>'auto',
        'safety_identifier'=>hash_hmac('sha256',(string)$userId,sf_ai_secret_key())];
    if(!in_array($payload['model'],sf_image_gen_models(),true))throw new RuntimeException('Image model not permitted.');
    return $payload;
}
/** Returns raw provider data only to server code. Never send image base64 to the job-list API. */
function sf_image_gen_request(array $job,int $adminId,?callable $transport=null): array {
    $options=sf_image_gen_options((int)($job['id']??0));
    $payload=sf_image_gen_payload($job,$adminId,(string)($options['background']??'auto'));
    $endpoint=SF_IMAGE_GEN_GENERATE_ENDPOINT;
    $reference=sf_image_gen_reference((int)($job['id']??0));
    if($reference){
        $source=sf_image_gen_reference_candidate((int)$reference['source_asset_id'],(string)$reference['source_sha256']);
        $bytes=file_get_contents($source['path']);
        if(!is_string($bytes)||strlen($bytes)!==(int)$source['asset']['size_bytes'])
            throw new RuntimeException('Could not read the verified image reference.');
        // Keep private reference bytes on the server. Only the explicitly
        // approved paid edit sends them over the authenticated TLS request.
        $payload['images']=[['image_url'=>'data:'.$source['asset']['mime_type'].';base64,'.base64_encode($bytes)]];
        $endpoint='https://api.openai.com/v1/images/edits';
        if((int)($options['mask_asset_id']??0)>0){
            $mask=sf_image_gen_mask_candidate((int)$options['mask_asset_id'],$source,(string)$options['mask_sha256']);
            $maskBytes=file_get_contents($mask['path']);
            if(!is_string($maskBytes)||strlen($maskBytes)!==(int)$mask['asset']['size_bytes'])
                throw new RuntimeException('Could not read the verified edit mask.');
            $payload['mask']=['image_url'=>'data:image/png;base64,'.base64_encode($maskBytes)];
        }
    }
    return sf_image_gen_provider_request($endpoint,$payload,$transport);
}
function sf_image_gen_provider_request(string $endpoint,array $payload,?callable $transport=null,?callable $poll=null): array {
    if(!in_array($endpoint,[SF_IMAGE_GEN_GENERATE_ENDPOINT,'https://api.openai.com/v1/images/edits'],true))
        throw new InvalidArgumentException('Image provider endpoint is not permitted.');
    if($poll!==null)$poll();
    if($transport)return $transport($payload);
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for image generation.');
    if(function_exists('set_time_limit'))@set_time_limit(150);
    $secret=sf_ai_provider_secret('openai');if($secret==='')throw new RuntimeException('OpenAI key is unavailable.');
    $response='';$requestId='';$c=curl_init($endpoint);
    if($c===false)throw new RuntimeException('Image provider connection is unavailable.');
    $options=[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>135,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret,'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$response):int{
            if(strlen($response)+strlen($chunk)>28*1024*1024)return 0;
            $response.=$chunk;return strlen($chunk);
        },CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$requestId):int{
            if(stripos($line,'x-request-id:')===0)$requestId=trim(substr($line,13));return strlen($line);
        }];
    if($poll!==null){
        $options[CURLOPT_NOPROGRESS]=false;
        $progressOption=defined('CURLOPT_XFERINFOFUNCTION')?CURLOPT_XFERINFOFUNCTION:CURLOPT_PROGRESSFUNCTION;
        $options[$progressOption]=static function()use($poll):int {$poll();return 0;};
    }
    try{
        curl_setopt_array($c,$options);
        $ok=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);
    }finally{curl_close($c);}
    if($ok===false||$status<200||$status>=300){
        $decoded=json_decode($response,true);$code=(string)($decoded['error']['code']??'provider_unavailable');
        if(!preg_match('/^[a-z0-9_]{1,60}$/i',$code))$code='provider_unavailable';
        throw new RuntimeException('Image provider request failed: '.$code);
    }
    $result=json_decode($response,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($result))throw new RuntimeException('Image provider returned invalid data.');
    $result['_stonefellow_request_id']=substr($requestId,0,160);
    return $result;
}
/** Dollar amounts are manually transcribed from an OpenAI invoice; never estimated from token counts. */
function sf_image_gen_reconcile_cost(int $jobId,int $adminId,int $cents,string $invoiceReference): array {
    sf_image_gen_schema();$job=sf_image_gen_job($jobId);
    if(!$job||!in_array((string)$job['status'],['ready','needs_review','failed'],true)||empty($job['started_at']))
        throw new RuntimeException('Only completed or uncertain paid requests can receive invoice reconciliation.');
    if($cents<0||$cents>1000000)throw new InvalidArgumentException('Invoice cost must be between $0 and $10,000.');
    $invoiceReference=sf_clean_text($invoiceReference,180);
    if(strlen($invoiceReference)<5)throw new InvalidArgumentException('Enter an invoice ID or reconciliation note (at least five characters).');
    $pdo=sf_db();$pdo->beginTransaction();
    try{
        $now=gmdate('c');$q=$pdo->prepare('SELECT job_id FROM image_generation_cost_reviews WHERE job_id=?');$q->execute([$jobId]);
        if($q->fetchColumn()){
            $q=$pdo->prepare('UPDATE image_generation_cost_reviews SET actual_cost_cents=?,verified_by=?,verified_at=?,invoice_reference=?,updated_at=? WHERE job_id=?');
            $q->execute([$cents,$adminId,$now,$invoiceReference,$now,$jobId]);
        }else{
            $q=$pdo->prepare('INSERT INTO image_generation_cost_reviews(job_id,actual_cost_cents,currency,verified_by,verified_at,invoice_reference,updated_at) VALUES(?,?,\'USD\',?,?,?,?)');
            $q->execute([$jobId,$cents,$adminId,$now,$invoiceReference,$now]);
        }
        $pdo->commit();return ['job_id'=>$jobId,'actual_cost_cents'=>$cents,'currency'=>'USD','verified_at'=>$now];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sf_image_gen_image_bytes(array $result): array {
    $base64=(string)($result['data'][0]['b64_json']??'');
    if($base64===''||strlen($base64)>25*1024*1024)throw new RuntimeException('Image provider did not return a supported image.');
    $bytes=base64_decode($base64,true);
    if(!is_string($bytes)||strlen($bytes)<200||strlen($bytes)>16*1024*1024)throw new RuntimeException('Image output exceeded safe size limits.');
    $info=@getimagesizefromstring($bytes);$mime=(string)($info['mime']??'');
    $ext=['image/webp'=>'webp','image/png'=>'png','image/jpeg'=>'jpg'][$mime]??'';
    if($ext===''||(int)($info[0]??0)<128||(int)($info[1]??0)<128)throw new RuntimeException('Image format or dimensions were invalid.');
    return ['bytes'=>$bytes,'extension'=>$ext,'mime'=>$mime,'width'=>(int)$info[0],'height'=>(int)$info[1]];
}
function sf_image_gen_complete(int $id,int $adminId,array $result): array {
    $img=sf_image_gen_image_bytes($result);$dir=SF_ROOT.'/storage/media/generated';
    if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Generated media storage is unavailable.');
    $name='stonefellow-ai-'.bin2hex(random_bytes(12)).'.'.$img['extension'];$rel='storage/media/generated/'.$name;
    $abs=SF_ROOT.'/'.$rel;if(file_put_contents($abs,$img['bytes'],LOCK_EX)!==strlen($img['bytes']))throw new RuntimeException('Image could not be saved.');
    try{
        require_once __DIR__.'/media-core.php';
        $asset=sf_media_register_existing_file($rel,$name,$adminId);
        if((string)($asset['stored_path']??'')!==$rel){
            @unlink($abs);
            throw new RuntimeException('Generated bytes duplicate an existing managed image. Review the existing asset instead.');
        }
        $job=sf_image_gen_job($id)?:[];
        $usage=(array)($result['usage']??[]);
        $technical=['source'=>'openai_image_generation','image_job_id'=>$id,'model'=>$job['model']??'',
            'size'=>$job['size']??'','quality'=>$job['quality']??'',
            'provider_request_id'=>(string)($result['_stonefellow_request_id']??'')];
        $update=sf_db()->prepare('UPDATE media_assets SET technical_json=?,updated_at=? WHERE id=?');
        $update->execute([sf_media_json($technical),gmdate('c'),(int)$asset['id']]);
        sf_media_update_asset((int)$asset['id'],['title'=>'AI Image Studio · Job #'.$id]);
        $q=sf_db()->prepare("UPDATE image_generation_jobs SET status='needs_review',media_asset_id=?,usage_json=?,provider_request_id=?,completed_at=?,updated_at=? WHERE id=? AND status='generating'");
        $now=gmdate('c');$q->execute([(int)$asset['id'],json_encode($usage,JSON_THROW_ON_ERROR),substr((string)($result['_stonefellow_request_id']??''),0,160),$now,$now,$id]);
        if($q->rowCount()!==1)throw new RuntimeException('Generation job changed during storage.');
        return ['asset_id'=>(int)$asset['id'],'asset_uuid'=>(string)$asset['uuid'],'status'=>'needs_review','usage'=>$usage];
    }catch(Throwable $e){throw $e;}
}
function sf_image_gen_mark_failed(int $id,string $errorCode): void {
    $code=preg_replace('/[^a-z0-9_]/i','',strtolower($errorCode))?:'generation_failed';
    $q=sf_db()->prepare("UPDATE image_generation_jobs SET status='failed',error_code=?,updated_at=? WHERE id=? AND status='generating'");
    $q->execute([substr($code,0,80),gmdate('c'),$id]);
}
function sf_image_gen_approve_asset(int $id,int $adminId): void {
    $job=sf_image_gen_job($id);
    if(!$job||($job['status']??'')!=='needs_review'||(int)($job['media_asset_id']??0)<1)
        throw new RuntimeException('Generate and inspect this private image before approving it.');
    $q=sf_db()->prepare("UPDATE image_generation_jobs SET status='ready',asset_approved_by=?,updated_at=? WHERE id=? AND status='needs_review'");
    $q->execute([$adminId,gmdate('c'),$id]);
    if($q->rowCount()!==1)throw new RuntimeException('Approval status changed.');
}
