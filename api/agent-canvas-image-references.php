<?php
declare(strict_types=1);

require_once __DIR__.'/media-core.php';
require_once __DIR__.'/image-generation-core.php';

const SF_AGENT_CANVAS_REFERENCE_MAX_BYTES=10*1024*1024;
const SF_AGENT_CANVAS_REFERENCE_TYPES=['image/png','image/jpeg','image/webp'];

/** Gallery publication is a separate, explicit opt-in from ordinary Media Library use. */
function sf_agent_canvas_reference_ensure_schema(): void {
    static $ready=false;
    if($ready)return;
    sf_media_ensure_schema();
    sf_image_gen_schema();
    $db=sf_db();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_reference_images (
            asset_id INTEGER PRIMARY KEY,id TEXT NOT NULL UNIQUE,created_at TEXT NOT NULL,
            FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE CASCADE)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_canvas_references_created ON agent_canvas_reference_images(created_at,id)');
    }else{
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_reference_images (
            asset_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,id CHAR(36) NOT NULL UNIQUE,
            created_at VARCHAR(40) NOT NULL,
            INDEX idx_canvas_references_created(created_at,id),
            CONSTRAINT fk_canvas_reference_asset FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    $ready=true;
}

function sf_agent_canvas_reference_id(mixed $id): bool {
    return is_string($id)&&preg_match('/\Aref-[a-f0-9]{32}\z/',$id)===1;
}

/** Recheck approval and protected uses when reading, including after publication. */
function sf_agent_canvas_reference_asset_file(array $asset,bool $verifyHash=true): ?array {
    if(($asset['category']??'')!=='image'||
        !in_array((string)($asset['mime_type']??''),SF_AGENT_CANVAS_REFERENCE_TYPES,true))return null;
    $id=(int)($asset['id']??0);
    if($id<1||sf_media_protected_master_asset($id))return null;
    $q=sf_db()->prepare("SELECT 1 FROM media_links WHERE asset_id=? AND entity_type='member_content' LIMIT 1");
    $q->execute([$id]);
    if($q->fetchColumn())return null;
    $q=sf_db()->prepare('SELECT 1 FROM image_generation_options WHERE mask_asset_id=? LIMIT 1');
    $q->execute([$id]);
    if($q->fetchColumn())return null;
    $technical=sf_media_decode($asset['technical_json']??'',[]);
    $relative=(string)($asset['stored_path']??'');
    if(($technical['source']??'')==='openai_image_generation'||str_starts_with($relative,'storage/media/generated/')){
        $q=sf_db()->prepare("SELECT 1 FROM image_generation_jobs
            WHERE id=? AND media_asset_id=? AND status='ready' AND asset_approved_by>0 LIMIT 1");
        $q->execute([(int)($technical['image_job_id']??0),$id]);
        if(!$q->fetchColumn())return null;
    }
    if(!str_starts_with($relative,'storage/media/')||str_contains($relative,"\0")||
        preg_match('#(?:^|/)\.\.?(?:/|$)#',$relative))return null;
    $root=realpath(SF_ROOT);
    $mediaRoot=realpath(SF_ROOT.'/storage/media');
    $path=realpath(SF_ROOT.'/'.$relative);
    if(!$root||!$mediaRoot||!$path||$path!==$root.'/'.$relative||
        !str_starts_with($path,$mediaRoot.DIRECTORY_SEPARATOR)||!is_file($path)||!is_readable($path))return null;
    $size=(int)($asset['size_bytes']??0);
    if($size<200||$size>SF_AGENT_CANVAS_REFERENCE_MAX_BYTES||@filesize($path)!==$size)return null;
    $info=@getimagesize($path);
    if(!$info||($info['mime']??'')!==$asset['mime_type']||$info[0]<1||$info[1]<1||$info[0]*$info[1]>60000000)return null;
    $sha=(string)($asset['sha256']??'');
    if(!preg_match('/\A[a-f0-9]{64}\z/',$sha))return null;
    if($verifyHash){
        $actual=hash_file('sha256',$path);
        if(!is_string($actual)||!hash_equals($sha,$actual))return null;
    }
    return ['path'=>$path,'mime'=>$asset['mime_type'],'size'=>$size,'width'=>(int)$info[0],
        'height'=>(int)$info[1],'sha256'=>$sha];
}

function sf_agent_canvas_reference_row(string $id): ?array {
    if(!sf_agent_canvas_reference_id($id))return null;
    sf_agent_canvas_reference_ensure_schema();
    $q=sf_db()->prepare('SELECT a.*,r.id reference_id,r.created_at reference_created_at
        FROM agent_canvas_reference_images r JOIN media_assets a ON a.id=r.asset_id WHERE r.id=?');
    $q->execute([$id]);
    return $q->fetch()?:null;
}

function sf_agent_canvas_reference_metadata(array $row,array $file): array {
    return ['id'=>$row['reference_id'],'title'=>sf_clean_text($row['title']?:'Reference image',180),
        'width'=>$file['width'],'height'=>$file['height'],'created_at'=>$row['reference_created_at'],
        'url'=>'api/agent-canvas-image.php?id='.$row['reference_id'],'source'=>'reference'];
}

function sf_agent_canvas_reference_get(string $id): ?array {
    $row=sf_agent_canvas_reference_row($id);
    $file=$row===null?null:sf_agent_canvas_reference_asset_file($row,false);
    return $file===null?null:sf_agent_canvas_reference_metadata($row,$file);
}

/** This resolver is shared by the pixel endpoint and provider reference inputs. */
function sf_agent_canvas_reference_file(string $id): ?array {
    $row=sf_agent_canvas_reference_row($id);
    return $row===null?null:sf_agent_canvas_reference_asset_file($row);
}

function sf_agent_canvas_references_page(int $limit=24,?string $before=null): array {
    sf_agent_canvas_reference_ensure_schema();
    $empty=['images'=>[],'next_cursor'=>null];
    $limit=max(1,min(100,$limit));
    $where='1=1';$params=[];
    if($before!==null){
        $cursor=sf_agent_canvas_reference_row($before);
        if($cursor===null)return $empty;
        $where='(r.created_at<? OR (r.created_at=? AND r.id<?))';
        $params=[$cursor['reference_created_at'],$cursor['reference_created_at'],$cursor['reference_id']];
    }
    $q=sf_db()->prepare('SELECT a.*,r.id reference_id,r.created_at reference_created_at
        FROM agent_canvas_reference_images r JOIN media_assets a ON a.id=r.asset_id
        WHERE '.$where.' ORDER BY r.created_at DESC,r.id DESC');
    $q->execute($params);
    $images=[];
    while($row=$q->fetch()){
        $file=sf_agent_canvas_reference_asset_file($row,false);
        if($file===null)continue;
        if(count($images)===$limit)return ['images'=>$images,'next_cursor'=>$images[$limit-1]['id']];
        $images[]=sf_agent_canvas_reference_metadata($row,$file);
    }
    return ['images'=>$images,'next_cursor'=>null];
}

function sf_agent_canvas_reference_admin_assets(array $assets,string $assetIdKey='id'): array {
    if(!$assets)return [];
    sf_agent_canvas_reference_ensure_schema();
    $ids=array_map(static fn(array $asset):int=>(int)$asset[$assetIdKey],$assets);
    $q=sf_db()->prepare('SELECT asset_id,id FROM agent_canvas_reference_images WHERE asset_id IN ('
        .implode(',',array_fill(0,count($ids),'?')).')');
    $q->execute($ids);
    $references=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach($assets as &$asset){
        $asset['in_reference_gallery']=isset($references[(int)$asset[$assetIdKey]]);
        $asset['reference_image_id']=$references[(int)$asset[$assetIdKey]]??null;
    }
    unset($asset);
    require_once __DIR__.'/media-image-analysis.php';
    return sf_media_image_analysis_assets($assets,$assetIdKey);
}

function sf_agent_canvas_reference_set(int $assetId,bool $enabled): array {
    sf_agent_canvas_reference_ensure_schema();
    $asset=sf_media_asset($assetId);
    if($asset===null)throw new InvalidArgumentException('Media asset not found.');
    if($enabled){
        if(sf_agent_canvas_reference_asset_file($asset)===null)
            throw new InvalidArgumentException('Choose a readable PNG, JPEG or WebP image up to 10 MB. Member images, private originals, edit masks and unapproved generated images cannot be shared.');
        $q=sf_db()->prepare('SELECT id FROM agent_canvas_reference_images WHERE asset_id=?');
        $q->execute([$assetId]);
        if(!$q->fetchColumn()){
            try{
                sf_db()->prepare('INSERT INTO agent_canvas_reference_images(asset_id,id,created_at) VALUES(?,?,?)')
                    ->execute([$assetId,'ref-'.bin2hex(random_bytes(16)),gmdate('c')]);
            }catch(PDOException $error){
                $q->execute([$assetId]);
                if(!$q->fetchColumn())throw $error;
            }
        }
    }else{
        sf_db()->prepare('DELETE FROM agent_canvas_reference_images WHERE asset_id=?')->execute([$assetId]);
    }
    return sf_agent_canvas_reference_admin_assets([$asset])[0];
}
