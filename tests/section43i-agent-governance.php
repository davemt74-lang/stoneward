<?php
declare(strict_types=1);
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
require dirname(__DIR__).'/api/agent-runtime.php';
$state=sf_agent_normalize_client_state([
 'active_track_id'=>' public-track ',
 'search_query'=>str_repeat('x',1500),
 'queue_count'=>-99,
 'notification_unread'=>1000000,
 'builder_limit_seconds'=>'4000000',
 '_authorized_admin'=>true,
 'api_key'=>'secret',
 'role'=>'admin',
 'access_token'=>'secret',
]);
$checks=[
 'track ID normalized'=>($state['active_track_id']??null)==='public-track',
 'search query bounded'=>strlen($state['search_query']??'')===300,
 'negative counts clamped'=>($state['queue_count']??null)===0,
 'unread counts bounded'=>($state['notification_unread']??null)===9999,
 'durations bounded'=>($state['builder_limit_seconds']??null)===360000,
 'forged admin authority omitted'=>!array_key_exists('_authorized_admin',$state),
 'credentials not accepted'=>!isset($state['api_key'])&&!isset($state['access_token']),
 'arbitrary role not accepted'=>!isset($state['role'])
];
foreach($checks as $name=>$ok)echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
if(in_array(false,$checks,true))exit(1);
$src=(string)file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$checks2=[
 'client sanitized at reply entry'=>str_contains($src,'$client=sf_agent_normalize_client_state($client)'),
 'admin authorization set by server'=>str_contains($src,"$"."client['_authorized_admin']=$"."isAdmin"),
 'playlist claim no longer says saved'=>!str_contains($src,'and saved it to your playlists.')
];
foreach($checks2 as $name=>$ok)echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
if(in_array(false,$checks2,true))exit(1);
