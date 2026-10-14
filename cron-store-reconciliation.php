<?php
declare(strict_types=1);
// Set up as a server-side cron job (PHP CLI only), never a public URL.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/api/bootstrap.php';
require_once __DIR__.'/api/store-payments.php';
require_once __DIR__.'/api/store-reconciliation.php';
try {
    $limit=isset($argv[1])?max(1,min(50,(int)$argv[1])):20;
    $report=sf_store_reconcile_batch($limit,120);
    echo json_encode(['ok'=>true,...$report],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit($report['needs_attention']?2:0);
} catch(Throwable $e){
    $reference='PAYREC-'.strtoupper(bin2hex(random_bytes(4)));
    error_log('Stonefellow '.$reference.' cron payment reconciliation: '.$e->getMessage());
    fwrite(STDERR,$reference.' reconciliation failed.'.PHP_EOL);
    exit(1);
}
