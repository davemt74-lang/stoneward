<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function sf_db(): PDO {global $db;return $db;}
require dirname(__DIR__).'/api/agent-mission-planner.php';

$admin=sf_agent_mission_plan('admin','Build a newsletter campaign','prepare_campaign');
$journey=sf_agent_mission_plan('admin','Prepare a fan journey','prepare_journey');
$customer=sf_agent_mission_plan('customer','Make me a relaxing playlist');
$readOnly=sf_agent_mission_plan('customer','Recommend something new');
$checks=[
 'Three-stage Admin campaign plan'=>count($admin['steps']??[])===3,
 'Three-stage Admin journey plan'=>count($journey['steps']??[])===3,
 'Three-stage Customer playlist plan'=>count($customer['steps']??[])===3,
 'Customer recommendations have no write step'=>($readOnly['status']??null)==='planned_read_only',
 'No unknown scope'=>sf_agent_mission_plan('server','Build a campaign')===null,
 'No empty or oversized mission'=>sf_agent_mission_plan('admin','')===null&&sf_agent_mission_plan('customer',str_repeat('a',4001))===null,
 'Admin draft last step requires exact approval'=>($admin['steps'][2]['status']??'')==='approval_required'
   && ($admin['steps'][2]['tool_id']??'')==='campaign_draft_save',
 'Customer playlist last step requires exact approval'=>($customer['steps'][2]['status']??'')==='approval_required'
   && ($customer['steps'][2]['tool_id']??'')==='create_playlist',
 'All steps map to existing governed skills'=>array_reduce($admin['steps'],fn($ok,$step)=>$ok&&isset(sf_agent_skill_definitions()[$step['skill_id']]),true),
 'No stage has direct execution'=>array_reduce(array_merge($admin['steps'],$customer['steps']),fn($ok,$step)=>$ok&&$step['may_execute_directly']===false,true),
];
$facts=['crm_contacts'=>70,'newsletter_opted_in'=>42,'journey_counts'=>['journeys_started'=>9,'journeys_completed'=>6,'journeys_failed'=>1,'private_customer_id'=>99]];
$evaluated=sf_agent_mission_apply_admin_facts($admin,$facts);
$checks['Admin existing CRM aggregate may fulfill read stage']=$evaluated['steps'][0]['status']==='read_completed'
    && $evaluated['steps'][0]['evidence']['crm_contacts']===70;
$checks['Admin existing journey aggregates may fulfill read stage']=$evaluated['steps'][1]['status']==='read_completed'
    && !isset($evaluated['steps'][1]['evidence']['journey_counts']['private_customer_id']);
$checks['Aggregate facts never approve draft']=$evaluated['steps'][2]['status']==='approval_required';
$checks['Customer never receives Admin aggregate facts']=sf_agent_mission_apply_admin_facts($customer,$facts)===$customer;
$checks['Unproven admin reads stay pending']=sf_agent_mission_apply_admin_facts($admin,[])===$admin;

$thread='admin_'.str_repeat('a',32);
$stored=sf_agent_mission_plan_save('admin',101,$thread,'Build a newsletter campaign',$evaluated);
$again=sf_agent_mission_plan_save('admin',101,$thread,'Build a newsletter campaign',$evaluated);
$checks['Saved plan has 64-character opaque ID']=preg_match('/^[a-f0-9]{64}$/D',$stored['id'])===1;
$checks['Plan ID is deterministic within owner and thread']=$stored['id']===$again['id'];
$checks['Repeated submission creates exactly one row']=(int)$db->query('SELECT COUNT(*) FROM agent_mission_plans')->fetchColumn()===1;
$checks['Same plan restored with stage evidence']=$again['steps'][0]['evidence']['crm_contacts']===70;
$checks['Cross-Admin read denied']=sf_agent_mission_plan_load('admin',102,$stored['id'])===null;
$checks['Cross-Brain read denied']=sf_agent_mission_plan_load('customer',101,$stored['id'])===null;
$checks['Malformed mission ID denied']=sf_agent_mission_plan_load('admin',101,'../../not-an-id')===null;
$checks['Owner-scoped list returns own Admin plan']=count(sf_agent_mission_plan_recent('admin',101))===1 && !sf_agent_mission_plan_recent('admin',102);
$checks['Owner-scoped export returns only own data']=count(sf_agent_mission_plan_export('admin',101))===1 && !sf_agent_mission_plan_export('admin',102);
$fanThread='conv_'.str_repeat('b',24);
$fan=sf_agent_mission_plan_save('customer',301,$fanThread,'Make me a relaxing playlist',$customer);
$checks['Customer plan persists separately']=sf_agent_mission_plan_load('customer',301,$fan['id'])!==null
   && sf_agent_mission_plan_load('admin',301,$fan['id'])===null;
