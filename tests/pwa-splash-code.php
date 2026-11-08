<?php
declare(strict_types=1);

$root = sys_get_temp_dir().'/stonefellow-splash-code-'.bin2hex(random_bytes(6));
mkdir($root.'/storage/media', 0700, true);
symlink(dirname(__DIR__).'/assets', $root.'/assets');
define('SF_ROOT', $root);
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("PRAGMA foreign_keys=ON; CREATE TABLE users(id INTEGER PRIMARY KEY); INSERT INTO users VALUES(1)");
$mode = 'success';
$calls = [];

function sf_db(): PDO { return $GLOBALS['db']; }
function sf_db_config(): array { return ['driver'=>'sqlite']; }
function sf_installed(): bool { return true; }
function sf_clean_text(mixed $value, int $limit=120): string { return substr(trim((string)$value), 0, $limit); }
function sf_ai_canvas_llm_candidates(): array {
    if ($GLOBALS['mode'] === 'unconfigured') return [];
    return [['provider'=>'openai', 'model'=>'stub-primary'], ['provider'=>'anthropic', 'model'=>'stub-alternate']];
}
function sf_openai_call(array $provider, string $system, array $history, string $message, int $limit, int $timeout): array {
    return splash_fixture_model($provider, $system, $message, $limit, $timeout);
}
function sf_anthropic_call(array $provider, string $system, array $history, string $message, int $limit, int $timeout): array {
    return splash_fixture_model($provider, $system, $message, $limit, $timeout);
}
function splash_fixture_model(array $provider, string $system, string $message, int $limit, int $timeout): array {
    global $mode, $calls;
    $calls[] = ['provider'=>$provider, 'system'=>$system, 'message'=>$message, 'limit'=>$limit, 'timeout'=>$timeout];
    if ($mode === 'fallback' && $provider['provider'] === 'openai') throw new RuntimeException('Fixture provider unavailable.');
    if ($mode === 'stale') {
        $state = sf_pwa_splash_state();
        ++$state['draft']['revision'];
        sf_meta_set(SF_PWA_SPLASH_META_KEY, json_encode($state, JSON_THROW_ON_ERROR));
    }
    $invalid = $mode === 'invalid' || ($mode === 'repair' && !str_contains($system, 'previous design failed markup'));
    $html = $invalid ? '<script>alert(1)</script>' : '<section class="splash"><h1>Guest signal</h1><figure class="hero"></figure></section>';
    $css = '.splash{color:white;background:#181226}';
    $js = 'const media=JSON.parse(document.getElementById("sf-splash-media").textContent);';
    return ['text'=>json_encode(['html'=>$html, 'css'=>$css, 'js'=>$js], JSON_THROW_ON_ERROR),
        'provider'=>$provider['provider'], 'model'=>$provider['model'], 'input_tokens'=>42, 'output_tokens'=>18];
}
function verify_splash(bool $ok, string $description): void {
    if (!$ok) throw new RuntimeException($description);
    echo 'PASS '.$description.PHP_EOL;
}
function reject_splash(callable $request, string $type, string $description): void {
    try {
        $request();
    } catch (Throwable $error) {
        if (!$error instanceof $type) throw $error;
        verify_splash(true, $description);
        return;
    }
    throw new RuntimeException($description);
}

require __DIR__.'/../api/site-settings.php';
require __DIR__.'/../api/entitlements.php';
require __DIR__.'/../api/media-core.php';
require __DIR__.'/../api/pwa-splash-code-core.php';

