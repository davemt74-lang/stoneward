<?php
declare(strict_types=1);
try {
    require __DIR__.'/bootstrap.php';
    require __DIR__.'/storefront-core.php';
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')
        sf_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    sf_json_response(sf_storefront_payload());
} catch(Throwable $e) {
    $ref='STORE-'.strtoupper(bin2hex(random_bytes(5)));
    error_log('Stonefellow '.$ref.' storefront response '.get_class($e).' detail='.$e->getMessage());
    $error=['ok'=>false,'message'=>'Store information could not be loaded (reference '.$ref.').','error_ref'=>$ref];
    if(function_exists('sf_json_response'))sf_json_response($error,503);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($error,JSON_UNESCAPED_SLASHES);
    exit;
}
