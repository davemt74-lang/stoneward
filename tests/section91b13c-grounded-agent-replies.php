<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-runtime.php';

$passed=0;$failed=0;
$check=static function(string $name,bool $ok)use(&$passed,&$failed):void {
    echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
    $ok?$passed++:$failed++;
};

foreach([
    'list all songs','name every track','show me the complete song catalog',
    'give me the full catalog','what songs do you have?',
    'which tracks are available?','all songs in the catalog',
] as $phrase){
    $check('Complete catalog route: '.$phrase,sf_agent_catalog_list_requested($phrase)
        &&sf_agent_local_route($phrase)==='catalog_list');
}
foreach([
    'play Earth Girl','what does Desert Lights mean?',
    'tell me about a record','open the builder',
] as $phrase){
    $check('No catalog override: '.$phrase,!sf_agent_catalog_list_requested($phrase));
}
$titles=[
    'The In Between','Desert Lights','The Long Way Home','Static Sky','Old Lines',
    'Daily Delusions','Two Hands Up','Intermittant patience','Subtle Surrender','Scent Of A Pistol'
];
$tracks=array_map(static fn(string $title,int $i):array=>['id'=>(string)($i+1),'title'=>$title],$titles,array_keys($titles));
$list=sf_agent_catalog_list_text($tracks);
$check('Exact published count',str_contains($list,'10 published songs'));
$check('Every catalog title is present once in order',
    $list==='Stonefellow currently has 10 published songs:'."\n"
        .implode("\n",array_map(static fn(string $title,int $i):string=>($i+1).'. '.$title,$titles,array_keys($titles))));
$check('No hallucinated Earth Girl',!str_contains($list,'Earth Girl'));
$check('Empty catalog is explicit',str_contains(sf_agent_catalog_list_text([]),'No published songs'));
$notFound=sf_agent_named_playback_plan('Earth Girl',$tracks);
$check('Unknown track never dispatched',($notFound['action']['type']??'')==='none');
$found=sf_agent_named_playback_plan('The In Between',$tracks);
$check('Playback requests are not reported as completed',
    ($found['action']['type']??'')==='play_track'
    &&str_contains((string)($found['text']??''),'not yet confirmed'));

$runtime=(string)file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$browser=(string)file_get_contents(dirname(__DIR__).'/assets/js/app.js');
$endpoint=(string)file_get_contents(dirname(__DIR__).'/api/agent-action-receipt.php');
$check('Pending history keyed to browser action receipt',str_contains($runtime,"'agent-action-pending:'")
    &&str_contains($runtime,'action_receipt_id')
    &&str_contains($runtime,'sf_agent_apply_browser_action_receipt('));
$check('Receipt only mutates matching user conversation row',str_contains($runtime,'WHERE id=? AND conversation_id=? AND user_id=? AND role=? AND provider=?'));
$check('Browser sends real result, never a planned string',str_contains($browser,"'agent-action-receipt.php'")
    &&str_contains($browser,"typeof playerResult==='string'"));
$check('Receipt endpoint authenticated',str_contains($endpoint,'sf_require_user(false,true)'));
$check('No unsolicited self-correction in system guidance',str_contains($runtime,'Do not volunteer retrospective self-corrections'));

echo "Section 91B13C grounded replies: $passed passed, $failed failed".PHP_EOL;
exit($failed?1:0);
