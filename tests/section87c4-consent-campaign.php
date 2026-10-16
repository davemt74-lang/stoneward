<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE fan_contacts(id INTEGER PRIMARY KEY,user_id INTEGER,
 email TEXT,marketing_opt_in INTEGER,unsubscribed_at TEXT)");
$db->exec("INSERT INTO fan_contacts VALUES
(1,10,'subscribed@local',1,NULL),
(2,11,'opted-out@local',1,'2026-10-01T10:00:00+00:00'),
(3,12,'never@local',0,NULL),
(4,13,'subscribed-again@local',1,NULL)");
function sf_db():PDO{global $db;return $db;}
function sf_crm_ensure_schema():void{}
require dirname(__DIR__).'/api/fan-conversion-campaign-strategy.php';
$checked=0;
function ok87c4(bool $good,string $why):void{
 global $checked;if(!$good)throw new RuntimeException('87C4 FAIL '.$why);
 $checked++;echo "PASS $why\n";
}
$eligible=sf_fan_conversion_eligible_newsletter_count();
ok87c4($eligible===2,'Current CRM opt-in excludes unsubscribed and never-consenting contacts');
$diagnostics=['opportunities'=>[
 ['code'=>'repeat_interest','reason'=>'INJECT_PRIVATE_CONTACT'],
 ['code'=>'purchase_path'],['code'=>'vinyl_path'],
 ['code'=>'save_discovery'],['code'=>'hidden_audience','reason'=>'USE_ALL_USERS']
]];
$strategy=sf_fan_conversion_campaign_strategy($diagnostics,$eligible);
$codes=array_column($strategy['ideas'],'code');
ok87c4(in_array('newsletter_music_review',$codes,true)
 &&in_array('newsletter_vinyl_review',$codes,true),
 'Newsletter ideas available to existing opted-in audience only');
ok87c4(in_array('cart_guidance',$codes,true)&&in_array('save_action',$codes,true),
 'On-site conversion improvements do not require mailing anyone');
ok87c4(count(array_filter($strategy['ideas'],fn($i)=>$i['channel']==='newsletter_review'))===2,
 'No unrequested newsletter campaigns generated');
ok87c4(count(array_filter($strategy['ideas'],fn($i)=>$i['executed']||$i['send_authorized']))===0
 &&$strategy['any_send_executed']===false&&$strategy['campaign_created']===false,
 'No marketing send, CRM mutation or campaign creation authorized');
ok87c4(!str_contains((string)json_encode($strategy),'INJECT_PRIVATE_CONTACT')
 &&!str_contains((string)json_encode($strategy),'USE_ALL_USERS'),
 'Untrusted recommendation text is not copied to campaign guidance');
$none=sf_fan_conversion_campaign_strategy($diagnostics,0);
ok87c4(count(array_filter($none['ideas'],fn($i)=>$i['channel']==='newsletter_review'))===0,
 'No consented audience means no newsletter recommendation');
ok87c4(count(array_filter($none['ideas'],fn($i)=>$i['channel']==='onsite'))>=2,
 'Opted-out audience does not suppress safe public UX recommendations');
$brief=sf_fan_conversion_agent_brief($strategy);
ok87c4($brief['eligible_current_opted_in_contacts']===2
 &&$brief['marketing_send_executed']===false&&$brief['admin_review_required'],
 'Admin Agent receives only bounded audience metrics and approval flags');
$injected=sf_fan_conversion_agent_brief(['eligible_opted_in_contacts'=>'ALL_CONTACTS',
 'ideas'=>[['code'=>'evil','reason'=>'SEND_PRIVATE_EMAIL'],['code'=>'save_action',
 'reason'=>'SEND_PRIVATE_EMAIL']]]);
ok87c4($injected['eligible_current_opted_in_contacts']===0
 &&$injected['idea_codes']===['save_action']
 &&!str_contains((string)json_encode($injected),'SEND_PRIVATE_EMAIL'),
 'Spoofed audience counts, commands and prose do not reach Agent model');
$explain=sf_fan_conversion_agent_copy($brief);
ok87c4(str_contains($explain,'unsent')&&str_contains($explain,'nothing was created or sent')
 &&str_contains($explain,'live consent'),'Agent explains eligibility without claiming execution');
$small=sf_fan_conversion_campaign_strategy(['opportunities'=>[['code'=>'sample_limited']]],15);
ok87c4(count($small['ideas'])===1&&$small['ideas'][0]['channel']==='onsite',
 'Small sample cannot trigger unsolicited audience campaigns');
$root=dirname(__DIR__);
$endpoint=(string)file_get_contents($root.'/admin/api/campaigns.php');
$ui=(string)file_get_contents($root.'/admin/assets/campaigns.js');
$brain=(string)file_get_contents($root.'/api/agent-admin-brain.php');
ok87c4(str_contains($endpoint,'sf_admin_require_auth($method!==\'GET\')')
 &&str_contains($endpoint,'sf_fan_conversion_eligible_newsletter_count()')
 &&str_contains($endpoint,"'fan_conversion_guidance'"),
 'Existing Admin Campaigns endpoint requires authentication and fetches live CRM consent');
ok87c4(str_contains($ui,'state.guidance=j.fan_conversion_guidance')
 &&str_contains($ui,'esc(i.title') && str_contains($ui,'esc(i.reason'),
 'Native Campaign Builder safely renders strategy without inventing a second app');
ok87c4(str_contains($brain,"$"."summary['fan_campaign_guidance']")
 &&str_contains($brain,'sf_fan_conversion_agent_copy('),
 'Existing Admin Agent context and reviewed campaign flow receive guidance');
$campaign=(string)file_get_contents($root.'/api/campaign-core.php');
$auto=(string)file_get_contents($root.'/api/automation-core.php');
ok87c4(str_contains($campaign,'function sf_campaign_send_recipient_allowed')
 &&str_contains($auto,'function sf_automation_outreach_allowed')
 &&str_contains($auto,'unsubscribed_at'),
 'Existing send-time consent and unsubscribe guards remain the only outreach path');
echo "87C4: $checked assertions green\n";
