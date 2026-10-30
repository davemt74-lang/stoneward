<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-runtime.php';
require_once dirname(__DIR__).'/api/agent-song-transcribe-core.php';
$count=0;
$check=static function(bool $passed,string $label)use(&$count):void{
    if(!$passed){fwrite(STDERR,"91B8B FAIL: $label\n");exit(1);}
    echo "91B8B PASS: $label\n";$count++;
};
$sample=[['id'=>'demo-published','title'=>'Genius of Unhumankind','release'=>'Happier Than Hay','audio'=>'/songs/demo.mp3']];
$post=['track_id'=>'demo-published','start_ms'=>'12100','end_ms'=>'27700','captured_at'=>'2026-10-30T10:12:35.000Z'];
$meta=sf_song_transcribe_request($post,$sample);
$check($meta['start_ms']===12100&&$meta['end_ms']===27700&&$meta['duration_ms']===15600,'Published song and precise playback offsets');
$check($meta['title']==='Genius of Unhumankind'&&$meta['release']==='Happier Than Hay','Title and release are resolved from trusted catalog, not submitted text');
$rejects=static function(array $in,array $catalog)use($check):void{
    try{sf_song_transcribe_request($in,$catalog);$check(false,'Invalid capture should fail');}
    catch(InvalidArgumentException){$check(true,'Invalid capture fails closed');}
};
$rejects($post,[]);
$rejects($post,[['id'=>'demo-published','title'=>'Private master','audio'=>'']]);
$rejects($post,[$sample[0],$sample[0]]);
$rejects(array_replace($post,['start_ms'=>'-2']),$sample);
$rejects(array_replace($post,['end_ms'=>'200000']),$sample);
$rejects(array_replace($post,['end_ms'=>'12200']),$sample);
$rejects(array_replace($post,['captured_at'=>'not-a-date']),$sample);
$check(sf_song_transcribe_mime('audio/webm')==='audio/webm'&&sf_song_transcribe_mime('video/mp4')==='video/mp4','Supported audio-container MIME');
try{sf_song_transcribe_mime('image/png');$check(false,'Non-audio MIME should fail');}
catch(InvalidArgumentException){$check(true,'Non-audio MIME rejected');}
$draft=sf_song_transcribe_draft($meta,['provider'=>'openai','model'=>'gpt-4o-mini-transcribe'],['text'=>" First line\nSecond line "]);
$check($draft['text']==="First line\nSecond line"&&$draft['origin']==='machine_draft'&&$draft['review_status']==='unverified','Transcript retains line breaks and explicitly unverified label');
$check($draft['timing']==='whole_clip_estimate'&&$draft['start_ms']===12100&&$draft['end_ms']===27700,'Whole-clip timestamps are estimates, not hallucinated word alignment');
$check(sf_agent_explicit_song_capture_route('Transcribe this song section')==='song_capture_start','Initial transcribe command still starts capture');
$check(sf_agent_explicit_song_capture_route('Transcribe this song section',['song_capture_available'=>1])==='song_capture_transcribe','Transcribe command uses completed clip');
$check(sf_agent_explicit_song_capture_route('What did you hear?')==='song_capture_transcribe','Past-audio question routes to capture transcription');
$check(sf_agent_explicit_song_capture_route('Play me a different song',['song_capture_available'=>1])===null,'Ordinary playback is not intercepted');
$plan=sf_agent_policy('song_capture_transcribe','Transcribe what you heard',['song_capture_available'=>1]);
$check(($plan['action']['type']??'')==='song_capture_transcribe','Only completed clip allows transcription action');
$noClip=sf_agent_policy('song_capture_transcribe','Transcribe what you heard',[]);
$check(($noClip['action']['type']??'')==='none','Unfinished clip fails closed');
$state=sf_agent_normalize_client_state(['song_capture_available'=>22]);
$check(($state['song_capture_available']??-1)===1,'Client clip flag strictly bounded');
$check(in_array('song_capture_transcribe',sf_agent_orchestrator_routes(false),true),'LLM only chooses allowlisted transcription action');
$choice=sf_agent_orchestrator_validate('{"route":"song_capture_transcribe"}',[],false,'transcribe what you heard');
$check(($choice['route']??'')==='song_capture_transcribe','Model route passes server policy gate');
echo "Section 91B8B: $count server contract checks passed\n";
