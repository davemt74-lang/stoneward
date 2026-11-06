<?php
declare(strict_types=1);

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("PRAGMA foreign_keys=ON; CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT NOT NULL);
    INSERT INTO users VALUES(1,'active'),(2,'active');
    CREATE TABLE user_privacy_events(id INTEGER PRIMARY KEY,user_id INTEGER,event_type TEXT);
    CREATE TABLE agent_scene_history(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,
        track_id TEXT NOT NULL,title TEXT NOT NULL,html TEXT NOT NULL,css TEXT NOT NULL,previous_id INTEGER NULL,created_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
    INSERT INTO agent_scene_history(user_id,track_id,title,html,css,created_at) VALUES(1,'','Legacy','<p>Legacy</p>','','fixture')");
function sf_db(): PDO {return $GLOBALS['db'];}
function sf_db_config(): array {return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $value,int $max=120): string {return substr(trim((string)$value),0,$max);}
require_once __DIR__.'/../api/agent-scene-core.php';
function verify(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
function rejects(callable $run,string $message): void {
    try{$run();}catch(InvalidArgumentException){verify(true,$message);return;}
    throw new RuntimeException('Expected rejection. '.$message);
}

$palette=['accent'=>'#F0C090','secondary'=>'#DABADA','surface'=>'#142536'];
$canonical=['accent'=>'#f0c090','secondary'=>'#dabada','surface'=>'#142536'];
verify(sf_agent_scene_palette($palette)===$canonical&&sf_agent_scene_palette(null)===null,
    'An optional orb palette contains canonical color data without changing the agent shape');
foreach([false,[],['accent'=>'#fff','secondary'=>'#222222','surface'=>'#333333'],
    ['accent'=>'red','secondary'=>'#222222','surface'=>'#333333'],
    ['accent'=>'#112233;color:red','secondary'=>'#222222','surface'=>'#333333'],
    ['accent'=>'#112233','secondary'=>'#222222'], $palette+['css'=>'body{display:none}']] as $invalid)
    rejects(fn()=>sf_agent_scene_palette($invalid),'Incomplete, unsupported or CSS-bearing color data is rejected');
$base=['html'=>'<section class="art"><div class="shape"></div></section>','css'=>'.art{color:#fff}'];
$context=['canvas_kind'=>'custom','design_brief'=>'An abstract composition','canvas_title'=>'Custom Canvas','track_id'=>'',
    'title'=>'','release'=>'','story'=>null,'lyric_section'=>null,'catalog_refs'=>[],'trusted_canvas_markup'=>true];
$rendered=sf_agent_scene_render(json_encode($base+['agent_palette'=>$palette]),$context);
verify($rendered['agent_palette']===$canonical&&$rendered['html']===$base['html'],
    'The model palette is optional metadata and adds no canvas content or widget');
$inherited=$context+['agent_palette'=>$canonical];
verify(sf_agent_scene_render(json_encode($base),$inherited)['agent_palette']===$canonical,
    'Revisions preserve the existing palette when no replacement is returned');
verify(sf_agent_scene_render(json_encode($base+['agent_palette'=>['accent'=>'bad']]),$inherited)['agent_palette']===$canonical&&
    !isset(sf_agent_scene_render(json_encode($base+['agent_palette'=>'bad']),$context)['agent_palette']),
    'Invalid optional model color data neither breaks a build nor replaces a valid palette');
verify(!isset(sf_agent_scene_render(json_encode($base+['agent_palette'=>null]),$inherited)['agent_palette']),
    'A revision can clear its explicit palette and restore automatic inference');
sf_agent_scene_history_ensure_schema();
verify(!isset(sf_agent_scene_history_get(1,1)['agent_palette']),
    'The additive palette schema preserves legacy rows and their previous response shape');
$scene=$rendered+['agent_design'=>'square'];
$saved=sf_agent_scene_history_save(1,$scene,null,0);
verify(sf_agent_scene_history_get(1,$saved['id'])===$saved&&$saved['agent_design']==='square'&&$saved['agent_palette']===$canonical,
    'A saved canvas round trips its palette separately from hidden or square agent design');
verify(sf_agent_scene_history_list(1)[0]['agent_palette']===$canonical&&sf_agent_scene_history_export(1)[1]['agent_palette']===$canonical,
    'History navigation and account export retain each canvas palette');
verify(sf_agent_scene_history_get(2,$saved['id'])===null&&sf_agent_scene_history_export(2)===[],
    'Palette metadata remains within the canvas owner history boundary');
rejects(fn()=>sf_agent_scene_history_save(1,array_replace($scene,['agent_palette'=>['accent'=>'bad']])),
    'Direct history callers cannot persist unvalidated color data');
$cleared=sf_agent_scene_history_save(1,array_replace($scene,['agent_palette'=>null]),$saved['id'],0);
verify(!isset(sf_agent_scene_history_get(1,$cleared['id'])['agent_palette'])&&sf_agent_scene_history_get(1,$saved['id'])['agent_palette']===$canonical,
    'Palette clearing appends a revision without altering an earlier canvas');
