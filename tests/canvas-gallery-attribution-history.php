<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-attribution-history-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/test.sqlite'],true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
file_put_contents($fixture.'/data/catalog.json','[]');file_put_contents($fixture.'/data/releases.json','[]');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }rmdir($fixture);
});
define('SF_ROOT',$fixture);require $root.'/api/bootstrap.php';require_once $root.'/api/migrations.php';
$checks=0;
function verify(bool $condition,string $label):void {if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function rejects(callable $run,string $label):void {try{$run();}catch(InvalidArgumentException){verify(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$db=sf_db();$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
for($id=1;$id<=3;$id++)$q->execute([$id,"fixture$id@example.invalid","Fixture $id",password_hash('fixture-password',PASSWORD_DEFAULT),$id===1?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
$db->exec("CREATE TABLE agent_scene_history(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,track_id TEXT NOT NULL,
    title TEXT NOT NULL,html TEXT NOT NULL,css TEXT NOT NULL,previous_id INTEGER NULL,created_at TEXT NOT NULL);
    INSERT INTO agent_scene_history(user_id,track_id,title,html,css,created_at) VALUES(2,'legacy','Legacy','<p>Legacy</p>','','fixture')");
sf_agent_scene_history_ensure_schema();
verify(in_array('gallery_attribution',$db->query('PRAGMA table_info(agent_scene_history)')->fetchAll(PDO::FETCH_COLUMN,1),true)&&
    !array_key_exists('gallery_attribution',sf_agent_scene_history_get(2,1))&&
    !array_key_exists('gallery_attribution',sf_agent_scene_history_list(2)[0]),
    'Attribution migration leaves legacy scenes without invented creator or source metadata');
$source=['id'=>str_repeat('a',32),'title'=>'Paper architecture','creator_label'=>'Original Artist'];
$ancestor=['id'=>str_repeat('b',32),'title'=>'Folded light','creator_label'=>'Earlier Artist'];
$oldest=['id'=>str_repeat('c',32),'title'=>'First sketch','creator_label'=>'First Artist'];
$raw=['source'=>$source,'ancestors'=>[$ancestor,$oldest,$ancestor,$source]];
$normalized=['source'=>$source,'ancestors'=>[$ancestor,$oldest]];
verify(sf_agent_scene_gallery_attribution($raw)===$normalized&&sf_agent_scene_gallery_attribution(null)===null,
    'Public attribution deduplicates ordered ancestors and never repeats the direct source');
$base=['canvas_kind'=>'custom','track_id'=>'','title'=>'My gallery remix','html'=>'<section class="remix"></section>','css'=>'',
    'design_brief'=>'Rework the selected public artwork for Stonefellow.','workflow_required'=>true];
$first=sf_agent_scene_history_save(2,$base+['gallery_attribution'=>$raw]);
verify($first['gallery_attribution']===$normalized&&sf_agent_scene_history_get(2,$first['id'])['gallery_attribution']===$normalized&&
    sf_agent_scene_history_list(2)[0]['gallery_attribution']===$normalized&&sf_agent_scene_history_export(2)[1]['gallery_attribution']===$normalized,
    'Actual attribution round-trips through save, owner read, history summary and privacy export');
verify(sf_agent_scene_history_get(3,$first['id'])===null&&sf_agent_scene_history_get(1,$first['id'])===null,
    'Another listener or site administrator cannot read a private remix attribution');
$revision=sf_agent_scene_history_save(2,$base+['gallery_attribution'=>$normalized,'verification_parent_id'=>$first['id']],$first['id']);
verify($revision['workflow_required']===true&&$revision['verification_parent_id']===$first['id']&&
    sf_agent_scene_history_get(2,$revision['id'])['gallery_attribution']===$normalized,
    'Gallery provenance coexists with pinned workflow and verification-parent metadata');
$cleared=sf_agent_scene_history_save(2,$base+['gallery_attribution'=>null],$revision['id']);
verify(!array_key_exists('gallery_attribution',$cleared)&&!array_key_exists('gallery_attribution',sf_agent_scene_history_get(2,$cleared['id']))&&
    sf_agent_scene_history_get(2,$first['id'])['gallery_attribution']===$normalized,
    'Explicit null omits attribution without rewriting a prior private revision');
$invalid=[true,[],['source'=>$source],['source'=>$source,'ancestors'=>'private'],
    ['source'=>$source+['owner_id'=>3],'ancestors'=>[]],['source'=>$source,'ancestors'=>[$ancestor+['scene_id'=>9]]],
    ['source'=>array_replace($source,['id'=>str_repeat('A',32)]),'ancestors'=>[]],
    ['source'=>array_replace($source,['title'=>str_repeat('x',241)]),'ancestors'=>[]],
    ['source'=>array_replace($source,['creator_label'=>str_repeat('x',121)]),'ancestors'=>[]],
    ['source'=>array_replace($source,['creator_label'=>"Private\0Label"]),'ancestors'=>[]],
    ['source'=>$source,'ancestors'=>['not-a-list'=>$ancestor]],
    ['source'=>$source,'ancestors'=>[$ancestor,$ancestor+['user_id'=>2]]]];
foreach($invalid as $value)rejects(fn()=>sf_agent_scene_history_save(2,$base+['gallery_attribution'=>$value]),
    'Invalid or private attribution shapes cannot enter canvas history');
$many=[];for($index=1;$index<=100;$index++)$many[]=['id'=>str_pad(dechex($index),32,'0',STR_PAD_LEFT),'title'=>'Canvas '.$index,'creator_label'=>'Artist'];
$long=['source'=>$source,'ancestors'=>$many];
verify(count(sf_agent_scene_gallery_attribution($long)['ancestors'])===100,
    'Valid provenance is bounded by serialized bytes rather than a fixed ancestry count');
verify(sf_agent_scene_gallery_attribution(['source'=>array_replace($source,['title'=>"Paper\r\narchitecture"]),'ancestors'=>[]])['source']['title']==="Paper\narchitecture",
    'Attribution accepts the same ordinary title whitespace as gallery metadata');
$over=[];for($index=1;$index<=60;$index++)$over[]=['id'=>str_pad(dechex($index),32,'0',STR_PAD_LEFT),'title'=>str_repeat('x',240),'creator_label'=>str_repeat('y',120)];
rejects(fn()=>sf_agent_scene_gallery_attribution(['source'=>$source,'ancestors'=>$over]),
    'Oversized provenance is rejected before it can exceed the 16 KiB metadata bound');
$export=sf_privacy_export(2,sf_user_by_email('fixture2@example.invalid'));
$rows=array_column($export['canvas_history'],null,'id');
verify($rows[$first['id']]['gallery_attribution']===$normalized&&!str_contains(json_encode($normalized),'owner_id')&&
    !str_contains(json_encode($normalized),'scene_id'),
    'Account export retains only public source identities and creator labels in provenance');
$epoch=sf_agent_scene_history_epoch(2);sf_privacy_clear_agent_history(2);
rejects(fn()=>sf_agent_scene_history_save(2,$base+['gallery_attribution'=>$normalized],null,$epoch),
    'A late result cannot restore private gallery provenance after history clearing');
echo "Canvas gallery attribution: $checks checks passed\n";
