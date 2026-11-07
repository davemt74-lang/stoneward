<?php
declare(strict_types=1);
require_once __DIR__.'/canvas-build-workflow-core.php';

final class SfCanvasGalleryConflict extends RuntimeException {}
final class SfCanvasGalleryNotFound extends RuntimeException {}

function sf_canvas_gallery_id(mixed $value): string {
    if(!is_string($value)||preg_match('/\A[a-f0-9]{32}\z/',$value)!==1)throw new InvalidArgumentException('Choose a canvas gallery entry.');return $value;
}
function sf_canvas_gallery_text(mixed $value,int $maximum,bool $required=false): string {
    if(!is_string($value)||strlen($value)>$maximum||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)!==0)
        throw new InvalidArgumentException('Use valid text within the gallery field limits.');
    $value=trim(str_replace(["\r\n","\r"],"\n",$value));if($required&&$value==='')throw new InvalidArgumentException('Complete the canvas title and creator label.');return $value;
}
function sf_canvas_gallery_exists(string $table='canvas_gallery_entries'): bool {
    if(!in_array($table,['canvas_gallery_entries','canvas_gallery_remixes'],true))return false;$db=sf_db();
    $q=$db->prepare($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?":'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$table]);$exists=$q->fetchColumn()!==false;$q->closeCursor();return $exists;
}
function sf_canvas_gallery_ensure_schema(): void {
    static $ready=false;if($ready)return;$db=sf_db();if($db->inTransaction())throw new LogicException('Initialize Canvas Gallery before a transaction.');
    sf_agent_scene_history_ensure_schema();sf_privacy_ensure_schema();sf_agent_canvas_image_ensure_schema();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec("CREATE TABLE IF NOT EXISTS canvas_gallery_entries(id TEXT PRIMARY KEY,user_id INTEGER NOT NULL,source_scene_id INTEGER NOT NULL,request_id TEXT NOT NULL,request_fingerprint TEXT NOT NULL,history_epoch INTEGER NOT NULL,status TEXT NOT NULL,title TEXT NOT NULL,description TEXT NOT NULL,creator_label TEXT NOT NULL,snapshot_json TEXT NOT NULL,attribution_json TEXT NULL,assets_json TEXT NOT NULL,snapshot_hash TEXT NOT NULL,preview_asset_id TEXT NULL,feedback TEXT NOT NULL DEFAULT '',reviewed_by INTEGER NULL,submitted_at TEXT NOT NULL,reviewed_at TEXT NULL,published_at TEXT NULL,updated_at TEXT NOT NULL,UNIQUE(user_id,request_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_canvas_gallery_status ON canvas_gallery_entries(status,submitted_at,id)');
        $db->exec("CREATE TABLE IF NOT EXISTS canvas_gallery_remixes(user_id INTEGER NOT NULL,request_id TEXT NOT NULL,entry_id TEXT NOT NULL,scene_id INTEGER NOT NULL,history_epoch INTEGER NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(user_id,request_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS canvas_gallery_entries(id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,source_scene_id BIGINT UNSIGNED NOT NULL,request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,request_fingerprint CHAR(64) NOT NULL,history_epoch BIGINT UNSIGNED NOT NULL,status VARCHAR(16) NOT NULL,title VARCHAR(240) NOT NULL,description TEXT NOT NULL,creator_label VARCHAR(120) NOT NULL,snapshot_json MEDIUMTEXT NOT NULL,attribution_json TEXT NULL,assets_json MEDIUMTEXT NOT NULL,snapshot_hash CHAR(64) NOT NULL,preview_asset_id CHAR(32) NULL,feedback TEXT NOT NULL,reviewed_by BIGINT UNSIGNED NULL,submitted_at VARCHAR(40) NOT NULL,reviewed_at VARCHAR(40) NULL,published_at VARCHAR(40) NULL,updated_at VARCHAR(40) NOT NULL,UNIQUE KEY idx_canvas_gallery_owner_request(user_id,request_id),INDEX idx_canvas_gallery_status(status,submitted_at,id),CONSTRAINT fk_canvas_gallery_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS canvas_gallery_remixes(user_id BIGINT UNSIGNED NOT NULL,request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,entry_id CHAR(32) NOT NULL,scene_id BIGINT UNSIGNED NOT NULL,history_epoch BIGINT UNSIGNED NOT NULL,created_at VARCHAR(40) NOT NULL,PRIMARY KEY(user_id,request_id),CONSTRAINT fk_canvas_gallery_remix_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $sqlite=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';$columns=$db->query($sqlite?'PRAGMA table_info(canvas_gallery_entries)':'SHOW COLUMNS FROM canvas_gallery_entries')->fetchAll(PDO::FETCH_COLUMN,$sqlite?1:0);
    if(!in_array('attribution_json',$columns,true)){
        try{$db->exec('ALTER TABLE canvas_gallery_entries ADD COLUMN attribution_json TEXT NULL');}
        catch(PDOException $error){$columns=$db->query($sqlite?'PRAGMA table_info(canvas_gallery_entries)':'SHOW COLUMNS FROM canvas_gallery_entries')->fetchAll(PDO::FETCH_COLUMN,$sqlite?1:0);if(!in_array('attribution_json',$columns,true))throw $error;}
    }$ready=true;
}
function sf_canvas_gallery_row(string $id,bool $lock=false): ?array {
    if(!sf_canvas_gallery_exists())return null;$db=sf_db();$q=$db->prepare('SELECT * FROM canvas_gallery_entries WHERE id=?'.($lock&&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}
/** Internal asset authorization projection. Never send this object as a public DTO. */
function sf_canvas_gallery_asset_entry(string $id): ?array {
    sf_canvas_gallery_id($id);$row=sf_canvas_gallery_row($id);return $row?['id'=>$id,'user_id'=>(int)$row['user_id'],'status'=>$row['status'],'assets'=>json_decode($row['assets_json'],true,8,JSON_THROW_ON_ERROR)]:null;
}
function sf_canvas_gallery_snapshot(array $scene,string $title): array {
    if(($scene['canvas_kind']??null)!=='custom'||($code=sf_agent_scene_code($scene))===null)throw new InvalidArgumentException('Choose a saved custom canvas.');
    $snapshot=['canvas_kind'=>'custom','track_id'=>'','title'=>$title,'html'=>$scene['html'],'css'=>$scene['css'],'catalog_refs'=>sf_agent_canvas_catalog_refs('',$scene['catalog_refs']??[])];
    if(!empty($scene['js'])){$snapshot['js']=$scene['js'];$snapshot['js_hash']=base64_encode(hash('sha256',$scene['js'],true));}
    if(isset($scene['agent_design'])){if(!in_array($scene['agent_design'],['hidden','square'],true))throw new InvalidArgumentException('Invalid agent design.');$snapshot['agent_design']=$scene['agent_design'];}
    if(isset($scene['agent_palette']))$snapshot['agent_palette']=sf_agent_scene_palette($scene['agent_palette']);
    if(array_key_exists('music_inputs',$scene))$snapshot['music_inputs']=sf_agent_canvas_music_inputs($scene['music_inputs']);
    if(array_key_exists('artist_inputs',$scene))$snapshot['artist_inputs']=sf_canvas_resource_artist_inputs($scene['artist_inputs']);
    if(isset($scene['playlist_inputs'])){
        $inputs=sf_canvas_playlists_inputs($scene['playlist_inputs']);$manifest=sf_canvas_playlists_manifest_validate($scene['playlist_manifest']??null);
        if($manifest===null)throw new InvalidArgumentException('This canvas needs its reviewed playlist snapshot.');
        foreach($manifest['playlists'] as $playlist)if($playlist['source']==='own')throw new InvalidArgumentException('This canvas contains private playlist references. Revise it to use public artist playlists or direct song selections before submitting.');
        $snapshot['playlist_inputs']=$inputs;$snapshot['playlist_manifest']=$manifest;
        $snapshot['direct_music_inputs']=sf_agent_canvas_music_inputs($scene['direct_music_inputs']??null);
    }

    if(isset($scene['gallery_attribution']))$snapshot['gallery_attribution']=sf_agent_scene_gallery_attribution($scene['gallery_attribution']);
    return $snapshot;
}
function sf_canvas_gallery_validate_scopes(array $snapshot,int $uid=0): void {
    if(isset($snapshot['music_inputs']))sf_agent_canvas_music_validate($snapshot['music_inputs']);
    if(isset($snapshot['playlist_inputs']))sf_canvas_playlists_compile(0,$snapshot['playlist_inputs'],$snapshot['playlist_manifest']??null);
    sf_canvas_resource_validate($uid,$snapshot['artist_inputs']??null);
    foreach(sf_agent_canvas_catalog_blocks($snapshot['catalog_refs']??[],false) as $block)if(empty($block['available']))throw new InvalidArgumentException('A selected public artist resource is no longer available.');
}
function sf_canvas_gallery_eligible(int $uid,array $scene): bool {
    if(($scene['canvas_kind']??null)!=='custom'||sf_agent_scene_code($scene)===null)return false;
    $workflow=sf_canvas_build_workflow_for_scene($uid,$scene['id']);
    if($workflow!==null)return $workflow['candidate_id']===$scene['id']&&in_array($workflow['state'],['ready','applied'],true);
    return ($scene['workflow_required']??false)!==true||sf_canvas_build_workflow_verified_derivative($uid,$scene)!==null;
}
function sf_canvas_gallery_public(array $row,bool $private=false): array {
    $attribution=isset($row['attribution_json'])?sf_agent_scene_gallery_attribution(json_decode($row['attribution_json'],true,12,JSON_THROW_ON_ERROR)):null;
    $item=['id'=>$row['id'],'title'=>$row['title'],'description'=>$row['description'],'creator_label'=>$row['creator_label'],
        'preview_url'=>$row['preview_asset_id']===null?null:'api/canvas-gallery.php?action=asset&id='.$row['id'].'&asset_id='.$row['preview_asset_id'],
        'submitted_at'=>$row['submitted_at'],'published_at'=>$row['published_at']];
    require_once __DIR__.'/canvas-access-core.php';
    $snapshot=$row['snapshot_json']??null;
    if($snapshot===null){$q=sf_db()->prepare('SELECT snapshot_json FROM canvas_gallery_entries WHERE id=?');$q->execute([$row['id']]);$snapshot=$q->fetchColumn();$q->closeCursor();}
    $source=is_string($snapshot)?json_decode($snapshot,true,16,JSON_THROW_ON_ERROR):[];
    $item['uses']=sf_canvas_access_public_uses((string)($source['html']??''));
    if($attribution!==null)$item['attribution']=$attribution;
    if($private)$item+=['status'=>$row['status'],'feedback'=>$row['feedback'],'snapshot_hash'=>$row['snapshot_hash'],'source_scene_id'=>(int)$row['source_scene_id'],'reviewed_at'=>$row['reviewed_at'],'updated_at'=>$row['updated_at']];
    return $item;
}
function sf_canvas_gallery_available(string $id): bool {
    $q=sf_db()->prepare("SELECT 1 FROM canvas_gallery_entries e JOIN users u ON u.id=e.user_id WHERE e.id=? AND u.status='active'");$q->execute([$id]);$active=$q->fetchColumn()!==false;$q->closeCursor();
    if(!$active)return false;
    $q=sf_db()->prepare('SELECT snapshot_json FROM canvas_gallery_entries WHERE id=?');$q->execute([$id]);$snapshot=json_decode((string)$q->fetchColumn(),true,16,JSON_THROW_ON_ERROR);$q->closeCursor();
    if(isset($snapshot['playlist_inputs'])){try{sf_canvas_playlists_compile(0,$snapshot['playlist_inputs'],$snapshot['playlist_manifest']??null);}catch(Throwable $e){return false;}}
    require_once __DIR__.'/canvas-gallery-assets.php';return sf_canvas_gallery_assets_references_available($id);
}
function sf_canvas_gallery_list(?int $uid=null,bool $admin=false,array $options=[]): array {
    $page=$options['page']??1;$limit=$options['limit']??24;
    if(!is_int($page)||$page<1||$page>1000000||!is_int($limit)||$limit<1||$limit>60)throw new InvalidArgumentException('Choose a valid gallery page.');
    $search=sf_canvas_gallery_text($options['q']??'',200);$status=$options['status']??($admin?'pending':($uid!==null?'all':'approved'));
    if(!in_array($status,['pending','approved','returned','declined','withdrawn','all'],true))throw new InvalidArgumentException('Choose a gallery status.');
    $empty=['items'=>[],'page'=>$page,'limit'=>$limit,'total'=>0,'has_more'=>false];if(!sf_canvas_gallery_exists())return $empty;
    $public=!$admin&&$uid===null;$where=[];$args=[];if($uid!==null&&!$admin){$where[]='user_id=?';$args[]=$uid;}
    if($public)$where[]="EXISTS(SELECT 1 FROM users u WHERE u.id=canvas_gallery_entries.user_id AND u.status='active')";
    if(!$admin&&$uid===null)$status='approved';if($status!=='all'){$where[]='status=?';$args[]=$status;}
    if($search!==''){$where[]='(LOWER(title) LIKE ? ESCAPE \'!\' OR LOWER(description) LIKE ? ESCAPE \'!\' OR LOWER(creator_label) LIKE ? ESCAPE \'!\')';$term='%'.str_replace(['!','%','_'],['!!','!%','!_'],strtolower($search)).'%';array_push($args,$term,$term,$term);}
    $sql=$where?' WHERE '.implode(' AND ',$where):'';$db=sf_db();
    if($public){
        // Count live artist-reference permissions without loading every submitted program.
        $rows=[];$total=0;$after=null;$offset=($page-1)*$limit;
        do{
            $cursor=$after===null?'':' AND (submitted_at<? OR (submitted_at=? AND id<?))';$queryArgs=$args;
            if($after!==null)array_push($queryArgs,$after['submitted_at'],$after['submitted_at'],$after['id']);
            $q=$db->prepare('SELECT id,title,description,creator_label,preview_asset_id,submitted_at,published_at,attribution_json FROM canvas_gallery_entries'.$sql.$cursor.' ORDER BY submitted_at DESC,id DESC LIMIT 100');
            $q->execute($queryArgs);$batch=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            foreach($batch as $row)if(sf_canvas_gallery_available($row['id'])){if($total>=$offset&&count($rows)<$limit)$rows[]=$row;$total++;}
            $after=$batch?end($batch):null;
        }while(count($batch)===100);
    }else{
        $q=$db->prepare('SELECT COUNT(*) FROM canvas_gallery_entries'.$sql);$q->execute($args);$total=(int)$q->fetchColumn();$q->closeCursor();
        $q=$db->prepare('SELECT * FROM canvas_gallery_entries'.$sql.' ORDER BY submitted_at DESC,id DESC LIMIT '.$limit.' OFFSET '.(($page-1)*$limit));$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    }
    return ['items'=>array_map(static fn(array $row):array=>sf_canvas_gallery_public($row,$admin||$uid!==null),$rows),'page'=>$page,'limit'=>$limit,'total'=>$total,'has_more'=>$page*$limit<$total];
}
function sf_canvas_gallery_get(string $id,?int $uid=null,bool $admin=false): ?array {
    sf_canvas_gallery_id($id);$row=sf_canvas_gallery_row($id);if(!$row||(!$admin&&$uid!==null&&(int)$row['user_id']!==$uid)||(!$admin&&$uid===null&&($row['status']!=='approved'||!sf_canvas_gallery_available($id))))return null;
    return sf_canvas_gallery_public($row,$admin||$uid!==null);
}
function sf_canvas_gallery_submit(array $user,array $request,?callable $capture=null): array {
    $uid=(int)($user['id']??0);$sceneId=$request['scene_id']??null;$requestId=sf_agent_scene_request_id($request['request_id']??null);
    if($uid<1||!is_int($sceneId)||$sceneId<1)throw new InvalidArgumentException('Choose your saved custom canvas version.');
    if(($request['publication_consent']??null)!==true)throw new InvalidArgumentException('Explicit consent to publish the code and selected assets is required.');
    $title=sf_canvas_gallery_text($request['title']??'',240,true);$description=sf_canvas_gallery_text($request['description']??'',2000);$label=sf_canvas_gallery_text($request['creator_label']??'',120,true);
    $preview=$request['preview_asset_id']??null;if($preview!==null&&!sf_agent_canvas_image_asset_id($preview))throw new InvalidArgumentException('Choose an image bound to this canvas.');
    $fingerprint=hash('sha256',sf_canvas_build_workflow_json([$sceneId,$title,$description,$label,$preview]));sf_canvas_gallery_ensure_schema();$db=sf_db();
    $existing=static function()use($db,$uid,$requestId,$fingerprint):?array {$q=$db->prepare('SELECT * FROM canvas_gallery_entries WHERE user_id=? AND request_id=?');$q->execute([$uid,$requestId]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if($row&&!hash_equals($row['request_fingerprint'],$fingerprint))throw new SfCanvasGalleryConflict('This submission request belongs to another saved version.');return $row?:null;};
    if($row=$existing())return sf_canvas_gallery_public($row,true);
    $epoch=sf_agent_scene_history_epoch($uid);$scene=sf_agent_scene_history_get($uid,$sceneId);
    if(!$scene||!sf_canvas_gallery_eligible($uid,$scene))throw new InvalidArgumentException('This saved canvas needs completed verification before it can be submitted.');
    $snapshot=sf_canvas_gallery_snapshot($scene,$title);sf_canvas_gallery_validate_scopes($snapshot,$uid);$id=bin2hex(random_bytes(16));
    require_once __DIR__.'/canvas-gallery-assets.php';$copied=false;$committed=false;
    try{
        $assets=($capture??sf_canvas_gallery_assets_snapshot(...))($uid,$snapshot+['id'=>$sceneId],$id);$copied=true;
        if(!is_array($assets)||!is_array($assets['scene']??null)||!is_array($assets['assets']??null))throw new RuntimeException('Gallery assets were not captured.');
        $snapshot=array_replace($snapshot,array_intersect_key($assets['scene'],array_flip(['html','css','js','js_hash','image_assets'])));$snapshot['title']=$title;
        if(sf_agent_scene_code($snapshot)===null)throw new InvalidArgumentException('The copied canvas code is invalid.');
        $previewId=$preview===null?null:($assets['source_map'][$preview]??null);if($preview!==null&&$previewId===null)throw new InvalidArgumentException('The preview image must be bound to this canvas.');
        $manifest=$assets['assets'];$hash=hash('sha256',sf_canvas_build_workflow_json(['title'=>$title,'description'=>$description,'creator_label'=>$label,'scene'=>$snapshot,'assets'=>$manifest,'preview_asset_id'=>$previewId]));
        $db->beginTransaction();sf_canvas_build_workflow_account($db,$uid);
        if(sf_agent_scene_history_epoch($uid)!==$epoch||sf_agent_scene_history_get($uid,$sceneId)!==$scene)throw new SfCanvasGalleryConflict('This saved canvas changed or was cleared before submission.');
        if(!sf_canvas_gallery_eligible($uid,$scene))throw new SfCanvasGalleryConflict('This saved canvas is no longer verified.');sf_canvas_gallery_validate_scopes($snapshot,$uid);
        if($row=$existing()){$db->commit();sf_canvas_gallery_assets_remove($id);return sf_canvas_gallery_public($row,true);}
        $now=gmdate('c');$db->prepare("INSERT INTO canvas_gallery_entries(id,user_id,source_scene_id,request_id,request_fingerprint,history_epoch,status,title,description,creator_label,snapshot_json,attribution_json,assets_json,snapshot_hash,preview_asset_id,feedback,submitted_at,updated_at) VALUES(?,?,?,?,?,?,'pending',?,?,?,?,?,?,?,?,'',?,?)")
            ->execute([$id,$uid,$sceneId,$requestId,$fingerprint,$epoch,$title,$description,$label,sf_canvas_build_workflow_json($snapshot),isset($snapshot['gallery_attribution'])?sf_canvas_build_workflow_json($snapshot['gallery_attribution']):null,sf_canvas_build_workflow_json($manifest),$hash,$previewId,$now,$now]);
        $db->commit();$committed=true;return sf_canvas_gallery_get($id,$uid);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();if($copied&&!$committed)sf_canvas_gallery_assets_remove($id);throw $error;}
}
function sf_canvas_gallery_withdraw(array $user,string $id): array {
    $uid=(int)($user['id']??0);sf_canvas_gallery_id($id);sf_canvas_gallery_ensure_schema();$db=sf_db();$db->beginTransaction();
    try{sf_agent_scene_history_lock_account($db,$uid);$row=sf_canvas_gallery_row($id,true);if(!$row||(int)$row['user_id']!==$uid)throw new SfCanvasGalleryNotFound('Canvas submission not found.');
        $db->prepare("UPDATE canvas_gallery_entries SET status='withdrawn',updated_at=? WHERE id=?")->execute([gmdate('c'),$id]);$db->commit();sf_canvas_gallery_cleanup_files([$id]);return sf_canvas_gallery_get($id,$uid);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function sf_canvas_gallery_moderate(array $admin,string $id,string $action,string $hash,string $feedback=''): array {
    if(($admin['role']??'')!=='admin')throw new InvalidArgumentException('Administrator access is required.');sf_canvas_gallery_id($id);
    if(!in_array($action,['approve','return','decline','unpublish'],true)||preg_match('/\A[a-f0-9]{64}\z/',$hash)!==1)throw new InvalidArgumentException('Review this exact submitted snapshot.');
    $feedback=sf_canvas_gallery_text($feedback,2000,$action==='return');sf_canvas_gallery_ensure_schema();require_once __DIR__.'/canvas-gallery-assets.php';sf_canvas_gallery_assets_prepare();$row=sf_canvas_gallery_row($id);if(!$row)throw new SfCanvasGalleryNotFound('Canvas submission not found.');
    $snapshotInputs=json_decode($row['snapshot_json'],true,16,JSON_THROW_ON_ERROR);if(isset($snapshotInputs['playlist_inputs'])){sf_canvas_playlists_ensure_schema();sf_media_ensure_schema();}
    $db=sf_db();$db->beginTransaction();
    try{sf_agent_scene_history_lock_account($db,(int)$row['user_id']);
        $q=$db->prepare('SELECT status FROM users WHERE id=?');$q->execute([(int)$row['user_id']]);$ownerStatus=$q->fetchColumn();$q->closeCursor();
        if($ownerStatus===false)throw new SfCanvasGalleryNotFound('Canvas submission not found.');
        if($action==='approve'&&$ownerStatus!=='active')throw new SfCanvasGalleryConflict('Only an active creator can publish a canvas.');
        $q=$db->prepare("SELECT 1 FROM users WHERE id=? AND role='admin' AND status='active'");$q->execute([(int)($admin['id']??0)]);$authorized=$q->fetchColumn()!==false;$q->closeCursor();if(!$authorized)throw new InvalidArgumentException('Administrator access is required.');
        $row=sf_canvas_gallery_row($id,true);if(!$row)throw new SfCanvasGalleryNotFound('Canvas submission not found.');
        if(!hash_equals($row['snapshot_hash'],$hash))throw new SfCanvasGalleryConflict('The submitted snapshot does not match this review.');
        $expected=$action==='unpublish'?'approved':'pending';$next=match($action){'approve'=>'approved','decline'=>'declined',default=>'returned'};
        if($row['status']!==$expected){if($row['status']===$next&&$row['feedback']===$feedback){$db->commit();return sf_canvas_gallery_public($row,true);}throw new SfCanvasGalleryConflict('This submission is no longer awaiting that review.');}
        $snapshot=json_decode($row['snapshot_json'],true,16,JSON_THROW_ON_ERROR);if($action==='approve'){
            sf_canvas_gallery_validate_scopes($snapshot,(int)$row['user_id']);if(!sf_canvas_gallery_available($id))throw new SfCanvasGalleryConflict('A submitted image is no longer authorized for publication.');
            foreach(json_decode($row['assets_json'],true,8,JSON_THROW_ON_ERROR) as $asset){$file=sf_canvas_gallery_assets_file($id,$asset['id'],$admin);if(!$file)throw new SfCanvasGalleryConflict('A submitted image is unavailable.');fclose($file['handle']);}
        }
        $now=gmdate('c');$db->prepare('UPDATE canvas_gallery_entries SET status=?,feedback=?,reviewed_by=?,reviewed_at=?,published_at=?,updated_at=? WHERE id=?')->execute([$next,$feedback,(int)$admin['id'],$now,$action==='approve'?$now:$row['published_at'],$now,$id]);
        $db->commit();return sf_canvas_gallery_get($id,null,true);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
/** Fresh viewer data is delivered only for this request and is never part of the submitted snapshot. */
function sf_canvas_gallery_open(string $id,?array $viewer=null,string $currentTrackId='',bool $admin=false): array {
    sf_canvas_gallery_id($id);$row=sf_canvas_gallery_row($id);$uid=(int)($viewer['id']??0);
    if(!$row||($row['status']!=='approved'&&!($admin&&($viewer['role']??'')==='admin')))throw new SfCanvasGalleryNotFound('Canvas gallery entry not found.');
    if($row['status']==='withdrawn'||(!$admin&&$row['status']==='approved'&&!sf_canvas_gallery_available($id)))throw new SfCanvasGalleryNotFound('Canvas gallery entry not found.');
    $scene=json_decode($row['snapshot_json'],true,16,JSON_THROW_ON_ERROR);$scene['gallery_id']=$id;$scene['gallery_attribution']=sf_canvas_gallery_attribution($row);
    $scene['catalog_blocks']=($scene['catalog_refs']??[])?sf_agent_canvas_catalog_blocks($scene['catalog_refs']):[];
    $data=sf_agent_canvas_data_for_scene($admin?[]:($viewer??[]),$scene['html'],$currentTrackId,$scene['music_inputs']??null,$scene['artist_inputs']??null,$admin?null:['gallery_id'=>$id]);
    if(!$admin)$scene['canvas_access']=sf_canvas_access_summary($viewer??[],['gallery_id'=>$id]);
    if($data){$scene['datasets']=$data;foreach($data as $dataset)if(array_key_exists('current_rows',$dataset)){$scene['dataset_track_id']=$currentTrackId;break;}}
    return ['item'=>sf_canvas_gallery_public($row,$admin),'scene'=>$scene];
}
function sf_canvas_gallery_attribution(array $row): array {
    $snapshot=json_decode($row['snapshot_json'],true,16,JSON_THROW_ON_ERROR);$old=sf_agent_scene_gallery_attribution($snapshot['gallery_attribution']??null);
    return sf_agent_scene_gallery_attribution(['source'=>['id'=>$row['id'],'title'=>$row['title'],'creator_label'=>$row['creator_label']],
        'ancestors'=>$old?[$old['source'],...$old['ancestors']]:[]]);
}
function sf_canvas_gallery_remix(array $user,string $id,string $requestId,?callable $copy=null): array {
    $uid=(int)($user['id']??0);sf_canvas_gallery_id($id);sf_agent_scene_request_id($requestId);if($uid<1)throw new InvalidArgumentException('Sign in to remix this canvas.');sf_canvas_gallery_ensure_schema();$expectedEpoch=sf_agent_scene_history_epoch($uid);
    require_once __DIR__.'/canvas-gallery-assets.php';sf_canvas_gallery_assets_prepare();$preflight=sf_canvas_gallery_row($id);if($preflight&&isset(json_decode($preflight['snapshot_json'],true,16)['playlist_inputs'])){sf_canvas_playlists_ensure_schema();sf_media_ensure_schema();}$db=sf_db();$db->beginTransaction();$files=[];$committed=false;
    try{sf_canvas_build_workflow_account($db,$uid);$epoch=sf_agent_scene_history_epoch($uid);if($epoch!==$expectedEpoch)throw new SfCanvasGalleryConflict('This remix was canceled by a privacy reset.');
        $q=$db->prepare('SELECT entry_id,scene_id,history_epoch FROM canvas_gallery_remixes WHERE user_id=? AND request_id=?');$q->execute([$uid,$requestId]);$old=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if($old){if($old['entry_id']!==$id)throw new SfCanvasGalleryConflict('This remix request belongs to another gallery entry.');$scene=sf_agent_scene_saved($uid,(int)$old['scene_id']);if(!$scene||(int)$old['history_epoch']!==$epoch)throw new SfCanvasGalleryConflict('This remix was cleared.');$db->commit();return ['scene'=>$scene,'item'=>sf_canvas_gallery_get($id)];}
        $row=sf_canvas_gallery_row($id,true);if(!$row||$row['status']!=='approved'||!sf_canvas_gallery_available($id))throw new SfCanvasGalleryNotFound('Canvas gallery entry not found.');
        $snapshot=json_decode($row['snapshot_json'],true,16,JSON_THROW_ON_ERROR);sf_canvas_gallery_validate_scopes($snapshot,$uid);
        $entry=$row+['scene'=>$snapshot,'assets'=>json_decode($row['assets_json'],true,8,JSON_THROW_ON_ERROR)];
        $copied=($copy??sf_canvas_gallery_assets_remix(...))($uid,$entry);$files=$copied['created_files']??[];$scene=array_replace($snapshot,$copied['scene']??[]);
        unset($scene['image_assets'],$scene['gallery_id'],$scene['workflow_required'],$scene['verification_parent_id']);$scene['gallery_attribution']=sf_canvas_gallery_attribution($row);
        $stored=sf_agent_scene_history_save($uid,$scene,null,$epoch);
        $db->prepare('INSERT INTO canvas_gallery_remixes(user_id,request_id,entry_id,scene_id,history_epoch,created_at) VALUES(?,?,?,?,?,?)')->execute([$uid,$requestId,$id,$stored['id'],$epoch,gmdate('c')]);
        $db->commit();$committed=true;$view=sf_agent_scene_saved($uid,$stored['id']);$images=sf_agent_canvas_image_assets($uid,$view);if($images)$view['image_assets']=$images;
        return ['item'=>sf_canvas_gallery_public($row),'scene'=>$view];
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();if(!$committed)foreach($files as $file)if(is_string($file)&&is_file($file))@unlink($file);throw $error;}
}
/** Optional privacy hooks never create gallery storage or remove files before transaction commit. */
function sf_canvas_gallery_export(int $uid): array {
    if($uid<1||!sf_canvas_gallery_exists())return [];$q=sf_db()->prepare('SELECT * FROM canvas_gallery_entries WHERE user_id=? ORDER BY submitted_at,id');$q->execute([$uid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    return array_map(static fn(array $row):array=>sf_canvas_gallery_public($row,true)+['scene'=>json_decode($row['snapshot_json'],true,16,JSON_THROW_ON_ERROR)],$rows);
}
function sf_canvas_gallery_clear(int $uid): array {
    $counts=['canvas_gallery_entries'=>0,'canvas_gallery_remixes'=>0];foreach(['canvas_gallery_remixes','canvas_gallery_entries'] as $table)if(sf_canvas_gallery_exists($table)){$q=sf_db()->prepare('DELETE FROM '.$table.' WHERE user_id=?');$q->execute([$uid]);$counts[$table]=$q->rowCount();}return $counts;
}
function sf_canvas_gallery_owned_ids(int $uid): array {
    if($uid<1||!sf_canvas_gallery_exists())return [];$q=sf_db()->prepare('SELECT id FROM canvas_gallery_entries WHERE user_id=?');$q->execute([$uid]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();return $ids;
}
function sf_canvas_gallery_cleanup_files(array $ids): void {
    if(!$ids)return;if(sf_db()->inTransaction())throw new LogicException('Remove gallery files only after committing their revocation.');
    require_once __DIR__.'/canvas-gallery-assets.php';foreach($ids as $id)if(is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/',$id)===1){
        try{sf_canvas_gallery_assets_remove($id);}catch(Throwable){error_log('[Stonefellow Canvas Gallery] revoked asset cleanup deferred');}
    }
}
