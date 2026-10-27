<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-admin-brain.php';
require dirname(__DIR__).'/api/agent-integration-coverage.php';
$fails=0;
$check=static function(bool $ok,string $label)use(&$fails):void{
    echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)$fails++;
};
$shows=[
 ['id'=>'future','title'=>'Upcoming Concert','date'=>'2026-11-15T19:00','venue'=>'Desert Stage','city'=>'Phoenix','status'=>'scheduled','public_visible'=>false,'archive_notes'=>'PRIVATE JOURNAL','ticket_url'=>'https://private.example/ticket'],
 ['id'=>'past','title'=>'Past Show','date'=>'2026-09-01','venue'=>'Old Venue','status'=>'scheduled'],
 ['id'=>'later','title'=>'Later Concert','date'=>'2026-12-01','venue'=>'Later Stage','status'=>'scheduled'],
 ['id'=>'postponed','title'=>'Delayed Date','date'=>'2026-11-01','venue'=>'Pending Venue','status'=>'postponed'],
 ['id'=>'cancelled','title'=>'Cancelled Date','date'=>'2026-11-03','venue'=>'Closed Stage','status'=>'cancelled'],
 ['id'=>'bad','title'=>'Bad Date','date'=>'not-a-date','venue'=>'Bad','status'=>'scheduled']
];
$upcoming=sf_agent_admin_shows_read($shows,'upcoming','2026-10-27');
$check($upcoming['matched']===2 && array_column($upcoming['rows'],'id')===['future','later'],'Upcoming shows exclude past, cancelled and postponed, sorted by date');
$check($upcoming['read_only'] && $upcoming['source']==='stonefellow.admin.shows' && $upcoming['as_of_utc_date']==='2026-10-27','Read scope and time basis explicit');
$check(sf_agent_admin_shows_read($shows,'postponed','2026-10-27')['rows'][0]['id']==='postponed','Postponed show query grounded');
$check(sf_agent_admin_shows_read($shows,'cancelled','2026-10-27')['rows'][0]['id']==='cancelled','Cancelled show query grounded');
$check(sf_agent_admin_shows_read($shows,'upcoming','2026-10-27',1)['truncated'],'Bounded results report truncation');
$check(!str_contains(json_encode($upcoming),'PRIVATE JOURNAL') && !str_contains(json_encode($upcoming),'private.example'),'No archive notes or ticket URLs escape');
$check(!in_array('bad',array_column(sf_agent_admin_shows_read($shows,'all','2026-10-27')['rows'],'id'),true),'Invalid show date ignored');
$check(sf_agent_admin_show_filter('What upcoming concerts are there?')==='upcoming','Natural upcoming show filter');
$check(sf_agent_admin_show_filter('Which shows were cancelled?')==='cancelled','Natural cancellation filter');
$check(str_contains(sf_agent_admin_read_brief($upcoming),'Desert Stage') && str_contains(sf_agent_admin_read_brief($upcoming),'Read only; no changes made'),'Agent gives real venue, date and read-only status');
$check(sf_admin_agent_plan('Show upcoming concerts')['type']==='shows_inventory_read','Voice/text intent uses read route');
$check(sf_admin_agent_plan('Which shows are postponed?')['type']==='shows_inventory_read','Postponement request uses read route');
$check(sf_admin_agent_plan('Open shows calendar')['type']==='shows_manager','Ordinary navigation preserved');
$contract=sf_agent_orchestration_contract('admin','shows_inventory_read');
$check($contract['tool_id']==='admin_show_inventory' && $contract['may_read_or_navigate'] && !$contract['may_execute_directly'] && !$contract['requires_exact_approval'],'Read tool registered without write authority');
$check(!sf_agent_orchestration_contract('customer','shows_inventory_read')['registered'] && !sf_agent_orchestration_contract('public','shows_inventory_read')['registered'],'Fan and public cannot discover private shows');
$audit=sf_agent_integration_coverage(sf_admin_agent_module_routes());
$check($audit['checks_passed'] && in_array('admin_show_inventory',array_column($audit['tools'],'id'),true),'91B1 integration audit knows new show tool');
$brain=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$check(str_contains($brain,'sf_agent_admin_shows_read(sf_admin_shows()') && !str_contains((string)file_get_contents(dirname(__DIR__).'/api/agent-admin-reads.php'),'sf_ai_llm_candidates'),'Real Admin data source, no external provider');
echo 'Section 91B2B: '.(17-$fails).'/17 checks'.PHP_EOL;
exit($fails?1:0);
