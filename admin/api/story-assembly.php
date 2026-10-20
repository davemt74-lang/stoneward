<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/media-core.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
require_once SF_ROOT.'/api/story-capture-core.php';
require_once SF_ROOT.'/api/story-capture-rights-core.php';
require_once SF_ROOT.'/api/story-transcript-core.php';
require_once SF_ROOT.'/api/story-scene-core.php';
require_once SF_ROOT.'/api/story-assembly-core.php';
require_once SF_ROOT.'/api/story-assembly-chat-core.php';
require_once SF_ROOT.'/api/story-assembly-history-core.php';
require_once SF_ROOT.'/api/story-assembly-restore-core.php';
require_once SF_ROOT.'/api/ai.php';
require_once SF_ROOT.'/api/story-inference-worker-core.php';
require_once SF_ROOT.'/api/story-composer-core.php';
require_once SF_ROOT.'/api/storyboard-preview-core.php';
require_once SF_ROOT.'/api/story-media-evidence-core.php';
require_once SF_ROOT.'/api/story-render-source-core.php';
require_once SF_ROOT.'/api/story-render-worker-core.php';
require_once SF_ROOT.'/api/story-render-mix-core.php';
require_once SF_ROOT.'/api/story-render-transition-core.php';
require_once SF_ROOT.'/api/story-render-edl-core.php';
require_once SF_ROOT.'/api/story-render-cut-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_admin_require_auth($method!=='GET');
$id=(int)$me['id'];
if(!sf_team_can($id,'team.assign_roles'))
 sf_json_response(['ok'=>false,'error'=>'forbidden'],403);
