<?php
declare(strict_types=1);
/**
 * G5D-5: read-only cross-feature production health for the Agent Chat canvas.
 * This reports evidence, not an AI quality score or a publishing authorization.
 * The editor's actual source, music rights, revision archive and private
 * playback status are independently reevaluated on every request.
 */
function sf_chat_production_certification(int $editor,?int $assemblyId):array{
 if($editor<1||!sf_team_can($editor,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 require_once __DIR__.'/story-chat-transition-core.php';
 $preview=sf_chat_transition_preview($editor,$assemblyId);
 if($preview['requires_selection']){
  return ['requires_selection'=>true,'assemblies'=>$preview['assemblies'],
   'assembly'=>null,'gates'=>[],'passed'=>0,'total'=>0,
   'private_only'=>true,'read_only'=>true,'publish_allowed'=>false];
 }
 require_once __DIR__.'/story-assembly-history-core.php';
 require_once __DIR__.'/story-stonefellow-music-auto-core.php';
 $assembly=$preview['assembly'];$id=(int)$assembly['id'];
 $cards=$preview['cards'];$blockers=$preview['blockers'];
 $history=sf_assembly_history_list($editor,$id);
 $archived=[];
 foreach(array_slice($history,0,3) as $r){
  // A digest mismatch or invalid archived entry is a certification failure,
  // never an exception that makes the entire Admin Agent Chat unusable.
  try{
   $entry=sf_assembly_history_read($editor,$id,(int)$r['revision']);
   if(!hash_equals((string)$entry['digest'],
       hash('sha256',json_encode($entry['items'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))))
    throw new RuntimeException('Revision digest not verifiable');
   $archived[]=['revision'=>(int)$r['revision'],'valid'=>true,
     'item_count'=>(int)$r['item_count']];
  }catch(Throwable $e){
   $archived[]=['revision'=>(int)$r['revision'],'valid'=>false,
     'item_count'=>(int)$r['item_count']];
  }
 }
 $sourceInvalid=array_values(array_filter($cards,
  static fn(array $c):bool=>!$c['source_verified']));
 $music=array_values(array_filter($cards,
  static fn(array $c):bool=>$c['kind']==='track'));
 $musicPending=array_values(array_filter($music,
  static fn(array $c):bool=>$c['sync_clearance']!=='cleared'||$c['master_clearance']!=='cleared'));
 $auto=0;
 foreach($music as $c)
  if(!empty(sf_stonefellow_music_auto_status((string)$c['source_id'])['eligible']))$auto++;
 $sourcesOk=count($cards)>0&&count($sourceInvalid)===0;
 $rightsOk=count($cards)>0&&count($blockers)===0;
 $musicOk=count($musicPending)===0;
 $historyOk=count($history)>0&&count($archived)>0&&
  count(array_filter($archived,static fn(array $x):bool=>!$x['valid']))===0;
 $edl=$preview['edl'];
 $cutCount=(int)$preview['cut_count'];
 $transitionsOk=$cutCount>0&&$preview['transition']!==null&&
  !empty($edl['available'])&&count($preview['cuts'])===$cutCount;
 $playbackOk=count($preview['render_reviews'])>0;
 $gates=[
  ['key'=>'source','label'=>'Verified scene and dialogue sources',
   'passed'=>$sourcesOk,'detail'=>count($cards).' cues · '.count($sourceInvalid).' invalid sources'],
  ['key'=>'music','label'=>'Stonefellow music sync and master rights',
   'passed'=>$musicOk,'detail'=>count($music).' tracks · '.$auto.
     ' standing-original authorizations · '.count($musicPending).' rights holds'],
  ['key'=>'evidence','label'=>'Contribution and local-media preflight',
   'passed'=>$rightsOk,'detail'=>count($blockers).' open source or rights blockers'],
  ['key'=>'history','label'=>'Integrity-checked editorial revisions',
   'passed'=>$historyOk,'detail'=>count($history).' archived revisions · '.
     count($archived).' checked digests'],
  ['key'=>'transitions','label'=>'Synchronized transitions and audio bridges',
   'passed'=>$transitionsOk,'detail'=>$cutCount.' boundaries · '.
      ($preview['transition']===null?'no reviewed crossfade':
        'saved reviewed crossfade').' · '.(!empty($edl['available'])?'EDL available':'EDL unavailable')],
  ['key'=>'playback','label'=>'Private render playback verification',
   'passed'=>$playbackOk,'detail'=>count($preview['render_reviews']).
      ' currently valid hash-verified private renders']
 ];
 $passed=count(array_filter($gates,static fn(array $g):bool=>$g['passed']));
 return [
  'requires_selection'=>false,'assemblies'=>[],'assembly'=>$assembly,
  'gates'=>$gates,'passed'=>$passed,'total'=>count($gates),
  'verified_history'=>$archived,'history_count'=>count($history),
  'blockers'=>array_slice($blockers,0,15),
  'latest_private_job_id'=>$playbackOk?(int)$preview['render_reviews'][0]['job_id']:null,
  'metadata_only'=>!$playbackOk,
  'all_readiness_gates_passed'=>$passed===count($gates),
  'private_only'=>true,'read_only'=>true,'render_started'=>false,
  'publish_allowed'=>false,'media_deleted'=>false,
  'required_human_review'=>true
 ];
}
