<?php
declare(strict_types=1);
/**
 * Section 44J: actual Agent proposal -> production Campaign Builder integration.
 * Uses an isolated SQLite database, real sf_campaign_save, and no mail/network.
 */
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users(id) VALUES(1),(2)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=120):string{
    $s=trim((string)$v);
    return function_exists('mb_substr')?mb_substr($s,0,$max):substr($s,0,$max);
}
require dirname(__DIR__).'/api/campaign-core.php';
require dirname(__DIR__).'/api/agent-actions-core.php';
// The production campaign schema is lazy-initialized by the first campaign API call.
sf_campaign_ensure_schema();
$errors=0;
$check=static function(bool $ok,string $name)use(&$errors):void{
    echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
    if(!$ok)$errors++;
};
$draft=[
    'name'=>'Agent-reviewed Release Newsletter',
    'headline'=>'Stonefellow Music Update',
    'body_text'=>'A reviewed preview of the release newsletter.',
    'status'=>'published', // Untrusted input must be forced to draft.
    'audience'=>['newsletter_only'=>true,'segment_id'=>7],
    'graph'=>sf_campaign_graph_default()
];
$p=sf_agent_action_prepare(1,'integrated-campaign-draft-00001','campaign_draft_save',$draft);
$check($p['status']==='proposed'&&$p['payload']['status']==='draft','Approved payload cannot publish');
$check((int)$db->query('SELECT COUNT(*) FROM campaigns')->fetchColumn()===0,'Prepare makes no campaign');
sf_agent_action_approve(1,$p['id'],$p['payload_hash'],'APPROVE');
$check((int)$db->query('SELECT COUNT(*) FROM campaigns')->fetchColumn()===0,'Approval makes no campaign');
$done=sf_agent_action_execute(1,$p['id']);
$id=(int)($done['result']['campaign_id']??0);
$campaign=sf_campaign_get($id);
$check($id>0&&$campaign!==null,'Production Campaign Builder created actual campaign');
$check(($campaign['status']??null)==='draft'&&empty($campaign['published_at']),'Production campaign is unpublished');
$check(($campaign['headline']??'')===$draft['headline'],'Approved headline persisted unchanged');
$check(($campaign['body_text']??'')===$draft['body_text'],'Approved draft body persisted unchanged');
$check(($campaign['audience']['segment_id']??null)===7,'Audience segment persisted');
$check(count($campaign['graph']['nodes']??[])===3,'Production graph validation preserved builder nodes');
$retry=sf_agent_action_execute(1,$p['id']);
$check($retry['result']===$done['result']&&(int)$db->query('SELECT COUNT(*) FROM campaigns')->fetchColumn()===1,'Completed retry returns same result with no duplicate');
$check((int)$db->query('SELECT COUNT(*) FROM campaign_message_runs')->fetchColumn()===0,'Agent cannot send an email');
$second=sf_agent_action_prepare(1,'integrated-campaign-draft-00002','campaign_draft_save',['name'=>'Simulated Interrupted Draft','graph'=>sf_campaign_graph_default()]);
sf_agent_action_approve(1,$second['id'],$second['payload_hash'],'APPROVE');
$db->exec("CREATE TRIGGER suppress_finish BEFORE UPDATE OF status ON agent_action_proposals WHEN NEW.status='completed' BEGIN SELECT RAISE(IGNORE); END;");
try{sf_agent_action_execute(1,$second['id']);$check(false,'Failed ledger persistence must fail closed');}
catch(RuntimeException $e){$check(true,'Failed ledger persistence must fail closed');}
$db->exec('DROP TRIGGER suppress_finish');
$state=sf_agent_action_get(1,$second['id']);
$check($state['status']==='review_required','Ambiguous save remains review_required');
$check((int)$db->query('SELECT COUNT(*) FROM campaigns')->fetchColumn()===2,'Partially recorded action may have saved campaign');
try{sf_agent_action_execute(1,$second['id']);$check(false,'Ambiguous save cannot repeat');}
catch(RuntimeException $e){$check(true,'Ambiguous save cannot repeat');}
$check((int)$db->query('SELECT COUNT(*) FROM campaigns')->fetchColumn()===2,'Manual review path never creates duplicate');
exit($errors?1:0);
