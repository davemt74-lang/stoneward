<?php
declare(strict_types=1);
/** Section 73 hotfix: real SQLite legacy-draft recovery and library failure isolation. */
$root=dirname(__DIR__);
$core=(string)file_get_contents($root.'/api/account-data.php');
$api=(string)file_get_contents($root.'/api/builds.php');
$migrations=(string)file_get_contents($root.'/api/migrations.php');
$checks=[
 'Saved draft has isolated auto-repair'=>str_contains($core,'function sf_account_draft_ensure_schema()'),
 'Upgrade migration 039 present'=>str_contains($migrations,"'id'=>'2026-10-13-039'"),
 'Schema target advances additively'=>str_contains($migrations,"const SF_DB_SCHEMA_TARGET = '1.3.48';"),
 'Default player modules skip entitlement DB'=>str_contains($core,'if($onlyDefaults){$modules=$defaults;}'),
 'Public errors no longer advise repeated upgrades'=>str_contains($api,'error_ref')&&!str_contains($api,'Draft storage needs a database upgrade'),
 'Paid features still require verified ownership'=>str_contains($core,'pendingPlayerModules')&&str_contains($core,'$modules=$defaults;'),
];
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    fwrite(STDERR,"FAIL: PDO SQLite driver required for legacy draft runtime verification.\n");exit(1);
}
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY); INSERT INTO users(id) VALUES(11),(12)');
$db->exec('CREATE TABLE user_saved_builds (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,title TEXT NOT NULL,build_json TEXT NOT NULL)');
$original=['title'=>'Old vinyl','format'=>'vinyl','A'=>['song-1'],'B'=>[],'customNote'=>'Keep this dedication'];
$q=$db->prepare('INSERT INTO user_saved_builds(user_id,title,build_json) VALUES(?,?,?)');
$q->execute([11,'Personal Vinyl',json_encode($original,JSON_THROW_ON_ERROR)]);
$oldId=(int)$db->lastInsertId();
function sf_db():PDO{return $GLOBALS['sqlite'];}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $value,int $max=120):string{return substr(trim((string)$value),0,$max);}
function sf_catalog():array{return [['id'=>'song-1','title'=>'Song 1']];}
function sf_turntable_module_defaults():array{return ['base'=>'base-studio','platter'=>'platter-classic','arm'=>'arm-studio','label'=>'label-classic','effect'=>'effect-none'];}
function sf_turntable_module(string $id,bool $activeOnly=true):?array{return $id==='arm-precision'?['id'=>$id,'slot'=>'arm']:null;}
function sf_turntable_valid_selection(array $raw,int $userId=0):array{$GLOBALS['ownershipQueries']++;throw new PDOException('Simulated library failure',42);}
$GLOBALS['sqlite']=$db;$GLOBALS['ownershipQueries']=0;
require $root.'/api/account-data.php';
$existing=sf_account_saved_builds(11);
$checks['Legacy draft repaired without overwriting ID, title or notes']=count($existing)===1&&$existing[0]['id']===$oldId&&$existing[0]['name']==='Personal Vinyl'&&$existing[0]['build']['customNote']==='Keep this dedication';
$checks['Saving and listing drafts never creates library schema']=(int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='user_library'")->fetchColumn()===0;
$raw=['title'=>'New custom record','format'=>'vinyl','A'=>['song-1'],'B'=>[],'playerModules'=>sf_turntable_module_defaults()];
$created=sf_account_save_build(11,$raw);
$checks['Default record saves with unrelated entitlement DB broken']=($created['name']??'')==='New custom record'&&(int)$created['id']>0;
$checks['Default record save never queried entitlement database']=$GLOBALS['ownershipQueries']===0;
$checks['Other accounts cannot list these drafts']=sf_account_saved_builds(12)===[];
$premium=$raw;$premium['playerModules']['arm']='arm-precision';
$pending=sf_account_save_build(11,$premium);
$checks['Customized draft saves despite unrelated entitlement DB error']=(int)$pending['id']>0;
$checks['Unverified premium module stays inactive and its intent is preserved']=($pending['build']['playerModules']['arm']??null)==='arm-studio'&&($pending['build']['pendingPlayerModules']['arm']??null)==='arm-precision';
$checks['Premium module validation attempted exactly once']=$GLOBALS['ownershipQueries']===1;
$renamed=sf_account_rename_build(11,$oldId,'Renamed record');
$checks['Rename preserves old track sequence and dedication']=($renamed['name']??'')==='Renamed record'&&($renamed['build']['A']??[])===['song-1']&&($renamed['build']['customNote']??'')==='Keep this dedication';
try{sf_account_rename_build(12,$oldId,'Unowned');$checks['Cannot rename another account draft']=false;}
catch(RuntimeException $e){$checks['Cannot rename another account draft']=true;}
sf_account_saved_builds_repair_schema($db,'sqlite');
$checks['Repeat migration-compatible repair preserves drafts']=(int)$db->query('SELECT COUNT(*) FROM user_saved_builds')->fetchColumn()===3;
$checks['Legacy draft still present on repeated read']=(sf_account_saved_builds(11)[2]['name']??'')==='Renamed record';
$bad=0;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$bad++;}
echo 'Section 73: '.(count($checks)-$bad).'/'.count($checks).' passed'.PHP_EOL;
exit($bad?1:0);
