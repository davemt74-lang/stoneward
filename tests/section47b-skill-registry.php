<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-orchestration.php';
$registry=sf_agent_tool_registry();$defs=sf_agent_skill_definitions();
$public=sf_agent_skill_inventory('public');
$customer=sf_agent_skill_inventory('customer');
$admin=sf_agent_skill_inventory('admin');
$tools=fn(array $skills): array=>array_column($skills,'tool_id');
$checks=[
 'Every non-internal tool has one skill manifest'=>count($registry)===count($defs)+1 && count(array_unique(array_column($defs,'tool')))==count($defs),
 'No internal audit skill'=>!in_array('agent_brain_log',array_column($defs,'tool'),true),
 'Public inventory contains published/queue/public-artist reads'=>count($public)===5
    && in_array('public_artist_facts',$tools($public),true)
    && !in_array('create_playlist',$tools($public),true)
    && !in_array('crm_aggregate',$tools($public),true),
 'Customer can discover private profile tools'=>in_array('create_playlist',$tools($customer),true) && in_array('favorite_track',$tools($customer),true),
 'Customer cannot discover Admin capabilities'=>!in_array('crm_aggregate',$tools($customer),true) && !in_array('campaign_draft_save',$tools($customer),true),
 'Admin cannot discover customer-private skills'=>in_array('crm_aggregate',$tools($admin),true) && !in_array('favorite_track',$tools($admin),true),
 'Unrecognized context fails closed'=>!sf_agent_skill_inventory('unknown') && sf_agent_skill_for_intent('unknown','prepare_campaign')===null,
 'Guest cannot request customer intents'=>sf_agent_skill_for_intent('public','create_playlist')===null,
 'Customer cannot request Admin intents'=>sf_agent_skill_for_intent('customer','prepare_campaign')===null,
 'Admin cannot request customer intents'=>sf_agent_skill_for_intent('admin','favorite_save')===null,
 'No internal intent exposed as public skill'=>sf_agent_skill_for_intent('admin','review_actions')===null,
 'Owner-controlled publication cannot become Agent write'=>!sf_agent_orchestration_contract('admin','publish_campaign')['may_prepare_reviewed_proposal'] && !sf_agent_orchestration_contract('admin','publish_campaign')['may_execute_directly'],
 'Owner-controlled outreach cannot become Agent write'=>!sf_agent_orchestration_contract('admin','send_campaign')['may_prepare_reviewed_proposal'] && !sf_agent_orchestration_contract('admin','send_campaign')['may_execute_directly'],
 'Customer favorite still requires approval'=>sf_agent_orchestration_contract('customer','favorite_save')['requires_exact_approval'] && !sf_agent_orchestration_contract('customer','favorite_save')['may_execute_directly'],
 'Admin campaign remains reviewed proposal'=>sf_agent_orchestration_contract('admin','prepare_campaign')['may_prepare_reviewed_proposal'] && !sf_agent_orchestration_contract('admin','prepare_campaign')['may_execute_directly'],
 'Admin journey remains reviewed proposal'=>sf_agent_orchestration_contract('admin','prepare_journey')['may_prepare_reviewed_proposal'] && !sf_agent_orchestration_contract('admin','prepare_journey')['may_execute_directly'],
 'Historical review contract still internal'=>sf_agent_orchestration_contract('admin','review_actions')['registered'] && !sf_agent_orchestration_contract('admin','review_actions')['may_read_or_navigate'],
 'Shared discovery returns same public skill ID'=>sf_agent_skill_for_intent('public','public_catalog_search')['id']===sf_agent_skill_for_intent('customer','public_catalog_search')['id'],
 'No unknown intent is auto-executable'=>!sf_agent_orchestration_contract('admin','arbitrary_php')['registered'] && !sf_agent_orchestration_contract('customer','delete_account')['registered'],
];
foreach(['public'=>$public,'customer'=>$customer,'admin'=>$admin] as $context=>$list) {
    foreach($list as $m) {
        $checks['Skill contract and no execution '. $context.' '.$m['id']]=
            $m['contract']==='stonefellow.skill.v1'
            && $m['context']===$context
            && $m['may_execute_directly']===false
            && $m['requires_exact_approval']===(bool)$registry[$m['tool_id']]['confirmation']
            && !array_key_exists('handler',$m)
            && !array_key_exists('owner',$m);
        foreach($m['intents'] as $intent) {
            $lookup=sf_agent_skill_for_intent($context,$intent);
            $checks['Intent route '.$context.' '.$intent]=$lookup!==null && $lookup['id']===$m['id'];
        }
    }
}
$checks['Public endpoint requires customer identity']=str_contains(file_get_contents(dirname(__DIR__).'/api/agent-brain.php'),'sf_require_user(false,$write)')
    && str_contains(file_get_contents(dirname(__DIR__).'/api/agent-brain.php'),"sf_agent_skill_inventory('customer')");
$checks['Admin endpoint requires authenticated admin']=str_contains(file_get_contents(dirname(__DIR__).'/admin/api/agent-tools.php'),'sf_admin_require_auth(false)')
    && str_contains(file_get_contents(dirname(__DIR__).'/admin/api/agent-tools.php'),"sf_agent_skill_inventory('admin')");
$checks['Admin Brain includes same skill registry']=str_contains(file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php'),"sf_agent_skill_inventory('admin')");
$checks['No dynamic tool dispatch in skill registry']=!preg_match('/\\b(call_user_func|eval|include|shell_exec)\\s*\\(/',file_get_contents(dirname(__DIR__).'/api/agent-skill-registry.php'));
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
echo 'Section 47B checks: '.(count($checks)-$fail).'/'.count($checks).PHP_EOL;
exit($fail?1:0);
