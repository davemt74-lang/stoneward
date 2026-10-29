<?php
declare(strict_types=1);
/** Section 91B3D: exact-reviewed private image attachment to a real song/release.
 * No exposure change, replacement, publication, deletion or file operation.
 * The Admin action ledger owns the one-time approval/execute transition.
 */
require_once __DIR__.'/agent-media-actions.php';

function sf_agent_media_link_parse(string $message):?array {
    if(!preg_match('/\A(?:please\s+)?attach\s+(?:existing\s+)?(?:media\s+)?asset\s+#?([1-9][0-9]{0,11})\s+as\s+(artwork|cover)\s+(?:to|for)\s+(?:the\s+)?(song|track|release)\s+#?([A-Za-z0-9][A-Za-z0-9_-]{0,159})\s*[.!]?\z/iu',trim($message),$m))return null;
    $type=strtolower($m[3]);$type=$type==='song'?'track':$type;
    $role=strtolower($m[2]);
    return ['asset_id'=>(int)$m[1],'entity_type'=>$type,'entity_id'=>$m[4],'role'=>$role];
}
/** Exact canonical JSON source, read afresh instead of a cached public catalog. */
function sf_agent_media_link_target_snapshot(string $type,string $id):array {
    if(!in_array($type,['track','release'],true)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,159}$/D',$id))
        throw new InvalidArgumentException('Use an exact song or release ID.');
    $path=SF_ROOT.($type==='track'?'/data/catalog.json':'/data/releases.json');
    $raw=@file_get_contents($path);
    if(!is_string($raw))throw new RuntimeException('Song or release source unavailable for review.');
    try{$rows=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
    catch(JsonException $e){throw new RuntimeException('Song or release source is invalid.');}
    if(!is_array($rows))throw new RuntimeException('Song or release source is invalid.');
    $matches=[];
    foreach($rows as $row)if(is_array($row)&&(string)($row['id']??'')===$id)$matches[]=$row;
    if(count($matches)!==1)throw new InvalidArgumentException(count($matches)?'Target ID is ambiguous.':'Song or release ID not found.');
    $row=$matches[0];
    $isPublic=$type==='track'
        ? (!empty($row['public_visible'])&&($row['status']??'')!=='archived')
        : (($row['state']??'')==='published'&&($row['public_visible']??true)!==false);
    return ['id'=>$id,'type'=>$type,'title'=>(string)($row['title']??$id),
        'source_hash'=>hash('sha256',$raw),'target_public'=>$isPublic];
}
function sf_agent_media_link_asset_revision(array $asset):string {
    return hash('sha256',json_encode([
        'id'=>(int)($asset['id']??0),'uuid'=>(string)($asset['uuid']??''),
        'category'=>(string)($asset['category']??''),'sha256'=>(string)($asset['sha256']??''),
        'stored_path'=>(string)($asset['stored_path']??''),'size_bytes'=>(int)($asset['size_bytes']??0),
        'updated_at'=>(string)($asset['updated_at']??''),
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
function sf_agent_media_link_target_links(string $type,string $id,bool $lock=false):array {
    $sql='SELECT * FROM media_links WHERE entity_type=? AND entity_id=? ORDER BY id';
    if($lock&&(string)(sf_db_config()['driver']??'')!=='sqlite')$sql.=' FOR UPDATE';
    $q=sf_db()->prepare($sql);$q->execute([$type,$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
/** Pure validation with explicit snapshots; never trusts client-supplied titles or counts. */
function sf_agent_media_link_normalize(array $raw,array $asset,array $assetLinks,array $target,array $targetLinks):array {
    $allowed=['asset_id','asset_title','entity_type','entity_id','entity_title','role',
        'asset_link_count','asset_public_link_count','target_link_count','target_public',
        'public_visible','download_allowed','featured',
        'expected_asset_revision','expected_asset_links_hash','expected_target_source_hash','expected_target_links_hash'];
    if(array_diff(array_keys($raw),$allowed))throw new InvalidArgumentException('Unsupported attachment action field.');
    $id=$raw['asset_id']??null;$type=$raw['entity_type']??null;
    $entityId=$raw['entity_id']??null;$role=$raw['role']??null;
    if(!is_int($id)||$id<1||$id!==(int)($asset['id']??0)||($asset['category']??'')!=='image')
        throw new InvalidArgumentException('Choose an existing image asset by numeric ID.');
    if(!is_string($type)||!is_string($entityId)||!is_string($role)
       ||!in_array($type,['track','release'],true)
       ||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,159}$/D',$entityId)
       ||$type!==$target['type']||$entityId!==$target['id']
       ||$role!==($type==='track'?'artwork':'cover'))
        throw new InvalidArgumentException('Only private song artwork or release cover candidates are supported.');
    // The fields are immutable booleans, not client-selectable exposure options.
    foreach(['public_visible','download_allowed','featured'] as $flag)
        if(!array_key_exists($flag,$raw)||$raw[$flag]!==false)
            throw new InvalidArgumentException('Attachment must remain private, not featured or downloadable.');
    foreach(['expected_asset_revision'=>sf_agent_media_link_asset_revision($asset),
             'expected_asset_links_hash'=>sf_agent_media_links_digest($assetLinks),
             'expected_target_source_hash'=>$target['source_hash'],
             'expected_target_links_hash'=>sf_agent_media_links_digest($targetLinks)] as $key=>$digest){
        if(!is_string($raw[$key]??null)||!preg_match('/^[a-f0-9]{64}$/D',$raw[$key])
           ||!hash_equals($raw[$key],$digest))
            throw new RuntimeException('The image, target record or attachments changed; prepare a fresh review.');
    }
    foreach($targetLinks as $link){
        if((int)($link['asset_id']??0)===$id)
            throw new InvalidArgumentException('This image is already attached to the selected song or release.');
    }
    $publicLinks=0;
    foreach($assetLinks as $link)if(!empty($link['public_visible']))$publicLinks++;
    return ['asset_id'=>$id,'asset_title'=>(string)($asset['title']?:$asset['original_name']??''),
        'entity_type'=>$type,'entity_id'=>$entityId,'entity_title'=>(string)$target['title'],
        'role'=>$role,'asset_link_count'=>count($assetLinks),'asset_public_link_count'=>$publicLinks,
        'target_link_count'=>count($targetLinks),'target_public'=>(bool)$target['target_public'],
        'public_visible'=>false,'download_allowed'=>false,'featured'=>false,
        'expected_asset_revision'=>sf_agent_media_link_asset_revision($asset),
        'expected_asset_links_hash'=>sf_agent_media_links_digest($assetLinks),
        'expected_target_source_hash'=>$target['source_hash'],
        'expected_target_links_hash'=>sf_agent_media_links_digest($targetLinks)];
}
function sf_agent_media_link_draft_from_chat(string $message):?array {
    $parsed=sf_agent_media_link_parse($message);if($parsed===null)return null;
    $asset=sf_media_asset($parsed['asset_id']);
    if(!$asset)throw new InvalidArgumentException('Media asset ID not found.');
    $target=sf_agent_media_link_target_snapshot($parsed['entity_type'],$parsed['entity_id']);
    $assetLinks=sf_agent_media_links($parsed['asset_id']);
    $targetLinks=sf_agent_media_link_target_links($parsed['entity_type'],$parsed['entity_id']);
    return sf_agent_media_link_normalize($parsed+[
        'public_visible'=>false,'download_allowed'=>false,'featured'=>false,
        'expected_asset_revision'=>sf_agent_media_link_asset_revision($asset),
        'expected_asset_links_hash'=>sf_agent_media_links_digest($assetLinks),
        'expected_target_source_hash'=>$target['source_hash'],
        'expected_target_links_hash'=>sf_agent_media_links_digest($targetLinks),
    ],$asset,$assetLinks,$target,$targetLinks);
}
function sf_agent_action_normalize_media_private_link(array $raw):array {
    $id=$raw['asset_id']??null;$type=$raw['entity_type']??null;$entityId=$raw['entity_id']??null;
    if(!is_int($id)||$id<1||!is_string($type)||!is_string($entityId))
        throw new InvalidArgumentException('Exact image and target IDs are required.');
    $asset=sf_media_asset($id);if(!$asset)throw new InvalidArgumentException('Media asset not found.');
    $target=sf_agent_media_link_target_snapshot($type,$entityId);
    return sf_agent_media_link_normalize($raw,$asset,sf_agent_media_links($id),$target,
        sf_agent_media_link_target_links($type,$entityId));
}
/** One single new private link, committed only if all approved snapshots still match. */
function sf_agent_media_save_approved_private_link(array $payload):array {
    $id=$payload['asset_id']??null;$type=$payload['entity_type']??null;$targetId=$payload['entity_id']??null;
    if(!is_int($id)||$id<1||!is_string($type)||!is_string($targetId))
        throw new InvalidArgumentException('Exact image and target IDs required.');
    sf_media_ensure_schema();$db=sf_db();$db->beginTransaction();
    try{
        $sql='SELECT * FROM media_assets WHERE id=?';
        if((string)(sf_db_config()['driver']??'')!=='sqlite')$sql.=' FOR UPDATE';
        $q=$db->prepare($sql);$q->execute([$id]);$asset=$q->fetch(PDO::FETCH_ASSOC);
        if(!$asset)throw new RuntimeException('Image asset no longer exists.');
        $target=sf_agent_media_link_target_snapshot($type,$targetId);
        $assetLinks=sf_agent_media_links($id,true);
        $targetLinks=sf_agent_media_link_target_links($type,$targetId,true);
        $draft=sf_agent_media_link_normalize($payload,$asset,$assetLinks,$target,$targetLinks);
        $sort=0;
        foreach($targetLinks as $link)if((string)($link['role']??'')===$draft['role'])
            $sort=max($sort,(int)$link['sort_order']+1);
        sf_media_guard_link_exposure($id,$draft['role'],false,false);
        $now=gmdate('c');
        $insert=$db->prepare('INSERT INTO media_links(asset_id,entity_type,entity_id,role,sort_order,featured,public_visible,download_allowed,link_json,created_at,updated_at) VALUES(?,?,?,?,?,0,0,0,?,?,?)');
        $insert->execute([$id,$type,$targetId,$draft['role'],$sort,'{}',$now,$now]);
        if($insert->rowCount()!==1)throw new RuntimeException('Private attachment creation was not confirmed.');
        $linkId=(int)$db->lastInsertId();
        $check=$db->prepare('SELECT asset_id,entity_type,entity_id,role,featured,public_visible,download_allowed FROM media_links WHERE id=?');
        $check->execute([$linkId]);$row=$check->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['asset_id']!==$id||$row['entity_type']!==$type||$row['entity_id']!==$targetId
           ||$row['role']!==$draft['role']||(int)$row['featured']!==0||(int)$row['public_visible']!==0
           ||(int)$row['download_allowed']!==0)throw new RuntimeException('Private attachment verification failed.');
        if(!hash_equals($draft['expected_target_source_hash'],
            sf_agent_media_link_target_snapshot($type,$targetId)['source_hash']))
            throw new RuntimeException('Target record changed during save.');
        $db->commit();
        return ['link_id'=>$linkId,'asset_id'=>$id,'entity_type'=>$type,'entity_id'=>$targetId,
            'role'=>$draft['role'],'public_visible'=>false,'status'=>'private_attachment_saved'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
