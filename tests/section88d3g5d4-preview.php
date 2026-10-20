<?php
declare(strict_types=1);
/** Separate private metadata/stream authorization test; no invented footage. */
require __DIR__.'/section88d3f-storyboard.php';
require_once dirname(__DIR__).'/api/story-chat-transition-core.php';
$previewChecks=0;
function previewPass(bool $ok,string $why):void{
 global $previewChecks;
 if(!$ok)throw new RuntimeException('G5D4 preview FAIL: '.$why);
 $previewChecks++;echo 'G5D4 PREVIEW PASS '.$why.PHP_EOL;
}
function previewDeny(callable $fn,string $why):void{
 $blocked=false;try{$fn();}catch(RuntimeException|InvalidArgumentException $e){$blocked=true;}
 previewPass($blocked,$why);
}
previewDeny(fn()=>sf_chat_transition_preview(2,$id),
 'Contributors cannot inspect private storyboard or render review metadata.');
$choose=sf_chat_transition_preview(1,null);
previewPass($choose['requires_selection']&&count($choose['assemblies'])>=1&&
 !$choose['executed_changes'],'Unknown assembly displays selector without mutation.');
$view=sf_chat_transition_preview(1,$id);
previewPass(!$view['requires_selection']&&$view['assembly']['revision']===4&&
 count($view['cards'])===3&&$view['cut_count']===2,
 'Chat storyboard reads exact current private cue and boundary count.');
previewPass($view['metadata_only']&&!$view['published']&&
 !$view['render_started']&&$view['render_reviews']===[],
 'Chat does not invent a private rendered MP4 or start publication.');
previewPass(count($view['blockers'])>0,
 'Missing source material is accurately surfaced as a blocker.');
previewPass(isset($view['transition_requirements']['requires_rights_and_verified_local_master'])&&
 $view['transition_requirements']['requires_rights_and_verified_local_master']===true,
 'FFmpeg transition controls retain local hash and rights certification gate.');
$bad=null;
try{sf_chat_transition_preview(1,999999);}catch(InvalidArgumentException|RuntimeException $e){$bad=true;}
previewPass($bad===true,'Unknown private assembly is never represented as a valid video.');
echo "G5D4 PRIVATE PREVIEW: $previewChecks assertions passed".PHP_EOL;
