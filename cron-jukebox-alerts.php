<?php
declare(strict_types=1);

// Run from the site's scheduler once a minute: php cron-jukebox-alerts.php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/api/bootstrap.php';
require_once __DIR__.'/api/jukebox-follow-core.php';
$result=sf_jukebox_alert_run();
echo json_encode(['ok'=>$result['errors']===0]+$result,JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['errors']===0?0:1);
