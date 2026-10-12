<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users VALUES(1),(2)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_crm_admin_summary():array{return ['contacts'=>20,'newsletter'=>5];}
function sf_campaign_admin_summary():array{return ['campaigns'=>4,'active'=>1];}
function sf_agent_action_recent(int $userId,int $limit=25):array{
    $list=[
        ['id'=>'act_owner_one','tool_id'=>'automation_draft_save','status'=>'review_required','payload'=>['name'=>'Private Journey for Admin One','secret'=>'do-not-return'],'result'=>[],'created_at'=>'2026-10-12'],
        ['id'=>'act_one_done','tool_id'=>'campaign_draft_save','status'=>'completed','payload'=>['name'=>'Newsletter Draft'],'result'=>['campaign_id'=>81],'created_at'=>'2026-10-12'],
    ];
    return $userId===1?$list:($userId===2?[['id'=>'act_other','tool_id'=>'campaign_draft_save','status'=>'proposed','payload'=>['name'=>'Other Admin Draft'],'result'=>[],'created_at'=>'2026-10-12']]:[]);
}
require dirname(__DIR__).'/api/agent-admin-brain.php';
$errors=0;$check=function(bool $ok,string $name)use(&$errors):void{echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$errors++;};
$one=sf_admin_agent_mission_summary(1);
$two=sf_admin_agent_mission_summary(2);
$check(count($one['missions'])===2&&count($two['missions'])===1,'Mission history strictly owner-scoped');
$check($one['counts']['review_required']===1&&$one['counts']['completed']===1,'Counts reflect durable ledger statuses');
$check($one['missions'][1]['result_entity_id']===81,'Completed result references real approved campaign');
$check($one['missions'][0]['review_required']===true,'Interrupted mission explicitly flagged');
$check(!str_contains(json_encode($one),'do-not-return'),'Sensitive proposal payload excluded from Brain summary');
$check(!str_contains(json_encode($two),'Private Journey for Admin One'),'Other admin cannot see a different mission');
$answer=sf_admin_agent_reply(1,'','Review my pending Agent actions');
$check(str_contains($answer['text'],'1 requiring review')&&str_contains($answer['text'],'1 completed'),'Chat reflects same mission state as Brain');
$check($answer['action']['type']==='review_actions','Chat routes to governed ledger');
$check(count($answer['missions'])===2,'Chat returns only own mission summaries');
$check(!str_contains(json_encode($answer),'do-not-return'),'Chat never exposes raw proposal secrets');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-chat.php');
$check(str_contains($api,'sf_admin_agent_brain_snapshot($adminId)'),'Admin API exposes same scoped Brain snapshot');
$check(str_contains($api,'sf_admin_require_auth($write)'),'Admin API remains permission/CSRF gated');
$source=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$check(str_contains($source,'sf_agent_action_recent($adminId,30)'),'Admin Brain uses canonical ledger instead of duplicate executor');
exit($errors?1:0);
