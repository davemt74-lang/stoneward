<?php
declare(strict_types=1);

/** 91B20I: private references only; never generates, publishes or deletes source media. */
function sf_image_studio_moodboard_schema(PDO $pdo, bool $sqlite): void {
    require_once __DIR__.'/media-core.php';
    sf_media_ensure_schema(); // All DDL stays outside write transactions (MySQL implicit commits).
    if ($sqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS image_studio_moodboard (
            id INTEGER PRIMARY KEY AUTOINCREMENT, style_id INTEGER NOT NULL,
            asset_id INTEGER NOT NULL, position INTEGER NOT NULL,
            caption TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL,
            UNIQUE(style_id,asset_id),
            FOREIGN KEY(style_id) REFERENCES image_studio_styles(id) ON DELETE CASCADE,
            FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE CASCADE)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_moodboard_order ON image_studio_moodboard(style_id,position,id)');
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS image_studio_moodboard (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            style_id BIGINT UNSIGNED NOT NULL, asset_id BIGINT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL, caption VARCHAR(160) NOT NULL DEFAULT '',
            created_at VARCHAR(40) NOT NULL, UNIQUE KEY uq_moodboard_asset(style_id,asset_id),
            INDEX idx_moodboard_order(style_id,position,id),
            CONSTRAINT fk_moodboard_style FOREIGN KEY(style_id) REFERENCES image_studio_styles(id) ON DELETE CASCADE,
            CONSTRAINT fk_moodboard_asset FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
function sf_image_studio_moodboard_items(int $styleId): array {
    $q=sf_db()->prepare('SELECT b.id,b.asset_id,b.position,b.caption,a.uuid,a.title,a.original_name,a.technical_json
        FROM image_studio_moodboard b JOIN media_assets a ON a.id=b.asset_id
        WHERE b.style_id=? ORDER BY b.position,b.id LIMIT 8');
    $q->execute([$styleId]);$items=[];
    foreach($q->fetchAll() as $row){
        // A previously approved generated image may later lose approval. Never return its preview.
        $approved=true;
        if((json_decode((string)$row['technical_json'],true)['source']??'')==='openai_image_generation'){
            try{sf_media_guard_generated_image_approval((int)$row['asset_id']);}
            catch(RuntimeException $e){$approved=false;}
        }
        $items[]=['id'=>(int)$row['id'],'asset_id'=>(int)$row['asset_id'],
            'position'=>(int)$row['position'],'caption'=>(string)$row['caption'],
            'uuid'=>$approved?(string)$row['uuid']:'','available'=>$approved,
            'title'=>$approved?((string)$row['title']?:$row['original_name']):'Approval required'];
    }
    return $items;
}
/** Serialization also protects the cap. SQLite obtains its write lock BEFORE reading the count. */
function sf_image_studio_moodboard_lock(PDO $pdo,int $styleId): void {
    if($styleId<1)throw new InvalidArgumentException('Choose an active visual style.');
    $mysql=(sf_db_config()['driver']??'')==='mysql';
    if(!$mysql){
        $q=$pdo->prepare('UPDATE image_studio_styles SET is_active=is_active WHERE id=? AND is_active=1');
        $q->execute([$styleId]);
    }
    $q=$pdo->prepare('SELECT id FROM image_studio_styles WHERE id=? AND is_active=1'.($mysql?' FOR UPDATE':''));
    $q->execute([$styleId]);
    if(!$q->fetch())throw new InvalidArgumentException('This visual style is unavailable or archived.');
}
/** Single transaction for reference mutation AND the official Admin audit receipt. */
function sf_image_studio_moodboard_change(int $adminId,int $styleId,string $action,array $input): array {
    if($adminId<1)throw new InvalidArgumentException('Administrator identity is required.');
    if(!in_array($action,['board_add','board_remove','board_move'],true))
        throw new InvalidArgumentException('Unsupported mood-board operation.');
    $assetId=(int)($input['asset_id']??0);$itemId=(int)($input['item_id']??0);
    $direction=(string)($input['direction']??'');
    $caption=sf_clean_text((string)($input['caption']??''),160);
    if($action==='board_remove'&&($input['confirmed']??false)!==true)
        throw new InvalidArgumentException('Confirm removal of this private mood-board reference.');
    if($action==='board_move'&&!in_array($direction,['up','down'],true))
        throw new InvalidArgumentException('Choose up or down.');
    sf_image_studio_style_schema();sf_ops_ensure_schema();
    // Validate the existing picker allowlist before entering a transaction: it may initialize schema.
    if($action==='board_add'){
        $eligible=false;
        foreach(sf_image_gen_available_references() as $asset)
            if((int)$asset['id']===$assetId){$eligible=true;break;}
        if(!$eligible)throw new InvalidArgumentException('Choose an eligible approved Media Library reference image.');
    }
    $pdo=sf_db();$pdo->beginTransaction();
    try{
        sf_image_studio_moodboard_lock($pdo,$styleId);
        $q=$pdo->prepare('SELECT id,asset_id FROM image_studio_moodboard WHERE style_id=? ORDER BY position,id');
        $q->execute([$styleId]);$rows=$q->fetchAll();$changed=false;
        if($action==='board_add'){
            // Revalidate approval inside the locked transaction without initializing schema.
            sf_media_guard_generated_image_approval($assetId);
            foreach($rows as $row)if((int)$row['asset_id']===$assetId)
                throw new InvalidArgumentException('This image is already on the mood board.');
            if(count($rows)>=8)throw new InvalidArgumentException('Each style can have at most eight reference images.');
            $q=$pdo->prepare('INSERT INTO image_studio_moodboard(style_id,asset_id,position,caption,created_at) VALUES(?,?,?,?,?)');
            $q->execute([$styleId,$assetId,count($rows)+1,$caption,gmdate('c')]);
            $itemId=(int)$pdo->lastInsertId();$rows[]=['id'=>$itemId,'asset_id'=>$assetId];$changed=true;
        }else{
            $index=array_search($itemId,array_map(static fn($r)=>(int)$r['id'],$rows),true);
            if($index===false)throw new InvalidArgumentException('Reference not found on this mood board.');
            $assetId=(int)$rows[$index]['asset_id'];
            if($action==='board_remove'){
                $q=$pdo->prepare('DELETE FROM image_studio_moodboard WHERE id=? AND style_id=?');
                $q->execute([$itemId,$styleId]);array_splice($rows,$index,1);$changed=true;
            }else{
                $next=$index+($direction==='up'?-1:1);
                if($next>=0&&$next<count($rows)){
                    [$rows[$index],$rows[$next]]=[$rows[$next],$rows[$index]];$changed=true;
                }
            }
        }
        if($changed){
            $q=$pdo->prepare('UPDATE image_studio_moodboard SET position=? WHERE style_id=? AND id=?');
            foreach($rows as $i=>$row)$q->execute([$i+1,$styleId,(int)$row['id']]);
            $event=['board_add'=>'image_style_board_added','board_remove'=>'image_style_board_removed','board_move'=>'image_style_board_moved'][$action];
            sf_log_admin_action($adminId,$event,'image_studio_style',(string)$styleId,
                ['item_id'=>$itemId,'asset_id'=>$assetId,'direction'=>$direction,'board_size'=>count($rows),'private'=>true]);
        }
        $items=sf_image_studio_moodboard_items($styleId);
        $pdo->commit();return $items;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
