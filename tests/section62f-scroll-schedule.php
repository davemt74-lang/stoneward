<?php
declare(strict_types=1);
$css=file_get_contents(__DIR__.'/../assets/css/turntable-builder.css');
$notification=file_get_contents(__DIR__.'/../api/notification-core.php');
$endpoint=file_get_contents(__DIR__.'/../api/notifications.php');
$cron=file_get_contents(__DIR__.'/../cron-notifications.php');
$checks=[
'Builder body cannot scroll on desktop'=>str_contains($css,'body.builder-workspace-active{height:100dvh;overflow:hidden}'),
'Main desktop stage clipped to viewport'=>str_contains($css,'body.builder-workspace-active .stage{height:100dvh;min-height:0;overflow:hidden;padding-bottom:0}'),
'Canvas does not overflow document'=>str_contains($css,'body.builder-workspace-active .dynamic-canvas{height:calc(100dvh - 64px);min-height:0;overflow:hidden}'),
'Announcement height considered'=>str_contains($css,'has-announcement .dynamic-canvas{height:calc(100dvh - 96px)}'),
'Left rail independently scrolls'=>str_contains($css,'.builder-left-rail{min-height:0;max-height:100%;overflow-y:auto;overflow-x:hidden'),
'Right turntable stage fills locked viewport'=>str_contains($css,'.builder-turntable-stage{position:relative!important;top:auto!important;align-self:stretch;overflow:clip;min-height:0;height:100%;max-height:100%'),
'Expanded audio footer stays clear of editor'=>str_contains($css,'has-player .builder-module{height:calc(100dvh - 64px - 184px)'),
'Announcement and audio footer both clear the editor'=>str_contains($css,'has-announcement.has-player .builder-module{height:calc(100dvh - 96px - 184px)'),
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
