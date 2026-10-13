<?php
declare(strict_types=1);
/** Section 61: account ownership, lossless rename, and draft endpoint contract. */
$root=dirname(__DIR__);$account=(string)file_get_contents($root.'/api/account-data.php');$api=(string)file_get_contents($root.'/api/builds.php');
$checks=[
 'Dedicated atomic rename uses transaction'=>str_contains($account,'function sf_account_rename_build(')&&str_contains($account,'$pdo->beginTransaction()'),
 'SQL fetch is owner-scoped'=>str_contains($account,'SELECT build_json FROM user_saved_builds WHERE id=? AND user_id=?'),
 'SQL rename stays owner-scoped'=>str_contains($account,'UPDATE user_saved_builds SET name=?,build_json=?,updated_at=? WHERE id=? AND user_id=?'),
 'Full draft JSON preserved and only title changed'=>str_contains($account,"$"."build['title']=$"."name;")&&str_contains($account,'json_encode($build,JSON_THROW_ON_ERROR'),
 'Rename action uses authenticated user and CSRF-protected request'=>str_contains($api,'sf_require_user(false,$write)')&&str_contains($api,"$"."action==='rename'")&&str_contains($api,'sf_account_rename_build($uid,$id,'),
 'Rename returns refreshed listing'=>str_contains($api,"'builds'=>sf_account_saved_builds($uid)")&&str_contains($api,"'build'=>$"."row")
];
if(in_array('sqlite',PDO::getAvailableDrivers(),true)){
 function sf_db(): PDO{return $GLOBALS['testDb'];}
 function sf_db_config(): array{return ['driver'=>'sqlite'];}
 function sf_clean_text(mixed $v,int $max=120): string{return substr(trim((string)$v),0,$max);}
 function sf_catalog(): array{return [['id'=>'song-1'],['id'=>'song-2']];}
 function sf_turntable_valid_selection(array $raw,int $userId=0): array{return ['base'=>'base-studio'];}
 $GLOBALS['testDb']=new PDO('sqlite::memory:');
 $GLOBALS['testDb']->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $GLOBALS['testDb']->exec('PRAGMA foreign_keys=ON;CREATE TABLE users(id INTEGER PRIMARY KEY);INSERT INTO users VALUES(1),(2)');
 require_once $root.'/api/account-data.php';
 $original=['title'=>'First name','format'=>'vinyl','theme'=>'desert','A'=>['song-1'],'B'=>['song-2'],
   'coverFront'=>'midnight','coverBack'=>'analog','customNote'=>'Keep this dedication'];
 sf_account_data_ensure_schema();
 $db=$GLOBALS['testDb'];
 $now=gmdate('c');
 $insert=$db->prepare('INSERT INTO user_saved_builds(user_id,name,format,build_json,created_at,updated_at) VALUES(?,?,?,?,?,?)');
 $insert->execute([1,'First name','vinyl',json_encode($original,JSON_THROW_ON_ERROR),$now,$now]);
 $stored=['id'=>(int)$db->lastInsertId()];
 $renamed=sf_account_rename_build(1,(int)$stored['id'],'Renamed special edition');
 $checks['Actual rename updates name']=($renamed['name']??'')==='Renamed special edition'&&($renamed['build']['title']??'')==='Renamed special edition';
 $checks['Actual rename preserves song sequencing and personalization']=$renamed['build']['A']===['song-1']&&$renamed['build']['B']===['song-2']&&$renamed['build']['coverFront']==='midnight'&&$renamed['build']['customNote']==='Keep this dedication';
 try{sf_account_rename_build(2,(int)$stored['id'],'Unowned');$checks['Cross-user modification is blocked']=false;}
 catch(RuntimeException $e){$checks['Cross-user modification is blocked']=true;}
 try{sf_account_rename_build(1,(int)$stored['id'],'   ');$checks['Blank name rejected']=false;}
 catch(InvalidArgumentException $e){$checks['Blank name rejected']=true;}
 $checks['Owner can reload renamed draft']=(sf_account_saved_builds(1)[0]['name']??'')==='Renamed special edition';
}else{
 echo 'INFO: PDO SQLite not installed; source contracts checked, database runtime integration deferred to configured database CI.'.PHP_EOL;
}
$failed=0;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$failed++;}
echo 'Section 61 rename: '.(count($checks)-$failed).'/'.count($checks).' checks passed'.PHP_EOL;
exit($failed?1:0);
