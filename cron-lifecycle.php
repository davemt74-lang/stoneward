<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/api/bootstrap.php';
$result=sf_lifecycle_run_all(500);
echo json_encode(['ok'=>true,...$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
