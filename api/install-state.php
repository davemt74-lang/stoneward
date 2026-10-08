<?php
declare(strict_types=1);

if (!defined('SF_ROOT')) {
    define('SF_ROOT', dirname(__DIR__));
}

const SF_DB_CONFIG = SF_ROOT . '/config/database.php';
const SF_INSTALL_LOCK = SF_ROOT . '/storage/install.lock';
const SF_DEFAULT_SQLITE_DB = SF_ROOT . '/storage/database/stonefellow.sqlite';

function sf_recover_database_config(): bool {
    if (is_file(SF_DB_CONFIG)) return true;
    // The one-click installer has always used this exact SQLite path. If the generated
    // config was lost during an overwrite but the database remains, recover safely.
    if (!is_file(SF_DEFAULT_SQLITE_DB)) return false;
    $dir = dirname(SF_DB_CONFIG);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return false;
    $cfg = ['driver' => 'sqlite', 'path' => SF_DEFAULT_SQLITE_DB];
    $php = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($cfg, true) . ";\n";
    if (@file_put_contents(SF_DB_CONFIG, $php, LOCK_EX) === false) return false;
    @chmod(SF_DB_CONFIG, 0640);
    return true;
}

function sf_installed(): bool {
    if (!is_file(SF_DB_CONFIG) && !sf_recover_database_config()) return false;
    if (!is_file(SF_INSTALL_LOCK)) {
        $dir = dirname(SF_INSTALL_LOCK);
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        @file_put_contents(SF_INSTALL_LOCK, "Stonefellow installed " . gmdate('c') . "\n", LOCK_EX);
    }
    return true;
}
