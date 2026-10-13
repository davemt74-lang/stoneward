<?php
declare(strict_types=1);
// Reproduce a live installation with an old user_library lacking item_key.
if(!extension_loaded('pdo_sqlite')) {
    // The runtime still checks that MySQL and SQLite repairs are present.
    $core=file_get_contents(__DIR__.'/../api/account-data.php');
    foreach(['SHOW COLUMNS FROM','PRAGMA table_info(user_library)','ADD COLUMN','legacy-'] as $needle){
        if(!str_contains($core,$needle)){fwrite(STDERR,"FAIL library repair: $needle\n");exit(1);}
    }
    echo "SKIP SQLite runtime (pdo_sqlite unavailable); MySQL/SQLite upgrade code structurally checked\n";
    exit(0);
}
$dir=sys_get_temp_dir().'/sf-legacy-library-'.bin2hex(random_bytes(5));
mkdir($dir);$dbFile=$dir.'/stonefellow.db';
$pdo=new PDO('sqlite:'.$dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)");
$pdo->exec("INSERT INTO users(id,email) VALUES (11,'owner@example.com')");
$pdo->exec("CREATE TABLE user_library (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,legacy_title TEXT NOT NULL)");
$pdo->exec("INSERT INTO user_library(user_id,legacy_title) VALUES (11,'Existing purchase A'),(11,'Existing purchase B')");
function sf_db():PDO{global $pdo;return $pdo;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_catalog():array{return [['id'=>'the-in-between','title'=>'The In Between','podEligible'=>true,'duration'=>272]];}
function sf_clean_text(mixed $v,int $length=120):string{return substr(trim((string)$v),0,$length);}
function sf_turntable_valid_selection(array $raw,int $userId):array{return $raw;}
require __DIR__.'/../api/account-data.php';
function checked(bool $ok,string $label):void{if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
sf_account_data_ensure_schema();
$columns=array_column($pdo->query('PRAGMA table_info(user_library)')->fetchAll(),'name');
foreach(['item_key','item_type','label','source_order_id','metadata_json','acquired_at'] as $col)checked(in_array($col,$columns,true),'Legacy library added '.$col);
$rows=$pdo->query('SELECT id,user_id,item_key,legacy_title FROM user_library ORDER BY id')->fetchAll();
checked(count($rows)===2,'Existing library row count preserved');
checked($rows[0]['legacy_title']==='Existing purchase A'&&$rows[1]['legacy_title']==='Existing purchase B','Existing record details remain unchanged');
checked($rows[0]['item_key']!==' '&&$rows[0]['item_key']!==''&&$rows[0]['item_key']!==$rows[1]['item_key'],'Unique fallback keys prevent index collisions');
$build=sf_account_save_build(11,['title'=>'New Vinyl Draft','A'=>['the-in-between'],'B'=>[],'format'=>'vinyl','coverFront'=>'stonefellow','coverBack'=>'stonefellow']);
checked((int)$build['id']>0 && $build['name']==='New Vinyl Draft','Saving customer vinyl draft succeeds');
checked(count(sf_account_saved_builds(11))===1,'Saved draft reload succeeds');
checked(count(sf_account_library(11))===2,'Library query with item_key succeeds');
sf_account_data_ensure_schema();
checked(count($pdo->query('SELECT id FROM user_library')->fetchAll())===2,'Repair is idempotent');
$integrity=file_get_contents(__DIR__.'/../api/migrations.php');
checked(str_contains($integrity,'2026-10-13-038')&&str_contains($integrity,"'item_key'"),'Versioned upgrader includes legacy-library repair and integrity');
$pdo=null;unlink($dbFile);rmdir($dir);
echo "Section 71 legacy user_library repair: 14/14 checks passed\n";
