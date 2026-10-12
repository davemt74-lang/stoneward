<?php
declare(strict_types=1);
require_once __DIR__.'/api/bootstrap.php';
if (!sf_admin_authenticated()) { http_response_code(403); exit('Forbidden'); }
header('Cache-Control: no-store');
echo 'Stonefellow operational health';
