<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-voice-validation.php';
$cases=[
 'ID3 valid'=>[str_repeat("ID3",1).str_repeat("x",200),true],
 'MPEG frame sync valid'=>["\xff\xfb".str_repeat("x",200),true],
 'JSON provider error rejected'=>[str_repeat('{"error":"invalid"}',10),false],
 'short response rejected'=>["ID3",false],
 'oversized response rejected'=>["ID3".str_repeat('x',12*1024*1024+1),false],
];
foreach($cases as $name=>[$payload,$expected]){
 $ok=sf_agent_voice_valid_mp3($payload)===$expected;
 echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
 if(!$ok)exit(1);
}
$endpoint=(string)file_get_contents(dirname(__DIR__).'/api/agent-voice.php');
$verified=strpos($endpoint,'sf_agent_voice_valid_mp3($audio)')<strpos($endpoint,'sf_consume_ai($u,$cost');
if(!$verified){fwrite(STDERR,"FAIL validation must precede token debit\n");exit(1);}
echo "PASS validation precedes token debit\n";
