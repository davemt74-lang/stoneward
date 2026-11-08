<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
require __DIR__.'/api/bootstrap.php';
require_once SF_ROOT.'/api/pwa-splash-core.php';

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
$policy = "sandbox allow-scripts; default-src 'none'; style-src 'unsafe-inline'; img-src data: blob:; font-src data:; "
    ."base-uri 'none'; form-action 'none'; frame-ancestors 'self'";
header('Content-Security-Policy: '.$policy);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed.');
}
$surface = $_GET['surface'] ?? null;
$revision = filter_var($_GET['revision'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
$draft = ($_GET['draft'] ?? null) === '1';
if (!in_array($surface, SF_PWA_SPLASH_SURFACES, true) || $revision === false) {
    http_response_code(404);
    exit('Splash design unavailable.');
}

try {
    if ($draft) {
        $user = sf_current_user();
        if (!$user || $user['role'] !== 'admin') {
            http_response_code(404);
            exit('Splash design unavailable.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    }
    $snapshot = sf_pwa_splash_state()[$draft ? 'draft' : 'published'];
    if ($snapshot['revision'] !== $revision || $snapshot[$surface]['mode'] !== 'code') {
        http_response_code(404);
        exit('Splash design unavailable.');
    }
    $design = sf_pwa_splash_code($snapshot[$surface]);
    $sources = [];
    foreach ($design['libraries'] as $library) {
        $source = file_get_contents(SF_ROOT.'/'.$library['path'], false, null, 0, $library['bytes'] + 1);
        if ($source === false || !hash_equals($library['sha256'], base64_encode(hash('sha256', $source, true)))) {
            throw new RuntimeException('Splash library changed.');
        }
        $sources[] = $source;
    }
    if (isset($design['js'])) $sources[] = $design['js'];
    if ($sources) {
        // Keeping the script bytes in inert JSON prevents raw-text parsing from
        // changing code or ending its enclosing script element prematurely.
        $runner = <<<'JS'
for (const source of JSON.parse(document.getElementById('sf-splash-source').textContent)) {
    const script = document.createElement('script');
    script.textContent = source;
    document.body.append(script);
}
JS;
        $sourceJson = json_encode($sources, JSON_HEX_TAG|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        $hashes = array_map(
            static fn(string $source): string => "'sha256-".base64_encode(hash('sha256', $source, true))."'",
            [$runner, ...$sources]
        );
        header('Content-Security-Policy: '.$policy.'; script-src '.implode(' ', $hashes));
    }
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    exit($draft ? htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8') : 'Splash design unavailable.');
} catch (Throwable $error) {
    error_log('[Stonefellow PWA splash render] '.get_class($error));
    http_response_code(503);
    exit('Splash design unavailable.');
}
?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Stonefellow splash design</title>
<style>html,body{margin:0;min-height:100%;color:inherit}<?=$design['css']?></style>
<body>
<?=$design['html']?>
<?php if ($sources): ?>
<script type="application/json" id="sf-splash-source"><?=$sourceJson?></script>
<script><?=$runner?></script>
<?php endif; ?>
</body>
</html>
