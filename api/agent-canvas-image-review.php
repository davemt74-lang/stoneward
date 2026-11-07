<?php
declare(strict_types=1);

require_once __DIR__.'/agent-scene-requests.php';
require_once __DIR__.'/agent-canvas-images.php';

function sf_agent_canvas_image_review_ensure_schema(): void {
    static $ready=false;
    if($ready)return;
    $db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize image review before opening a transaction.');
    sf_agent_scene_request_ensure_schema();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_image_reviews (
            user_id INTEGER NOT NULL,request_id TEXT NOT NULL,scene_json TEXT NOT NULL,
            previous_id INTEGER NULL,candidates_json TEXT NOT NULL,selected_image_id TEXT NULL,
            PRIMARY KEY(user_id,request_id),
            FOREIGN KEY(user_id,request_id) REFERENCES agent_scene_requests(user_id,request_id) ON DELETE CASCADE)');
    }else{
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_image_reviews (
            user_id BIGINT UNSIGNED NOT NULL,request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            scene_json MEDIUMTEXT NOT NULL,previous_id BIGINT UNSIGNED NULL,candidates_json TEXT NOT NULL,
            selected_image_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            PRIMARY KEY(user_id,request_id),
            CONSTRAINT fk_canvas_image_review_request FOREIGN KEY(user_id,request_id)
                REFERENCES agent_scene_requests(user_id,request_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    $ready=true;
}

/** Join the caller's token settlement so an unpublished draft and its charge settle together. */
function sf_agent_canvas_image_review_stage(int $userId,string $requestId,array $scene,?int $previousId,
    int $historyEpoch,array $generatedImages,int $used,?int $remaining): array {
    sf_agent_scene_request_id($requestId);
    $db=sf_db();
    if(!$db->inTransaction())throw new LogicException('Stage image review in the token settlement transaction.');
    sf_agent_scene_history_lock_account($db,$userId);
    if($userId<1||$historyEpoch<0||$used<0||($remaining!==null&&$remaining<0)||
        ($previousId!==null&&$previousId<1)||($scene['canvas_kind']??null)!=='custom'||
        sf_agent_scene_code($scene)===null||!array_is_list($generatedImages)||
        count($generatedImages)<1||count($generatedImages)>SF_AGENT_CANVAS_IMAGE_LIMIT)
        throw new InvalidArgumentException('Invalid image variation review.');
    $candidates=[];
    $batch=sf_agent_canvas_image_batch_ids($requestId);
    foreach($generatedImages as $offset=>$image){
        $id=$image['id']??null;
        if($id!==$batch[$offset]||($asset=sf_agent_canvas_image_get($userId,$id))===null)
            throw new InvalidArgumentException('A generated image is unavailable for review.');
        $candidates[]=['id'=>$id,'title'=>$asset['title'],'index'=>$offset+1];
    }
    $bindings=array_intersect(sf_agent_canvas_image_bindings($scene),$batch);
    if(array_values($bindings)!==[$candidates[0]['id']]||sf_agent_scene_history_epoch($userId)!==$historyEpoch)
        throw new InvalidArgumentException('The image variation draft is no longer available.');
    if($previousId!==null&&sf_agent_scene_history_get($userId,$previousId)===null)
        throw new InvalidArgumentException('Choose your saved canvas to revise.');
    $q=$db->prepare("UPDATE agent_scene_requests SET state='review',active_tokens_used=?,active_tokens_remaining=?,design_source='model'
        WHERE user_id=? AND request_id=? AND state='pending' AND history_epoch=? AND created_at>(? + 0)
        AND EXISTS (SELECT 1 FROM users WHERE id=? AND status='active')");
    $q->execute([$used,$remaining,$userId,$requestId,$historyEpoch,time()-SF_AGENT_SCENE_REQUEST_TTL,$userId]);
    if($q->rowCount()!==1)throw new InvalidArgumentException('The image variation request is no longer available.');
    $q=$db->prepare('INSERT INTO agent_canvas_image_reviews(user_id,request_id,scene_json,previous_id,candidates_json)
        VALUES(?,?,?,?,?)');
    $q->execute([$userId,$requestId,json_encode($scene,JSON_THROW_ON_ERROR),$previousId,json_encode($candidates,JSON_THROW_ON_ERROR)]);
    return ['state'=>'review','generated_images'=>$candidates,'image_total'=>count($candidates),
        'active_tokens_used'=>$used,'active_tokens_remaining'=>$remaining,'design_source'=>'model'];
}

function sf_agent_canvas_image_review_result(int $userId,string $requestId): array {
    sf_agent_scene_request_id($requestId);
    sf_agent_canvas_image_review_ensure_schema();
    sf_agent_canvas_image_ensure_schema();
    $q=sf_db()->prepare("SELECT r.candidates_json,q.active_tokens_used,q.active_tokens_remaining,q.history_epoch
        FROM agent_canvas_image_reviews r JOIN agent_scene_requests q ON q.user_id=r.user_id AND q.request_id=r.request_id
        JOIN users u ON u.id=r.user_id AND u.status='active'
        WHERE r.user_id=? AND r.request_id=? AND q.state='review'");
    $q->execute([$userId,$requestId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||(int)$row['history_epoch']!==sf_agent_scene_history_epoch($userId))return ['state'=>'failed'];
    $candidates=json_decode($row['candidates_json'],true,8,JSON_THROW_ON_ERROR);
    $images=[];
    foreach($candidates as $candidate){
        $asset=sf_agent_canvas_image_get($userId,$candidate['id']);
        if($asset!==null&&sf_agent_canvas_image_file($userId,$candidate['id'])!==null)
            $images[]=['id'=>$asset['id'],'title'=>$asset['title'],'index'=>$candidate['index']];
    }
    if(!$images)return ['state'=>'failed'];
    return ['state'=>'review','generated_images'=>$images,'image_total'=>count($images),
        'active_tokens_used'=>(int)$row['active_tokens_used'],
        'active_tokens_remaining'=>$row['active_tokens_remaining']===null?null:(int)$row['active_tokens_remaining'],
        'design_source'=>'model'];
}

/** A first write serializes SQLite selections with cancellation and privacy clearing. */
function sf_agent_canvas_image_request_lock(PDO $db,int $userId,string $requestId): void {
    sf_agent_scene_history_lock_account($db,$userId);
    $db->prepare('UPDATE agent_scene_requests SET state=state WHERE user_id=? AND request_id=?')->execute([$userId,$requestId]);
}

function sf_agent_canvas_image_review_choose(int $userId,string $requestId,string $imageId): array {
    sf_agent_scene_request_id($requestId);
    if(!sf_agent_canvas_image_id($imageId))throw new InvalidArgumentException('Choose an available image variation.');
    sf_agent_canvas_image_review_ensure_schema();
    sf_agent_canvas_image_ensure_schema();
    $db=sf_db();
    $preflight=$db->prepare('SELECT scene_json FROM agent_canvas_image_reviews WHERE user_id=? AND request_id=? AND selected_image_id IS NULL');
    $preflight->execute([$userId,$requestId]);
    $candidate=$preflight->fetchColumn();
    $preflight->closeCursor();
    if(is_string($candidate)){
        $scene=json_decode($candidate,true,16,JSON_THROW_ON_ERROR);
        sf_canvas_resource_prepare($scene['artist_inputs']??null);
    }
    $db->beginTransaction();
    try{
        sf_agent_canvas_image_request_lock($db,$userId,$requestId);
        $q=$db->prepare('SELECT r.scene_json,r.previous_id,r.candidates_json,r.selected_image_id,
            q.state,q.history_epoch,q.active_tokens_used,q.active_tokens_remaining
            FROM agent_canvas_image_reviews r JOIN agent_scene_requests q ON q.user_id=r.user_id AND q.request_id=r.request_id
            JOIN users u ON u.id=r.user_id AND u.status=\'active\' WHERE r.user_id=? AND r.request_id=?');
        $q->execute([$userId,$requestId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||!in_array($row['state'],['review','ready'],true)||
            (int)$row['history_epoch']!==sf_agent_scene_history_epoch($userId))
            throw new InvalidArgumentException('This image variation review is no longer available.');
        $candidates=json_decode($row['candidates_json'],true,8,JSON_THROW_ON_ERROR);
        if(!in_array($imageId,array_column($candidates,'id'),true))
            throw new InvalidArgumentException('Choose an available image variation.');
        if($row['state']==='ready'){
            if($row['selected_image_id']!==$imageId)throw new InvalidArgumentException('An image variation has already been selected.');
        }else{
            $scene=json_decode($row['scene_json'],true,16,JSON_THROW_ON_ERROR);
            sf_canvas_resource_validate_scopes($scene['artist_inputs']??null);
            if(sf_agent_canvas_image_file($userId,$imageId)===null)
                throw new InvalidArgumentException('Choose an available image variation.');
            $scene=sf_agent_canvas_image_apply($scene,$imageId,$candidates[0]['id']);
            $stored=sf_agent_scene_history_save($userId,$scene,$row['previous_id']===null?null:(int)$row['previous_id'],(int)$row['history_epoch']);
            sf_agent_scene_request_finish($userId,$requestId,$stored['id'],(int)$row['active_tokens_used'],
                $row['active_tokens_remaining']===null?null:(int)$row['active_tokens_remaining'],'model','review');
            $db->prepare('UPDATE agent_canvas_image_reviews SET selected_image_id=? WHERE user_id=? AND request_id=?')
                ->execute([$imageId,$userId,$requestId]);
        }
        $db->commit();
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();
        throw $error;
    }
    return sf_agent_scene_request_result($userId,$requestId)??['state'=>'failed'];
}

function sf_agent_canvas_image_swap(int $userId,array $request): array {
    require_once __DIR__.'/agent-canvas-image-inputs.php';
    $requestId=sf_agent_scene_request_id($request['request_id']??null);
    $previousId=$request['previous_id']??null;
    $imageId=$request['image_id']??null;
    $replacementId=$request['replacement_id']??null;
    if(!is_int($previousId)||$previousId<1||!sf_agent_canvas_image_asset_id($imageId)||
        !sf_agent_canvas_image_asset_id($replacementId)||$imageId===$replacementId)
        throw new InvalidArgumentException('Choose a canvas image and a different replacement.');
    sf_agent_canvas_image_ensure_schema();
    // Initialize reference lookups and balance storage before beginning the revision transaction.
    sf_agent_canvas_image_asset_get($userId,$replacementId);
    sf_entitlements_ensure_schema();
    $fingerprint=hash('sha256',serialize(['swap_image',$previousId,$imageId,$replacementId]));
    return sf_agent_scene_run_request($userId,$requestId,$fingerprint,
        static function(int $epoch)use($userId,$requestId,$previousId,$imageId,$replacementId):array {
            $db=sf_db();
            $db->beginTransaction();
            try{
                sf_agent_canvas_image_request_lock($db,$userId,$requestId);
                $original=sf_agent_scene_history_get($userId,$previousId);
                if(($original['canvas_kind']??null)!=='custom'||sf_agent_scene_code($original)===null||
                    !in_array($imageId,sf_agent_canvas_image_bindings($original),true))
                    throw new InvalidArgumentException('Choose an image placed on your saved canvas.');
                if(str_starts_with($replacementId,'ref-')&&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
                    $q=$db->prepare('SELECT id FROM agent_canvas_reference_images WHERE id=? FOR UPDATE');
                    $q->execute([$replacementId]);
                    if($q->fetchColumn()===false)throw new InvalidArgumentException('The replacement image is unavailable for your account.');
                }
                if(sf_agent_canvas_image_asset_get($userId,$replacementId)===null)
                    throw new InvalidArgumentException('The replacement image is unavailable for your account.');
                $file=sf_agent_canvas_image_asset_file($userId,$replacementId);
                if($file===null)throw new InvalidArgumentException('The replacement image could not be read.');
                $scene=sf_agent_canvas_image_apply($original,$replacementId,$imageId);
                $stored=sf_agent_scene_history_save($userId,$scene,$previousId,$epoch);
                $q=$db->prepare('SELECT role FROM users WHERE id=?');$q->execute([$userId]);
                $admin=$q->fetchColumn()==='admin';
                $q=$db->prepare('SELECT balance FROM user_token_balances WHERE user_id=?');$q->execute([$userId]);
                $remaining=$admin?null:max(0,(int)$q->fetchColumn());
                $source=sf_agent_scene_request_design_source($userId,$previousId);
                sf_agent_scene_request_finish($userId,$requestId,$stored['id'],0,$remaining,$source);
                $db->commit();
            }catch(Throwable $error){
                if($db->inTransaction())$db->rollBack();
                throw $error;
            }
            return sf_agent_scene_request_result($userId,$requestId)??['state'=>'failed'];
        });
}
