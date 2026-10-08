<?php
declare(strict_types=1);
define('SF_ROOT', __DIR__);
require_once __DIR__.'/api/bootstrap.php';
sf_logout_user();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: ./?logged_out=1', true, 303);
exit;
