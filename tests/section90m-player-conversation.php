<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-runtime.php';

$passed=0;$failed=0;
$check=static function(string $name,bool $ok)use(&$passed,&$failed):void{
    echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
    $ok?$passed++:$failed++;
};

foreach([
    'pause the music'=>'player_pause',
    'stop playback'=>'player_stop',
    'resume the song'=>'player_resume',
    'unpause it'=>'player_resume',
    'play the current track'=>'player_resume',
    'skip this song'=>'player_next',
    'next track'=>'player_next',
    'previous song'=>'player_previous',
    'go back one track'=>'player_previous',
    'turn down the sound'=>'player_volume',
    'a little louder'=>'player_volume',
    'volume to 35 percent'=>'player_volume',
    'mute'=>'player_volume',
    'unmute'=>'player_volume',
    "what's playing?"=>'player_status',
    'which song is this'=>'player_status',
    'how much time is left'=>'player_status',
    'is the music playing'=>'player_status',
] as $phrase=>$expected){
    $check('Natural player command '.$phrase,sf_agent_explicit_player_route($phrase)===$expected);
    $check('Local Agent route '.$phrase,sf_agent_local_route($phrase)===$expected);
}
foreach(['pause the newsletter','stop my membership','play the album Northern Sky','turntable upgrades','tell me about my order','skip this kind of music'] as $phrase){
    $check('Unrelated request is not a player command: '.$phrase,sf_agent_explicit_player_route($phrase)===null);
}
foreach([
    ['volume to 35 percent','set',35],['set volume to 40%','set',40],
    ['volume at 200','set',100],['turn it up','adjust',10],
    ['make it quieter','adjust',-10],['mute','mute',null],['unmute','unmute',null],
] as [$phrase,$mode,$percent]){
    $action=sf_agent_player_volume_action($phrase);
    $check('Bounded volume action '.$phrase,($action['type']??'')==='set_player_volume'
        &&($action['mode']??'')===$mode&&($action['percent']??null)===$percent);
}
$state=sf_agent_normalize_client_state(['active_track_id'=>'safe','player_is_playing'=>45,'player_volume_percent'=>250,'player_position_seconds'=>900000,'player_duration_seconds'=>-5,'unapproved_field'=>'secret']);
$check('Playback state is bounded',$state['player_is_playing']===1&&$state['player_volume_percent']===100&&$state['player_position_seconds']===86400&&$state['player_duration_seconds']===0);
$check('Unapproved browser field dropped',!array_key_exists('unapproved_field',$state));
echo "Section 90M player conversation: $passed passed, $failed failed".PHP_EOL;
exit($failed?1:0);