try {
    $file = 'storage/media/host-reference.png';
    file_put_contents(SF_ROOT.'/'.$file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aH1sAAAAASUVORK5CYII='));
    $asset = sf_media_register_existing_file($file, 'private-library-filename.png', 1);
    $admin = ['id'=>1, 'role'=>'admin'];
    sf_token_adjust(1, 11, 'admin_grant', 'test-splash-admin');
    $input = ['surface'=>'waiting', 'revision'=>0, 'brief'=>'Build a quiet, plum-colored waiting screen.',
        'design'=>['html'=>'<section class="unfinished">', 'css'=>'', 'js'=>''],
        'imageSlots'=>[['name'=>'hero', 'assetId'=>(int)$asset['id']]]];
    $snapshot = sf_pwa_splash_state();
    $proposal = sf_pwa_splash_code_propose($admin, $input);
    verify_splash(array_keys($proposal) === ['surface', 'revision', 'code'] && $proposal['revision'] === 0
        && $proposal['surface'] === 'waiting' && array_keys($proposal['code']) === ['html', 'css', 'js']
        && str_contains($proposal['code']['html'], 'Guest signal'), 'The Agent returns staged code for the requested surface and revision');
    $message = $calls[0]['message'];
    $prompt = json_decode($message, true, 12, JSON_THROW_ON_ERROR);
    verify_splash($prompt['current_code'] === $input['design'] && $prompt['image_slots'] === ['hero']
        && !str_contains($message, 'private-library-filename') && !str_contains($message, $asset['uuid'])
        && !str_contains($message, 'assetId') && !str_contains($message, 'data:image')
        && str_contains($calls[0]['system'], 'sf-splash-media'), 'The provider receives unfinished code and authorized slot names only');
    verify_splash(sf_pwa_splash_state() === $snapshot
        && !$db->query("SELECT 1 FROM sqlite_master WHERE name='agent_scene_history'")->fetchColumn(),
        'A proposal writes neither the splash draft nor Canvas History');
    $ledger = static fn(): array => $db->query("SELECT delta,provider,model,input_tokens,output_tokens FROM token_ledger
        WHERE source='pwa-splash-coding-agent' ORDER BY id")->fetchAll();
    verify_splash($ledger() === [['delta'=>0, 'provider'=>'openai', 'model'=>'stub-primary', 'input_tokens'=>42, 'output_tokens'=>18]]
        && sf_token_balance(1) === 11, 'Successful proposals use the existing zero-charge administrator ledger');

    $mode = 'repair';
    $before = count($calls);
    sf_pwa_splash_code_propose($admin, $input);
    verify_splash(count($calls) === $before + 2 && count($ledger()) === 2, 'An invalid first result gets one repair and only accepted usage is recorded');
    $mode = 'fallback';
    $before = count($calls);
    sf_pwa_splash_code_propose($admin, $input);
    verify_splash(count($calls) === $before + 2 && $calls[$before]['timeout'] === 12 && $ledger()[2]['provider'] === 'anthropic',
        'Provider fallback uses the configured alternate inside the shared timeout budget');
    $mode = 'invalid';
    reject_splash(static fn()=>sf_pwa_splash_code_propose($admin, $input), SfAgentSceneInvalidOutput::class, 'Unsafe generated markup cannot become a proposal');
    verify_splash(count($ledger()) === 3 && sf_pwa_splash_state() === $snapshot, 'Rejected output leaves prior settings and usage ledger intact');

    $mode = 'stale';
    reject_splash(static fn()=>sf_pwa_splash_code_propose($admin, $input), SfPwaSplashConflict::class, 'A concurrent draft change rejects the provider result');
    $before = count($calls);
    reject_splash(static fn()=>sf_pwa_splash_code_propose($admin, $input), SfPwaSplashConflict::class, 'An already stale revision is rejected before a provider call');
    verify_splash(count($calls) === $before && count($ledger()) === 3 && sf_pwa_splash_state()['published'] === $snapshot['published'],
        'Stale work adds no accepted usage and the live splash is untouched');
    $input['revision'] = 1;
    $mode = 'unconfigured';
    reject_splash(static fn()=>sf_pwa_splash_code_propose($admin, $input), DomainException::class, 'A disabled provider produces actionable configuration feedback');
    $mode = 'success';
    reject_splash(static fn()=>sf_pwa_splash_code_propose($admin, array_replace($input, ['imageSlots'=>[['name'=>'hero', 'assetId'=>999]]])),
        InvalidArgumentException::class, 'An unavailable image slot is rejected before generation');
    reject_splash(static fn()=>sf_pwa_splash_code_propose($admin, array_replace($input, ['design'=>['html'=>str_repeat('a', 12001)]])),
        InvalidArgumentException::class, 'Oversized editor source is rejected before generation');
    reject_splash(static fn()=>sf_pwa_splash_code_propose(['id'=>1, 'role'=>'customer'], $input), LogicException::class, 'The core rejects callers without administrator authority');
    verify_splash(count($calls) === $before, 'Rejected configuration and inputs never contact a model');
} finally {
    foreach (glob(SF_ROOT.'/storage/media/*') ?: [] as $file) unlink($file);
    rmdir(SF_ROOT.'/storage/media');
    rmdir(SF_ROOT.'/storage');
    unlink(SF_ROOT.'/assets');
    rmdir(SF_ROOT);
}
