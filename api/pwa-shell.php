<?php
declare(strict_types=1);

require_once __DIR__.'/pwa-splash-core.php';

function sf_pwa_shell_attr(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sf_pwa_shell_head(array $published, string $legacyIcon = ''): void {
    $icon = ($published['iconUrl'] ?? '') ?: ($legacyIcon ?: 'assets/icons/stonefellow-pwa-192.png');
    ?>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="<?=sf_pwa_shell_attr($icon)?>">
<?php if (!empty($published['startupUrl'])): ?>
<link rel="apple-touch-startup-image" href="<?=sf_pwa_shell_attr($published['startupUrl'])?>">
<?php endif;
}

function sf_pwa_shell_frame_policy(string $base, string $surfaceUrl): string {
    $path = parse_url($surfaceUrl, PHP_URL_PATH);
    $origin = preg_match('~^https?://(?:\[[a-f0-9:]+\]|[a-z0-9.-]+)(?::[0-9]+)?(?=/|$)~iD', $base, $matches)
        ? $matches[0] : '';
    return $origin !== '' && is_string($path) && preg_match('#^/[a-zA-Z0-9_./%~-]*pwa-splash-preview\.php$#D', $path)
        ? 'frame-src '.$origin.$path : "frame-src 'none'";
}

function sf_pwa_shell_art(array $surface, string $frameUrl): void {
    if (($surface['mode'] ?? '') === 'image' && !empty($surface['assetUrl'])): ?>
<img class="sf-pwa-splash-art" src="<?=sf_pwa_shell_attr($surface['assetUrl'])?>" alt="" loading="lazy" decoding="async">
<?php elseif ($frameUrl !== ''): ?>
<template data-pwa-splash-frame><iframe class="sf-pwa-splash-art" title="Stonefellow splash design" src="<?=sf_pwa_shell_attr($frameUrl)?>" sandbox="allow-scripts" referrerpolicy="no-referrer" aria-hidden="true" tabindex="-1" inert></iframe></template>
<?php endif;
}
