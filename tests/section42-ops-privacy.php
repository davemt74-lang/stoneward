<?php
declare(strict_types=1);
/** Section 42C privacy and operational-state guardrails. */
$root=dirname(__DIR__);
$upgrade=(string)file_get_contents($root.'/upgrade.php');
$health=(string)file_get_contents($root.'/admin/operations-health.php');
$checks=[
    'Upgrader never renders exception message'=>!str_contains($upgrade,'$e->getMessage()'),
    'Upgrader still records failure types'=>str_contains($upgrade,"get_class($e)"),
    'Upgrader still uses backup verifier'=>str_contains($upgrade,'sf_upgrade_verify_backup($backup)'),
    'Upgrader still enforces CSRF'=>str_contains($upgrade,'sf_upgrade_csrf_valid('),
    'Operational health checks last upgrade status'=>str_contains($health,'sf_upgrade_recent_runs(1)'),
    'Operational health never displays recorded error text'=>!str_contains($health,"['error_text']"),
    'Operational health never runs migrations'=>!str_contains($health,'sf_migration_run_all('),
    'Operational health never creates backups'=>!str_contains($health,'sf_upgrade_backup_database(')
];
foreach($checks as $name=>$ok) echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
if(in_array(false,$checks,true))exit(1);
