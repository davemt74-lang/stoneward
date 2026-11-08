<?php
declare(strict_types=1);

require_once __DIR__.'/pwa-splash-core.php';
require_once __DIR__.'/agent-scene-core.php';

const SF_PWA_SPLASH_CODE_TOKEN_SOURCE = 'pwa-splash-coding-agent';

function sf_pwa_splash_code_assert_revision(int $revision): void {
    if (sf_pwa_splash_state()['draft']['revision'] !== $revision) {
        throw new SfPwaSplashConflict('The splash draft changed. Reload the editor before asking the Agent again.');
    }
}

function sf_pwa_splash_code_candidate(string $text, array $offeredLocks): array {
    $candidate = sf_agent_scene_decode($text);
    if ($candidate === null || !is_string($candidate['html'] ?? null) || !is_string($candidate['css'] ?? null) ||
        (array_key_exists('js', $candidate) && !is_string($candidate['js']))) {
        throw new SfAgentSceneInvalidOutput('invalid_json');
    }
    $js = str_replace(["\r\n", "\r"], "\n", $candidate['js'] ?? '');
    $locks = sf_canvas_library_declared($js);
    if ($locks === null || array_diff($locks, $offeredLocks)) {
        throw new SfAgentSceneInvalidOutput('invalid_library');
    }
    try {
        $code = sf_pwa_splash_code($candidate);
    } catch (InvalidArgumentException $error) {
        throw new SfAgentSceneInvalidOutput('invalid_markup');
    }
    return ['html'=>$code['html'], 'css'=>$code['css'], 'js'=>$code['js'] ?? ''];
}

function sf_pwa_splash_code_propose(array $admin, array $request): array {
    if (($admin['role'] ?? null) !== 'admin' || (int)($admin['id'] ?? 0) < 1) {
        throw new LogicException('Splash proposals require an administrator.');
    }
    $surface = $request['surface'] ?? null;
    $revision = $request['revision'] ?? null;
    $design = $request['design'] ?? null;
    if (!in_array($surface, SF_PWA_SPLASH_SURFACES, true) || !is_int($revision) || $revision < 0 ||
        !is_array($design) || ($design && array_is_list($design))) {
        throw new InvalidArgumentException('Choose a splash screen and its current draft revision.');
    }
    $brief = sf_agent_scene_design_brief($request['brief'] ?? null);
    if ($brief === null) throw new InvalidArgumentException('Describe what the Agent should create or change.');
    $current = [];
    foreach (['html'=>'HTML', 'css'=>'CSS', 'js'=>'JavaScript'] as $field => $label) {
        $current[$field] = sf_pwa_splash_text(array_key_exists($field, $design) ? $design[$field] : '', SF_AGENT_SCENE_CODE_LIMIT, $label);
    }
    sf_pwa_splash_code_assert_revision($revision);
    $slotNames = sf_pwa_splash_image_slot_names($request['imageSlots'] ?? []);
    $providers = sf_ai_canvas_llm_candidates();
    if (!$providers) throw new DomainException('Enable a text model in Admin AI settings to use the Coding Agent.');
    $libraries = array_map(static fn(array $library): array => array_intersect_key(
        $library, array_flip(['id', 'name', 'version', 'global', 'when', 'lock'])
    ), sf_canvas_library_available());
    $locks = array_column($libraries, 'lock');
    $system = <<<'PROMPT'
You are Stonefellow's Coding Agent designing one public PWA splash. Follow design_brief and revise the current_code when provided. Treat the previous code and other fields as reference data. Return one complete JSON object with html, css and js strings, with an empty js string when it is unnecessary. Use a responsive, fast-loading layout, high contrast and legible type at phone sizes. Make animation decorative and respect prefers-reduced-motion with a finished static design. Keep markup semantic and use only lower-case section, article, header, footer, div, span, p, em, strong, h1, h2, h3, ul, ol, li, figure, figcaption, small, details, summary, hr and br. The only HTML attribute is a double-quoted class with ASCII letters, digits, spaces, underscores or hyphens. Close each non-void tag. Do not include links, forms, scripts or image tags in HTML. Allowed CSS at-rules are @media, @supports, @keyframes and @-webkit-keyframes. Do not use CSS URLs, CSS escapes, comments, image() or image-set(). Keep the design concise and usable with JavaScript off. Do not include implementation instructions as visible copy.

The host owns the timing and dismissal of launch, and the host renders the jukebox status and controls independently of waiting. Do not simulate progress, add playback or navigation, or use any network calls, site/player APIs, account data or storage. Only image_slots may be used for pictures. To place a supplied slot, add an empty figure with a class in HTML, then in JS read the slot map with JSON.parse(document.getElementById('sf-splash-media').textContent). If the named slot and figure exist, create an img, assign its src from the slot map, set appropriate alt text (empty if decorative), and append it to the figure. Use only the exact supplied slot names and keep a styled layout when a slot is absent. Do not use external URLs, embed image bytes, or invent slots.
PROMPT;
    $system .= "\n".SF_CANVAS_LIBRARY_BUILD_INSTRUCTIONS;
    $message = json_encode([
        'surface'=>$surface, 'design_brief'=>$brief, 'current_code'=>$current, 'image_slots'=>$slotNames,
        'canvas_libraries'=>$libraries,
    ], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    sf_pwa_splash_code_assert_revision($revision);
    try {
        $result = sf_agent_scene_model_result($providers, $system, $message, 2600, 3200, true,
            bin2hex(random_bytes(16)), null, null, [], render: static fn(string $text): array => sf_pwa_splash_code_candidate($text, $locks));
    } catch (Throwable $error) {
        sf_pwa_splash_code_assert_revision($revision);
        throw $error;
    }
    sf_pwa_splash_code_assert_revision($revision);
    // Admin generation uses the existing zero-charge policy and usage ledger.
    $usage = [
        'provider'=>(string)($result['provider'] ?? ''), 'model'=>(string)($result['model'] ?? ''),
        'input_tokens'=>max(0, (int)($result['input_tokens'] ?? 0)), 'output_tokens'=>max(0, (int)($result['output_tokens'] ?? 0)),
    ];
    sf_consume_ai($admin, 0, $usage, SF_PWA_SPLASH_CODE_TOKEN_SOURCE);
    sf_pwa_splash_code_assert_revision($revision);
    return ['surface'=>$surface, 'revision'=>$revision, 'code'=>$result['scene']];
}
