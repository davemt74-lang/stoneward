<?php
declare(strict_types=1);

const SF_AGENT_CANVAS_IMAGE_MAX_BYTES=16*1024*1024;
const SF_AGENT_CANVAS_IMAGE_TYPES=['image/webp','image/png','image/jpeg'];
const SF_AGENT_CANVAS_IMAGE_LIMIT=4;

function sf_agent_canvas_image_ensure_schema(): void {
    static $ready=false;
    if($ready)return;
    $db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize canvas image storage before starting a transaction.');
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_images (
            user_id INTEGER NOT NULL,id TEXT NOT NULL,storage_key TEXT NOT NULL,
            title TEXT NOT NULL,mime TEXT NOT NULL,size_bytes INTEGER NOT NULL,width INTEGER NOT NULL,
            height INTEGER NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY (user_id,id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_canvas_images_created ON agent_canvas_images(user_id,created_at,id)');
    }else{
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_images (
            user_id BIGINT UNSIGNED NOT NULL,id CHAR(32) NOT NULL,storage_key CHAR(48) NOT NULL,
            title VARCHAR(180) NOT NULL,mime VARCHAR(32) NOT NULL,size_bytes INT UNSIGNED NOT NULL,
            width INT UNSIGNED NOT NULL,height INT UNSIGNED NOT NULL,created_at VARCHAR(40) NOT NULL,
            PRIMARY KEY (user_id,id),INDEX idx_canvas_images_created(user_id,created_at,id),
            CONSTRAINT fk_canvas_image_owner FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    $ready=true;
}

function sf_agent_canvas_image_id(mixed $id): bool {
    return is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/',$id)===1;
}

function sf_agent_canvas_image_dir(int $userId): string {
    return SF_ROOT.'/storage/canvas-images/'.$userId;
}

function sf_agent_canvas_image_metadata(array $row): array {
    return ['id'=>$row['id'],'title'=>$row['title'],'width'=>(int)$row['width'],'height'=>(int)$row['height'],
        'created_at'=>$row['created_at'],'url'=>'api/agent-canvas-image.php?id='.$row['id']];
}

function sf_agent_canvas_image_row(int $userId,string $id): ?array {
    if($userId<1||!sf_agent_canvas_image_id($id))return null;
    sf_agent_canvas_image_ensure_schema();
    $q=sf_db()->prepare('SELECT * FROM agent_canvas_images WHERE user_id=? AND id=?');
    $q->execute([$userId,$id]);
    return $q->fetch()?:null;
}

function sf_agent_canvas_image_get(int $userId,string $id): ?array {
    $row=sf_agent_canvas_image_row($userId,$id);
    return $row===null?null:sf_agent_canvas_image_metadata($row);
}

/** The first image retains the original per-request identity. */
function sf_agent_canvas_image_batch_ids(string $requestId): array {
    if(!sf_agent_canvas_image_id($requestId))throw new InvalidArgumentException('Invalid canvas image request.');
    $ids=[$requestId];
    for($index=2;$index<=SF_AGENT_CANVAS_IMAGE_LIMIT;$index++)
        $ids[]=substr(hash('sha256','stonefellow-canvas-image:'.$requestId.':'.$index),0,32);
    return $ids;
}

function sf_agent_canvas_image_saved_batch(int $userId,string $requestId): array {
    $ids=sf_agent_canvas_image_batch_ids($requestId);
    sf_agent_canvas_image_ensure_schema();
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $query=sf_db()->prepare('SELECT id,title FROM agent_canvas_images WHERE user_id=? AND id IN ('.$placeholders.')');
    $query->execute([$userId,...$ids]);
    $rows=array_column($query->fetchAll(),null,'id');
    $images=[];
    foreach($ids as $offset=>$id)if(isset($rows[$id]))$images[]=$rows[$id]+['index'=>$offset+1];
    return $images;
}

function sf_agent_canvas_images_list(int $userId,int $limit=24): array {
    return sf_agent_canvas_images_page($userId,$limit)['images'];
}

function sf_agent_canvas_images_page(int $userId,int $limit=24,?string $before=null): array {
    $empty=['images'=>[],'next_cursor'=>null];
    if($userId<1)return $empty;
    sf_agent_canvas_image_ensure_schema();
    $limit=max(1,min(100,$limit));
    $where='user_id=?';$params=[$userId];
    if($before!==null){
        $cursor=sf_agent_canvas_image_get($userId,$before);
        if($cursor===null)return $empty;
        $where.=' AND (created_at<? OR (created_at=? AND id<?))';
        array_push($params,$cursor['created_at'],$cursor['created_at'],$cursor['id']);
    }
    $q=sf_db()->prepare('SELECT id,title,width,height,created_at FROM agent_canvas_images
        WHERE '.$where.' ORDER BY created_at DESC,id DESC LIMIT '.($limit+1));
    $q->execute($params);
    $rows=$q->fetchAll();
    return ['images'=>array_map('sf_agent_canvas_image_metadata',array_slice($rows,0,$limit)),
        'next_cursor'=>count($rows)>$limit?$rows[$limit-1]['id']:null];
}

/** The caller removes $createdFile if its enclosing transaction rolls back. */
function sf_agent_canvas_image_store(int $userId,string $id,string $title,array $image,?string &$createdFile=null): array {
    $createdFile=null;
    if($userId<1||!sf_agent_canvas_image_id($id))throw new InvalidArgumentException('Invalid canvas image reference.');
    sf_agent_canvas_image_ensure_schema();
    $db=sf_db();$ownsTransaction=!$db->inTransaction();
    if($ownsTransaction)$db->beginTransaction();
    $path=null;
    try{
        $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$db->prepare('SELECT status FROM users WHERE id=?'.$lock);
        $q->execute([$userId]);
        if($q->fetchColumn()!=='active')throw new InvalidArgumentException('Account unavailable.');
        $saved=sf_agent_canvas_image_get($userId,$id);
        if($saved!==null){
            if($ownsTransaction)$db->commit();
            return $saved;
        }
        $bytes=$image['bytes']??null;
        if(!is_string($bytes)||strlen($bytes)<200||strlen($bytes)>SF_AGENT_CANVAS_IMAGE_MAX_BYTES)
            throw new InvalidArgumentException('Invalid canvas image size.');
        $info=@getimagesizefromstring($bytes);
        $mime=$info['mime']??'';$width=(int)($info[0]??0);$height=(int)($info[1]??0);
        if(!in_array($mime,SF_AGENT_CANVAS_IMAGE_TYPES,true)||$width<128||$height<128||$width>4096||$height>4096)
            throw new InvalidArgumentException('Invalid canvas image format.');
        $title=sf_clean_text($title,strlen($title));
        $title=preg_match('/\A.{1,180}/us',$title,$characters)===1?$characters[0]:'Generated canvas image';
        $dir=sf_agent_canvas_image_dir($userId);
        if((!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))||is_link($dir))
            throw new RuntimeException('Canvas image storage is unavailable.');
        $key=bin2hex(random_bytes(24));
        $path=$dir.'/'.$key.'.bin';
        if(@file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))
            throw new RuntimeException('Canvas image could not be saved.');
        @chmod($path,0600);
        $now=gmdate('c');
        $q=$db->prepare('INSERT INTO agent_canvas_images (user_id,id,storage_key,title,mime,size_bytes,width,height,created_at)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $q->execute([$userId,$id,$key,$title,$mime,strlen($bytes),$width,$height,$now]);
        if($ownsTransaction)$db->commit();
        $createdFile=$path;
        return sf_agent_canvas_image_metadata(['id'=>$id,'title'=>$title,'width'=>$width,'height'=>$height,'created_at'=>$now]);
    }catch(Throwable $error){
        if($ownsTransaction&&$db->inTransaction())$db->rollBack();
        if($path!==null)@unlink($path);
        throw $error;
    }
}

/** Return only a verified file inside the requesting account's directory. */
function sf_agent_canvas_image_file(int $userId,string $id): ?array {
    $row=sf_agent_canvas_image_row($userId,$id);
    if($row===null||!in_array($row['mime'],SF_AGENT_CANVAS_IMAGE_TYPES,true)||
        !preg_match('/\A[a-f0-9]{48}\z/',$row['storage_key']))return null;
    $dir=sf_agent_canvas_image_dir($userId);
    if(is_link($dir)||($root=realpath($dir))===false)return null;
    $size=(int)$row['size_bytes'];
    $path=$root.'/'.$row['storage_key'].'.bin';
    if($size<200||$size>SF_AGENT_CANVAS_IMAGE_MAX_BYTES||is_link($path)||!is_file($path)||@filesize($path)!==$size)return null;
    return ['path'=>$path,'mime'=>$row['mime'],'size'=>$size];
}

function sf_agent_canvas_image_clear_metadata(int $userId): int {
    $q=sf_db()->prepare('DELETE FROM agent_canvas_images WHERE user_id=?');
    $q->execute([$userId]);
    return $q->rowCount();
}

/** Called after account closure commits and subsequent writes are blocked. */
function sf_agent_canvas_image_clear_files(int $userId): void {
    $dir=sf_agent_canvas_image_dir($userId);
    if(is_link($dir)||!is_dir($dir))return;
    foreach(glob($dir.'/*.bin')?:[] as $path){
        if(preg_match('/\A[a-f0-9]{48}\.bin\z/',basename($path))&&!@unlink($path))
            error_log('[Stonefellow canvas images] Could not remove a closed account file.');
    }
    @rmdir($dir);
}
