<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-runtime.php';
$original=[
 'route'=>'player_recommend','provider_status'=>'completed','input_tokens'=>25,'output_tokens'=>50,
 'provider_error'=>'Authorization: Bearer secret-example',
 'nested'=>['api_key'=>'sk-example','password'=>'password-secret','action'=>'open_library'],
 'response_excerpt'=>str_repeat('A',2000),
];
$clean=sf_agent_brain_safe_details($original);
$encoded=json_encode($clean);
$checks=[
 'Provider error redacted'=>$clean['provider_error']==='[redacted]',
 'Nested secrets redacted'=>$clean['nested']['api_key']==='[redacted]'&&$clean['nested']['password']==='[redacted]',
 'Status retained'=>$clean['provider_status']==='completed',
 'Token analytics retained'=>$clean['input_tokens']===25&&$clean['output_tokens']===50,
 'Action retained'=>$clean['nested']['action']==='open_library',
 'Long strings bounded'=>strlen($clean['response_excerpt'])<=500,
 'No original secret leaked'=>!str_contains($encoded,'secret-example')&&!str_contains($encoded,'sk-example')&&!str_contains($encoded,'password-secret')
];
foreach($checks as $label=>$ok)echo ($ok?'PASS':'FAIL').' '.$label.PHP_EOL;
if(in_array(false,$checks,true))exit(1);
