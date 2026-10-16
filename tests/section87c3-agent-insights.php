<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users VALUES(1),(2)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_crm_admin_summary():array{return ['contacts'=>24,'newsletter'=>11];}
function sf_campaign_admin_summary():array{return ['campaigns'=>3,'active'=>1];}
function sf_fan_journey_counts(int $days=30):array{return ['counts'=>['journeys_started'=>12,'journeys_completed'=>10,'journeys_failed'=>2],'rates'=>[]];}
function sf_catalog():array{return [
 ['id'=>'published','title'=>'Published Track','state'=>'published','public_visible'=>true],
 ['id'=>'secret','title'=>'HIDDEN_PRIVATE_TITLE','state'=>'private','public_visible'=>false],
];}
function sf_seo_public_track(string $id):?array{
 return $id==='published'?['id'=>'published','title'=>'Published Track']:null;
}
function sf_engagement_analytics(int $days=30,?int $userId=null):array{return [
 'starts'=>100,'repeat_starts'=>30,'skips'=>5,'favorites_added'=>0,
 'paid_orders'=>0,'builds_created'=>1,'custom_media_orders'=>0,
 'users'=>[['user_id'=>999,'email'=>'PRIVATE_FAN@invalid.test']],
 'tracks'=>[
  ['track_id'=>'published','title'=>'Spoofed title','listeners'=>20,'listens'=>80,'repeat_starts'=>26],
  ['track_id'=>'secret','title'=>'HIDDEN_PRIVATE_TITLE','listeners'=>20,'listens'=>15]
 ]
];}
require dirname(__DIR__).'/api/agent-admin-brain.php';
$checks=0;
function pass87c3(bool $value,string $why):void{
 global $checks;if(!$value)throw new RuntimeException('87C3 FAIL '.$why);
 $checks++;echo "PASS $why\n";
}
$context=sf_admin_agent_operational_context();
$brief=(array)($context['fan_journey_signals']??[]);
pass87c3($brief['source']==='admin_aggregate_only','Admin Brain consumes canonical aggregate source');
pass87c3($brief['metrics']['Listening starts']===100
 &&$brief['metrics']['Favorites added']===0,'Allowlisted listening and favorite counts survive');
pass87c3(in_array('repeat_interest',$brief['opportunity_codes'],true)
 &&in_array('save_discovery',$brief['opportunity_codes'],true),
 'Existing site heuristics supply evidence-backed codes');
pass87c3(!str_contains((string)json_encode($brief),'PRIVATE_FAN')
 &&!str_contains((string)json_encode($brief),'HIDDEN_PRIVATE_TITLE')
 &&!str_contains((string)json_encode($brief),'999'),
 'Customer identities and private/untrusted catalog titles excluded from model facts');
pass87c3($brief['attribution_verified']===false
 &&$brief['transactions_or_changes_executed']===false,
 'Model facts cannot assert funnel attribution or side effects');
$injected=sf_fan_journey_agent_brief([
 'days'=>50000,
 'measures'=>[
 ['label'=>'Listening starts','count'=>20],
 ['label'=>'Private memory','count'=>3],
 ['label'=>'Recorded paid-status orders','count'=>'100 OR EXECUTE'],
 ],
 'opportunities'=>[
 ['code'=>'evil','suggestion'=>'DISABLE_SECURITY'],
 ['code'=>'save_discovery','suggestion'=>'EMAIL_PRIVATE_FANS'],
 ]
]);
$encoded=(string)json_encode($injected);
pass87c3(!str_contains($encoded,'DISABLE_SECURITY')
 &&!str_contains($encoded,'EMAIL_PRIVATE_FANS')
 &&!str_contains($encoded,'Private memory'),'Injected metrics and suggestions are not forwarded to Agent');
pass87c3($injected['days']===3650 && !isset($injected['metrics']['Recorded paid-status orders']),
 'Time and numeric model facts are bounded');
$answer=sf_admin_agent_reply(1,'','How is fan engagement doing?');
pass87c3($answer['action']['type']==='journey_report' && $answer['action']['view']==='analytics',
 'Existing Admin Agent routes a fan engagement question to Admin Analytics');
pass87c3(str_contains($answer['text'],'100 listening starts')
 &&str_contains($answer['text'],'Save'),'Admin Agent explains evidence and concrete follow-up');
pass87c3(str_contains($answer['text'],'read-only')
 &&str_contains($answer['text'],'No fan outreach'),'Recommendations explicitly disclose no actions');
pass87c3(!str_contains($answer['text'],'PRIVATE_FAN')
 &&!str_contains($answer['text'],'HIDDEN_PRIVATE_TITLE'),'Agent reply cannot reveal private records');
pass87c3($answer['brain']['scope']==='admin' && $answer['brain']['owner_id']===1,
 'Existing Admin Agent ownership persists');
pass87c3(sf_admin_agent_thread_list(2)===[],'Another Admin does not inherit Agent conversation');
$null=sf_fan_journey_agent_message(['metrics'=>[]]);
pass87c3(str_contains($null,'unavailable'),'Missing analytics fail closed without made-up data');
$src=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
pass87c3(str_contains($src,'sf_fan_journey_agent_brief($diagnostics)')
 &&str_contains($src,'sf_admin_agent_research_reply($message,$context,$prior,$adminId)'),
 'Existing Agent conversation and LLM routing remain integrated');
pass87c3(!str_contains((string)file_get_contents(dirname(__DIR__).'/api/fan-journey-agent-bridge.php'),'sf_campaign_save(')
 &&!str_contains((string)file_get_contents(dirname(__DIR__).'/api/fan-journey-agent-bridge.php'),'sf_crm_newsletter_signup('),
 'Intelligence bridge cannot save campaigns or enroll fans');
echo "87C3: $checks checks green\n";
