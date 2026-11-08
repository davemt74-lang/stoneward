<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/pwa-splash-core.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    sf_json_response(['ok'=>false, 'error'=>'method_not_allowed'], 405);
}
try {
    $admin = sf_admin_require_auth($method === 'POST');
    $csrf = sf_admin_csrf();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($method === 'GET') {
        $state = sf_pwa_splash_state();
    } else {
        $limit = 180 * 1024;
        $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
        if ($raw === false || strlen($raw) > $limit) {
            sf_json_response(['ok'=>false, 'error'=>'request_too_large', 'message'=>'The splash draft is too large.'], 413);
        }
        $request = json_decode($raw, true, 12);
        if (!is_array($request) || array_is_list($request)) {
            sf_json_response(['ok'=>false, 'error'=>'invalid_json', 'message'=>'Use a valid splash request.'], 400);
        }
        $action = $request['action'] ?? null;
        $revision = $request['revision'] ?? null;
        $settings = $request['settings'] ?? [];
        if (!in_array($action, ['save', 'publish', 'restore', 'import'], true) ||
            !is_int($revision) || $revision < 0 || !is_array($settings) || ($settings && array_is_list($settings))) {
            throw new InvalidArgumentException('Choose a valid splash action and draft revision.');
        }
        $state = sf_pwa_splash_update((int)$admin['id'], $action, $revision, $settings, $request);
    }
    sf_json_response([
        'ok'=>true, 'csrfToken'=>$csrf, 'hasUnpublishedChanges'=>sf_pwa_splash_has_changes($state),
        'draft'=>sf_pwa_splash_present($state['draft'], true, true),
        'published'=>sf_pwa_splash_present($state['published'], false, true),
    ]);
} catch (SfPwaSplashConflict $error) {
    sf_json_response(['ok'=>false, 'error'=>'pwa_splash_conflict', 'message'=>$error->getMessage()], 409);
} catch (InvalidArgumentException $error) {
    sf_json_response(['ok'=>false, 'error'=>'invalid_pwa_splash', 'message'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[Stonefellow PWA splash] '.get_class($error));
    sf_json_response(['ok'=>false, 'error'=>'pwa_splash_unavailable', 'message'=>'Splash settings are temporarily unavailable.'], 503);
}
