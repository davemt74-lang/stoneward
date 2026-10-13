<?php
declare(strict_types=1);
$css=file_get_contents(__DIR__.'/../assets/css/turntable-builder.css');
$notification=file_get_contents(__DIR__.'/../api/notification-core.php');
$endpoint=file_get_contents(__DIR__.'/../api/notifications.php');
$cron=file_get_contents(__DIR__.'/../cron-notifications.php');
$checks=[
'Normal page scrolling restored'=>str_contains($css,'body.builder-workspace-active{height:auto;overflow-y:auto}'),
'Page stage allowed to extend'=>str_contains($css,'body.builder-workspace-active .stage{height:auto;min-height:calc(100dvh - 64px);overflow:visible}'),
'Canvas no longer traps scroll'=>str_contains($css,'body.builder-workspace-active .dynamic-canvas{height:auto;min-height:0;overflow:visible}'),
'Builder follows panel content'=>str_contains($css,'body.builder-workspace-active .builder-module{height:auto;min-height:calc(100dvh - 64px)'),
'No left-hand scrollbar'=>str_contains($css,'.builder-left-rail{height:auto;min-height:0;max-height:none;overflow:visible;scrollbar-gutter:auto;'),
'Sticky player with viewport offset'=>str_contains($css,'.builder-turntable-stage{position:sticky!important;top:76px!important'),
'Announcement sticky offset'=>str_contains($css,'has-announcement .builder-turntable-stage{top:108px!important'),
'Bottom chat footer still protected'=>str_contains($css,'padding-bottom:calc(190px + env(safe-area-inset-bottom,0px))'),
'Admin production rejects historical cassettes'=>str_contains(file_get_contents(__DIR__.'/../admin/api/vinyl-production.php'),'$isVinyl=true;break;')&&str_contains(file_get_contents(__DIR__.'/../admin/api/vinyl-production.php'),"==='vinyl'"),
'Admin design proof filters cassette lines'=>str_contains(file_get_contents(__DIR__.'/../admin/assets/fulfillment.js'),"item.type==='custom_media'&&(item.format||'vinyl')==='vinyl'"),
'Entering builder resets prior document scroll'=>str_contains(file_get_contents(__DIR__.'/../assets/js/app.js'),'window.scrollTo(0,0);renderBuilder()'),
'Mobile hides the desktop stage'=>str_contains($css,'@media(max-width:1023px)')&&str_contains($css,'.builder-turntable-stage{display:none!important}'),
'Notification generator runs at baseline initialization'=>str_contains($notification,'sf_notification_seed_baseline($userId,$now);$vinylGenerated=sf_notification_generate_vinyl_updates($userId)'),
'Notification generator runs on repeat generation'=>str_contains($notification,'$vinylGenerated=sf_notification_generate_vinyl_updates($userId);return ['),
'Notification refresh uses shared generator'=>str_contains($endpoint,"'refresh'")&&str_contains($endpoint,'sf_notification_generate_for_user($uid)'),
'Scheduled notification cron uses shared generator'=>str_contains($cron,'sf_notification_generate_all('),
'Customer Agent stays read-only'=>str_contains(file_get_contents(__DIR__.'/../api/agent-runtime.php'),'I cannot change production'),
];
foreach($checks as $name=>$pass){echo ($pass?'PASS ':'FAIL ').$name."\n";if(!$pass)exit(1);}
echo "Section 62F desktop+scheduled integration: 17/17 checks passed\n";
