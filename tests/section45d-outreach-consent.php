<?php
declare(strict_types=1);
function sf_privacy_personalization_enabled(int $userId):bool{return $userId===7;}
function sf_crm_contact_by_id(int $id):?array{global $storedContacts;return $storedContacts[$id]??null;}
require dirname(__DIR__).'/api/automation-core.php';
$contact=['user_id'=>7,'marketing_opt_in'=>1,'unsubscribed_at'=>null,'agent_auto_engage'=>1];
$checks=[
 'Opted-in contact eligible for email'=>sf_automation_outreach_allowed('email',$contact),
 'Current subscriber can receive opted-in Agent message'=>sf_automation_outreach_allowed('agent_message',$contact),
 'Email opt-out suppresses send'=>!sf_automation_outreach_allowed('email',$contact+['marketing_opt_in'=>0]) // overridden below
];
$optout=$contact;$optout['marketing_opt_in']=0;
$checks['Email opt-out suppresses send']=!sf_automation_outreach_allowed('email',$optout);
$optout=$contact;$optout['unsubscribed_at']=gmdate('c');
$checks['Unsubscribe timestamp prevents email despite stale opt-in flag']=!sf_automation_outreach_allowed('email',$optout);
$optout=$contact;$optout['agent_auto_engage']=0;
$checks['Disabled Agent engagement suppresses notification']=!sf_automation_outreach_allowed('agent_message',$optout);
$optout=$contact;$optout['user_id']=8;
$checks['Privacy personalization off suppresses proactive Agent']=!sf_automation_outreach_allowed('agent_message',$optout);
$optout=$contact;$optout['user_id']=null;
$checks['Unlinked contact cannot receive private Agent notification']=!sf_automation_outreach_allowed('agent_message',$optout);
$checks['Unrecognized outreach type denied']=!sf_automation_outreach_allowed('push',$contact);
$storedContacts=[33=>$contact+['id'=>33]];
$snapshot=$contact;$snapshot['id']=33;
$storedContacts[33]['marketing_opt_in']=0;
$checks['Latest CRM opt-out overrides earlier journey snapshot']=!sf_automation_outreach_allowed('email',$snapshot);
$storedContacts[33]['marketing_opt_in']=1;$storedContacts[33]['agent_auto_engage']=0;
$checks['Latest Agent opt-out overrides earlier journey snapshot']=!sf_automation_outreach_allowed('agent_message',$snapshot);
$storedContacts=[];

$src=(string)file_get_contents(dirname(__DIR__).'/api/automation-core.php');
$checks['Production Agent step applies helper']=str_contains($src,"sf_automation_outreach_allowed('agent_message',$"."contact)");
$checks['Production email step applies helper']=str_contains($src,"sf_automation_outreach_allowed('email',$"."contact)");
$checks['CRM activity events remain as trigger source']=str_contains($src,'sf_automation_process_crm_event');
$failed=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;}
exit($failed?1:0);
