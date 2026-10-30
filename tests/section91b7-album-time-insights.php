<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/agent-runtime.php';
$pass=0;
$check=static function(bool $okay,string $name)use(&$pass):void {
    if(!$okay){fwrite(STDERR,"91B7 FAIL $name\n");exit(1);}
    echo "91B7 PASS $name\n";$pass++;
};
$catalog=[
 ['id'=>'subtle','title'=>'Subtle Surrender','release'=>'Happier Than Hay','audio'=>'/media/subtle.mp3'],
 ['id'=>'hands','title'=>'Two Hands Up','release'=>'Happier Than Hay','audio'=>'/media/hands.mp3'],
 ['id'=>'genius','title'=>'Genius of Unhumankind','release'=>'Happier Than Hay','audio'=>'/media/genius.mp3'],
 ['id'=>'hidden','title'=>'Unreleased Demo','release'=>'Happier Than Hay','audio'=>''],
 ['id'=>'else','title'=>'Other Song','release'=>'Other Album','audio'=>'/media/other.mp3'],
];
$first=sf_agent_release_request('Play something from Happier Than Hey');
$check(($first['title']??'')==='happier than hey','ASR album playback request extracted');
$second=sf_agent_release_request('Choose any song from the Happier Than Hey album and play it');
$check(($second['title']??'')==='happier than hey','Pick any track is playback, not release browsing');
$check(sf_agent_explicit_player_route('Play something from Happier Than Hey')==='player_play_from_release','Album request routed before named-track fallback');
$check(sf_agent_explicit_player_route('Choose any song from Happier Than Hey')==='player_play_from_release','Choose request routed to player');
$history=[['role'=>'user','content'=>'Play something from Happier Than Hey'],['role'=>'assistant','content'=>'I could not find that album.']];
$follow=sf_agent_release_request('Play another song from that album',['active_release_title'=>'Other Album'],$history);
$check(($follow['title']??'')==='happier than hey'&&!empty($follow['different']),'Follow-up references prior album rather than unrelated active track');
$check(count(sf_agent_release_tracks('happier than hey',$catalog))===3,'Spoken Hey resolves published Hay album and ignores unplayable source');
$plan=sf_agent_release_play_plan('Happier Than Hey',$catalog,'subtle',['enabled'=>true,'daypart'=>'morning','daypart_counts'=>['morning'=>['hands'=>1,'genius'=>0]]]);
$check(($plan['action']['type']??'')==='play_track'&&($plan['action']['track_id']??'')==='genius','Time-of-day history supports alternate album selection without restarting active song');
$check(($plan['track']['release']??'')==='Happier Than Hay','Selected track belongs to requested album');
$notFound=sf_agent_release_play_plan('not an album',$catalog,'subtle');
$check(($notFound['action']['type']??'')==='none','Unknown release never plays an unrelated track');
$check(sf_agent_release_request('Play Genius of Unhumankind')===null,'Named song retains named-track path');
$check(sf_agent_release_request('Play the entire Happier Than Hay album')===null,'Full album request remains a listening-session route');
$check(sf_agent_daypart(7)==='morning'&&sf_agent_daypart(14)==='afternoon'&&sf_agent_daypart(19)==='evening'&&sf_agent_daypart(23)==='night'&&sf_agent_daypart(2)==='night','Four local-time listening periods');
$check(sf_agent_timezone('America/Phoenix')->getName()==='America/Phoenix'&&sf_agent_timezone('invalid/zone')->getName()==='UTC','Valid time zone with safe fallback');
$ctx=sf_agent_listening_context(['enabled'=>true,'timezone'=>'America/Phoenix','daypart'=>'morning','top_by_daypart'=>['morning'=>[['title'=>'Genius of Unhumankind','plays'=>4]]],'recent'=>[['title'=>'Two Hands Up','at'=>'2026-10-30 08:04','daypart'=>'morning']]]);
$check(str_contains($ctx,'Morning preferences')&&str_contains($ctx,'2026-10-30 08:04'),'Agent context references historical preferences and exact local timestamps');
$check(sf_agent_listening_context(['enabled'=>false])==='','Disabled personalization produces no listening context');
$orchestration=sf_agent_orchestrator_validate('{"route":"player_play_from_release","release_title":"Happier Than Hay"}',$catalog,false,'Play something from Happier Than Hey');
$check(($orchestration['album_query']??'')==='happier than hey','LLM album route remains catalog validated');
$check(sf_agent_orchestrator_validate('{"route":"player_play_from_release","release_title":"Private Master"}',$catalog,false,'Play something from Nonexistent Album')['album_query']==='','LLM cannot authorize unavailable albums');
echo "Section 91B7: $pass published-album, daypart, privacy and conversation assertions passed\n";
