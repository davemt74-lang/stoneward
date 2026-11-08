<?php
declare(strict_types=1);

$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-canvas-ideas-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js','assets/vendor/canvas'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }
    rmdir($fixture);
});
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'],true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
file_put_contents($fixture.'/data/catalog.json',json_encode([
    ['id'=>'published-track','title'=>'Published fixture song','duration'=>120],
    ['id'=>'private-track','title'=>'Secret unreleased song','public_visible'=>false]]));
file_put_contents($fixture.'/data/releases.json','[]');file_put_contents($fixture.'/data/shows.json','[]');
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';require_once $root.'/api/migrations.php';
require_once $root.'/api/canvas-projects-core.php';require_once $root.'/api/canvas-project-ideas.php';
foreach(sf_canvas_library_registry() as $library)copy($root.'/'.$library['path'],$fixture.'/'.$library['path']);
function verify(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function rejects(callable $run,string $message): void {
    try{$run();}catch(InvalidArgumentException|UnexpectedValueException|DomainException|RuntimeException){verify(true,$message);return;}
    throw new RuntimeException('Expected rejection: '.$message);
}
$db=sf_db();
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
foreach([1,2,3,4] as $owner)$q->execute([$owner,'fixture'.$owner.'@example.invalid','Listener',password_hash('fixture',PASSWORD_DEFAULT),$owner===3?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');sf_token_adjust(1,100000,'admin_grant','ideas-fixture');sf_token_adjust(2,100000,'admin_grant','ideas-fixture-2');
$user=['id'=>1,'role'=>'user'];$provider=[['provider'=>'openai','model'=>'fixture']];
$base=['canvas_kind'=>'custom','track_id'=>'','title'=>'Legacy geometry','html'=>'<section class="shapes"></section>','css'=>'.shapes{color:#ccaaff}'];
$legacy=sf_agent_scene_history_save(1,$base);$own=[];
for($i=1;$i<=14;$i++)$own[]=sf_agent_scene_history_save(1,array_replace($base,['title'=>'Canvas '.$i,
    'design_brief'=>'Original private creative instruction '.$i,'agent_palette'=>['accent'=>'#aabbcc','secondary'=>'#ccbbdd','surface'=>'#121212'],
    'music_inputs'=>['mode'=>'selected','track_ids'=>['chosen-one','chosen-two']]]));
$foreign=sf_agent_scene_history_save(2,array_replace($base,['title'=>'Foreign private invention','design_brief'=>'Other owner secret original prompt']));
$context=sf_canvas_project_ideas_context(1,$legacy['id']);
verify(count($context['canvas_history'])===12&&$context['canvas_history'][0]['id']===$legacy['id']&&
    !isset($context['canvas_history'][0]['design_brief'])&&isset($context['canvas_history'][0]['existing_markup']),
    'Idea references prioritize the current owned canvas and describe legacy source without fabricating its original prompt');
verify(str_contains(json_encode($context),'Original private creative instruction')&&!str_contains(json_encode($context),'Other owner secret')&&
    !str_contains(json_encode($context),'Foreign private invention')&&!str_contains(json_encode($context),'Secret unreleased song'),
    'Only this account history and currently published artist facts reach creative planning');
verify($context['canvas_history'][1]['agent_palette']['surface']==='#121212'&&$context['canvas_history'][1]['music_inputs']===['mode'=>'selected','count'=>2]&&
    !str_contains(json_encode($context),'chosen-one'),
    'Style and music scope are compact reference data rather than duplicated private resource lists');
rejects(fn()=>sf_canvas_project_ideas_context(1,$foreign['id']),'A current canvas cannot point at another account history');
$offered=array_column($context['canvas_history'],'id');
$idea=['title'=>'Interwoven rooms','concept'=>'Combine earlier gestures into a new navigable scene.',
    'brief'=>'Build a new canvas that integrates the selected earlier visual structures and interactions.','source_scene_ids'=>array_slice($offered,0,5)];
verify(count(sf_canvas_project_idea_validate($idea,$offered)['source_scene_ids'])===5,
    'A concept can combine more than three offered canvases without a presentation-specific rule');
rejects(fn()=>sf_canvas_project_idea_validate(array_replace($idea,['source_scene_ids'=>[$foreign['id']]]),$offered),
    'The model cannot expand the offered history to another account scene');
$calls=0;$received=null;$system='';$request=['request_id'=>str_repeat('a',32),'current_canvas_id'=>$legacy['id']];
$infer=static function(array $provider,string $prompt,string $message,int $output)use(&$calls,&$received,&$system,$idea,$request,$user):array {
    $calls++;$received=json_decode($message,true,16,JSON_THROW_ON_ERROR);$system=$prompt;
    $pending=sf_canvas_project_ideas_suggest($user,$request,null,[$provider]);
    verify($pending['state']==='processing','A simultaneous replay sees the existing idea request and makes no second provider call');
    return ['text'=>json_encode($idea),'provider'=>'openai','model'=>'fixture','input_tokens'=>40,'output_tokens'=>24];
};
$before=sf_token_balance(1);$result=sf_canvas_project_ideas_suggest($user,$request,$infer,$provider);$project=$result['proposal'];
verify($calls===1&&$result['state']==='ready'&&$project['status']==='awaiting_approval'&&$project['source']==='agent',
    'One explicit idea request creates an approval proposal without beginning a canvas or image build');
verify(array_column($received['references']['available_canvas_libraries'],'name')===['Anime.js','Color Thief','D3','Matter.js','Three.js']&&
    array_keys($received['references']['available_canvas_libraries'][0])===['name','when']&&
    str_contains($system,'Consider native browser capabilities first'),
    'Planning sees optional library selection criteria while keeping proposals focused on the experience');
verify(count($received['references']['canvas_history'])===12&&
    $received['references']['canvas_history'][1]['design_brief']==='Original private creative instruction 14'&&
    isset($received['references']['canvas_history'][0]['existing_styles'])&&
    !isset($received['references']['canvas_history'][0]['design_brief'])&&
    array_diff($idea['source_scene_ids'],array_column($received['references']['canvas_history'],'id'))===[],
    'The text model receives real earlier prompts and legacy source context for every chosen integration source');
verify(in_array('my_purchases',array_column($received['references']['available_data_tools'],'id'),true)&&
    in_array('personal_listening_stats',array_column($received['references']['available_data_tools'],'id'),true)&&
    !isset($received['references']['available_data_tools'][0]['rows']),
    'Creative planning sees reusable data capabilities without reading purchase or listening values');
$payload=$project['project']??$project['build_request']??[];
verify($payload['brief']===$idea['brief']&&$payload['source_scene_ids']===$idea['source_scene_ids']&&$payload['track_id']==='',
    'An authored concept carries its exact build brief and source set without requiring a song');
verify($result['active_tokens_used']===64&&sf_token_balance(1)===$before-64,
    'Idea generation settles exactly the reported text-model usage against this account');
verify(sf_canvas_project_ideas_suggest($user,$request,$infer,$provider)===$result&&$calls===1,
    'A completed request replay returns the saved proposal and original token receipt');
rejects(fn()=>sf_canvas_project_ideas_suggest($user,$request+['brief'=>'Different input'],$infer,$provider),
    'An idea request ID cannot be reused for different instructions');

$plan=['request_id'=>str_repeat('b',32),'project'=>['canvas_kind'=>'custom','track_id'=>'','brief'=>'Create a quiet <canvas> interface with my selected songs.',
    'music_inputs'=>['mode'=>'selected','track_ids'=>['song-two','song-one']],'source_scene_ids'=>[$own[0]['id']]]];
$planCalls=0;
$planInfer=static function()use(&$planCalls):array {
    $planCalls++;
    return ['text'=>json_encode(['title'=>'Quiet songs','concept'=>'A quiet interface for the songs you selected.',
        'brief'=>'Add an unrelated merchandise store.','source_scene_ids'=>[99999]]),'provider'=>'openai','model'=>'fixture','input_tokens'=>11,'output_tokens'=>13];
};
$planned=sf_canvas_project_ideas_plan($user,$plan,$planInfer,$provider);$plannedPayload=$planned['proposal']['project']??$planned['proposal']['build_request'];
verify($planCalls===1&&$plannedPayload===sf_canvas_project_normalize($plan['project'])&&$planned['proposal']['source']==='user',
    'Project planning creates a short concept while preserving the exact user brief, resources and source choices');
verify(str_contains($plannedPayload['brief'],'<canvas>'),
    'A user can name HTML elements in a code project without the planner stripping or rejecting their instruction');
$bulkIds=array_map(static fn(int $index):string=>'song'.str_pad((string)$index,4,'0',STR_PAD_LEFT),range(1,350));
$bulkBrief='Arrange the selected songs into an original interface. '.implode(' ',array_map(static fn(string $id):string=>'{music-track:'.$id.'}',$bulkIds));
$bulkProject=['canvas_kind'=>'custom','track_id'=>'','brief'=>$bulkBrief,'music_inputs'=>['mode'=>'selected','track_ids'=>$bulkIds]];
$bulkPrompt=null;
$bulkInfer=static function(array $provider,string $system,string $message)use(&$bulkPrompt):array {
    $bulkPrompt=json_decode($message,true,16,JSON_THROW_ON_ERROR);
    return ['text'=>json_encode(['title'=>'Selected song interface','concept'=>'An original interface for your selected songs.']),
        'provider'=>'openai','model'=>'fixture','input_tokens'=>14,'output_tokens'=>16];
};
$bulk=sf_canvas_project_ideas_plan($user,['request_id'=>str_repeat('1',32),'project'=>$bulkProject],$bulkInfer,$provider);
verify($bulk['proposal']['project']['brief']===$bulkBrief&&$bulk['proposal']['project']['music_inputs']['track_ids']===$bulkIds&&
    $bulkPrompt['listener_project']['music_inputs']===['mode'=>'selected','count'=>350]&&!str_contains(json_encode($bulkPrompt),'music-track:')&&
    !str_contains(json_encode($bulkPrompt),'song0001'),
    'Large music selections retain their complete approval snapshot while planning receives a compact count and natural brief');
sf_canvas_project_preferences_save(1,['ideas_enabled'=>false]);
$localPlan=$plan;$localPlan['request_id']=str_repeat('c',32);$before=sf_token_balance(1);
$local=sf_canvas_project_ideas_plan($user,$localPlan,$planInfer,$provider);
verify($planCalls===1&&$local['active_tokens_used']===0&&sf_token_balance(1)===$before&&$local['proposal']['status']==='awaiting_approval',
    'Ideas-off planning uses the original brief without a provider call and still waits for approval');
rejects(fn()=>sf_canvas_project_ideas_suggest($user,['request_id'=>str_repeat('d',32)],$planInfer,$provider),
    'Turning ideas off suppresses requested fresh-idea generation before provider use');
sf_canvas_project_preferences_save(1,['ideas_enabled'=>true]);

$badCalls=0;$badRequest=['request_id'=>str_repeat('e',32)];$before=sf_token_balance(1);
$bad=static function()use(&$badCalls):array {$badCalls++;return ['text'=>'not complete JSON','input_tokens'=>20,'output_tokens'=>5];};
rejects(fn()=>sf_canvas_project_ideas_suggest($user,$badRequest,$bad,$provider),
    'An incomplete idea response creates no proposal');
verify(sf_token_balance(1)===$before&&sf_canvas_project_ideas_suggest($user,$badRequest,$bad,$provider)['state']==='failed'&&$badCalls===1,
    'Failed planning releases its reservation and a replay does not pay for another attempt');

$clearedId=str_repeat('f',32);$before=sf_token_balance(1);$projectCount=count(sf_canvas_project_list(1));
$clearInfer=static function()use($db,$idea):array {
    sf_canvas_project_ideas_clear(1);
    $db->exec("INSERT INTO user_privacy_events(user_id,event_type,metadata_json,created_at) VALUES(1,'agent_history_cleared','{}','fixture')");
    return ['text'=>json_encode($idea),'provider'=>'openai','model'=>'fixture','input_tokens'=>20,'output_tokens'=>5];
};
rejects(fn()=>sf_canvas_project_ideas_suggest($user,['request_id'=>$clearedId],$clearInfer,$provider),
    'A privacy reset during inference prevents a late proposal from restoring cleared work');
verify(sf_token_balance(1)===$before&&count(sf_canvas_project_list(1))===$projectCount,
    'Discarding a late idea also rolls back its token charge and proposal write');
$empty=sf_canvas_project_ideas_context(4);
verify($empty['canvas_history']===[]&&$empty['current_canvas_id']===null,
    'Creative planning supports an empty history and no currently playing song');
verify(sf_canvas_project_ideas_export(2)===[]&&sf_canvas_project_ideas_clear(2)===0,
    'Idea request export and clearing remain account scoped');

// Ordinary canvas dialogue is metered conversation, never an implicit build.
$sourceFeedback=sf_canvas_feedback_set(1,['scene_id'=>$own[0]['id'],'preference'=>'like','reason'=>'Keep the palette but rethink the interactions.']);
sf_canvas_feedback_set(2,['scene_id'=>$foreign['id'],'preference'=>'dislike','reason'=>'Another listener private opinion']);
$selected=[$own[0]['id'],$own[1]['id']];$discussionInput=null;$discussionCalls=0;
$discussionRequest=['request_id'=>str_repeat('2',32),'brief'=>'What could we do with these earlier canvases?',
    'source_scene_ids'=>$selected,'current_canvas_id'=>$own[2]['id']];
$discussionInfer=static function(array $provider,string $system,string $message)use(&$discussionInput,&$discussionCalls):array {
    $discussionCalls++;$discussionInput=json_decode($message,true,20,JSON_THROW_ON_ERROR);
    return ['text'=>json_encode(['reply'=>'We can preserve the visual language and rethink how the selected experiences respond to input.','proposal'=>null]),
        'provider'=>'openai','model'=>'fixture','input_tokens'=>17,'output_tokens'=>19];
};
$before=sf_token_balance(1);$beforeProjects=count(sf_canvas_project_list(1));
$discussed=sf_canvas_project_ideas_discuss($user,$discussionRequest,$discussionInfer,$provider);
verify($discussed['state']==='ready'&&!isset($discussed['proposal'])&&str_contains($discussed['reply'],'preserve the visual language')&&
    count(sf_canvas_project_list(1))===$beforeProjects&&$discussed['active_tokens_used']===36&&sf_token_balance(1)===$before-36,
    'A normal question returns a short discussion reply and exact usage without creating a project or starting a build');
verify($discussionInput['selected_source_ids']===$selected&&
    array_slice(array_column($discussionInput['references']['canvas_history'],'id'),0,2)===$selected&&
    $discussionInput['references']['selected_source_canvases']['sources'][0]['code']['html']===$base['html']&&
    $discussionInput['references']['selected_source_canvases']['sources'][0]['design_brief']==='Original private creative instruction 1',
    'Explicit older selections take priority and supply their actual owned prompts and code');
verify($discussionInput['references']['explicit_feedback'][0]['preference']==='like'&&
    $discussionInput['references']['explicit_feedback'][0]['reason']===$sourceFeedback['reason']&&
    !str_contains(json_encode($discussionInput),'Another listener private opinion'),
    'Discussion uses deliberate creative preferences while excluding another account feedback');
verify(sf_canvas_project_ideas_discuss($user,$discussionRequest,$discussionInfer,$provider)===$discussed&&$discussionCalls===1,
    'A discussion-only reply survives retry with no duplicate provider call or charge');
rejects(fn()=>sf_canvas_project_ideas_discuss($user,array_replace($discussionRequest,['source_scene_ids'=>[$own[3]['id']]]),$discussionInfer,$provider),
    'A discussion request ID is bound to the exact selected source scope');

// A currently approved image inventory entry can be discussed without new image inference.
$chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',128,128,8,2,0,0,0)).
    $chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\xff\x22\x55",128),128))).$chunk('IEND','');
$imageId=str_repeat('4',32);sf_agent_canvas_image_store(1,$imageId,'Selected private artwork',['bytes'=>$png]);
require_once $root.'/api/agent-canvas-image-analysis-core.php';sf_agent_canvas_image_analysis_schema();
$analysis=['description'=>'A geometric studio illustration','visible_text'=>'','objects'=>['shapes'],'scene'=>'An abstract room',
    'style'=>'Layered illustration','colors'=>['deep red'],'tags'=>['geometry'],'uncertainties'=>[]];
$db->prepare("INSERT INTO agent_canvas_image_analyses(user_id,analysis_id,image_id,source_sha256,state,analysis_json,suggestions_json,provider,model,created_at,updated_at)
    VALUES(1,?,?,?,'approved',?,'[]','fixture','fixture',?,?)")
    ->execute([str_repeat('5',32),$imageId,hash('sha256',$png),json_encode($analysis),gmdate('c'),gmdate('c')]);
$imageScene=sf_agent_scene_history_save(1,array_replace($base,['title'=>'Image experience','design_brief'=>'Compose the selected illustration with responsive forms.',
    'html'=>'<figure class="sf-image-'.$imageId.'"></figure>']));
$selected=[$imageScene['id'],$own[0]['id']];$proposalInput=null;
$followup=['request_id'=>str_repeat('3',32),'brief'=>'Keep the design and rethink the interactions. {canvas:'.$selected[0].'} {canvas:'.$selected[1].'} {image-'.$imageId.'}',
    'source_scene_ids'=>$selected,'artist_inputs'=>['merch_ids'=>[]],'music_inputs'=>['mode'=>'selected','track_ids'=>['second','first']],
    'image_reference_ids'=>[$imageId]];
$proposalInfer=static function(array $provider,string $system,string $message)use(&$proposalInput,$selected):array {
    $proposalInput=json_decode($message,true,20,JSON_THROW_ON_ERROR);
    return ['text'=>json_encode(['reply'=>'I can preserve the visual design and combine the selected interactions in one new experience.',
        'proposal'=>['title'=>'Reframed interactions','concept'=>'The same visual language with interactions rethought around your selected experiences.',
            'brief'=>'Preserve the selected visual design and rebuild its interaction behavior with a native <canvas> surface.',
            'source_scene_ids'=>$selected]]),'provider'=>'openai','model'=>'fixture','input_tokens'=>23,'output_tokens'=>29];
};
$proposed=sf_canvas_project_ideas_discuss($user,$followup,$proposalInfer,$provider);$snapshot=$proposed['proposal']['project'];
verify($proposed['proposal']['status']==='awaiting_approval'&&$snapshot['source_scene_ids']===$selected&&
    $snapshot['music_inputs']===$followup['music_inputs']&&$snapshot['artist_inputs']===$followup['artist_inputs']&&
    $snapshot['image_reference_ids']===[$imageId]&&str_contains($snapshot['brief'],'{image-'.$imageId.'}'),
    'A conversational proposal preserves exact chosen sources, music, image references and deliberate empty commerce scope while awaiting approval');
verify($proposalInput['references']['selected_source_canvases']['sources'][0]['image_assets'][0]['colors']===['deep red']&&
    $proposalInput['references']['selected_source_canvases']['sources'][0]['image_assets'][0]['style']==='Layered illustration'&&
    $proposalInput['references']['recent_canvas_discussion'][0]['agent']===$discussed['reply'],
    'Follow-up discussion receives current approved image metadata and the actual earlier dialogue');
$unauthorizedCalls=0;$unauthorizedInfer=static function()use(&$unauthorizedCalls,$foreign):array {
    $unauthorizedCalls++;
    return ['text'=>json_encode(['reply'=>'A different composition.','proposal'=>['title'=>'Wrong scope','concept'=>'Uses an unselected source.',
        'brief'=>'Build from the other canvas.','source_scene_ids'=>[$foreign['id']]]]),'input_tokens'=>9,'output_tokens'=>9];
};
$before=sf_token_balance(1);
rejects(fn()=>sf_canvas_project_ideas_discuss($user,['request_id'=>str_repeat('6',32),'brief'=>'Combine these.',
    'source_scene_ids'=>[$own[0]['id']]],$unauthorizedInfer,$provider),
    'The model cannot turn a conversational selection into access to an unselected or foreign source');
verify(sf_token_balance(1)===$before,'Invalid conversational source output releases its text-model reservation');

sf_canvas_project_preferences_save(1,['ideas_enabled'=>false]);$beforeProjects=count(sf_canvas_project_list(1));
$disabled=sf_canvas_project_ideas_discuss($user,array_replace($followup,['request_id'=>str_repeat('7',32)]),$proposalInfer,$provider);
verify(isset($disabled['reply'])&&!isset($disabled['proposal'])&&count(sf_canvas_project_list(1))===$beforeProjects,
    'Ideas off still permits normal discussion while suppressing new project suggestions');
sf_canvas_project_preferences_save(1,['ideas_enabled'=>true]);
$longDialogue=['request_id'=>str_repeat('8',32),'brief'=>str_repeat('Consider the interactions. ',100).' '.implode(' ',array_map(
    static fn(string $id):string=>'{music-track:'.$id.'}',$bulkIds))];
$long=sf_canvas_project_ideas_discuss($user,$longDialogue,$discussionInfer,$provider);
verify(isset($long['reply'])&&strlen($longDialogue['brief'])>4000&&strlen($discussionInput['listener_request'])<4000&&
    $discussionInput['references']['selected_music_inputs']===['mode'=>'selected','count'=>350],
    'Normal discussion supports longer dialogue and complete tagged music selections after resource parsing');
$export=sf_canvas_project_ideas_export(1);
verify(in_array($discussed['reply'],array_column($export,'reply'),true)&&sf_canvas_project_ideas_export(2)===[],
    'Account export includes its saved discussion text and excludes other listeners');
$before=sf_token_balance(1);
$resetInfer=static function()use($user):array {
    sf_privacy_clear_agent_history($user['id']);
    return ['text'=>json_encode(['reply'=>'A late discussion response.','proposal'=>null]),'input_tokens'=>13,'output_tokens'=>15];
};
rejects(fn()=>sf_canvas_project_ideas_discuss($user,['request_id'=>str_repeat('9',32),'brief'=>'What could we try next?'],$resetInfer,$provider),
    'A privacy reset during discussion prevents late conversation or project restoration');
verify(sf_token_balance(1)===$before&&sf_canvas_project_ideas_export(1)===[]&&sf_canvas_project_list(1)===[]&&sf_canvas_feedback_export(1)===[],
    'Discussion privacy reset removes its text and preferences and discards the in-flight charge atomically');

foreach(['{ticket:7}','{merch:hoodie}','{merch-hoodies}','{canvas:1}','{music-track:another-song}','{music-all}','{music-clear}','/music-all',
    '{image-'.str_repeat('a',32).'}','{reference-image-'.str_repeat('b',32).'}','{merch-clear}','{tickets-clear}',
    '{media-albums}','[[tickets:price:7]]','merch-hoodies'] as $token){
    $attempt=['title'=>'Guarded concept','concept'=>'A concept without new selections.',
        'brief'=>$token==='merch-hoodies'?$token:'Compose a calm experience. '.$token,'source_scene_ids'=>[]];
    rejects(fn()=>sf_canvas_project_idea_validate($attempt,[]),'Model-authored operational tokens cannot expand a proposal resource scope');
}
$injected=['title'=>'Changed inventory','concept'=>'A concept attempting to alter the selected inputs.',
    'brief'=>'Compose a calm experience. {ticket:7}','source_scene_ids'=>[]];
$injectionInfer=static fn():array=>['text'=>json_encode($injected),'provider'=>'openai','model'=>'fixture','input_tokens'=>7,'output_tokens'=>9];
$before=sf_token_balance(1);$count=count(sf_canvas_project_list(1));
rejects(fn()=>sf_canvas_project_ideas_suggest($user,['request_id'=>str_repeat('a1',16),'artist_inputs'=>['merch_ids'=>[]]],$injectionInfer,$provider),
    'A fresh idea cannot add a ticket binding to the listener deliberately empty merchandise selection');
verify(sf_token_balance(1)===$before&&count(sf_canvas_project_list(1))===$count,
    'Rejected suggestion bindings release the reservation and create no project');
$discussionInjection=static fn():array=>['text'=>json_encode(['reply'=>'A proposed concept.','proposal'=>$injected]),
    'provider'=>'openai','model'=>'fixture','input_tokens'=>8,'output_tokens'=>10];
rejects(fn()=>sf_canvas_project_ideas_discuss($user,['request_id'=>str_repeat('b1',16),'brief'=>'What could we make?',
    'music_inputs'=>['mode'=>'all']],$discussionInjection,$provider),
    'A conversational proposal cannot smuggle new resource bindings into its authored brief');
verify(sf_token_balance(1)===$before&&count(sf_canvas_project_list(1))===$count,
    'Rejected conversational bindings preserve the original balance and selected scope');

// Simulate a reset after the request captures its epoch but before it takes the
// account lock, without adding a production-only test hook.
$ideaSource=file_get_contents($root.'/api/canvas-project-ideas.php');
$start=strpos($ideaSource,'function sf_canvas_project_ideas_generate(');
$end=strpos($ideaSource,'/** Privacy callers',$start);
$interleaved=substr($ideaSource,$start,$end-$start);
$interleaved=str_replace('function sf_canvas_project_ideas_generate(','function fixture_canvas_discuss_after_reset(',$interleaved);
$position=strpos($interleaved,'$db->beginTransaction();');
$interleaved=substr_replace($interleaved,'sf_privacy_clear_agent_history($owner);$db->beginTransaction();',$position,strlen('$db->beginTransaction();'));
eval($interleaved);
$earlyCalls=0;$earlyInfer=static function()use(&$earlyCalls):array {$earlyCalls++;throw new RuntimeException('Provider must not run.');};
rejects(fn()=>fixture_canvas_discuss_after_reset($user,['request_id'=>str_repeat('c1',16),'brief'=>'Private brief submitted before reset.'],
    'discuss',$earlyInfer,$provider),'A reset between epoch capture and account locking rejects the stale claim');
verify($earlyCalls===0&&sf_canvas_project_ideas_export(1)===[],
    'The early claim boundary restores neither a private request brief nor a metered provider call');

// A later old worker must also leave a fresh request with the same random ID
// alone. Fibers deterministically pause the new worker at its provider boundary.
$sameId=str_repeat('d1',16);$freshFiber=null;$before=sf_token_balance(1);
$oldInfer=static function()use($user,$provider,$sameId,&$freshFiber):array {
    sf_privacy_clear_agent_history(1);
    $freshFiber=new Fiber(static fn():array=>sf_canvas_project_ideas_discuss($user,
        ['request_id'=>$sameId,'brief'=>'Fresh discussion after the reset.'],static function():array {
            Fiber::suspend('fresh-provider-running');
            return ['text'=>json_encode(['reply'=>'Only the fresh discussion survives.','proposal'=>null]),
                'provider'=>'openai','model'=>'fixture','input_tokens'=>5,'output_tokens'=>7];
        },$provider));
    $freshFiber->start();
    return ['text'=>json_encode(['reply'=>'Stale response from before the reset.','proposal'=>null]),
        'provider'=>'openai','model'=>'fixture','input_tokens'=>11,'output_tokens'=>13];
};
rejects(fn()=>sf_canvas_project_ideas_discuss($user,['request_id'=>$sameId,'brief'=>'Old private discussion before reset.'],$oldInfer,$provider),
    'An old worker cannot settle its stale response over a fresh request after reset');
verify(sf_canvas_project_ideas_row(1,$sameId)['state']==='processing'&&$freshFiber->isSuspended(),
    'The stale worker failure handler leaves the new epoch request processing');
$freshFiber->resume();$freshResult=$freshFiber->getReturn();
verify($freshResult['reply']==='Only the fresh discussion survives.'&&sf_token_balance(1)===$before-12&&
    !str_contains(json_encode(sf_canvas_project_ideas_export(1)),'Old private discussion'),
    'The fresh request completes independently and only its exact provider usage is charged');
$adminId=str_repeat('e1',16);$adminBalance=sf_token_balance(3);
$adminResult=sf_canvas_project_ideas_discuss(['id'=>3,'role'=>'admin'],['request_id'=>$adminId,'brief'=>'Discuss the available canvas possibilities.'],
    static fn():array=>['text'=>json_encode(['reply'=>'We can explore a new interaction direction.','proposal'=>null]),
        'provider'=>'openai','model'=>'fixture','input_tokens'=>6,'output_tokens'=>8],$provider);
verify($adminResult['state']==='ready'&&$adminResult['active_tokens_used']===0&&$adminResult['active_tokens_remaining']===null&&
    sf_token_balance(3)===$adminBalance&&sf_canvas_project_ideas_row(3,$adminId)['reservation_key']===null,
    'Unlimited administrator discussion succeeds with a null reservation and preserves the account balance');

$libraries=sf_canvas_library_registry();
$threeFile=$fixture.'/'.$libraries['three']['path'];
$tampered=file_get_contents($threeFile);
$tampered[0]=$tampered[0]==='A'?'B':'A';
file_put_contents($threeFile,$tampered);
verify(array_column(sf_canvas_library_available(),'id')===['anime','color-thief','d3','matter'],
    'A same-size modified local bundle is withheld from the approved inventory');
unlink($fixture.'/'.$libraries['color-thief']['path']);
verify(array_column(sf_canvas_library_available(),'id')===['anime','d3','matter'],
    'A missing local bundle is also withheld without expanding the approved inventory');
