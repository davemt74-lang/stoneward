<?php
declare(strict_types=1);
/**
 * 88D2A: Private, server-observed media technical evidence.
 * No model output, inferred people, contributor assertions or publication permissions.
 */
function sf_evidence_schema():void {
  static $ready=false; if($ready)return;
  $db=sf_db(); $sqlite=(sf_db_config()['driver']??'')==='sqlite';
  if($sqlite){
    $db->exec("CREATE TABLE IF NOT EXISTS story_media_evidence (
      submission_id INTEGER PRIMARY KEY, asset_id INTEGER NOT NULL,
      evidence_version INTEGER NOT NULL, media_category TEXT NOT NULL,
      mime_type TEXT NOT NULL, byte_size INTEGER NOT NULL,
      image_width INTEGER NULL, image_height INTEGER NULL,
      sha256 TEXT NOT NULL, observed_at TEXT NOT NULL,
      FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
      FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE CASCADE)");
  }else{
    $db->exec("CREATE TABLE IF NOT EXISTS story_media_evidence (
      submission_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      asset_id BIGINT UNSIGNED NOT NULL, evidence_version INT NOT NULL,
      media_category VARCHAR(20) NOT NULL, mime_type VARCHAR(120) NOT NULL,
      byte_size BIGINT UNSIGNED NOT NULL, image_width INT NULL, image_height INT NULL,
      sha256 CHAR(64) NOT NULL, observed_at VARCHAR(40) NOT NULL,
      INDEX idx_evidence_asset(asset_id),
      CONSTRAINT fk_evidence_submission FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
      CONSTRAINT fk_evidence_asset FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE CASCADE)
      ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
  $ready=true;
}
/** Observe only real local bytes before they are moved to private Media Library. */
function sf_evidence_observe_upload(array $file):array {
  $path=$file['tmp_name']??'';
  if(!is_string($path)||!is_file($path)||!is_readable($path))
    throw new InvalidArgumentException('Upload bytes unavailable.');
  $bytes=filesize($path);
  if($bytes===false||$bytes<1||$bytes>250*1024*1024)
    throw new InvalidArgumentException('Invalid evidence file size.');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
  if(!is_string($mime)||strlen($mime)>120)
    throw new InvalidArgumentException('Unknown media format.');
  $hash=hash_file('sha256',$path);
  if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/',$hash))
    throw new RuntimeException('Unable to fingerprint media.');
  $w=null;$h=null;
  if(str_starts_with($mime,'image/')){
    $dims=@getimagesize($path);
    if(is_array($dims)){
      $w=(int)$dims[0];$h=(int)$dims[1];
    }
  }
  return ['mime_type'=>$mime,'byte_size'=>$bytes,'image_width'=>$w,
    'image_height'=>$h,'sha256'=>$hash];
}
/** Bind technical observations to a server-created submission and its actual media asset. */
function sf_evidence_record(int $submissionId,int $assetId,array $observed):void {
  if($submissionId<1||$assetId<1)throw new InvalidArgumentException('Invalid evidence identity.');
  sf_capture_schema();sf_evidence_schema();
  $db=sf_db();
  $q=$db->prepare('SELECT s.asset_id,a.category FROM story_capture_submissions s JOIN media_assets a ON a.id=s.asset_id WHERE s.id=?');
  $q->execute([$submissionId]);$s=$q->fetch(PDO::FETCH_ASSOC);
  if(!$s||(int)$s['asset_id']!==$assetId||sf_media_protected_master_asset($assetId))
    throw new InvalidArgumentException('Submission and media identity mismatch.');
  $hash=$observed['sha256']??'';
  $mime=$observed['mime_type']??'';
  $bytes=$observed['byte_size']??0;
  if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/',$hash)
    ||!is_string($mime)||!preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i',$mime)
    ||!is_int($bytes)||$bytes<1||$bytes>250*1024*1024)
    throw new InvalidArgumentException('Invalid observed technical metadata.');
  $category=(string)$s['category'];
  $matches=match($category){
    'image'=>str_starts_with($mime,'image/'),
    'video'=>str_starts_with($mime,'video/'),
    'audio'=>str_starts_with($mime,'audio/'),
    default=>false
  };
  if(!$matches)throw new InvalidArgumentException('Evidence MIME category mismatch.');
  $w=$observed['image_width']??null;$h=$observed['image_height']??null;
  foreach([$w,$h] as $dimension)
    if($dimension!==null&&(!is_int($dimension)||$dimension<1||$dimension>100000))
      throw new InvalidArgumentException('Invalid pixel dimensions.');
  if($category!=='image'&&($w!==null||$h!==null))
    throw new InvalidArgumentException('Pixel dimensions not supported for this category.');
  $check=$db->prepare('SELECT submission_id FROM story_media_evidence WHERE submission_id=?');
  $check->execute([$submissionId]);
  if($check->fetchColumn())throw new RuntimeException('Evidence is immutable once recorded.');
  $insert=$db->prepare('INSERT INTO story_media_evidence
    (submission_id,asset_id,evidence_version,media_category,mime_type,byte_size,image_width,image_height,sha256,observed_at)
    VALUES(?,?,?,?,?,?,?,?,?,?)');
  $insert->execute([$submissionId,$assetId,1,$category,$mime,$bytes,$w,$h,$hash,gmdate('c')]);
}
/** Only owner or existing team editorial authority may inspect private evidence. */
function sf_evidence_for_submission(int $viewerId,int $submissionId):?array {
  if($viewerId<1||$submissionId<1)return null;
  sf_capture_schema();sf_evidence_schema();
  $admin=sf_team_can($viewerId,'team.assign_roles');
  $sql='SELECT e.* FROM story_media_evidence e
    JOIN story_capture_submissions s ON s.id=e.submission_id
    WHERE e.submission_id=?';
  if(!$admin)$sql.=' AND s.contributor_id=?';
  $q=sf_db()->prepare($sql);
  $q->execute($admin?[$submissionId]:[$submissionId,$viewerId]);
  $result=$q->fetch(PDO::FETCH_ASSOC);
  return $result?:null;
}
