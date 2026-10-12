<?php
declare(strict_types=1);
/** Section 42A static boundary regression. Run: php tests/section42-operations-health.php */
$root=dirname(__DIR__);
$path=$root.'/admin/operations-health.php';
$source=(string)file_get_contents($path);
$assertions=[
    'auth gate before checks'=>strpos($source,'sf_current_user()')!==false && strpos($source,"!== 'admin'")!==false,
    'explicit forbid'=>strpos($source,'http_response_code(403)')!==false,
    'no-store'=>strpos($source,'no-store')!==false,
    'noindex'=>strpos($source,'noindex')!==false,
    'read-only migration status'=>strpos($source,'sf_migration_status()')!==false,
    'no migration execution'=>strpos($source,'sf_migration_run_all(')===false,
    'no backup execution'=>strpos($source,'sf_upgrade_backup_database(')===false,
    'no mutating preflight'=>strpos($source,'sf_migration_core_preflight(')===false,
    'no raw error display'=>strpos($source,'$e->getMessage()')===false,
    'HTML escaping'=>strpos($source,'htmlspecialchars(')!==false
];
foreach($assertions as $name=>$ok) echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
if(in_array(false,$assertions,true)) exit(1);
