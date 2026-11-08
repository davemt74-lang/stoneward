<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/canvas-library-core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    sf_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if ($_GET) sf_json_response(['ok' => false, 'error' => 'invalid_canvas_library_request'], 422);
sf_json_response(['ok' => true, 'libraries' => sf_canvas_library_available()]);
