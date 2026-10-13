<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE fan_contacts(user_id INTEGER PRIMARY KEY,agent_auto_engage INTEGER NOT NULL)');
foreach([[1,1],[2,0],[3,1],[4,1]] as $row)$db->prepare('INSERT INTO fan_contacts(user_id,agent_auto_engage) VALUES(?,?)')->execute($row);
function sf_db(): PDO {global $db;return $db;}
$GLOBALS['privacy']=[1=>true,2=>true,3=>true,4=>false];
$GLOBALS['memory']=[
  1=>[['memory_key'=>'proactive suggestions','memory_text'=>'yes']],
  2=>[['memory_key'=>'proactive suggestions','memory_text'=>'yes']],
  3=>[['memory_key'=>'music preferences','memory_text'=>'I enjoy quiet songs']],
  4=>[['memory_key'=>'proactive suggestions','memory_text'=>'yes']]
];
$GLOBALS['recommendations']=0;
function sf_privacy_personalization_enabled(int $uid): bool {return (bool)($GLOBALS['privacy'][$uid]??false);}
function sf_agent_customer_memory_list(int $uid): array {return $GLOBALS['memory'][$uid]??[];}
function sf_agent_recommend_track(string $message,?string $exclude=null,?int $userId=null): ?array {
  $GLOBALS['recommendations']++;return ['id'=>'published-track-42','title'=>'Stonefellow Song','mood'=>['mellow']];
}
require dirname(__DIR__).'/api/agent-proactive-research.php';

$yes=sf_agent_proactive_customer_suggestion(1);
$checks=[
 'Opted-in owner receives one passive catalog suggestion'=>is_array($yes)&&$yes['track_id']==='published-track-42',
 'Preview cannot execute actions'=>$yes['may_execute_directly']===false&&$yes['delivery']==='in_app_preview_only',
 'Preview contains no customer memory content'=>!str_contains(json_encode($yes),'proactive suggestions'),
 'Disabled CRM proactive flag prevents recommendation'=>sf_agent_proactive_customer_suggestion(2)===null,
 'Missing explicit saved preference prevents recommendation'=>sf_agent_proactive_customer_suggestion(3)===null,
 'Privacy-disabled account prevents recommendation'=>sf_agent_proactive_customer_suggestion(4)===null,
 'Unregistered user receives no recommendation'=>sf_agent_proactive_customer_suggestion(5)===null,
 'Invalid user ID denied'=>sf_agent_proactive_customer_suggestion(0)===null,
 'Only eligible owner calls actual recommender'=>$GLOBALS['recommendations']===1,
 'GET preview does not insert a CRM event'=>(int)$db->query('SELECT COUNT(*) FROM fan_contacts')->fetchColumn()===4,
];
$admin=sf_agent_proactive_admin_research(['crm_contacts'=>100,'newsletter_opted_in'=>40,'campaigns_active'=>0,'journey_counts'=>['journeys_failed'=>2],'customer_secret'=>'DO_NOT_SHARE']);
$checks['Admin receives evidence-backed opportunities']=count($admin)===3;
$checks['Every Admin item points to an evidence source']=array_reduce($admin,fn($ok,$row)=>$ok&&!empty($row['source'])&&!empty($row['evidence']),true);
$checks['Admin delivery never sends or publishes']=array_reduce($admin,fn($ok,$row)=>$ok&&$row['delivery']==='in_app_read_only',true);
$checks['Admin opportunities exclude private fields']=!str_contains(json_encode($admin),'DO_NOT_SHARE');
$checks['Empty aggregate source returns no invented opportunity']=sf_agent_proactive_admin_research([])===[];
$source=(string)file_get_contents(dirname(__DIR__).'/api/agent-proactive-research.php');
$checks['No engagement dispatcher invoked']=!str_contains($source,'sf_crm_next_agent_engagement(')&&!str_contains($source,'sf_crm_agent_log(');
$checks['No CRM auto-upsert on passive preview']=!str_contains($source,'sf_crm_sync_user(');
$checks['Owner consent query parameterized']=str_contains($source,'WHERE user_id=? LIMIT 1');
$checks['Customer Brain GET requires authenticated owner']=str_contains(file_get_contents(dirname(__DIR__).'/api/agent-brain.php'),'sf_require_user(false,$write)')
 && str_contains(file_get_contents(dirname(__DIR__).'/api/agent-brain.php'),'sf_agent_proactive_customer_suggestion($userId)');
$checks['Admin Brain GET exposes only aggregate research']=str_contains(file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php'),'sf_agent_proactive_admin_research(sf_admin_agent_operational_context())');
$checks['Both canvases display separate proactive cards']=str_contains(file_get_contents(dirname(__DIR__).'/assets/js/agent-brain.js'),'Published music suggestion')
 && str_contains(file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js'),'Evidence-backed research');
$failed=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;}
echo '47F: '.(count($checks)-$failed).'/'.count($checks).' checks passed'.PHP_EOL;
exit($failed?1:0);
