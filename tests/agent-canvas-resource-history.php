<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-resource-history-'.bin2hex(random_bytes(6));
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
$legacy=sf_agent_scene_history_get(2,1);
verify(in_array('artist_inputs',$db->query('PRAGMA table_info(agent_scene_history)')->fetchAll(PDO::FETCH_COLUMN,1),true)&&
    !array_key_exists('artist_inputs',$legacy)&&!array_key_exists('artist_inputs',sf_agent_scene_history_export(2)[0]),
    'Additive migration leaves legacy artist selections unavailable rather than inventing resources');
$base=['canvas_kind'=>'custom','track_id'=>'','title'=>'Resource composition','html'=>'<section class="composition"></section>',
    'css'=>'.composition{color:#fff}','design_brief'=>'Build a composition using my chosen artist resources.',
    'music_inputs'=>['mode'=>'selected','track_ids'=>['song-two','song-one']]];
$artist=['merch_ids'=>['hoodie-two','hoodie-one','hoodie-two'],'ticket_ids'=>['offer-one']];
$expected=['merch_ids'=>['hoodie-two','hoodie-one'],'ticket_ids'=>['offer-one']];
$first=sf_agent_scene_history_save(2,$base+['artist_inputs'=>$artist]);
verify($first['artist_inputs']===$expected&&sf_agent_scene_history_get(2,$first['id'])['artist_inputs']===$expected,
    'Artist input IDs round-trip in chosen order with duplicates normalized independently by kind');
verify(sf_agent_scene_history_get(2,$first['id'])['music_inputs']===$base['music_inputs']&&
    sf_agent_scene_history_get(2,$first['id'])['design_brief']===$base['design_brief'],
    'Artist resources coexist with the actual prompt and independent music selection');
verify(!array_key_exists('artist_inputs',sf_agent_scene_history_list(2)[0]),
    'Lightweight history summaries omit potentially large artist resource lists');
verify(sf_agent_scene_history_get(3,$first['id'])===null&&sf_agent_scene_history_get(1,$first['id'])===null,
    'Other listeners and administrator accounts cannot read private artist selections');
rejects(fn()=>sf_agent_scene_history_save(3,$base+['artist_inputs'=>$expected],$first['id']),
    'A different account cannot use an artist-resource canvas as its revision parent');
$revision=sf_agent_scene_history_save(2,$base+['artist_inputs'=>['merch_ids'=>[]]],$first['id']);
verify(sf_agent_scene_history_get(2,$revision['id'])['artist_inputs']===['merch_ids'=>[]]&&
    !array_key_exists('ticket_ids',$revision['artist_inputs'])&&sf_agent_scene_history_get(2,$first['id'])['artist_inputs']===$expected,
    'Explicit empty per-kind selections persist without inventing an omitted kind or rewriting an earlier revision');
$clear=sf_agent_scene_history_save(2,$base+['artist_inputs'=>null],$revision['id']);
$q=$db->prepare('SELECT artist_inputs FROM agent_scene_history WHERE id=?');$q->execute([$clear['id']]);
verify(!array_key_exists('artist_inputs',$clear)&&!array_key_exists('artist_inputs',sf_agent_scene_history_get(2,$clear['id']))&&$q->fetchColumn()===null,
    'An explicit null clears the entire optional artist-input definition');
foreach([true,'merch',['unknown_ids'=>['hoodie-one']],['merch_ids'=>['../private']],['ticket_ids'=>[1]],['merch_ids'=>['valid'=>true]],['merch_ids'=>[str_repeat('x',181)]]] as $invalid)
    rejects(fn()=>sf_agent_scene_history_save(2,$base+['artist_inputs'=>$invalid]),'Malformed or authority-bearing artist input shapes cannot be persisted');
rejects(fn()=>sf_agent_scene_history_save(2,array_replace($base,['canvas_kind'=>'listening_room','track_id'=>'song-one','music_inputs'=>null,'artist_inputs'=>$expected])),
    'Artist resource inputs attach only to custom canvas code');
$largeIds=[];for($index=0;$index<500;$index++)$largeIds[]='m'.str_repeat('x',156).str_pad((string)$index,3,'0',STR_PAD_LEFT);
$large=['merch_ids'=>$largeIds,'ticket_ids'=>[]];
$largeScene=sf_agent_scene_history_save(2,$base+['artist_inputs'=>$large]);
verify(strlen(json_encode($large,JSON_THROW_ON_ERROR))>65535&&sf_agent_scene_history_get(2,$largeScene['id'])['artist_inputs']===$large,
    'Large ID inventories beyond a MySQL TEXT payload round-trip without sampling or truncation');
$export=sf_privacy_export(2,sf_user_by_email('fixture2@example.invalid'));
$byId=array_column($export['canvas_history'],null,'id');
verify($byId[$first['id']]['artist_inputs']===$expected&&$byId[$largeScene['id']]['artist_inputs']===$large,
    'Owner privacy export includes the exact stored selections for earlier and large canvas revisions');
$other=sf_agent_scene_history_save(3,$base+['artist_inputs'=>['ticket_ids'=>['other-offer']]]);
$epoch=sf_agent_scene_history_epoch(2);sf_privacy_clear_agent_history(2);
rejects(fn()=>sf_agent_scene_history_save(2,$base+['artist_inputs'=>$large],null,$epoch),
    'A late generated result cannot restore artist selections after a privacy reset');
verify(sf_agent_scene_history_export(2)===[]&&sf_agent_scene_history_get(3,$other['id'])['artist_inputs']===['ticket_ids'=>['other-offer']],
    'History clearing removes only the caller artist resources and preserves another account canvas');
echo "Canvas resource history: $checks checks passed\n";
