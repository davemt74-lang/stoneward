<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/fan-journey-recommendations.php';
$journey=['counts'=>['known_contacts'=>60,'newsletter_opted_in'=>3,'journeys_started'=>20,'journeys_failed'=>5,'active_contacts'=>12,'listening_contacts'=>0]];
$campaigns=['campaigns'=>[['name'=>'Unperforming Offer','participants'=>20,'converted'=>0],['name'=>'Successful Offer','participants'=>20,'converted'=>5],['name'=>'Small sample','participants'=>2,'converted'=>0]]];
$automation=['runs'=>20,'failed'=>5];
$advice=sf_fan_journey_recommendation_rules($journey,$campaigns,$automation);
$json=json_encode($advice,JSON_THROW_ON_ERROR);
$checks=[
 'Warnings surface observed failure counts'=>str_contains($json,'5 of 20 journey runs failed'),
 'Weak campaign identified only when sample sufficient'=>str_contains($json,'Unperforming Offer')&&!str_contains($json,'Small sample'),
 'Successful campaign not spuriously flagged'=>!str_contains($json,'Successful Offer'),
 'Newsletter advice explicitly respects permission'=>str_contains($json,'unconsented fans'),
 'Listening warning avoids assuming inactivity'=>str_contains($json,'Anonymous listening may still occur'),
 'Every recommendation is manual review only'=>count(array_filter($advice,fn($a)=>($a['execution']??null)!=='manual_review_only'))===0,
 'No contact records returned'=>!str_contains($json,'email')&&!str_contains($json,'contact_id'),
 'No empty-baseline division by zero'=>count(sf_fan_journey_recommendation_rules(['counts'=>[]],[],[]))===1,
 'Output limited to ten'=>count(sf_fan_journey_recommendation_rules($journey,['campaigns'=>array_fill(0,40,['name'=>'Poor','participants'=>30,'converted'=>0])],[]))<=10,
 'No execution or sending functions invoked'=>!str_contains(file_get_contents(dirname(__DIR__).'/api/fan-journey-recommendations.php'),'sf_automation_save(')&&!str_contains(file_get_contents(dirname(__DIR__).'/api/fan-journey-recommendations.php'),'sf_campaign_send_email_node('),
 'Read-only endpoint requires admin'=>str_contains(file_get_contents(dirname(__DIR__).'/admin/api/fan-journey-advice.php'),'sf_admin_require_auth(false)')
];
$errors=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$errors++;}
exit($errors?1:0);
