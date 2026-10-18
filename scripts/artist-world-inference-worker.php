<?php
declare(strict_types=1);
/** CLI-only supervised Artist World worker. No network-accessible worker route. */
if(PHP_SAPI!=='cli')exit(1);
define('SF_ROOT',dirname(__DIR__));
require_once SF_ROOT.'/api/bootstrap.php';
require_once SF_ROOT.'/api/ai.php';
require_once SF_ROOT.'/api/media-core.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
require_once SF_ROOT.'/api/story-capture-core.php';
require_once SF_ROOT.'/api/story-media-evidence-core.php';
require_once SF_ROOT.'/api/story-capture-rights-core.php';
require_once SF_ROOT.'/api/story-inference-queue-core.php';
require_once SF_ROOT.'/api/story-inference-worker-core.php';
// Operator must supply an actual authorized editor; no implicit superuser ID.
$editor=0;
foreach(array_slice($argv,1) as $arg)
 if(preg_match('/^--editor-id=([1-9][0-9]*)$/D',$arg,$m))$editor=(int)$m[1];
if($editor<1){fwrite(STDERR,"Usage: php scripts/artist-world-inference-worker.php --editor-id=EDITOR_ID\n");exit(2);}
try{
 $result=sf_inference_run_once($editor);
 echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
 exit(in_array($result['status'],['empty','pending_review'],true)?0:3);
}catch(Throwable $e){
 // No provider messages, source media, credentials or paths in worker output.
 fwrite(STDERR,"Inference unavailable; verify owner opt-in, provider configuration and editor permissions.\n");
 exit(4);
}
