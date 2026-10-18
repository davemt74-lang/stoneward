<?php
declare(strict_types=1);
/**
 * 88D3E supervised one-shot CLI: explicitly enabled, at most one queued proposal.
 * Browser endpoints cannot initiate provider traffic.
 */
if(PHP_SAPI!=='cli')exit(1);
define('SF_ROOT',dirname(__DIR__));
require_once SF_ROOT.'/api/bootstrap.php';
require_once SF_ROOT.'/api/ai.php';
require_once SF_ROOT.'/api/media-core.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
require_once SF_ROOT.'/api/story-capture-core.php';
require_once SF_ROOT.'/api/story-capture-rights-core.php';
require_once SF_ROOT.'/api/story-transcript-core.php';
require_once SF_ROOT.'/api/story-scene-core.php';
require_once SF_ROOT.'/api/story-assembly-core.php';
require_once SF_ROOT.'/api/story-inference-worker-core.php';
require_once SF_ROOT.'/api/story-composer-core.php';
$editor=0;foreach(array_slice($argv,1) as $arg)
 if(preg_match('/^--editor-id=([1-9][0-9]*)$/D',$arg,$m))$editor=(int)$m[1];
if(!$editor){fwrite(STDERR,"Usage: php scripts/artist-world-story-composer.php --editor-id=EDITOR_ID\n");exit(2);}
try{
 $result=sf_composer_run_once($editor);
 echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
 exit(in_array($result['status'],['empty','pending_review'],true)?0:3);
}catch(Throwable $e){
 // Do not disclose provider keys, private story metadata, or provider response.
 fwrite(STDERR,"Story composer unavailable; check owner opt-in, rights and server-side provider configuration.\n");
 exit(4);
}
