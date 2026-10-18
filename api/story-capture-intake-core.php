<?php
declare(strict_types=1);
/**
 * 88D2H2: fail-closed Artist World upload acceptance.
 * Media ingest precedes the SQL transaction (it stores filesystem bytes).
 * The submission, team audit and immutable evidence are committed together.
 */
function sf_capture_preflight_upload(int $viewerId,array $draft):void {
  if($viewerId<1)throw new RuntimeException('Login required.');
  $kind=(string)($draft['submission_type']??'');
  if(!sf_capture_kind($kind)||sf_capture_clean($draft['title']??'',180)==='')
    throw new InvalidArgumentException('Invalid capture type or title.');
  if(($draft['rights_attested']??false)!==true)
    throw new InvalidArgumentException('Contributor rights attestation required.');
  if(!in_array((string)($draft['people_release_status']??'unconfirmed'),
    ['not_applicable','requires_review','unconfirmed'],true))
    throw new InvalidArgumentException('Release approval cannot be self-certified.');
  $captured=sf_capture_clean($draft['captured_at']??'',40);
  if($captured!==''&&(strtotime($captured)===false||strtotime($captured)>time()+86400))
    throw new InvalidArgumentException('Invalid capture timestamp.');
  $requestId=(int)($draft['request_id']??0);
  if($requestId>0 && !in_array($requestId,array_map('intval',
    array_column(sf_capture_requests($viewerId),'id')),true))
    throw new InvalidArgumentException('Shot request is unavailable to this role.');
}
function sf_capture_store_with_evidence(int $viewerId,array $draft,int $assetId,array $observed):int {
  sf_capture_preflight_upload($viewerId,$draft);
  // Pre-create any tables before beginning MySQL's transactional DML.
  sf_media_ensure_schema();
  sf_capture_schema();
  sf_evidence_schema();
  if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
  $db=sf_db();
  if($db->inTransaction())throw new RuntimeException('Nested capture transaction is unsupported.');
  $db->beginTransaction();
  try{
    $id=sf_capture_submit($viewerId,$draft,$assetId);
    sf_evidence_record($id,$assetId,$observed); // Required, not best-effort.
    $db->commit();
    return $id;
  }catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    throw $e;
  }
}
/**
 * Only discard a newly-ingested asset, owned by this contributor, when both
 * regular Media Library attachments and capture references are absent.
 * Previously deduplicated assets are NEVER candidates for compensation.
 */
function sf_capture_cleanup_created_asset(int $viewerId,int $assetId,bool $created):bool {
  if(!$created||$viewerId<1||$assetId<1)return false;
  $asset=sf_media_asset($assetId);
  if(!$asset||(int)($asset['created_by']??0)!==$viewerId)return false;
  $db=sf_db();
  foreach(['SELECT COUNT(*) FROM media_links WHERE asset_id=?',
    'SELECT COUNT(*) FROM story_capture_submissions WHERE asset_id=?'] as $sql){
    $q=$db->prepare($sql);$q->execute([$assetId]);
    if((int)$q->fetchColumn()>0)return false;
  }
  return sf_media_delete_asset($assetId);
}
