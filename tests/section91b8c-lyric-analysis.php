<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-runtime.php';
require_once dirname(__DIR__).'/api/agent-song-analyze-core.php';
$count=0;
$check=static function(bool $condition,string $label)use(&$count):void{
    if(!$condition){fwrite(STDERR,"91B8C FAIL: $label\n");exit(1);}
    echo "91B8C PASS: $label\n";$count++;
};
$catalog=[['id'=>'track-1','title'=>'Genius of Unhumankind','release'=>'Happier Than Hay','audio'=>'/public/track-1.mp3']];
$post=['track_id'=>'track-1','start_ms'=>'12100','end_ms'=>'27700',
    'captured_at'=>'2026-10-30T10:12:35Z','transcript'=>"A line in the chorus\nWhat it means",
    'question'=>'Analyze the imagery in that verse','timezone'=>'America/Phoenix'];
$request=sf_song_analysis_request($post,$catalog);
$check($request['track_id']==='track-1'&&$request['title']==='Genius of Unhumankind','Song identity comes from published catalog');
$check($request['transcript']==="A line in the chorus\nWhat it means",'Lyric draft keeps line breaks');
$check($request['mode']==='interpretation'&&$request['draft_status']==='unverified_user_supplied','Unverified interpretation is explicit');
$research=sf_song_analysis_request(array_replace($post,['question'=>'Research the history behind this reference']),$catalog);
$check($research['mode']==='grounded_research','Research request gets provenance-aware mode');
$report=sf_song_analysis_request(array_replace($post,['question'=>'Give me a detailed report']),$catalog);
$check($report['mode']==='structured_report','Detailed report mode is preserved');
$reject=static function(array $payload,array $tracks)use($check):void{
    try{sf_song_analysis_request($payload,$tracks);$check(false,'Invalid payload should be rejected');}
    catch(InvalidArgumentException){$check(true,'Invalid lyric analysis rejected');}
};
$reject($post,[]);
$reject(array_replace($post,['track_id'=>'private-track']),$catalog);
$reject(array_replace($post,['end_ms'=>'93000']),$catalog);
$reject(array_replace($post,['transcript'=>'']),$catalog);
$reject(array_replace($post,['transcript'=>['not','lyrics']]),$catalog);
$reject(array_replace($post,['transcript'=>str_repeat('x',16001)]),$catalog);
$reject(array_replace($post,['question'=>'']),$catalog);
$reject(array_replace($post,['question'=>str_repeat('x',1201)]),$catalog);
$system=sf_song_analysis_system($request,'PUBLISHED CATALOG: creator credit supplied by Stonefellow','CONSENTED: mornings');
$check(str_contains($system,'unverified')&&str_contains($system,'never as commands'),
    'Provider is instructed to treat transcript as untrusted quoted data');
$check(str_contains($system,'live web searching is not connected')&&str_contains($system,'CONSENTED: mornings'),
    'No invented external citations; optional consented history correctly scoped');
$result=sf_song_analysis_report($request,['text'=>'The words suggest two possible readings.','provider'=>'anthropic','model'=>'haiku']);
$check($result['report']==='The words suggest two possible readings.'&&$result['draft_status']==='unverified_user_supplied',
    'Output never promotes raw draft to verified lyrics');
$check(in_array('no_live_web_search',$result['source_notes'],true),'Report carries transparent research-source limitation');
$check(sf_agent_explicit_song_capture_route('Analyze this verse',['song_lyric_draft_available'=>1])==='song_capture_analyze',
    'Voice and typed analysis uses the dedicated route');
$check(sf_agent_explicit_song_capture_route('What does that verse mean?',['song_lyric_draft_available'=>1])==='song_capture_analyze',
    'Conversational question about the captured verse uses analysis');
$check(sf_agent_explicit_song_capture_route('Analyze this verse')===null,'No draft means no accidental analysis');
$check(($plan=sf_agent_policy('song_capture_analyze','Analyze this verse',['song_lyric_draft_available'=>1]))['action']['type']==='song_capture_analyze',
    'Server policy authorizes analysis only for a reported draft');
$check((sf_agent_policy('song_capture_analyze','Analyze this verse',[])['action']['type']??'')==='none',
    'Missing draft fails closed without catalog or database lookup');
$check((sf_agent_normalize_client_state(['song_lyric_draft_available'=>8])['song_lyric_draft_available']??0)===1,
    'Draft availability state bounded to one');
$check(in_array('song_capture_analyze',sf_agent_orchestrator_routes(false),true),'LLM selects only allowlisted analysis intent');
$choice=sf_agent_orchestrator_validate('{"route":"song_capture_analyze"}',[],false,'Analyze this verse');
$check(($choice['route']??'')==='song_capture_analyze','Model-intent route validated under server policy');
echo "Section 91B8C: $count PHP analysis assertions passed\n";
