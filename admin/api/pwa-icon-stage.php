<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/pwa-icon-core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    sf_json_response(['ok'=>false, 'error'=>'method_not_allowed'], 405);
}
$admin = sf_admin_require_auth(true);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
    $raw = file_get_contents('php://input', false, null, 0, 1025);
    if (!is_string($raw) || strlen($raw) > 1024) {
        sf_json_response(['ok'=>false, 'error'=>'request_too_large', 'message'=>'The install icon request is too large.'], 413);
    }
    $request = json_decode($raw, true, 4);
    $jobId = $request['jobId'] ?? null;
    $revision = $request['revision'] ?? null;
    if (!is_int($jobId) || $jobId < 1 || !is_int($revision) || $revision < 0) {
        throw new InvalidArgumentException('Choose an approved image and a current PWA draft revision.');
    }
    $state = sf_pwa_icon_stage($jobId, $revision, (int)$admin['id']);
    sf_json_response(['ok'=>true, 'revision'=>$state['draft']['revision'], 'iconAssetId'=>$state['draft']['iconAssetId']]);
} catch (SfPwaSplashConflict $error) {
    sf_json_response(['ok'=>false, 'error'=>'pwa_splash_conflict', 'message'=>$error->getMessage()], 409);
} catch (SfPwaIconUnavailable $error) {
    sf_json_response(['ok'=>false, 'error'=>'pwa_icon_conversion_unavailable', 'message'=>$error->getMessage()], 503);
} catch (InvalidArgumentException $error) {
    sf_json_response(['ok'=>false, 'error'=>'invalid_pwa_icon', 'message'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[Stonefellow install icon] '.get_class($error));
    sf_json_response(['ok'=>false, 'error'=>'pwa_icon_unavailable', 'message'=>'The install icon could not be prepared. Check that the approved artwork is available, or upload a square PNG in PWA & Splash.'], 503);
}
