<?php
declare(strict_types=1);
/** Section 91B3B: exact-approved Media Library caption edits, no attachment writes.
 * Production callers already load media-core through the normal API bootstrap;
 * isolated suites may substitute the media handlers without eager loading it.
 */

function sf_agent_media_parse_caption(string $message):?array {
    if(!preg_match('/\A(?:please\s+)?(?:set|update|change)\s+(?:the\s+)?caption\s+(?:for|on)\s+(?:media\s+)?asset\s+#?([1-9][0-9]{0,11})\s+to\s+(.{1,500})\z/isu',trim($message),$m))return null;
    $caption=trim($m[2]);
    return $caption===''?null:['asset_id'=>(int)$m[1],'value'=>$caption];
}
/** 91B3C: accessibility metadata is a separate, image-only approved operation. */
function sf_agent_media_parse_alt_text(string $message):?array {
    if(!preg_match('/\A(?:please\s+)?(?:set|update|change)\s+(?:the\s+)?(?:alt(?:ernative)?\s+text)\s+(?:for|on)\s+(?:media\s+)?asset\s+#?([1-9][0-9]{0,11})\s+to\s+(.{1,500})\z/isu',trim($message),$m))return null;
    $value=trim($m[2]);
    return $value===''?null:['asset_id'=>(int)$m[1],'value'=>$value];
}
function sf_agent_media_alt_revision(array $asset):string {
    return hash('sha256',json_encode([
        'id'=>(int)($asset['id']??0),'uuid'=>(string)($asset['uuid']??''),
        'category'=>(string)($asset['category']??''),'alt_text'=>(string)($asset['alt_text']??''),
        'updated_at'=>(string)($asset['updated_at']??''),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function sf_agent_media_revision(array $asset):string {
    return hash('sha256',json_encode([
        'id'=>(int)($asset['id']??0),'uuid'=>(string)($asset['uuid']??''),
        'caption'=>(string)($asset['caption']??''),'updated_at'=>(string)($asset['updated_at']??''),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function sf_agent_media_links_digest(array $links):string {
    $rows=[];
    foreach($links as $link){
        if(!is_array($link))continue;
        $rows[]=[(int)($link['id']??0),(string)($link['entity_type']??''),
            (string)($link['entity_id']??''),(string)($link['role']??''),
            (int)($link['sort_order']??0),(int)!empty($link['featured']),
            (int)!empty($link['public_visible']),(int)!empty($link['download_allowed']),
            (string)($link['link_json']??''),(string)($link['updated_at']??'')];
    }
    usort($rows,static fn($a,$b)=>$a[0]<=>$b[0]);
    return hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
/** Pure, strict canonicalization; exposed values are the only writable metadata. */
function sf_agent_media_normalize_caption(array $raw,array $asset,array $links):array {
    $allowed=['asset_id','asset_title','field','previous','value','expected_revision','expected_links_hash','attachment_count','public_link_count'];
    if(array_diff(array_keys($raw),$allowed))throw new InvalidArgumentException('Unsupported Media Library action fields.');
    $id=(int)($raw['asset_id']??0);
    if($id<1||$id!==(int)($asset['id']??0)||($raw['field']??'')!=='caption')
        throw new InvalidArgumentException('Only the caption of one existing media asset may be changed.');
    $value=trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',strip_tags((string)($raw['value']??'')))??'');
    if($value===''||strlen($value)>500)throw new InvalidArgumentException('A 1–500 byte caption is required.');
    if($value===(string)($asset['caption']??''))throw new InvalidArgumentException('The proposed caption is unchanged.');
    $revision=sf_agent_media_revision($asset);$linksHash=sf_agent_media_links_digest($links);
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($raw['expected_revision']??''))
        ||!hash_equals((string)$raw['expected_revision'],$revision))
        throw new RuntimeException('Media asset changed; prepare a fresh review.');
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($raw['expected_links_hash']??''))
        ||!hash_equals((string)$raw['expected_links_hash'],$linksHash))
        throw new RuntimeException('Media attachments changed; prepare a fresh review.');
    $public=0;foreach($links as $link)if(is_array($link)&&!empty($link['public_visible']))$public++;
    return ['asset_id'=>$id,'asset_title'=>(string)($asset['title']??$asset['original_name']??''),
        'field'=>'caption','previous'=>(string)($asset['caption']??''),'value'=>$value,
        'attachment_count'=>count($links),'public_link_count'=>$public,
        'expected_revision'=>$revision,'expected_links_hash'=>$linksHash];
}
function sf_agent_media_links(int $id,bool $lock=false):array {
    $sql='SELECT * FROM media_links WHERE asset_id=? ORDER BY id';
    if($lock&&(string)(sf_db_config()['driver']??'')!=='sqlite')$sql.=' FOR UPDATE';
    $q=sf_db()->prepare($sql);$q->execute([$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sf_agent_media_draft_from_chat(string $message):?array {
    $parsed=sf_agent_media_parse_caption($message);if($parsed===null)return null;
    $asset=sf_media_asset($parsed['asset_id']);
    if(!$asset)throw new InvalidArgumentException('That Media Library asset ID was not found.');
    $links=sf_agent_media_links($parsed['asset_id']);
    return sf_agent_media_normalize_caption([
        'asset_id'=>$parsed['asset_id'],'field'=>'caption','value'=>$parsed['value'],
        'expected_revision'=>sf_agent_media_revision($asset),
        'expected_links_hash'=>sf_agent_media_links_digest($links),
    ],$asset,$links);
}
function sf_agent_action_normalize_media_caption(array $raw):array {
    $id=(int)($raw['asset_id']??0);if($id<1)throw new InvalidArgumentException('A real media asset ID is required.');
    $asset=sf_media_asset($id);if(!$asset)throw new InvalidArgumentException('Media asset not found.');
    return sf_agent_media_normalize_caption($raw,$asset,sf_agent_media_links($id));
}
/** Independent canonical contract: never let an alt-text payload change a caption or links. */
function sf_agent_media_normalize_alt_text(array $raw,array $asset,array $links):array {
    $allowed=['asset_id','asset_title','field','previous','value','expected_revision','expected_links_hash','attachment_count','public_link_count'];
    if(array_diff(array_keys($raw),$allowed))throw new InvalidArgumentException('Unsupported Media Library accessibility fields.');
    $id=(int)($raw['asset_id']??0);
    if($id<1||$id!==(int)($asset['id']??0)||($raw['field']??'')!=='alt_text'
       ||(string)($asset['category']??'')!=='image')
        throw new InvalidArgumentException('Only alt text on one existing image asset may be changed.');
    if(!is_string($raw['value']??null))throw new InvalidArgumentException('Image alt text must be text.');
    $value=trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',strip_tags($raw['value']))??'');
    if($value===''||strlen($value)>500)throw new InvalidArgumentException('A 1–500 byte image alt text is required.');
    if($value===(string)($asset['alt_text']??''))throw new InvalidArgumentException('Image alt text is unchanged.');
    $revision=sf_agent_media_alt_revision($asset);$linksHash=sf_agent_media_links_digest($links);
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($raw['expected_revision']??''))
       ||!hash_equals((string)$raw['expected_revision'],$revision))
        throw new RuntimeException('Image metadata changed; prepare a fresh review.');
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($raw['expected_links_hash']??''))
       ||!hash_equals((string)$raw['expected_links_hash'],$linksHash))
        throw new RuntimeException('Media attachments changed; prepare a fresh review.');
    $public=0;foreach($links as $link)if(is_array($link)&&!empty($link['public_visible']))$public++;
    return ['asset_id'=>$id,'asset_title'=>(string)($asset['title']??$asset['original_name']??''),
        'field'=>'alt_text','previous'=>(string)($asset['alt_text']??''),'value'=>$value,
        'attachment_count'=>count($links),'public_link_count'=>$public,
        'expected_revision'=>$revision,'expected_links_hash'=>$linksHash];
}
function sf_agent_media_alt_draft_from_chat(string $message):?array {
    $parsed=sf_agent_media_parse_alt_text($message);if($parsed===null)return null;
    $asset=sf_media_asset($parsed['asset_id']);
    if(!$asset)throw new InvalidArgumentException('That Media Library asset ID was not found.');
    $links=sf_agent_media_links($parsed['asset_id']);
    return sf_agent_media_normalize_alt_text([
        'asset_id'=>$parsed['asset_id'],'field'=>'alt_text','value'=>$parsed['value'],
        'expected_revision'=>sf_agent_media_alt_revision($asset),
        'expected_links_hash'=>sf_agent_media_links_digest($links),
    ],$asset,$links);
}
function sf_agent_action_normalize_media_alt_text(array $raw):array {
    $id=(int)($raw['asset_id']??0);if($id<1)throw new InvalidArgumentException('A real media asset ID is required.');
    $asset=sf_media_asset($id);if(!$asset)throw new InvalidArgumentException('Media asset not found.');
    return sf_agent_media_normalize_alt_text($raw,$asset,sf_agent_media_links($id));
}
/** The ledger claims execution before this transaction starts. */
function sf_agent_media_save_approved_caption(array $payload):array {
    $id=(int)($payload['asset_id']??0);if($id<1)throw new InvalidArgumentException('Asset ID missing.');
    sf_media_ensure_schema();$db=sf_db();
    // PDO must own transaction state for both SQLite fixture and production
    // MySQL so commit/rollback are reliable; the conditional UPDATE is CAS.
    $db->beginTransaction();
    try{
        $sql='SELECT * FROM media_assets WHERE id=?';
        if((string)(sf_db_config()['driver']??'')!=='sqlite')$sql.=' FOR UPDATE';
        $q=$db->prepare($sql);$q->execute([$id]);$asset=$q->fetch(PDO::FETCH_ASSOC);
        if(!$asset)throw new RuntimeException('Media asset no longer exists.');
        $draft=sf_agent_media_normalize_caption($payload,$asset,sf_agent_media_links($id,true));
        $now=gmdate('c');
        $update=$db->prepare('UPDATE media_assets SET caption=?,updated_at=? WHERE id=? AND caption=? AND updated_at=?');
        $update->execute([$draft['value'],$now,$id,$draft['previous'],(string)$asset['updated_at']]);
        if($update->rowCount()!==1)throw new RuntimeException('Media asset changed before save.');
        $check=$db->prepare('SELECT caption FROM media_assets WHERE id=?');$check->execute([$id]);
        if((string)$check->fetchColumn()!==$draft['value'])throw new RuntimeException('Saved caption could not be verified.');
        $db->commit();
        return ['asset_id'=>$id,'field'=>'caption','status'=>'metadata_saved','public_link_count'=>$draft['public_link_count']];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Ledger claimed execution before entry. Compare-and-set inside DB-owned transaction. */
function sf_agent_media_save_approved_alt_text(array $payload):array {
    $id=(int)($payload['asset_id']??0);if($id<1)throw new InvalidArgumentException('Asset ID missing.');
    sf_media_ensure_schema();$db=sf_db();$db->beginTransaction();
    try{
        $sql='SELECT * FROM media_assets WHERE id=?';
        if((string)(sf_db_config()['driver']??'')!=='sqlite')$sql.=' FOR UPDATE';
        $q=$db->prepare($sql);$q->execute([$id]);$asset=$q->fetch(PDO::FETCH_ASSOC);
        if(!$asset)throw new RuntimeException('Media asset no longer exists.');
        $draft=sf_agent_media_normalize_alt_text($payload,$asset,sf_agent_media_links($id,true));
        $update=$db->prepare("UPDATE media_assets SET alt_text=?,updated_at=? WHERE id=? AND category='image' AND alt_text=? AND updated_at=?");
        $update->execute([$draft['value'],gmdate('c'),$id,$draft['previous'],(string)$asset['updated_at']]);
        if($update->rowCount()!==1)throw new RuntimeException('Image asset changed before save.');
        $check=$db->prepare('SELECT alt_text FROM media_assets WHERE id=?');$check->execute([$id]);
        if((string)$check->fetchColumn()!==$draft['value'])throw new RuntimeException('Saved alt text could not be verified.');
        $db->commit();
        return ['asset_id'=>$id,'field'=>'alt_text','status'=>'metadata_saved',
            'public_link_count'=>$draft['public_link_count']];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
