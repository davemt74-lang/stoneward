<?php
declare(strict_types=1);
if (!defined('SF_ROOT')) define('SF_ROOT', dirname(__DIR__));
require_once SF_ROOT . '/api/bootstrap.php';
require_once SF_ROOT . '/api/migrations.php';
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
$user = sf_current_user();
if (!$user || ($user['role'] ?? '') !== 'admin' || ($user['status'] ?? 'active') !== 'active') {
    http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Administrator access required.');
}
function sf_ops_health_checks(): array {
    $checks = [];
    $add = static function(string $label, bool $ok, string $remedy = '') use (&$checks): void {
        $checks[] = ['label'=>$label, 'ok'=>$ok, 'remedy'=>$ok?'':$remedy];
    };
    $add('PHP 8.1 or newer', PHP_VERSION_ID >= 80100, 'Upgrade the PHP runtime.');
    $add('PDO extension', extension_loaded('pdo'), 'Enable PDO.');
    $add('Secure random generator', function_exists('random_bytes'), 'Enable secure random support.');
    $add('Storage permissions', is_dir(SF_ROOT.'/storage') && is_writable(SF_ROOT.'/storage'), 'Review storage directory permissions.');
    $add('Backup directory', is_dir(SF_ROOT.'/storage/backups') && is_writable(SF_ROOT.'/storage/backups'), 'Check backup directory permissions using the database upgrade preflight.');
    try {
        if (!sf_installed()) $add('Database configuration', false, 'Complete installation.');
        else {
            $driver = (string)(sf_db_config()['driver'] ?? '');
            $add('Database driver', in_array($driver, ['sqlite','mysql'], true), 'Configure a supported database.');
            $add('PDO database driver', ($driver === 'sqlite' && extension_loaded('pdo_sqlite')) || ($driver === 'mysql' && extension_loaded('pdo_mysql')), 'Enable the configured PDO driver.');
            sf_db()->query('SELECT 1');
            $add('Database connection', true);
            $status = sf_migration_status();
            $add('Migration checksums', (int)($status['drift'] ?? 1) === 0, 'Investigate migration drift before upgrading.');
            $add('Migrations current', (int)($status['pending'] ?? 1) === 0, 'Run the administrator-approved database upgrade.');
            $recent = sf_upgrade_recent_runs(1);
            if ($recent) {
                $last = (string)($recent[0]['status'] ?? '');
                $add('Last recorded upgrade outcome', $last === 'completed', 'Review the latest database upgrade attempt in upgrade.php; an interrupted or failed upgrade needs administrator review.');
            }
        }
    } catch (Throwable $e) {
        $add('Database diagnostics', false, 'Review protected server logs and database configuration.');
    }
    return $checks;
}
function sf_ops_health_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
$checks = sf_ops_health_checks();
$attention = count(array_filter($checks, static fn(array $c): bool => !$c['ok']));
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stonefellow — Operational Health</title><style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#0b0a09;color:#eee7de;font:15px/1.5 system-ui,sans-serif}main{width:min(850px,calc(100% - 32px));margin:40px auto 80px}h1{font:normal clamp(2rem,5vw,3.2rem)/1.1 Georgia,serif}a{color:#ddbf86}nav{display:flex;gap:20px;flex-wrap:wrap}section{background:#17130f;border:1px solid #443a30;margin:12px 0;padding:16px}.pass{color:#a6d5b0}.warn{color:#efb78c}p{color:#c9bcb0}*:focus-visible{outline:3px solid #ddbf86;outline-offset:3px}</style></head><body><main><nav><a href="./">Admin</a><a href="../upgrade.php">Database upgrade</a></nav><h1>Operational Health</h1><p>Read-only diagnostics. Credentials, file paths, SQL errors, and private records are not disclosed.</p><p role="status"><?=count($checks)?> checks; <?=$attention?> require attention</p><?php foreach($checks as $check): ?><section><strong><?=sf_ops_health_h($check['label'])?></strong><div class="<?=$check['ok']?'pass':'warn'?>"><?=$check['ok']?'PASS':'ATTENTION'?></div><?php if($check['remedy']!==''): ?><p><?=sf_ops_health_h($check['remedy'])?></p><?php endif; ?></section><?php endforeach; ?><p>No repairs, migrations, or backups were executed.</p></main></body></html>
