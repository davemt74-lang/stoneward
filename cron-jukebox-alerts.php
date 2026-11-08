<?php
declare(strict_types=1);

// Run from the site's scheduler once a minute: php cron-jukebox-alerts.php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/api/bootstrap.php';
require_once __DIR__.'/api/jukebox-follow-core.php';
require_once __DIR__.'/api/canvas-app-alert-core.php';
$result=sf_jukebox_alert_run();
$canvas=sf_canvas_app_alert_run();
$ok=$result['errors']===0&&$canvas['errors']===0&&$canvas['failed']===0;
echo json_encode(['ok'=>$ok]+$result+['canvas_apps'=>$canvas],JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
