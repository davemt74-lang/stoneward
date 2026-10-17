<?php
declare(strict_types=1);
/**
 * 88C3A: Band Member profiles on existing users and many-to-many Team roles.
 * A likeness here is an ADMIN-curated media reference with documented consent;
 * it is never automatic facial identification or biometric enrollment.
 */
function sf_band_ensure_schema():void {
    static $ready=false;if($ready)return;
    sf_team_ensure_schema();$db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite){
        $db->exec("CREATE TABLE IF NOT EXISTS band_member_profiles (
          user_id INTEGER PRIMARY KEY,stage_name TEXT NOT NULL DEFAULT '',
          biography_draft TEXT NOT NULL DEFAULT '',biography_public TEXT NOT NULL DEFAULT '',
          instruments_json TEXT NOT NULL DEFAULT '[]',gallery_id INTEGER NULL,
          likeness_asset_id INTEGER NULL,likeness_consent_at TEXT NULL,
          public_at TEXT NULL,published_by INTEGER NULL,updated_at TEXT NOT NULL,
          FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $db->exec("CREATE TABLE IF NOT EXISTS band_member_knowledge (
          id INTEGER PRIMARY KEY AUTOINCREMENT,member_user_id INTEGER NOT NULL,title TEXT NOT NULL,
          body TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'draft',created_by INTEGER NOT NULL,
          approved_by INTEGER NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
          FOREIGN KEY(member_user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_band_knowledge_member ON band_member_knowledge(member_user_id,status,id)");
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS band_member_profiles (
          user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,stage_name VARCHAR(140) NOT NULL DEFAULT '',
          biography_draft TEXT NOT NULL,biography_public TEXT NOT NULL,
          instruments_json TEXT NOT NULL,gallery_id BIGINT UNSIGNED NULL,
          likeness_asset_id BIGINT UNSIGNED NULL,likeness_consent_at VARCHAR(40) NULL,
          public_at VARCHAR(40) NULL,published_by BIGINT UNSIGNED NULL,updated_at VARCHAR(40) NOT NULL,
          CONSTRAINT fk_band_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)
          ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS band_member_knowledge (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,member_user_id BIGINT UNSIGNED NOT NULL,
          title VARCHAR(180) NOT NULL,body TEXT NOT NULL,status VARCHAR(24) NOT NULL,
          created_by BIGINT UNSIGNED NOT NULL,approved_by BIGINT UNSIGNED NULL,
          created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
          INDEX idx_band_knowledge_member(member_user_id,status,id),
          CONSTRAINT fk_band_knowledge_member FOREIGN KEY(member_user_id) REFERENCES users(id) ON DELETE CASCADE)
          ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready=true;
}
function sf_band_is_member(int $userId):bool {
    return $userId>0&&in_array('band_member',sf_team_roles($userId),true);
}
function sf_band_admin(int $actorId):bool {
    $q=sf_db()->prepare("SELECT 1 FROM users WHERE id=? AND role='admin' AND status='active'");
    $q->execute([$actorId]);return (bool)$q->fetchColumn();
}
function sf_band_instruments(mixed $items):array {
    if(!is_array($items)||count($items)>15)throw new InvalidArgumentException('Instruments must be a list of 15 or fewer.');
    $out=[];foreach($items as $item){
        if(!is_string($item))throw new InvalidArgumentException('Invalid instrument.');
        $text=sf_clean_text($item,80);
        if($text!==''&&!in_array($text,$out,true))$out[]=$text;
    }return $out;
}
function sf_band_validate_gallery(int $galleryId):void {
    if($galleryId===0)return;
    if($galleryId<0||!function_exists('sf_gallery_get')||!sf_gallery_get($galleryId,false))
        throw new InvalidArgumentException('Unknown Media Library photo gallery.');
}
function sf_band_public_gallery(int $galleryId):?array {
    if($galleryId<=0)return null;
    if(!function_exists('sf_gallery_get'))return null;
    $gallery=sf_gallery_get($galleryId,true);
    if(!$gallery||($gallery['status']??'')!=='published')return null;
    // Use canonical public payload, never unrestricted private gallery attachment rows.
    return function_exists('sf_gallery_public_payload')?sf_gallery_public_payload($gallery):null;
}
function sf_band_likeness(int $galleryId,int $assetId):?array {
    if($galleryId<=0||$assetId<=0||!sf_band_public_gallery($galleryId))return null;
    if(!function_exists('sf_media_links')||!function_exists('sf_media_asset'))return null;
    foreach(sf_media_links('photo_gallery',(string)$galleryId) as $link){
        if((int)($link['asset_id']??0)!==$assetId||empty($link['public_visible'])
          ||!in_array((string)($link['role']??''),['cover','photo'],true))continue;
        $asset=sf_media_asset($assetId);
        if(!$asset||($asset['category']??'')!=='image'
          ||(function_exists('sf_media_protected_master_asset')&&sf_media_protected_master_asset($assetId)))return null;
        return ['asset_id'=>$assetId,'url'=>sf_media_managed_url($asset,'large'),
          'alt_text'=>sf_clean_text($asset['alt_text']??'Artist portrait',180),
          'credit'=>sf_clean_text($asset['credit']??'',160)];
    }
    return null;
}
function sf_band_profile_row(int $userId):?array {
    sf_band_ensure_schema();$q=sf_db()->prepare('SELECT p.*,u.display_name,u.status FROM band_member_profiles p JOIN users u ON u.id=p.user_id WHERE p.user_id=?');
    $q->execute([$userId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function sf_band_profile_public(int $userId):?array {
    if(!sf_band_is_member($userId))return null;
    $r=sf_band_profile_row($userId);
    if(!$r||$r['status']!=='active'||empty($r['public_at'])||trim((string)$r['biography_public'])==='')return null;
    $gallery=sf_band_public_gallery((int)($r['gallery_id']??0));
    $likeness=$gallery&&!empty($r['likeness_consent_at'])
      ?sf_band_likeness((int)$r['gallery_id'],(int)($r['likeness_asset_id']??0)):null;
    return [
      'user_id'=>$userId,
      'stage_name'=>(string)($r['stage_name']?:$r['display_name']),
      'biography'=>(string)$r['biography_public'],
      'instruments'=>json_decode((string)$r['instruments_json'],true)?:[],
      'gallery'=>$gallery?['id'=>(int)$r['gallery_id'],'slug'=>$gallery['slug']??'','title'=>$gallery['title']??'']:null,
      'likeness'=>$likeness,
      'knowledge'=>sf_band_knowledge($userId,true),
      'published_at'=>$r['public_at']
    ];
}
function sf_band_profile_private(int $memberId,int $viewerId):?array {
    if($memberId!==$viewerId&&!sf_band_admin($viewerId))throw new RuntimeException('Not authorized for this band member.');
    if(!sf_band_is_member($memberId))return null;
    $r=sf_band_profile_row($memberId);
    if(!$r)return ['user_id'=>$memberId,'stage_name'=>'','biography_draft'=>'','biography_public'=>'',
      'instruments'=>[],'gallery_id'=>0,'likeness_asset_id'=>0,'public_at'=>null,'knowledge'=>sf_band_knowledge($memberId,false)];
    return ['user_id'=>$memberId,'stage_name'=>$r['stage_name'],'biography_draft'=>$r['biography_draft'],
       'biography_public'=>$r['biography_public'],'instruments'=>json_decode((string)$r['instruments_json'],true)?:[],
       'gallery_id'=>(int)($r['gallery_id']??0),'likeness_asset_id'=>(int)($r['likeness_asset_id']??0),
       'likeness_consent_at'=>$r['likeness_consent_at'],'public_at'=>$r['public_at'],
       'knowledge'=>sf_band_knowledge($memberId,false)];
}
function sf_band_profile_update(int $memberId,int $actorId,array $patch):array {
    if(!sf_band_is_member($memberId))throw new InvalidArgumentException('Target must have an active Band Member role.');
    $admin=sf_band_admin($actorId);
    if(!$admin&&$memberId!==$actorId)throw new RuntimeException('You can only edit your own draft.');
    foreach(array_keys($patch) as $key)
        if(!in_array($key,['stage_name','biography_draft','instruments','gallery_id','likeness_asset_id'],true))
            throw new InvalidArgumentException('Unsupported member profile field.');
    if(!$admin&&(array_key_exists('gallery_id',$patch)||array_key_exists('likeness_asset_id',$patch)))
        throw new RuntimeException('Photo and likeness references require Admin curation.');
    $prior=sf_band_profile_row($memberId);
    $stage=sf_clean_text($patch['stage_name']??$prior['stage_name']??'',140);
    $bio=sf_clean_text($patch['biography_draft']??$prior['biography_draft']??'',6000);
    $instruments=sf_band_instruments($patch['instruments']??json_decode((string)($prior['instruments_json']??'[]'),true)?:[]);
    $gallery=(int)($patch['gallery_id']??$prior['gallery_id']??0);
    $asset=(int)($patch['likeness_asset_id']??$prior['likeness_asset_id']??0);
    if($admin){
        sf_band_validate_gallery($gallery);
        if($asset>0&&!sf_band_likeness($gallery,$asset))
            throw new InvalidArgumentException('A likeness must be a currently public photo in the selected published gallery.');
    }
    $now=gmdate('c');$db=sf_db();
    $published=(string)($prior['biography_public']??'');$publicAt=$prior['public_at']??null;
    // Any draft edit never changes published content; Admin must publish separately.
    if((sf_db_config()['driver']??'')==='sqlite')
        $sql='INSERT INTO band_member_profiles(user_id,stage_name,biography_draft,biography_public,instruments_json,gallery_id,likeness_asset_id,likeness_consent_at,public_at,published_by,updated_at)
          VALUES(?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET stage_name=excluded.stage_name,biography_draft=excluded.biography_draft,instruments_json=excluded.instruments_json,gallery_id=excluded.gallery_id,likeness_asset_id=excluded.likeness_asset_id,likeness_consent_at=excluded.likeness_consent_at,updated_at=excluded.updated_at';
    else
        $sql='INSERT INTO band_member_profiles(user_id,stage_name,biography_draft,biography_public,instruments_json,gallery_id,likeness_asset_id,likeness_consent_at,public_at,published_by,updated_at)
          VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE stage_name=VALUES(stage_name),biography_draft=VALUES(biography_draft),instruments_json=VALUES(instruments_json),gallery_id=VALUES(gallery_id),likeness_asset_id=VALUES(likeness_asset_id),likeness_consent_at=VALUES(likeness_consent_at),updated_at=VALUES(updated_at)';
    // Changing the likeness clears any prior documented consent. Explicit Admin
    // approval is required again before public portrait display.
    $consent=($asset!==((int)($prior['likeness_asset_id']??0)))?null:($prior['likeness_consent_at']??null);
    $db->prepare($sql)->execute([$memberId,$stage,$bio,$published,json_encode($instruments,JSON_UNESCAPED_UNICODE),$gallery?:null,$asset?:null,
      $consent,$publicAt,$prior['published_by']??null,$now]);
    sf_team_audit($actorId,'band.profile_draft_updated','band_member',(string)$memberId,
      'Updated band member profile draft',['user_id'=>$memberId]);
    return sf_band_profile_private($memberId,$actorId);
}
function sf_band_profile_publish(int $memberId,int $adminId,bool $consentConfirmed):array {
    if(!sf_band_admin($adminId))throw new RuntimeException('Administrator approval required.');
    if(!sf_band_is_member($memberId))throw new InvalidArgumentException('Band Member role is required.');
    $r=sf_band_profile_row($memberId);
    if(!$r||trim((string)$r['biography_draft'])==='')throw new InvalidArgumentException('Biography draft required.');
    $asset=(int)($r['likeness_asset_id']??0);$gallery=(int)($r['gallery_id']??0);
    if($asset>0){
        if(!$consentConfirmed)throw new InvalidArgumentException('Documented permission is required before publishing likeness imagery.');
        if(!sf_band_likeness($gallery,$asset))throw new InvalidArgumentException('Portrait no longer published in approved gallery.');
    }
    sf_db()->prepare('UPDATE band_member_profiles SET biography_public=?,public_at=?,published_by=?,likeness_consent_at=?,updated_at=? WHERE user_id=?')
      ->execute([(string)$r['biography_draft'],gmdate('c'),$adminId,$asset>0?gmdate('c'):null,gmdate('c'),$memberId]);
    sf_team_audit($adminId,'band.profile_published','band_member',(string)$memberId,'Approved a band member biography and curated imagery',
      ['user_id'=>$memberId]);
    return sf_band_profile_public($memberId)??[];
}
function sf_band_knowledge(int $memberId,bool $publicOnly):array {
    sf_band_ensure_schema();
    $sql='SELECT id,title,body,status,created_at,updated_at FROM band_member_knowledge WHERE member_user_id=?';
    if($publicOnly)$sql.=" AND status='approved'";
    $sql.=' ORDER BY id DESC LIMIT 100';
    $q=sf_db()->prepare($sql);$q->execute([$memberId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sf_band_knowledge_save(int $memberId,int $actorId,string $title,string $body):int {
    if(!sf_band_is_member($memberId))throw new InvalidArgumentException('Band Member role required.');
    if($memberId!==$actorId&&!sf_band_admin($actorId))throw new RuntimeException('Not authorized to edit this knowledge.');
    $title=sf_clean_text($title,180);$body=sf_clean_text($body,16000);
    if($title===''||$body==='')throw new InvalidArgumentException('Knowledge title and content are required.');
    $now=gmdate('c');$q=sf_db()->prepare('INSERT INTO band_member_knowledge(member_user_id,title,body,status,created_by,approved_by,created_at,updated_at) VALUES(?,?,?, ?,?,NULL,?,?)');
    $q->execute([$memberId,$title,$body,'draft',$actorId,$now,$now]);
    $id=(int)sf_db()->lastInsertId();sf_team_audit($actorId,'band.knowledge_drafted','band_knowledge',(string)$id,
      'Added member-specific knowledge for review',['user_id'=>$memberId]);
    return $id;
}
function sf_band_knowledge_approve(int $knowledgeId,int $adminId):void {
    if(!sf_band_admin($adminId))throw new RuntimeException('Admin review required.');
    $q=sf_db()->prepare('UPDATE band_member_knowledge SET status=?,approved_by=?,updated_at=? WHERE id=?');
    $q->execute(['approved',$adminId,gmdate('c'),$knowledgeId]);
    if($q->rowCount()!==1)throw new InvalidArgumentException('Knowledge entry not found.');
    sf_team_audit($adminId,'band.knowledge_approved','band_knowledge',(string)$knowledgeId,'Approved member knowledge');
}
