<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-runtime.php';
$checks=0;
$assert=static function(bool $passed,string $name)use(&$checks):void{
    if(!$passed){fwrite(STDERR,"91B11B FAIL: $name\n");exit(1);}
    echo "91B11B PASS: $name\n";$checks++;
};
$assert(sf_agent_explicit_song_capture_route('Start live transcription')==='song_capture_live_start','Explicit live transcription routes separately');
$assert(sf_agent_explicit_song_capture_route('Transcribe live')==='song_capture_live_start','Spoken live transcription request');
$assert(sf_agent_explicit_song_capture_route('Show me real-time lyrics')==='song_capture_live_start','Real-time lyric captions route');
$assert(sf_agent_explicit_song_capture_route('Transcribe this song section')==='song_capture_start','Ordinary recording remains local by default');
$assert(sf_agent_explicit_song_capture_route('Play another song')===null,'Ordinary playback cannot accidentally start live');
$assert(in_array('song_capture_live_start',sf_agent_orchestrator_routes(false),true),'LLM route is explicitly allowlisted');
$decision=sf_agent_orchestrator_validate('{"route":"song_capture_live_start"}',[],false,'Start live transcription');
$assert(($decision['route']??'')==='song_capture_live_start','Model route is subject to the server policy gate');
$plan=sf_agent_policy('song_capture_live_start','Start live transcription',[
    'player_is_playing'=>1,'active_track_id'=>'published-1','song_capture_active'=>1]);
$assert(($plan['action']['type']??'')==='song_capture_live_start','Already capturing can upgrade to live after opt-in');
$notPlaying=sf_agent_policy('song_capture_live_start','Start live transcription',['active_track_id'=>'published-1']);
$assert(($notPlaying['action']['type']??'none')==='none','No live upload without active playback');
$notConsented=sf_agent_policy('song_capture_live_start','What are live captions?',[
    'player_is_playing'=>1,'active_track_id'=>'published-1']);
$assert(($notConsented['action']['type']??'none')==='none','Model cannot infer permission from a question about live captions');
$already=sf_agent_policy('song_capture_live_start','Start live transcription',[
    'player_is_playing'=>1,'active_track_id'=>'published-1','song_capture_live_enabled'=>1]);
$assert(($already['action']['type']??'none')==='none','Duplicate live activation is blocked');
$state=sf_agent_normalize_client_state(['song_capture_live_enabled'=>15]);
$assert(($state['song_capture_live_enabled']??-1)===1,'Client live status is normalized to a boolean');
echo "Section 91B11B: $checks server route assertions passed\n";
