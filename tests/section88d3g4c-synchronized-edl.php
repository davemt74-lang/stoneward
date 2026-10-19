<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/story-render-edl-core.php';
require dirname(__DIR__).'/api/story-render-mix-core.php';
$checks=0;
function checkG4C(bool $v,string $description):void{
 global $checks;
 if(!$v)throw new RuntimeException('FAIL: '.$description);
 ++$checks;echo 'PASS: '.$description.PHP_EOL;
}
function rejectG4C(callable $fn,string $description):void{
 try{$fn();throw new LogicException('Invalid EDL was accepted.');}
 catch(InvalidArgumentException|RuntimeException $e){checkG4C(true,$description);}
}
$items=[
 ['kind'=>'scene','source_id'=>'1','duration_ms'=>2000,'start_ms'=>100],
 ['kind'=>'transcript','source_id'=>'2','duration_ms'=>2000,'start_ms'=>0],
 ['kind'=>'track','source_id'=>'song','duration_ms'=>2000,'start_ms'=>0],
];
$plan=['revision'=>4,'total_ms'=>6000,'items'=>$items];
$plain=sf_render_edl_build($plan);
checkG4C($plain['output_total_ms']===6000&&count($plain['cuts'])===2&&$plain['cuts'][0]['duration_ms']===0,'Hard-cut EDL preserves original run time.');
checkG4C($plain['dialogue_duck_windows']===[['start_ms'=>2000,'end_ms'=>4000]],'Dialogue follows hard-cut clip clock.');
$transition=['style'=>'fade','overlap_ms'=>250];
$bed=['track_id'=>'song','gain_pct'=>20,'duck_pct'=>35,'fade_ms'=>500];
$edit=sf_render_edl_build($plan,$transition,$bed);
checkG4C($edit['output_total_ms']===5500&&$edit['source_total_ms']===6000,'Overlap reduces actual output duration by 500ms.');
checkG4C($edit['clips'][1]['output_in_ms']===1750&&$edit['clips'][1]['output_out_ms']===3750,'Overlapping dialogue starts with its incoming transition.');
checkG4C($edit['clips'][2]['output_in_ms']===3500&&$edit['cuts'][1]['start_ms']===3500,'Second overlap lands on the certified clock.');
checkG4C($edit['dialogue_duck_windows']===[['start_ms'=>1750,'end_ms'=>3750]],'Music ducking covers the entire actually audible dialogue clip.');
checkG4C($edit['music']['enabled']===true&&$edit['music']['gain_pct']===20,'Licensed music metadata is tied to the correct catalog cue.');
checkG4C($edit['metadata_only']&&$edit['publication_enabled']===false&&!isset($edit['clips'][0]['path']),'EDL does not leak source paths or permit publishing.');
require dirname(__DIR__).'/api/story-render-transition-core.php';
$effective=sf_render_transition_effective_manifest($plan,$transition);
checkG4C($effective['total_ms']===5500,'Private render effective duration matches EDL.');
checkG4C($effective['edl_duck_windows']===$edit['dialogue_duck_windows'],'Production mix and metadata preview consume identical dialogue boundaries.');
$filter=sf_render_mix_filter($effective,$bed);
checkG4C(str_contains($filter,'between(t,1.750,3.750)'),'FFmpeg soundtrack duck follows true audible dialogue times.');
rejectG4C(fn()=>sf_render_edl_build($plan,['style'=>'shell;evil','overlap_ms'=>250]),'Untrusted FFmpeg transition filter rejected.');
rejectG4C(fn()=>sf_render_edl_build($plan,['style'=>'fade','overlap_ms'=>800]),'Excessive overlap rejected.');
rejectG4C(fn()=>sf_render_edl_build($plan,null,['track_id'=>'other','gain_pct'=>20,'duck_pct'=>35,'fade_ms'=>500]),'Unknown licensed music cue rejected.');
$stale=$plan;$stale['total_ms']=6200;
rejectG4C(fn()=>sf_render_edl_build($stale),'Source duration mismatch rejected.');
$short=$plan;$short['items'][1]['duration_ms']=500;$short['total_ms']=4500;
rejectG4C(fn()=>sf_render_edl_build($short,$transition),'Unsafe shot handle rejected.');
$double=[
 ['kind'=>'transcript','source_id'=>'1','duration_ms'=>2000,'start_ms'=>0],
 ['kind'=>'transcript','source_id'=>'2','duration_ms'=>2000,'start_ms'=>0],
];
$joined=sf_render_edl_build(['total_ms'=>4000,'items'=>$double],$transition);
checkG4C($joined['dialogue_duck_windows']===[['start_ms'=>0,'end_ms'=>3750]],'Adjacent overlapping dialogue merges into one undoubled music-duck window.');
echo "88D3G4C: $checks synchronized timeline assertions passed".PHP_EOL;
