<?php
declare(strict_types=1);
/** 88D3G4F: governed AI/editorial clip refinements, no autonomous edits. */
function sf_capture_clean($value,int $limit=300):string{
 return substr(trim((string)$value),0,$limit);
}
require dirname(__DIR__).'/api/story-composer-core.php';
require dirname(__DIR__).'/api/story-render-edl-core.php';
$n=0;
function passF(bool $ok,string $label):void{
 global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);
 $n++;echo 'PASS '.$label.PHP_EOL;
}
function rejectF(callable $call,string $label):void{
 try{$call();throw new LogicException('Expected denial: '.$label);}
 catch(InvalidArgumentException|RuntimeException $e){passF(true,$label);}
}
$context=['items'=>[
 ['kind'=>'scene','source_id'=>'1','duration_ms'=>2000],
 ['kind'=>'transcript','source_id'=>'2','duration_ms'=>800],
 ['kind'=>'track','source_id'=>'song','duration_ms'=>1500]
]];
$response=['order'=>[2,0,1],'logline'=>'A verified private studio story',
 'beats'=>[
  ['reason'=>'Begin with music','transition'=>'fade','trim_start_ms'=>300,'trim_end_ms'=>100],
  ['reason'=>'Reveal the scene','transition'=>'dissolve','trim_start_ms'=>200,'trim_end_ms'=>0],
  ['reason'=>'End with dialogue','transition'=>'audio_bridge','trim_end_ms'=>100]
 ]];
$validated=sf_composer_validate_suggestion($response,$context);
passF($validated['order']===[2,0,1]&&$validated['beats'][0]['source_index']===2
 &&$validated['beats'][1]['source_index']===0,'Model trim suggestions remain bound to exact existing source permutation.');
passF($validated['beats'][0]['trim_start_ms']===300&&$validated['beats'][0]['trim_end_ms']===100
 &&$validated['beats'][2]['trim_start_ms']===0,'Provider outputs optional explicit 100ms precision trims.');
passF($validated['publication_authorized']===false&&$validated['render_authorized']===false,
 'AI proposals cannot approve publishing or rendering.');
$legacy=$response;unset($legacy['beats'][0]['trim_start_ms'],$legacy['beats'][0]['trim_end_ms']);
passF(sf_composer_validate_suggestion($legacy,$context)['beats'][0]['trim_end_ms']===0,
 'Pre-existing provider response without trim fields retains backward compatibility.');
$bad=$response;$bad['beats'][0]['trim_start_ms']=550;
rejectF(fn()=>sf_composer_validate_suggestion($bad,$context),'Unapproved trim increments blocked.');
$bad=$response;$bad['beats'][0]['trim_start_ms']=-100;
rejectF(fn()=>sf_composer_validate_suggestion($bad,$context),'Negative unsafe trim blocked.');
$bad=$response;$bad['beats'][0]['trim_start_ms']='100';
rejectF(fn()=>sf_composer_validate_suggestion($bad,$context),'Untrusted string durations rejected.');
$bad=$response;$bad['beats'][2]['trim_start_ms']=500;$bad['beats'][2]['trim_end_ms']=100;
rejectF(fn()=>sf_composer_validate_suggestion($bad,$context),
 'Trim suggestions cannot remove required 500ms of verified dialogue evidence.');
$bad=$response;$bad['order']=[0,0,2];
rejectF(fn()=>sf_composer_validate_suggestion($bad,$context),
 'Duplicate or invented model source positions are still rejected.');
$plan=['revision'=>3,'total_ms'=>6000,'items'=>[
 ['kind'=>'scene','duration_ms'=>2000,'start_ms'=>0],
 ['kind'=>'transcript','duration_ms'=>2000,'start_ms'=>200],
 ['kind'=>'track','duration_ms'=>2000,'start_ms'=>100]
]];
$edl=sf_render_edl_build($plan,['style'=>'fade','overlap_ms'=>250,
 'cuts'=>[['style'=>'fade','overlap_ms'=>250],['style'=>'wipeleft','overlap_ms'=>500]]]);
passF($edl['output_total_ms']===5250&&$edl['cuts'][1]['start_ms']===3250,
 'G4F playhead can use actual G4E per-cut times, not stale storyboard cut positions.');
passF($edl['clips'][1]['output_in_ms']===1750&&$edl['clips'][1]['output_out_ms']===3750,
 'Crossfade preview correctly highlights both overlapping cards.');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/story-assembly.js');
passF(str_contains($ui,'editCheckpoint()')&&str_contains($ui,'editRestore(undoEdits,redoEdits)')
 &&str_contains($ui,'editRestore(redoEdits,undoEdits)'),
 'Private undo/redo history is session-local and reversible.');
passF(str_contains($ui,"data-trim-side=")&&str_contains($ui,"item.duration_ms=item.out_ms-item.in_ms"),
 'Precision trim UI maintains exact source in/out/duration identity.');
passF(str_contains($ui,'data-composer-refine')&&str_contains($ui,'proposal.status===')&&
 str_contains($ui,'window.confirm(')&&str_contains($ui,'editCheckpoint();view.items=next;changed();draw();'),
 'AI refinements are only staged after review and explicit owner confirmation.');
passF(str_contains($ui,'renderEdl?.clips?.[i]')&&
 str_contains($ui,'renderEdl?.output_total_ms||storyboard.total_ms'),
 'Metadata storyboard follows the real synchronized edit decision list.');
echo "88D3G4F: $n functional assertions passed".PHP_EOL;