try{
 if($method==='GET'){
  $assemblyId=(int)($_GET['assembly_id']??0);
  if(($_GET['action']??'')==='compare_revisions'){
   $assembly=(int)($_GET['assembly_id']??0);
   sf_json_response(['ok'=>true,'comparison'=>sf_assembly_history_compare($id,$assembly,
     (int)($_GET['from_revision']??0),(int)($_GET['to_revision']??0))]);
  }
  sf_json_response(['ok'=>true,'assemblies'=>sf_assembly_list($id),
    'assembly'=>$assemblyId>0?sf_assembly_read($id,$assemblyId):null,
    'revision_history'=>$assemblyId>0?sf_assembly_history_list($id,$assemblyId):[],
    'restoration_proposals'=>$assemblyId>0?sf_assembly_restore_list($id,$assemblyId):[],
    'composer_enabled'=>sf_composer_enabled(),
    'composer_proposals'=>$assemblyId>0?sf_composer_list($id,$assemblyId):[],
    'storyboard'=>$assemblyId>0?sf_storyboard_preview($id,$assemblyId):null,
    'render_certification'=>$assemblyId>0?sf_render_source_admin_status($id,$assemblyId,(int)(sf_assembly_read($id,$assemblyId)['revision']??0)):null,
    'render_enabled'=>sf_render_enabled(),
    'render_jobs'=>$assemblyId>0?sf_render_jobs($id,$assemblyId):[],
    'render_mix'=>$assemblyId>0?sf_render_mix_settings($id,$assemblyId,(int)(sf_assembly_read($id,$assemblyId)['revision']??0)):null,
    'render_transition'=>$assemblyId>0?sf_render_transition_settings($id,$assemblyId,(int)(sf_assembly_read($id,$assemblyId)['revision']??0)):null,
    'render_edl'=>$assemblyId>0?sf_render_edl_preview($id,$assemblyId,(int)(sf_assembly_read($id,$assemblyId)['revision']??0)):null,
    'render_audio_candidates'=>$assemblyId>0?sf_render_audio_candidates($id):[],
    'candidates'=>sf_assembly_candidates($id),'publication_enabled'=>false,
    'video_rendering_enabled'=>false,'ai_provider_invocation'=>false]);
 }
 if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
 $p=sf_request_json();
 $action=(string)($p['action']??'');
 if($action==='request_restore'){
   sf_json_response(['ok'=>true,'proposal'=>sf_assembly_restore_request($id,
     (int)($p['assembly_id']??0),(int)($p['revision']??0),
     (int)($p['target_revision']??0),(string)($p['reason']??''))]);
 }
 if($action==='review_restore'){
   sf_json_response(['ok'=>true,'changed'=>sf_assembly_restore_review($id,
     (int)($p['proposal_id']??0),(string)($p['decision']??''),
     (string)($p['basis']??''))]);
 }
 if($action==='apply_restore'){
   sf_json_response(['ok'=>true,'result'=>sf_assembly_restore_apply($id,
     (int)($p['proposal_id']??0))]);
 }
 if($action==='configure_transition'){
   sf_render_transition_set($id,(int)($p['assembly_id']??0),
     (int)($p['revision']??0),(string)($p['style']??''),
     (int)($p['overlap_ms']??0));
   sf_json_response(['ok'=>true,'message'=>'Private audio/video crossfades recorded.']);
 }
 if($action==='configure_cuts'){
   if(!is_array($p['cuts']??null))sf_json_response(['ok'=>false,'error'=>'invalid_cuts'],422);
   sf_render_cut_set($id,(int)($p['assembly_id']??0),(int)($p['revision']??0),$p['cuts']);
   sf_json_response(['ok'=>true,'message'=>'Individual revision-locked audio/video cuts recorded.']);
 }
 if($action==='configure_mix'){
   sf_render_mix_set($id,(int)($p['assembly_id']??0),(int)($p['revision']??0),
     (string)($p['track_id']??''),(int)($p['gain_pct']??0),
     (int)($p['duck_pct']??0),(int)($p['fade_ms']??0));
   sf_json_response(['ok'=>true,'message'=>'Licensed audio bed preset saved for this revision.']);
 }
 if($action==='configure_render'){
  if(!is_bool($p['enabled']??null))sf_json_response(['ok'=>false,'error'=>'invalid_enabled'],422);
  sf_render_configure($id,$p['enabled']);
  sf_json_response(['ok'=>true,'render_enabled'=>sf_render_enabled()]);
 }
 if($action==='enqueue_render'){
  sf_json_response(['ok'=>true,'job'=>sf_render_enqueue($id,(int)($p['assembly_id']??0),
   (int)($p['revision']??0))]);
 }
 if($action==='bind_render_master'){
  sf_render_master_bind($id,(int)($p['assembly_id']??0),(int)($p['revision']??0),
    (string)($p['track_id']??''),(int)($p['asset_id']??0));
  sf_json_response(['ok'=>true,'message'=>'Private SHA-256 music master bound to current editorial revision.']);
 }
 if($action==='review_music_rights'){
  sf_storyboard_license_review($id,(int)($p['assembly_id']??0),
    (string)($p['track_id']??''),(string)($p['license_type']??''),
    (string)($p['decision']??''),(string)($p['basis']??''),
    (int)($p['revision']??0));
  sf_json_response(['ok'=>true,'message'=>'Private scoped music-license review recorded.']);
 }
 if($action==='configure_composer'){
  if(!is_bool($p['enabled']??null))sf_json_response(['ok'=>false,'error'=>'invalid_enabled'],422);
  sf_composer_configure($id,$p['enabled']);
  sf_json_response(['ok'=>true,'composer_enabled'=>sf_composer_enabled()]);
 }
 if($action==='compose'){
  sf_json_response(['ok'=>true,'proposal'=>sf_composer_queue($id,
    (int)($p['assembly_id']??0),(int)($p['revision']??0))]);
 }
 if($action==='review_composer'){
  sf_json_response(['ok'=>true,'changed'=>sf_composer_review($id,
    (int)($p['proposal_id']??0),(string)($p['decision']??''))]);
 }
 if($action==='apply_composer'){
  sf_json_response(['ok'=>true,'result'=>sf_composer_apply($id,(int)($p['proposal_id']??0))]);
 }
 if($action==='create'){
  sf_json_response(['ok'=>true,'assembly_id'=>sf_assembly_create($id,
   (string)($p['title']??''),(string)($p['brief']??''))]);
 }
 if($action==='chat_move'){
  if(($p['confirmed']??null)!==true)
   throw new InvalidArgumentException('Timeline move requires separate human confirmation.');
  sf_json_response(['ok'=>true,'result'=>sf_assembly_chat_move($id,
    (int)($p['assembly_id']??0),(int)($p['revision']??0),(int)($p['index']??-1),
    (string)($p['direction']??''),(string)($p['review_basis']??''))]);
 }
 if($action==='replace'){
  if(!is_array($p['items']??null))sf_json_response(['ok'=>false,'error'=>'invalid_items'],422);
  sf_json_response(['ok'=>true,'result'=>sf_assembly_replace($id,
   (int)($p['assembly_id']??0),(int)($p['revision']??0),$p['items'])]);
 }
 sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
}catch(InvalidArgumentException $e){
 sf_json_response(['ok'=>false,'error'=>'invalid_assembly','message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
 sf_json_response(['ok'=>false,'error'=>'assembly_conflict_or_forbidden','message'=>$e->getMessage()],409);
}catch(Throwable $e){
 error_log('[Stonefellow assembly] '.get_class($e));
 sf_json_response(['ok'=>false,'error'=>'assembly_unavailable'],503);
}
