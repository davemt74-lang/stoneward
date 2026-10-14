<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-capability-audit.php';

$registry=sf_agent_tool_registry();
$original=serialize($registry);
$a=sf_agent_capability_audit();
$checks=[
 'Existing registered tools discovered'=>$a['registered']===count($registry) && $a['registered']>=15,
 'Real contracts, owners, paths and role matrix pass'=>$a['checks_passed']===true,
 'Fan and Admin scopes inventoried'=>($a['by_scope']['admin']??0)>0 && ($a['by_scope']['signed_in_fan']??0)>0,
 'Three approved review-first drafts, never direct execution'=>$a['approved_proposal_executors']===['automation_draft_save','campaign_draft_save','commerce_followup_add'] && !$a['unapproved_executors'],
 'Guest excluded from signed-in and admin tools'=>!in_array('favorite_track',array_column(sf_agent_tool_inventory('guest'),'id'),true)
    && !in_array('crm_aggregate',array_column(sf_agent_tool_inventory('guest'),'id'),true),
 'Fan excluded from admin tools'=>!in_array('crm_aggregate',array_column(sf_agent_tool_inventory('fan'),'id'),true)
    && !sf_agent_tool_allowed('campaign_draft_save','fan',true),
 'Internal Brain logging never discoverable'=>!in_array('agent_brain_log',array_column(sf_agent_tool_inventory('admin'),'id'),true)
    && !sf_agent_tool_allowed('agent_brain_log','admin',true),
 'Read-only audit leaves registry intact'=>$original===serialize(sf_agent_tool_registry()),
];

// Deliberately broken tools must be rejected by the same production validator.
$bad=$registry;
$bad['campaign_publish']['confirmation']=false;
$checks['Mutation: consequential action cannot skip approval']=in_array('campaign_publish',sf_agent_capability_audit($bad)['confirmation_violations'],true);
$bad=$registry;
$bad['campaign_publish']['scope']='fan';
$checks['Mutation: consequential tool cannot become fan-scoped']=in_array('campaign_publish:scope',sf_agent_capability_audit($bad)['confirmation_violations'],true);
$bad=$registry;
$bad['crm_aggregate']['owner']='api/../../config/secrets.php';
$checks['Mutation: owner traversal blocked']=in_array('crm_aggregate',sf_agent_capability_audit($bad)['unsafe_owner_paths'],true);
$bad=$registry;
$bad['crm_aggregate']['owner']='api/does-not-exist.php';
$checks['Mutation: missing owner detected']=in_array('crm_aggregate',sf_agent_capability_audit($bad)['missing_owner_files'],true);
$bad=$registry;
$bad['crm_aggregate']['handler']='bad-handler!';
$checks['Mutation: malformed handler rejected']=in_array('crm_aggregate',sf_agent_capability_audit($bad)['invalid_contracts'],true);
$bad=$registry;
$bad['crm_aggregate']['scope']='unknown';
$checks['Mutation: unknown scope rejected']=in_array('crm_aggregate',sf_agent_capability_audit($bad)['invalid_contracts'],true);
$bad=$registry;
$bad['crm_aggregate']['execution']='remote_script';
$checks['Mutation: unknown execution rejected']=in_array('crm_aggregate',sf_agent_capability_audit($bad)['invalid_contracts'],true);
$bad=$registry;
unset($bad['crm_aggregate']['confirmation']);
$checks['Mutation: missing contract field rejected']=in_array('crm_aggregate',sf_agent_capability_audit($bad)['invalid_contracts'],true);
$bad=$registry;
$bad['agent_brain_log']['kind']='read';
$checks['Mutation: server-internal scope cannot be public kind']=in_array('agent_brain_log:internal_scope',sf_agent_capability_audit($bad)['invalid_contracts'],true);
$bad=$registry;
$bad['campaign_publish']['confirmation']='true';
$checks['Mutation: string is not approval bool']=in_array('campaign_publish',sf_agent_capability_audit($bad)['invalid_contracts'],true);
$bad=$registry;
$bad['crm_aggregate']='bad';
$checks['Mutation: non-array tool rejected']=in_array('crm_aggregate',sf_agent_capability_audit($bad)['invalid_contracts'],true);
$checks['Approved proposal still needs confirmed admin']=
    !sf_agent_tool_executable('campaign_draft_save','admin',false)
    && sf_agent_tool_executable('campaign_draft_save','admin',true)
    && !sf_agent_tool_executable('campaign_draft_save','fan',true)
    && !sf_agent_tool_executable('campaign_send_email','admin',true);
$fail=0;
foreach($checks as $name=>$pass) {
    echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;
    if(!$pass)$fail++;
}
if($fail) {
    fwrite(STDERR,json_encode($a,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
echo 'Section 47A checks: '.count($checks).' passed'.PHP_EOL;
