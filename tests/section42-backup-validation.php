<?php
declare(strict_types=1);
/** Standalone Section 42B backup verifier regression; no production database is touched. */
$dir=sys_get_temp_dir().'/sf-42-backup-'.bin2hex(random_bytes(6));
if(!mkdir($dir.'/storage/backups',0700,true))throw new RuntimeException('temp fixture creation failed');
define('SF_ROOT',$dir);
require dirname(__DIR__).'/api/migrations.php';
$passed=0;
function sf42_check(bool $condition,string $description):void{
    global $passed;
    if(!$condition){fwrite(STDERR,'FAIL '.$description.PHP_EOL);exit(1);}
    $passed++;echo 'PASS '.$description.PHP_EOL;
}
function sf42_reject(callable $f):bool{
    try{$f();return false;}catch(RuntimeException $e){return true;}
}
try{
    sf42_check(sf42_reject(fn()=>sf_upgrade_verify_backup(['file'=>'../../private.sqlite','driver'=>'sqlite'])),'path traversal rejected');
    sf42_check(sf42_reject(fn()=>sf_upgrade_verify_backup(['file'=>'storage/backups/stonefellow-pre-upgrade-20261012-125400.sqlite','driver'=>'sqlite'])),'missing backup rejected');
    $name='stonefellow-pre-upgrade-20261012-125400.sqlite';
    if(extension_loaded('pdo_sqlite')){
        $pdo=new PDO('sqlite:'.$dir.'/storage/backups/'.$name);
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY,value TEXT)');
        $pdo->exec("INSERT INTO test(value) VALUES ('verified')");
        unset($pdo);
        $ok=sf_upgrade_verify_backup(['file'=>'storage/backups/'.$name,'driver'=>'sqlite']);
        sf42_check($ok['verified']===true&&$ok['bytes']>256&&strlen($ok['sha256'])===64,'SQLite backup integrity and checksum');
        file_put_contents($dir.'/storage/backups/'.$name,'corruption');
        sf42_check(sf42_reject(fn()=>sf_upgrade_verify_backup(['file'=>'storage/backups/'.$name,'driver'=>'sqlite'])),'truncated SQLite backup rejected');
    }
    $sqlName='stonefellow-pre-upgrade-20261012-125401.sql';
    $sql=$dir.'/storage/backups/'.$sqlName;
    $header="-- Stonefellow database backup\n-- Database: test\n-- Created: 2026-10-12T12:54:01Z\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    file_put_contents($sql,$header.str_repeat("-- fixture\n",35)."SET FOREIGN_KEY_CHECKS=1;\n");
    $ok=sf_upgrade_verify_backup(['file'=>'storage/backups/'.$sqlName,'driver'=>'mysql']);
    sf42_check($ok['verified']===true&&strlen($ok['sha256'])===64,'SQL export markers and checksum');
    file_put_contents($sql,$header.str_repeat("-- fixture\n",35));
    sf42_check(sf42_reject(fn()=>sf_upgrade_verify_backup(['file'=>'storage/backups/'.$sqlName,'driver'=>'mysql'])),'truncated SQL export rejected');
    echo $passed.' checks passed'.PHP_EOL;
}finally{
    foreach(glob($dir.'/storage/backups/*')?:[] as $file)@unlink($file);
    @rmdir($dir.'/storage/backups');@rmdir($dir.'/storage');@rmdir($dir);
}
