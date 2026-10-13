<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-capability-audit.php';
$a=sf_agent_capability_audit();
$checks=[
 'Existing registered tools discovered'=>$a['registered']>=15,
 'Registry IDs unique'=>$a['unique_ids'],
 'No invalid contracts'=>!$a['invalid_contracts'],
 'Every tool points to an existing owner file'=>!$a['missing_owner_files'],
 'No tool executes without explicit approval'=>!$a['direct_executors'],
 'Fan and Admin scopes inventoried'=>($a['by_scope']['admin']??0)>0 && ($a['by_scope']['signed_in_fan']??0)>0,
 'Read-only audit'=>str_contains(file_get_contents(dirname(__DIR__).'/api/agent-capability-audit.php'),'read-only audit'),
 'Audit passed'=>$a['checks_passed']
];
$failed=false;
foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$failed=true;}
if($failed){fwrite(STDERR,json_encode($a,JSON_PRETTY_PRINT).PHP_EOL);exit(1);}
