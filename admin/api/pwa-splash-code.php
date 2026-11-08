<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/pwa-splash-code-core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    sf_json_response(['ok'=>false, 'error'=>'method_not_allowed'], 405);
}
try {
    $admin = sf_admin_require_auth(true);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $limit = 180 * 1024;
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) {
        sf_json_response(['ok'=>false, 'error'=>'request_too_large', 'message'=>'The splash draft is too large.'], 413);
    }
    $request = json_decode($raw, true, 12);
    if (!is_array($request) || array_is_list($request)) {
        sf_json_response(['ok'=>false, 'error'=>'invalid_json', 'message'=>'Use a valid splash request.'], 400);
    }
    sf_json_response(['ok'=>true] + sf_pwa_splash_code_propose($admin, $request));
} catch (SfPwaSplashConflict $error) {
    sf_json_response(['ok'=>false, 'error'=>'pwa_splash_conflict', 'message'=>$error->getMessage()], 409);
} catch (SfAgentSceneInvalidOutput $error) {
    sf_json_response(['ok'=>false, 'error'=>'invalid_pwa_splash_code_output',
        'message'=>'The Coding Agent returned a design the splash editor could not use. Try describing a smaller change.'], 422);
} catch (InvalidArgumentException $error) {
    sf_json_response(['ok'=>false, 'error'=>'invalid_pwa_splash_code_request', 'message'=>$error->getMessage()], 422);
} catch (DomainException $error) {
    sf_json_response(['ok'=>false, 'error'=>'pwa_splash_code_model_required', 'message'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[Stonefellow PWA splash coding] '.get_class($error));
    sf_json_response(['ok'=>false, 'error'=>'pwa_splash_code_unavailable', 'message'=>'The Coding Agent could not complete the splash. Try again in a moment.'], 503);
}
