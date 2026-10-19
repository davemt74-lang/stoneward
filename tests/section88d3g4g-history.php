<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users VALUES(1),(2)');
$db->exec('CREATE TABLE story_assemblies(id INTEGER PRIMARY KEY,revision INTEGER)');
$db->exec('INSERT INTO story_assemblies VALUES(9,1),(10,1)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_assembly_schema():void{}
function sf_assembly_editor(int $editor):void{if($editor!==1)throw new RuntimeException('Private editor only.');}
require dirname(__DIR__).'/api/story-assembly-history-core.php';
$n=0;
function yesG4G(bool $v,string $message):void{global $n;
 if(!$v)throw new RuntimeException('FAIL '.$message);
 $n++;echo 'PASS '.$message.PHP_EOL;
}
function noG4G(callable $f,string $message):void{
 try{$f();throw new LogicException('Expected rejection');}
 catch(RuntimeException|InvalidArgumentException $e){yesG4G(true,$message);}
}
sf_assembly_history_schema();
$clip=static fn($kind,$id,$in,$out,$notes):array=>[
 'kind'=>$kind,'source_id'=>(string)$id,'in_ms'=>$in,'out_ms'=>$out,
 'duration_ms'=>$out-$in,'notes'=>$notes];
$scene=$clip('scene','42',0,2000,'Opening wide');
$dialogue=$clip('transcript','43',0,1800,'Band discussion');
$music=$clip('track','stone-music',0,2100,'Licensed song');
$db->beginTransaction();
sf_assembly_history_archive(1,9,1,[]);
sf_assembly_history_archive(1,9,2,[$scene,$dialogue,$music]);
$db->commit();
yesG4G(count(sf_assembly_history_list(1,9))===2,'Exact editor can list immutable private versions.');
yesG4G(sf_assembly_history_list(1,9)[0]['revision']===2
 &&sf_assembly_history_list(1,9)[0]['duration_ms']===5900,
 'Private history shows stable revision order, clip count and duration.');
sf_assembly_history_archive(1,9,2,[$scene,$dialogue,$music]);
yesG4G((int)$db->query('SELECT COUNT(*) FROM story_assembly_revisions')->fetchColumn()===2,
 'Identical archive is idempotent without duplicate database writes.');
noG4G(fn()=>sf_assembly_history_archive(1,9,2,[$music]),'Immutable revision cannot be silently replaced.');
noG4G(fn()=>sf_assembly_history_list(2,9),'Contributor cannot access production history.');
noG4G(fn()=>sf_assembly_history_read(1,10,2),'One assembly cannot fetch another assembly snapshot.');
$db->beginTransaction();
sf_assembly_history_archive(1,9,3,[$dialogue,$scene,$music]);
$db->rollBack();
noG4G(fn()=>sf_assembly_history_read(1,9,3),'Failed save rolls back the archived snapshot.');
$sceneShort=$clip('scene','42',100,1900,'Opening close');
$second=$clip('scene','42',0,1500,'Repeat camera');
$db->beginTransaction();
sf_assembly_history_archive(1,9,3,[$dialogue,$sceneShort,$music,$second]);
$db->commit();
$diff=sf_assembly_history_compare(1,9,2,3);
$types=array_column($diff['changes'],'type');
yesG4G($diff['from_count']===3&&$diff['to_count']===4
 &&$diff['from_duration_ms']===5900&&$diff['to_duration_ms']===5400,
 'Revision summary compares actual source-timing totals.');
yesG4G(in_array('reordered',$types,true)&&in_array('modified',$types,true)
 &&in_array('added',$types,true),'Comparison distinguishes reorder, trim/notes edit and duplicate clip addition.');
yesG4G($diff['private_only']&&!$diff['publication_allowed']&&!$diff['media_exported']&&!$diff['restored'],
 'History and comparison grant no publishing, export or one-click restore privileges.');
noG4G(fn()=>sf_assembly_history_compare(1,9,3,3),'Identical versions cannot create misleading diffs.');
noG4G(fn()=>sf_assembly_history_compare(2,9,1,3),'Unauthorized user cannot compare private revisions.');
$db->exec("UPDATE story_assembly_revisions SET items_json='[]' WHERE assembly_id=9 AND revision=2");
noG4G(fn()=>sf_assembly_history_compare(1,9,2,3),'Modified archived JSON fails cryptographic integrity verification.');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
$assembly=file_get_contents(dirname(__DIR__).'/api/story-assembly-core.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/story-assembly.js');
yesG4G(str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 &&str_contains($api,'sf_assembly_history_compare(')&&str_contains($api,"'render_edl'=>")
 &&str_contains($api,"'revision_history'=>"),
 'Authenticated API exposes historical diffs and restores current EDL visualization.');
yesG4G(str_contains($assembly,'sf_assembly_history_archive($editor,$assemblyId,$revision+1,$prepared)')
 &&str_contains($assembly,'$db->beginTransaction()'),
 'New source-verified revisions and history are archived in the same transaction.');
yesG4G(str_contains($ui,'assembly-visual-tracks')&&str_contains($ui,'historyCompare')
 &&str_contains($ui,'AI Director review history')&&str_contains($ui,'revisionHistory='),
 'Studio includes aligned private metadata lanes, change comparison and AI review history.');
echo "88D3G4G: $n isolated revision/ACL/history assertions passed".PHP_EOL;
