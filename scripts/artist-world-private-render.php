<?php
declare(strict_types=1);
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
require_once SF_ROOT.'/api/story-media-evidence-core.php';
require_once SF_ROOT.'/api/story-assembly-core.php';
require_once SF_ROOT.'/api/story-composer-core.php';
require_once SF_ROOT.'/api/storyboard-preview-core.php';
require_once SF_ROOT.'/api/story-render-source-core.php';
require_once SF_ROOT.'/api/story-render-worker-core.php';
require_once SF_ROOT.'/api/story-render-mix-core.php';
require_once SF_ROOT.'/api/story-render-transition-core.php';
require_once SF_ROOT.'/api/story-render-edl-core.php';
require_once SF_ROOT.'/api/story-render-cut-core.php';
$editor=0;
foreach(array_slice($argv,1) as $arg)
 if(preg_match('/^--editor-id=([1-9][0-9]*)$/D',$arg,$m))$editor=(int)$m[1];
if($editor<1){fwrite(STDERR,"Usage: php scripts/artist-world-private-render.php --editor-id=EDITOR_ID\n");exit(2);}
try{
 $result=sf_render_run_once($editor);
 echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
 exit(in_array($result['status'],['empty','private_review'],true)?0:3);
}catch(Throwable $e){
 fwrite(STDERR,"Private render unavailable; verify supervisor opt-in and approved sources.\n");
 exit(4);
}