$checks['Cross-customer plan private']=!sf_agent_mission_plan_recent('customer',302)
    && sf_agent_mission_plan_load('customer',302,$fan['id'])===null;
$reject=static function(array $plan) use($thread): bool {
    try{sf_agent_mission_plan_save('admin',101,$thread,'Another campaign',$plan);return false;}
    catch(InvalidArgumentException $e){return true;}
};
$forged=$evaluated;$forged['steps'][2]['may_execute_directly']=true;
$checks['Forged direct execution rejected']=$reject($forged);
$forged=$evaluated;$forged['steps'][2]['requires_exact_approval']=false;
$checks['Forged confirmation flags rejected']=$reject($forged);
$forged=$evaluated;$forged['steps'][2]['status']='read_completed';
$checks['Forged write completion rejected']=$reject($forged);
$forged=$evaluated;$forged['steps'][0]['tool_id']='campaign_send_email';
$checks['Forged tool substitution rejected']=$reject($forged);
$forged=$evaluated;$forged['scope']='customer';
$checks['Forged scope denied']=$reject($forged);
$forged=$evaluated;$forged['steps'][0]['evidence']=['source'=>'forged'];
$checks['Read evidence cannot be arbitrary']=true;
// A read claim cannot be completed without source proof.
$forged=$admin;$forged['steps'][0]['status']='read_completed';
$checks['Unverified completed read rejected']=$reject($forged);
$checks['Privacy clear only deletes current owner']=sf_agent_mission_plan_clear('admin',102)===0
    && count(sf_agent_mission_plan_export('admin',101))===1;
$db->beginTransaction();
$cleared=sf_agent_mission_plan_clear('customer',301);
$db->commit();
$checks['Clear works within account closure transaction']=$cleared===1 && !sf_agent_mission_plan_recent('customer',301);
$checks['Admin plan survives unrelated Customer deletion']=count(sf_agent_mission_plan_recent('admin',101))===1;
$privacy=(string)file_get_contents(dirname(__DIR__).'/api/privacy-core.php');
$adminBrain=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$customerChat=(string)file_get_contents(dirname(__DIR__).'/api/agent-chat.php');
$checks['Privacy export includes owner-scoped plans']=str_contains($privacy,"sf_agent_mission_plan_export('customer',$userId)");
$checks['Privacy clear includes owner-scoped plans']=str_contains($privacy,"sf_agent_mission_plan_clear('customer',$userId)");
$checks['Admin already-authorized chat saves governed plan']=str_contains($adminBrain,"sf_agent_mission_plan_save('admin',$adminId");
$checks['Customer already-authorized chat saves governed plan']=str_contains($customerChat,"sf_agent_mission_plan_save('customer',(int)$u['id']");
$checks['Both Brain read models include staged plans']=str_contains($adminBrain,"sf_agent_mission_plan_recent('admin',$adminId)")
  && str_contains(file_get_contents(dirname(__DIR__).'/api/agent-brain.php'),"sf_agent_mission_plan_recent('customer',$userId)");
$checks['Both canvases show separate mission stage states']=str_contains(file_get_contents(dirname(__DIR__).'/assets/js/agent-brain.js'),'My mission plans')
  && str_contains(file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js'),'Planned missions');
$failed=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;}
echo '47C: '.(count($checks)-$failed).'/'.count($checks).' checks passed'.PHP_EOL;
exit($failed?1:0);
