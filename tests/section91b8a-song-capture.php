<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/agent-runtime.php';
$passed=0;
$check=static function(bool $condition,string $label)use(&$passed):void{
    if(!$condition){fwrite(STDERR,"91B8A FAIL $label\n");exit(1);}
    $passed++;echo "91B8A PASS $label\n";
};
$check(sf_agent_explicit_song_capture_route('Stonefellow listen to this next verse')==='song_capture_start','Verse listening starts a distinct capture');
$check(sf_agent_explicit_song_capture_route('Capture the current chorus')==='song_capture_start','Capture current chorus');
$check(sf_agent_explicit_song_capture_route('Transcribe this song section')==='song_capture_start','Transcribe a requested section');
$check(sf_agent_explicit_song_capture_route('Activate song listening mode')==='song_capture_start','Explicit listening-mode activation');
$check(sf_agent_explicit_song_capture_route('Stop listening')==='song_capture_stop','Stop the audio capture');
$check(sf_agent_explicit_song_capture_route('Okay, that is enough',['song_capture_active'=>1])==='song_capture_stop','Active capture recognizes conversational finish');
$check(sf_agent_explicit_song_capture_route('That is enough',['song_capture_active'=>0])===null,'No accidental capture stop when inactive');
$check(sf_agent_explicit_song_capture_route('Are you listening to this verse?')==='song_capture_status','Capture status query');
$check(sf_agent_explicit_song_capture_route('Are you still listening to this chorus?')==='song_capture_status','Status query with continuous listening phrase');
$check(sf_agent_explicit_song_capture_route('Play something from Happier Than Hay')===null,'Normal album playback is not capture');
$check(sf_agent_explicit_song_capture_route('Start a listening session')===null,'Playlist listening sessions stay separate');
$check(sf_agent_explicit_song_capture_route('Stop the music')===null,'Stop music is not stop song capture');
$normalized=sf_agent_normalize_client_state(['song_capture_active'=>27,'active_track_id'=>'demo']);
$check(($normalized['song_capture_active']??0)===1,'Untrusted active flag is bounded');
$check(in_array('song_capture_start',sf_agent_orchestrator_routes(false),true)&&in_array('song_capture_stop',sf_agent_orchestrator_routes(true),true),'LLM and fan routes advertise capture tools');
$choice=sf_agent_orchestrator_validate('{"route":"song_capture_start"}',[],false,'listen to this verse');
$check(($choice['route']??'')==='song_capture_start','LLM route validates under server policy');
echo "Section 91B8A: $passed capture routing assertions passed\n";
