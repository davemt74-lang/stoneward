<?php
declare(strict_types=1);
$core=file_get_contents(__DIR__.'/../api/vinyl-customer-status.php');
$agent=file_get_contents(__DIR__.'/../api/agent-runtime.php');
$notify=file_get_contents(__DIR__.'/../api/notifications.php');
$notifyCore=file_get_contents(__DIR__.'/../api/notification-core.php');
$account=file_get_contents(__DIR__.'/../api/account.php');
$ui=file_get_contents(__DIR__.'/../assets/js/app.js');
$checks=[
'Owner scoped by session user id'=>str_contains($core,'$owner!==$userId'),
'Legacy email ownership guarded'=>str_contains($core,'$owner===0')&&str_contains($core,'$email!==\'\''),
'Only vinyl build orders surfaced'=>str_contains($core,"==='custom_media'")&&str_contains($core,"==='vinyl'"),
'Result history bounded'=>str_contains($core,'array_slice')&&str_contains($core,'min(25'),
'Agent requires authenticated user id'=>str_contains($agent,'$userId!==null && $userId>0'),
'Agent uses shared private status source'=>str_contains($agent,'sf_vinyl_customer_status'),
'Agent response does not write production'=>str_contains($agent,"case 'order_support':")&&str_contains($agent,'I cannot change production'),
'Milestone notifications use user ID'=>str_contains($notifyCore,'sf_vinyl_customer_status($userId'),
'Milestones deduplicated'=>str_contains($notifyCore,"'vinyl:'.hash("),
'Transactional status delivered from central notification generator'=>str_contains($notifyCore,'sf_notify_user($userId,')&&str_contains($notifyCore,"'vinyl_production'"),
'Customer account returns only owned vinyl'=>str_contains($account,'sf_vinyl_customer_status($uid'),
'Customer order card renders production state'=>str_contains($ui,'o.vinyl_production.status'),
];
foreach($checks as $name=>$pass){echo ($pass?'PASS ':'FAIL ').$name."\n";if(!$pass)exit(1);}
echo "Section 62D: 12/12 structural checks passed\n";
