<?php
declare(strict_types=1);
/**
 * Artist World Capture: private first-party creative evidence, never auto-public.
 * Real contributors -> reviewed assets -> future AI production.
 */
function sf_capture_role_types():array {
  return ['fan'=>'Fan','customer'=>'Customer','band_member'=>'Band Member','manager'=>'Manager',
    'website_manager'=>'Website Manager','road_crew'=>'Road Crew','sound_crew'=>'Sound Crew',
    'lighting_crew'=>'Lighting Crew','photographer'=>'Photographer','videographer'=>'Videographer',
    'producer'=>'Producer','tour_manager'=>'Tour Manager','merch_manager'=>'Merch Manager',
    'publicist'=>'Publicist','all'=>'Everyone'];
}
function sf_capture_roles(int $userId):array {
  if($userId<1)return [];
  $roles=['fan']; // Registered fan is a contributor classification, never a Team Admin grant.
  if(function_exists('sf_team_roles'))
    foreach(sf_team_roles($userId) as $r)if(isset(sf_capture_role_types()[$r]))$roles[]=$r;
  return array_values(array_unique($roles));
}
function sf_capture_schema():void {
  static $ready=false;if($ready)return;
  $db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
  sf_media_ensure_schema();
  if($sqlite){
    $db->exec("CREATE TABLE IF NOT EXISTS story_capture_requests(
      id INTEGER PRIMARY KEY AUTOINCREMENT,created_by INTEGER NOT NULL,
      title TEXT NOT NULL,brief TEXT NOT NULL,shot_type TEXT NOT NULL,
      target_role TEXT NOT NULL,world_context TEXT NOT NULL,creative_suggestion TEXT NOT NULL,
      suggestion_origin TEXT NOT NULL,review_status TEXT NOT NULL,
      created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
      FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)");
    $db->exec("CREATE TABLE IF NOT EXISTS story_capture_submissions(
      id INTEGER PRIMARY KEY AUTOINCREMENT,contributor_id INTEGER NOT NULL,
      request_id INTEGER NULL,asset_id INTEGER NULL,submission_type TEXT NOT NULL,
      title TEXT NOT NULL,description TEXT NOT NULL,dialogue_text TEXT NOT NULL,
      captured_at TEXT NOT NULL,role_snapshot TEXT NOT NULL,
      rights_attested INTEGER NOT NULL,people_release_status TEXT NOT NULL,
      review_status TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
      FOREIGN KEY(contributor_id) REFERENCES users(id) ON DELETE RESTRICT,
      FOREIGN KEY(request_id) REFERENCES story_capture_requests(id) ON DELETE SET NULL,
      FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE SET NULL)");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_capture_submissions_actor_time ON story_capture_submissions(contributor_id,created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_capture_submissions_review ON story_capture_submissions(review_status,created_at)');
  }else{
    $db->exec("CREATE TABLE IF NOT EXISTS story_capture_requests(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,created_by BIGINT UNSIGNED NOT NULL,
      title VARCHAR(180) NOT NULL,brief TEXT NOT NULL,shot_type VARCHAR(32) NOT NULL,
      target_role VARCHAR(40) NOT NULL,world_context VARCHAR(180) NOT NULL,
      creative_suggestion TEXT NOT NULL,suggestion_origin VARCHAR(25) NOT NULL,
      review_status VARCHAR(24) NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
      INDEX idx_capture_request_status(review_status,created_at),
      CONSTRAINT fk_capture_request_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)
      ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS story_capture_submissions(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      contributor_id BIGINT UNSIGNED NOT NULL,request_id BIGINT UNSIGNED NULL,
      asset_id BIGINT UNSIGNED NULL,submission_type VARCHAR(32) NOT NULL,
      title VARCHAR(180) NOT NULL,description TEXT NOT NULL,dialogue_text TEXT NOT NULL,
      captured_at VARCHAR(40) NOT NULL,role_snapshot VARCHAR(255) NOT NULL,
      rights_attested TINYINT(1) NOT NULL,people_release_status VARCHAR(32) NOT NULL,
      review_status VARCHAR(24) NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
      INDEX idx_capture_submissions_actor_time(contributor_id,created_at),
      INDEX idx_capture_submissions_review(review_status,created_at),
      CONSTRAINT fk_capture_submission_actor FOREIGN KEY(contributor_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_capture_submission_request FOREIGN KEY(request_id) REFERENCES story_capture_requests(id) ON DELETE SET NULL,
      CONSTRAINT fk_capture_submission_media FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE SET NULL)
      ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
  $ready=true;
}
function sf_capture_clean(mixed $x,int $max):string {
  if(!is_string($x)&&!is_numeric($x))return '';
  return sf_clean_text((string)$x,$max);
}
function sf_capture_kind(string $kind):bool {
  return in_array($kind,['photo','video','audio','ambient','dialogue','story_idea','screenshot'],true);
}
function sf_capture_create_request(int $adminId,array $input):int {
  if(!sf_team_can($adminId,'team.assign_roles'))throw new RuntimeException('Site administrator required.');
  sf_capture_schema();
  $title=sf_capture_clean($input['title']??'',180);
  $brief=sf_capture_clean($input['brief']??'',2800);
  $kind=(string)($input['shot_type']??'');
  $target=(string)($input['target_role']??'all');
  $origin=(string)($input['suggestion_origin']??'human');
  if($title===''||$brief===''||!sf_capture_kind($kind)||!isset(sf_capture_role_types()[$target]))
    throw new InvalidArgumentException('Shot request requires a title, description, valid type and audience.');
  if(!in_array($origin,['human','ai_suggestion'],true))throw new InvalidArgumentException('Invalid creative origin.');
  // AI proposals are never published without a human review.
  $state=$origin==='ai_suggestion'?'draft':((!empty($input['publish']))?'open':'draft');
  $now=gmdate('c');
  $sql='INSERT INTO story_capture_requests(created_by,title,brief,shot_type,target_role,world_context,creative_suggestion,suggestion_origin,review_status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)';
  sf_db()->prepare($sql)->execute([$adminId,$title,$brief,$kind,$target,
    sf_capture_clean($input['world_context']??'',180),
    sf_capture_clean($input['creative_suggestion']??'',2500),$origin,$state,$now,$now]);
  $id=(int)sf_db()->lastInsertId();
  sf_team_audit($adminId,'capture.request_created','shot_request',(string)$id,'Created '.$kind.' capture request');
  return $id;
}
function sf_capture_publish_request(int $adminId,int $requestId,bool $open):void {
  if(!sf_team_can($adminId,'team.assign_roles'))throw new RuntimeException('Site administrator required.');
  sf_capture_schema();
  $q=sf_db()->prepare("UPDATE story_capture_requests SET review_status=?,updated_at=? WHERE id=?");
  $q->execute([$open?'open':'closed',gmdate('c'),$requestId]);
  if(!$q->rowCount())throw new InvalidArgumentException('Request unavailable.');
  sf_team_audit($adminId,'capture.request_reviewed','shot_request',(string)$requestId,$open?'Approved and opened shot request':'Closed shot request');
}
function sf_capture_requests(int $viewerId):array {
  if($viewerId<1)return [];
  sf_capture_schema();$admin=sf_team_can($viewerId,'team.assign_roles');$roles=sf_capture_roles($viewerId);
  $q=sf_db()->query('SELECT id,title,brief,shot_type,target_role,world_context,creative_suggestion,suggestion_origin,review_status,created_at FROM story_capture_requests ORDER BY id DESC LIMIT 160');
  $out=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
    if(!$admin&&($r['review_status']!=='open'||($r['target_role']!=='all'&&!in_array($r['target_role'],$roles,true))))continue;
    $out[]=$r;
  }
  return $out;
}
function sf_capture_submit(int $viewerId,array $data,int $serverAssetId=0):int {
  if($viewerId<1)throw new RuntimeException('Login required.');
  sf_capture_schema();
  $kind=(string)($data['submission_type']??'');
  $title=sf_capture_clean($data['title']??'',180);
  $description=sf_capture_clean($data['description']??'',3000);
  $dialogue=sf_capture_clean($data['dialogue_text']??'',5000);
  if(!sf_capture_kind($kind)||$title===''||($description===''&&$dialogue===''&&$serverAssetId<1))
    throw new InvalidArgumentException('Capture needs a valid type, title and material.');
  if(($data['rights_attested']??false)!==true)
    throw new InvalidArgumentException('Contributor must affirm the rights to submit the material.');
  $release=(string)($data['people_release_status']??'unconfirmed');
  if(!in_array($release,['not_applicable','requires_review','unconfirmed'],true))
    throw new InvalidArgumentException('Release approval cannot be self-certified.');
  $captured=sf_capture_clean($data['captured_at']??'',40);
  if($captured!==''&&(strtotime($captured)===false||strtotime($captured)>time()+86400))
    throw new InvalidArgumentException('Invalid capture timestamp.');
  $reqId=(int)($data['request_id']??0);
  if($reqId>0){
    $matches=array_column(sf_capture_requests($viewerId),'id');
    if(!in_array($reqId,array_map('intval',$matches),true))
      throw new InvalidArgumentException('Shot request is unavailable to this role.');
  }
  if($serverAssetId>0){
    $asset=sf_media_asset($serverAssetId);
    if(!$asset||sf_media_protected_master_asset($serverAssetId))throw new InvalidArgumentException('Media is not eligible for story capture.');
    $cat=(string)$asset['category'];
    if((in_array($kind,['photo','screenshot'],true)&&$cat!=='image')
      ||($kind==='video'&&$cat!=='video')
      ||(in_array($kind,['audio','ambient','dialogue'],true)&&!in_array($cat,['audio','video'],true)))
        throw new InvalidArgumentException('Media type does not match capture type.');
  }
  // Throttle user-generated intake independent of the Media Library size cap.
  $q=sf_db()->prepare('SELECT COUNT(*) FROM story_capture_submissions WHERE contributor_id=? AND created_at>=?');
  $q->execute([$viewerId,gmdate('c',time()-86400)]);
  if((int)$q->fetchColumn()>=30)throw new RuntimeException('Daily capture limit reached.');
  $now=gmdate('c');$roles=sf_capture_roles($viewerId);
  $stmt=sf_db()->prepare('INSERT INTO story_capture_submissions(contributor_id,request_id,asset_id,submission_type,title,description,dialogue_text,captured_at,role_snapshot,rights_attested,people_release_status,review_status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $stmt->execute([$viewerId,$reqId>0?$reqId:null,$serverAssetId>0?$serverAssetId:null,$kind,$title,$description,$dialogue,$captured,implode(',',array_slice($roles,0,15)),1,$release,'pending_review',$now,$now]);
  $id=(int)sf_db()->lastInsertId();
  sf_team_audit($viewerId,'capture.submitted','capture_submission',(string)$id,'Submitted '.$kind.' for review');
  return $id;
}
function sf_capture_my_submissions(int $viewerId):array {
  sf_capture_schema();
  $q=sf_db()->prepare('SELECT id,request_id,submission_type,title,description,captured_at,people_release_status,review_status,created_at FROM story_capture_submissions WHERE contributor_id=? ORDER BY id DESC LIMIT 80');
  $q->execute([$viewerId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sf_capture_review_queue(int $adminId):array {
  if(!sf_team_can($adminId,'team.assign_roles'))throw new RuntimeException('Administrator required.');
  sf_capture_schema();
  require_once __DIR__.'/story-capture-rights-core.php';
  sf_capture_rights_schema();
  // Contributor release fields are self-reported, not independent clearance.
  return sf_db()->query("SELECT s.id,s.contributor_id,s.request_id,s.asset_id,s.submission_type,
    s.title,s.description,s.dialogue_text,s.captured_at,s.role_snapshot,
    s.people_release_status,s.review_status,s.created_at,
    COALESCE(ai.status,'unreviewed') AS external_ai_clearance,
    COALESCE(pub.status,'unreviewed') AS publication_clearance
    FROM story_capture_submissions s
    LEFT JOIN story_capture_rights_reviews ai ON ai.submission_id=s.id AND ai.purpose='external_ai'
    LEFT JOIN story_capture_rights_reviews pub ON pub.submission_id=s.id AND pub.purpose='publication'
    ORDER BY s.id DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
}
function sf_capture_review(int $adminId,int $submissionId,string $decision,string $basis=''):void {
  if(!sf_team_can($adminId,'team.assign_roles'))throw new RuntimeException('Administrator required.');
  if(!in_array($decision,['approved_for_edit','needs_release','rejected'],true))
    throw new InvalidArgumentException('Unknown editorial decision.');
  $basis=sf_capture_clean($basis,400);
  if($basis!==''&&strlen($basis)<25)throw new InvalidArgumentException('Review basis must be at least 25 characters.');
  sf_capture_schema();$db=sf_db();
  $ownTransaction=!$db->inTransaction();
  $savepoint='sf_capture_review_'.$submissionId;
  if($ownTransaction)$db->beginTransaction();
  else $db->exec('SAVEPOINT '.$savepoint);
  try{
    $q=$db->prepare('UPDATE story_capture_submissions SET review_status=?,updated_at=? WHERE id=? AND review_status=?');
    $q->execute([$decision,gmdate('c'),$submissionId,'pending_review']);
    if(!$q->rowCount())throw new InvalidArgumentException('Submission is unavailable or already reviewed.');
    sf_team_audit($adminId,'capture.reviewed','capture_submission',(string)$submissionId,
      'Reviewed contribution: '.$decision.($basis!==''?' · Basis: '.$basis:''));
    if($ownTransaction)$db->commit();else $db->exec('RELEASE SAVEPOINT '.$savepoint);
  }catch(Throwable $e){
    if($ownTransaction){if($db->inTransaction())$db->rollBack();}
    else {$db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$db->exec('RELEASE SAVEPOINT '.$savepoint);}
    throw $e;
  }
}
