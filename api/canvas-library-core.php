<?php
declare(strict_types=1);

const SF_CANVAS_LIBRARY_BUILD_INSTRUCTIONS = <<<'PROMPT'
Honor an explicit library choice when it appears in canvas_libraries. Otherwise, use native HTML, CSS, Canvas 2D or Web APIs when they fit, and choose a library only when its capabilities materially help the design. The host supplies each declared library as its listed global. If js uses any libraries, put exactly one comment on the first line: // sf-libraries: followed by one space and the exact lock values, separated by commas with no spaces. Copy each lock as provided, including its digest. Omit the comment when no library is needed. On revisions, retain locks for dependencies still used and remove unused locks. Network imports, external scripts, addons and module loaders are unavailable. A library grants no additional access to assets, personal data or the network.
PROMPT;

/** Reviewed browser bundles. A lock identifies these exact bytes, even across deployments. */
function sf_canvas_library_registry(): array {
    $cards = [
        'color-thief' => [
            'name' => 'Color Thief',
            'version' => '3.5.0',
            'global' => 'ColorThief',
            'sha256' => '9KjGmp30P1mNciedabWfLZ0pVho0/pmxF0ktvJCBY+4=',
            'bytes' => 22281,
            'when' => 'The requested experience needs colors sampled from an approved, loaded image or an origin-clean canvas.',
            'api' => 'ColorThief.getPaletteSync(image, {colorCount: 6}) returns swatches with hex() methods. Handle missing or unreadable images.',
        ],
        'three' => [
            'name' => 'Three.js',
            'version' => '0.187.0',
            'global' => 'THREE',
            'sha256' => 'x4iVrN/195Rpb8aECJMAa4ZA8cSt+X/Nx7yIkaptnQY=',
            'bytes' => 749788,
            'when' => 'The requested experience benefits from real 3D geometry, camera motion, depth, or lighting.',
            'api' => 'Use the bundled THREE core APIs. WebGLRenderer needs WebGL 2; handle an unavailable renderer gracefully. Use local geometry and approved assets, size the renderer responsively, and stop animation and dispose resources on pagehide.',
        ],
    ];
    foreach ($cards as $id => $card) {
        $cards[$id] = $card + [
            'id' => $id,
            'path' => 'assets/vendor/canvas/'.$id.'.js',
            'license' => 'MIT',
            'lock' => $id.'@'.$card['version'].'#'.$card['sha256'],
        ];
    }
    return $cards;
}

/** Return ordered pins, or null for a malformed or unreviewed declaration. */
function sf_canvas_library_declared(string $source): ?array {
    $markers = preg_match_all('/(?:\A|[\n\x{2028}\x{2029}])[ \t]*\/\/[ \t]*sf-libraries\b/iu', $source);
    if ($markers === 0) return [];
    $prefix = '// sf-libraries: ';
    $first = explode("\n", $source, 2)[0];
    if ($markers !== 1 || !str_starts_with($first, $prefix)) return null;
    $locks = explode(',', substr($first, strlen($prefix)));
    $known = array_column(sf_canvas_library_registry(), 'lock');
    if (count(array_unique($locks)) !== count($locks) || array_diff($locks, $known)) return null;
    return $locks;
}

/** Only the host can resolve paths. Return nothing for a missing or modified deployment asset. */
function sf_canvas_library_available(): array {
    require_once __DIR__.'/canvas-capabilities.php';
    try {
        $policy = sf_canvas_permission_policy();
    } catch (Throwable $error) {
        error_log('[Stonefellow canvas libraries] Permission policy unavailable.');
        return [];
    }
    $root = defined('SF_ROOT') ? SF_ROOT : dirname(__DIR__);
    $libraries = [];
    foreach (sf_canvas_library_registry() as $id => $card) {
        if (!sf_canvas_library_enabled($id, $policy)) continue;
        $path = $root.'/'.$card['path'];
        $source = is_file($path) ? @file_get_contents($path, false, null, 0, $card['bytes'] + 1) : false;
        if (!is_string($source) || strlen($source) !== $card['bytes'] ||
            !hash_equals($card['sha256'], base64_encode(hash('sha256', $source, true)))) continue;
        $libraries[] = $card;
    }
    return $libraries;
}
