<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function sf_db(): PDO {global $db;return $db;}
require dirname(__DIR__).'/api/agent-specialist-delegation.php';
$admin=sf_agent_mission_plan('admin','Prepare a newsletter campaign','prepare_campaign');
$admin=sf_agent_mission_apply_admin_facts($admin,['crm_contacts'=>40,'newsletter_opted_in'=>22,'journey_counts'=>['journeys_started'=>8,'journeys_failed'=>2,'journeys_completed'=>6]]);
$a=sf_agent_mission_plan_save('admin',11,'admin_'.str_repeat('a',32),'Prepare a newsletter campaign',$admin);
$customer=sf_agent_mission_plan('customer','Make a mellow playlist');
$c=sf_agent_mission_plan_save('customer',29,'conv_'.str_repeat('b',24),'Make a mellow playlist',$customer);
$adminDelegates=sf_agent_specialist_assignments('admin',11,$a['id']);
$customerDelegates=sf_agent_specialist_assignments('customer',29,$c['id']);
$checks=[
 'Admin receives three bounded assignments'=>count($adminDelegates)===3,
 'Customer receives three bounded assignments'=>count($customerDelegates)===3,
 'Admin CRM specialist completed only verified aggregate read'=>$adminDelegates[0]['specialist']==='crm_aggregate_analyst'
    && $adminDelegates[0]['status']==='read_completed'
    && $adminDelegates[0]['evidence']['crm_contacts']===40,
 'Admin journey specialist has scoped evidence'=>$adminDelegates[1]['specialist']==='journey_aggregate_analyst'
    && $adminDelegates[1]['evidence']['journeys_failed']===2,
 'Admin reviewed draft never executes'=>$adminDelegates[2]['status']==='awaiting_owner_review'
    && $adminDelegates[2]['requires_exact_approval'] && !$adminDelegates[2]['can_execute_write'],
 'Customer playlist requires owner approval'=>$customerDelegates[2]['status']==='awaiting_owner_review'
    && $customerDelegates[2]['requires_exact_approval'],
 'Customer reads remain pending, not fictitiously completed'=>$customerDelegates[0]['status']==='read_pending'
    && $customerDelegates[1]['status']==='read_pending',
 'No Customer evidence leaks Admin counts'=>!str_contains(json_encode($customerDelegates),'crm_contacts'),
 'No delegates receive direct write execution'=>array_reduce(array_merge($adminDelegates,$customerDelegates),fn($ok,$s)=>$ok && $s['can_execute_write']===false && $s['read_budget']===1,true),
 'Delegation IDs are stable over restart'=>$adminDelegates===sf_agent_specialist_assignments('admin',11,$a['id']),
 'No delegated API secrets or raw handlers'=>!str_contains(json_encode($adminDelegates),'api_key')
    && !str_contains(json_encode($adminDelegates),'handler'),
 'Admin A cannot see Admin B plans'=>sf_agent_specialist_assignments('admin',12,$a['id'])===[],
 'Customer A cannot see Customer B plans'=>sf_agent_specialist_assignments('customer',30,$c['id'])===[],
 'Customer cannot delegate Admin plan'=>sf_agent_specialist_assignments('customer',11,$a['id'])===[],
 'Admin cannot delegate Customer plan'=>sf_agent_specialist_assignments('admin',29,$c['id'])===[],
 'Invalid mission ID fails closed'=>sf_agent_specialist_assignments('admin',11,'../../bad')===[],
 'Unknown delegation role fails closed'=>sf_agent_specialist_assignments('server',11,$a['id'])===[],
 'Unknown owner fails closed'=>sf_agent_specialist_assignments('admin',0,$a['id'])===[],
 'Unknown registered specialist kind denied'=>sf_agent_specialist_kind('arbitrary_php')===null,
 'Recent assignments filtered by owner'=>count(sf_agent_specialist_recent('admin',11))===1
    && sf_agent_specialist_recent('admin',12)===[],
 'Recent assignments filtered by Brain'=>count(sf_agent_specialist_recent('customer',29))===1
    && sf_agent_specialist_recent('customer',11)===[],
 'Assignment labels come from trusted skill registry'=>$adminDelegates[0]['skill_id']==='admin_crm_insights'
    && $customerDelegates[0]['skill_id']==='catalog_discovery',
];
$save=$db->prepare('UPDATE agent_mission_plans SET plan_json=? WHERE mission_id=?');
$tamper=$admin;$tamper['steps'][0]['tool_id']='campaign_send_email';
$save->execute([json_encode($tamper),$a['id']]);
$checks['Forged delegated tool ID denied']=sf_agent_specialist_assignments('admin',11,$a['id'])===[];
$tamper=$admin;$tamper['steps'][2]['may_execute_directly']=true;
$save->execute([json_encode($tamper),$a['id']]);
$checks['Forged direct write authority denied']=sf_agent_specialist_assignments('admin',11,$a['id'])===[];
$tamper=$admin;$tamper['steps'][2]['status']='read_completed';
$save->execute([json_encode($tamper),$a['id']]);
$checks['Forged write completion denied']=sf_agent_specialist_assignments('admin',11,$a['id'])===[];
$save->execute([json_encode($admin),$a['id']]);
$checks['Restored plan delegates safely']=sf_agent_specialist_assignments('admin',11,$a['id'])===$adminDelegates;
$checks['Customer privacy clear revokes assignments']=sf_agent_mission_plan_clear('customer',29)===1
    && sf_agent_specialist_assignments('customer',29,$c['id'])===[];
$checks['Admin plans survive unrelated Customer clear']=sf_agent_specialist_assignments('admin',11,$a['id'])===$adminDelegates;
$adminSource=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$fanSource=(string)file_get_contents(dirname(__DIR__).'/api/agent-brain.php');
$checks['Authenticated Admin Brain reads owner assignments']=str_contains($adminSource,"sf_agent_specialist_recent('admin',$adminId)");
$checks['Authenticated Customer Brain reads owner assignments']=str_contains($fanSource,"sf_agent_specialist_recent('customer',$userId)");
$checks['Customer UI shows truthful specialist state']=str_contains(file_get_contents(dirname(__DIR__).'/assets/js/agent-brain.js'),'My Agent specialists');
$checks['Admin UI shows truthful specialist state']=str_contains(file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js'),'Specialist assignments');
$bad=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$bad++;}
echo '47G: '.(count($checks)-$bad).'/'.count($checks).' checks passed'.PHP_EOL;
exit($bad?1:0);
