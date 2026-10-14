<?php
declare(strict_types=1);
/** 75E4: disposable CI-only real MySQL legacy upgrade and customer-data-preservation drill. */
if(PHP_SAPI!=='cli'||getenv('SF_E4_EPHEMERAL')!=='yes'||getenv('GITHUB_ACTIONS')!=='true'){
 fwrite(STDERR,"Refused outside disposable CI.\n");exit(3);
}
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/sf75e4-'.bin2hex(random_bytes(5));
mkdir($tmp.'/storage/backups',0700,true);define('SF_ROOT',$tmp);
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=stonefellow_test;charset=utf8mb4',
 getenv('SF_E2_DB_USER')?:'stonefellow',getenv('SF_E2_DB_PASS')?:'stonefellow',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function sf_db():PDO{global $pdo;return $pdo;}
function sf_db_config():array{return ['driver'=>'mysql','database'=>'stonefellow_test'];}
$GLOBALS['storedMeta']=[];
function sf_ai_meta_set(string $k,string $v):void{$GLOBALS['storedMeta'][$k]=$v;}
require $root.'/api/account-data.php';require $root.'/api/migrations.php';
$passes=0;$fails=0;
function e4(bool $ok,string $label):void{global $passes,$fails;if($ok)$passes++;else $fails++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;}
function e4tables():array{return sf_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);}
function e4clean():void{
 $p=sf_db();$p->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(e4tables() as $t)$p->exec('DROP TABLE '.chr(96).str_replace(chr(96),'',(string)$t).chr(96));
 $p->exec('SET FOREIGN_KEY_CHECKS=1');
}
try{
 e4($pdo->query('SELECT DATABASE()')->fetchColumn()==='stonefellow_test'&&e4tables()===[],'Isolated fixture starts with empty CI database');
 $pdo->exec("CREATE TABLE users (id BIGINT UNSIGNED NOT NULL PRIMARY KEY,email VARCHAR(255) NOT NULL,password_hash VARCHAR(255) NOT NULL,display_name VARCHAR(160) NOT NULL,role VARCHAR(40) NOT NULL,status VARCHAR(32) NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,last_login_at VARCHAR(40) NULL) ENGINE=InnoDB");
 $pdo->prepare('INSERT INTO users (id,email,password_hash,display_name,role,status,created_at,updated_at) VALUES (7,?,?,?,?,?,?,?)')->execute(['fan@example.test',str_repeat('x',60),'Legacy Fan','admin','active','2024-01-01','2024-01-01']);
 $pdo->exec("CREATE TABLE user_saved_builds (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,title TEXT NOT NULL,data_json LONGTEXT NOT NULL,notes TEXT NULL,CONSTRAINT fk_e4_saved_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
 $json=json_encode(['format'=>'vinyl','title'=>'Legacy Vinyl','A'=>['track-1'],'customNote'=>'Keep me'],JSON_THROW_ON_ERROR);
 $pdo->prepare('INSERT INTO user_saved_builds (id,user_id,title,data_json,notes) VALUES(23,7,?,?,?)')->execute(['Legacy Vinyl',$json,'Original fan notes']);
 $pdo->exec("CREATE TABLE user_library (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,legacy_title VARCHAR(160) NOT NULL DEFAULT '',CONSTRAINT fk_e4_library_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
 $pdo->exec("INSERT INTO user_library (id,user_id,legacy_title) VALUES (31,7,'Bought Track'),(32,7,'Saved Player')");
 sf_migration_registry_ensure();
 $defs=sf_migration_definitions();$ins=$pdo->prepare('INSERT INTO schema_migrations (migration_id,app_version,description,checksum,execution_ms,applied_at) VALUES(?,?,?,?,0,?)');
 foreach($defs as $m){if($m['id']==='2026-10-13-039')continue;$ins->execute([$m['id'],$m['app_version'],$m['description'],sf_migration_checksum($m),'2026-10-12T00:00:00Z']);}
 $prior=sf_migration_status();
 e4($prior['pending']===1&&$prior['drift']===0,'Historical migration checksums matched; only legacy draft repair pending');
 e4(sf_migration_core_preflight()['ok'],'Legacy installed-users preflight passed');
 $backup=sf_upgrade_backup_database();$valid=sf_upgrade_verify_backup($backup);
 e4($valid['verified']&&$valid['driver']==='mysql','MySQL backup integrity and checksum verified');
 $path=SF_ROOT.'/'.$backup['file'];$sql=file_get_contents($path);$hash=hash_file('sha256',$path);
 e4(str_contains($sql,'Legacy Vinyl')&&str_contains($sql,'Bought Track')&&str_contains($sql,'INSERT INTO '.chr(96).'user_saved_builds'.chr(96)),'Backup contains private draft and Library rows');
 $run=sf_migration_run_all(7,$backup['file']);
 e4($run['ok']&&count(array_filter($run['results'],fn($r)=>$r['status']==='pass'))===1,'Upgrade applied only the missing migration');
 e4(sf_migration_status()['current']&&sf_migration_status()['drift']===0,'Schema registry current without drift');
 $cols=sf_migration_columns('user_saved_builds');
 e4(!array_diff(['name','format','build_json','created_at','updated_at'],$cols),'Legacy draft table received all required new columns');
 $draft=$pdo->query('SELECT * FROM user_saved_builds WHERE id=23')->fetch();
 e4($draft&&(int)$draft['user_id']===7&&$draft['name']==='Legacy Vinyl'&&$draft['format']==='vinyl'&&$draft['build_json']===$json,'Draft owner, ID, and unmodified JSON preserved');
 e4($draft['title']==='Legacy Vinyl'&&$draft['data_json']===$json&&$draft['notes']==='Original fan notes','Mandatory legacy fields and customer notes preserved');
 e4((int)$pdo->query('SELECT COUNT(*) FROM user_library')->fetchColumn()===2,'Legacy library rows preserved');
 $fields=sf_account_draft_legacy_fields($pdo,['title'=>'Another Record','format'=>'vinyl','A'=>[]],gmdate('c'),true);
 e4(($fields['title']??'')==='Another Record'&&is_string($fields['data_json']??null),'New drafts populate legacy required columns');
 $second=sf_migration_run_all(7,$backup['file']);
 e4($second['ok']&&count(array_filter($second['results'],fn($r)=>$r['status']==='pass'))===0,'Second upgrade run skips all migrations');
 e4((int)$pdo->query('SELECT COUNT(*) FROM user_saved_builds')->fetchColumn()===1&&hash_file('sha256',$path)===$hash,'Idempotent upgrade preserves drafts and original verified backup');
 e4(($GLOBALS['storedMeta']['db.schema_version']??'')===SF_DB_SCHEMA_TARGET,'Recorded schema version matches target');
 $first=$defs[0];
 $pdo->prepare("UPDATE schema_migrations SET checksum='tampered' WHERE migration_id=?")->execute([$first['id']]);
 e4(sf_migration_status()['drift']===1,'Modified applied checksum detected');
 $blocked=false;try{sf_migration_run_all(7,$backup['file']);}catch(RuntimeException $x){$blocked=str_contains($x->getMessage(),'checksum drift');}
 e4($blocked,'Historical migration drift stops upgrade safely');
 $pdo->prepare('UPDATE schema_migrations SET checksum=? WHERE migration_id=?')->execute([sf_migration_checksum($first),$first['id']]);
 e4(sf_migration_status()['drift']===0,'Registry restored after negative test');

 // Replay this known synthetic fixture dump to prove a real MySQL restore.
 // Only the CI fixture is parsed here; never use this splitter as a production importer.
 e4clean();
 $restoredStatements=preg_split('/;\s*\n/',preg_replace('/^--[^\n]*\n/m','',$sql));
 $restoredCount=0;
 foreach($restoredStatements as $sqlStatement){
   if(trim($sqlStatement)==='')continue;
   $pdo->exec($sqlStatement);$restoredCount++;
 }
 e4($restoredCount>=5,'Real MySQL restores the generated pre-upgrade SQL backup');
 $recovered=$pdo->query('SELECT id,user_id,title,data_json,notes FROM user_saved_builds WHERE id=23')->fetch();
 e4($recovered&&(int)$recovered['user_id']===7&&$recovered['data_json']===$json&&$recovered['notes']==='Original fan notes',
    'Restored database contains exact original customer data');
 e4(!in_array('name',sf_migration_columns('user_saved_builds'),true)
    &&sf_migration_status()['pending']===1,'Backup restores legacy schema and original migration history');
 $restoreRun=sf_migration_run_all(7,$backup['file']);
 e4($restoreRun['ok']&&sf_migration_status()['current']
    &&(int)$pdo->query('SELECT COUNT(*) FROM user_library')->fetchColumn()===2,
    'Restored legacy database upgrades safely with customer library preserved');

}finally{
 e4clean();foreach(glob(SF_ROOT.'/storage/backups/*')?:[] as $f)@unlink($f);
 @rmdir(SF_ROOT.'/storage/backups');@rmdir(SF_ROOT.'/storage');@rmdir(SF_ROOT);
}
echo 'Section 75E4 legacy MySQL upgrade: '.$passes.'/'.($passes+$fails).' passed'.PHP_EOL;
exit($fails?1:0);
