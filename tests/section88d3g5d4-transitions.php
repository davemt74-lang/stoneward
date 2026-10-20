<?php
declare(strict_types=1);
/** Reuse real FFmpeg multiple-cut acceptance, then certify new Chat review gates. */
require __DIR__.'/section88d3g4e-per-cut.php';
function sf_capture_clean($value,int $max=400):string{
 return substr(trim((string)$value),0,$max);
}
require_once dirname(__DIR__).'/api/story-chat-transition-core.php';
$checksG5D4=0;
function g5d4Pass(bool $ok,string $label):void{
 global $checksG5D4;
 if(!$ok)throw new RuntimeException('G5D4 FAIL '.$label);
 $checksG5D4++;echo 'G5D4 PASS '.$label.PHP_EOL;
}
function g5d4Deny(callable $job,string $label):void{
 $blocked=false;try{$job();}catch(RuntimeException|InvalidArgumentException $e){$blocked=true;}
 g5d4Pass($blocked,$label);
}
g5d4Deny(fn()=>sf_chat_transition_global(1,9,2,'fade',250,'short'),
 'Private transition cannot bypass written human review.');
g5d4Deny(fn()=>sf_chat_transition_global(2,9,2,'fade',250,
 'Contributor cannot change production audio/video transitions.'),
 'Contributor cannot authorize transition changes.');
g5d4Deny(fn()=>sf_chat_transition_global(1,9,1,'fade',250,
 'Older message cannot override current approved revision.'),
 'Stale assembly revision rejected.');
g5d4Deny(fn()=>sf_chat_transition_global(1,9,2,'evil-filter',250,
 'Untrusted filter commands cannot become FFmpeg code.'),
 'Arbitrary FFmpeg transition strings rejected.');
g5d4Deny(fn()=>sf_chat_transition_global(1,9,2,'fade',999,
 'Excessive crossfade is not within reviewed pilot limits.'),
 'Unsupported overlap rejected.');
$auditFailure=true;
g5d4Deny(fn()=>sf_chat_transition_global(1,9,2,'fade',250,
 'The soundtrack fades together with the scene for continuity.'),
 'Failed activity audit rolls back global transition.');
$auditFailure=false;
g5d4Pass(sf_render_transition_settings(1,9,2)['overlap_ms']===500,
 'Audit rollback preserves previous approved global crossfade.');
sf_chat_transition_global(1,9,2,'fade',250,
 'The soundtrack fades together with the scene for continuity.');
g5d4Pass(sf_render_transition_settings(1,9,2)['overlap_ms']===250,
 'Reviewed synchronized audio/video fade persisted without starting render.');
$cuts=[['style'=>'fade','overlap_ms'=>250],
       ['style'=>'wipeleft','overlap_ms'=>500]];
g5d4Deny(fn()=>sf_chat_transition_cuts(1,9,2,$cuts,'too short'),
 'Per-cut audio bridge requires written editorial reason.');
g5d4Deny(fn()=>sf_chat_transition_cuts(2,9,2,$cuts,
 'Contributor may not change private audio bridge parameters.'),
 'Contributor denied exact per-cut edit.');
g5d4Deny(fn()=>sf_chat_transition_cuts(1,9,2,
 [['style'=>'evil;exec','overlap_ms'=>250],$cuts[1]],
 'Injection attempt cannot be stored in cut styles.'),
 'Per-cut FFmpeg injection rejected.');
$auditFailure=true;
g5d4Deny(fn()=>sf_chat_transition_cuts(1,9,2,$cuts,
 'Music bridge should overlap the incoming dialogue and visual shot.'),
 'Failed audit rolls back all per-cut changes.');
$auditFailure=false;
g5d4Pass(sf_render_cut_settings(1,9,2)===null,
 'Rejected per-cut preset does not survive.');
sf_chat_transition_cuts(1,9,2,$cuts,
 'Music bridge should overlap the incoming dialogue and visual shot.');
$stored=sf_render_transition_settings(1,9,2);
g5d4Pass($stored['cuts']===$cuts,
 'Exact reviewed per-cut crossfade timing persists in old canonical renderer.');
$edl=sf_render_edl_build($plan,$stored);
g5d4Pass($edl['output_total_ms']===5250&&
  $edl['dialogue_duck_windows']===[['start_ms'=>1750,'end_ms'=>3750]],
 'Preview cut timing and music duck bridge reflect real EDL calculations.');
$rights=false;
g5d4Deny(fn()=>sf_chat_transition_cuts(1,9,2,$cuts,
 'Rights revocation must block this audio bridge change now.'),
 'Revoked render source permission blocks transition updates.');
$rights=true;
$db->exec("INSERT INTO story_render_jobs(assembly_id,revision,status,created_by,created_at,updated_at)
 VALUES(9,2,'queued',1,'2026-10-19','2026-10-19')");
g5d4Deny(fn()=>sf_chat_transition_cuts(1,9,2,$cuts,
 'A queued private video must freeze transition parameters.'),
 'Queued render revision locks further cut edits.');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
$agent=file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
g5d4Pass(str_contains($api,"'chat_transition'")&&str_contains($api,"'chat_cuts'")&&
 str_contains($api,"'confirmed'")&&str_contains($ui,'data-agent-transition-global')&&
 str_contains($ui,'data-agent-transition-cuts')&&
 str_contains($ui,'story-render-review.php?action=stream')&&
 str_contains($agent,"'production_timeline_transitions'"),
 'Authenticated chat transitions, real private review player and planner are wired.');
echo "G5D4: $checksG5D4 focused acceptance checks passed".PHP_EOL;
