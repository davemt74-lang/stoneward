<?php
declare(strict_types=1);
$root=dirname(__DIR__);
define('SF_ROOT',$root);
require_once __DIR__.'/api/bootstrap.php';
sf_logout_user();
header('Cache-Control: no-store');
header('Location: login.php', true, 303);
exit;
