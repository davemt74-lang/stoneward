<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = file_get_contents($root . '/admin/assets/admin.css');
$index = file_get_contents($root . '/admin/index.php');

$assertions = 0;
$failures = [];

$check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$check($css !== false, 'admin.css must be readable');
$check($index !== false, 'admin/index.php must be readable');

if ($css !== false) {
    $check(str_contains($css, 'Admin sidebar density:'), 'sidebar density override must exist');
    $check(str_contains($css, '.sidebar nav button{min-height:26px;padding:2px 8px;font-size:.66rem;line-height:1}'), 'desktop sidebar rows must be compact');
    $check(str_contains($css, '@media (min-width:761px) and (max-height:840px)'), 'short desktop viewport rule must exist');
    $check(str_contains($css, '.sidebar nav button{min-height:23px;padding:1px 8px;font-size:.64rem;line-height:1}'), 'short viewport rows must be denser');
    $check(str_contains($css, '@media(max-width:760px)'), 'existing mobile sidebar behavior must remain');
}

if ($index !== false) {
    preg_match_all('/<button\s+type="button"\s+data-view="[^"]+"/', $index, $matches);
    $check(count($matches[0]) === 27, 'admin sidebar should expose all 27 navigation buttons');
    $check(str_contains($index, 'data-view="settings"'), 'Settings must remain in the sidebar');
    $check(str_contains($index, 'data-view="operations"'), 'Operations must remain in the sidebar');
}

if ($failures !== []) {
    fwrite(STDERR, "Admin sidebar density regression failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Admin sidebar density regression: {$assertions} assertions passed\n";
