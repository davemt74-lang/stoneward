<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'message'=>'Method not allowed'],405);

// Built-in stock catalog is public. Temporary ownership failures must not blank the editor.
$modules=sf_turntable_catalog();
$defaults=sf_turntable_module_defaults();
try {
    $user=sf_current_user();
    $owned=sf_turntable_owned((int)($user['id']??0));
    sf_json_response(['ok'=>true,'modules'=>$modules,'owned'=>$owned,'defaults'=>$defaults]);
} catch (Throwable $e) {
    error_log('Turntable module ownership lookup failed: '.$e->getMessage());
    // Fail closed for paid ownership, never grant paid modules without verification.
    sf_json_response(['ok'=>true,'modules'=>$modules,'owned'=>array_values($defaults),'defaults'=>$defaults,
        'warning'=>'Your module ownership could not be verified. Free player components are available; retry before buying or changing paid components.']);
}
