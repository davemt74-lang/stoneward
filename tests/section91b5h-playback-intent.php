<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/agent-runtime.php';
$passed=0;
$check=static function(bool $ok,string $description)use(&$passed):void{
    if(!$ok){fwrite(STDERR,"91B5H FAIL $description\n");exit(1);}
    $passed++;echo "91B5H PASS $description\n";
};
$songs=[
    ['id'=>'subtle-surrender','title'=>'Subtle Surrender','audio'=>'/media/subtle.mp3'],
    ['id'=>'two-hands-up','title'=>'Two Hands Up','audio'=>'/media/hands.mp3'],
    ['id'=>'river-night','title'=>'River Night','audio'=>'/media/river.mp3'],
];
$check(sf_agent_named_playback_query('change songs two hands up')==='two hands up','Live voice phrase is named playback');
$check(sf_agent_named_playback_query('Change the song to Two Hands Up')==='two hands up','Change the song to title');
$check(sf_agent_named_playback_query('Switch to Two Hands Up')==='two hands up','Switch to title');
$check(sf_agent_named_playback_query('Play Genius 7 Humankind')==='genius 7 humankind','Unmatched named request remains explicit');
$check(sf_agent_named_playback_query('Stonefellow, play Two Hands Up')==='two hands up','Wake-prefixed voice command');
$check(sf_agent_named_playback_query('play an album')===null,'Album requests are not mistaken for named track commands');
$check(sf_agent_named_playback_query('play music')===null,'Generic music playback retains existing routing');
$check(sf_agent_explicit_player_route('change songs two hands up')==='player_play_named','Named change is deterministic before model routing');
$check(sf_agent_explicit_player_route('play genius 7 humankind')==='player_play_named','Unknown named track never enters advisory fallback');
$check(sf_agent_match_track_title('change songs two hands up',$songs)['id']==='two-hands-up','Published title recognized within natural speech');
$check(sf_agent_match_track_title('play Two-Hands Up!',$songs)['id']==='two-hands-up','Punctuation-normalized title');
$change=sf_agent_named_playback_plan('two hands up',$songs,'subtle-surrender');
$check(($change['action']['type']??'')==='play_track'&&($change['action']['track_id']??'')==='two-hands-up','Requested track wins over prior playing track');
$unknown=sf_agent_named_playback_plan('genius 7 humankind',$songs,'subtle-surrender');
$check(($unknown['action']['type']??'')==='none' && ($unknown['track']??false)===null,'Unmatched request never replays the active song');
$check(str_contains($unknown['text'],'not changed'),'Unmatched request explicitly reports no playback change');
$check(sf_agent_named_playback_plan('this song',$songs,'subtle-surrender')['action']['track_id']==='subtle-surrender','Explicit deictic reference may use currently selected track');
$duplicates=[['id'=>'one','title'=>'Two Hands Up'],['id'=>'two','title'=>'Two Hands Up']];
$check(sf_agent_named_playback_plan('two hands up',$duplicates,'one')['action']['type']==='none','Duplicate song titles ask for release clarification');
$check(sf_agent_match_track_title('play unrelated song',$songs)===null,'Unknown titles do not match partial words');
echo "Section 91B5H: $passed deterministic command, catalog and identity assertions passed\n";
