<?php
declare(strict_types=1);

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("PRAGMA foreign_keys=ON; CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT NOT NULL);
    INSERT INTO users VALUES(1,'active'),(2,'active');
    CREATE TABLE user_privacy_events(id INTEGER PRIMARY KEY,user_id INTEGER,event_type TEXT);
    CREATE TABLE agent_scene_history(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,
        track_id TEXT NOT NULL,title TEXT NOT NULL,html TEXT NOT NULL,css TEXT NOT NULL,previous_id INTEGER NULL,created_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
    INSERT INTO agent_scene_history(user_id,track_id,title,html,css,created_at) VALUES(1,'legacy','Legacy','<p>Legacy</p>','','fixture')");
function sf_db(): PDO {return $GLOBALS['db'];}
function sf_db_config(): array {return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $value,int $max=120): string {return substr(trim((string)$value),0,$max);}
require_once __DIR__.'/../api/agent-scene-core.php';
function verify(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
function rejects(callable $run,string $message): void {
    try{$run();}catch(InvalidArgumentException|LogicException|UnexpectedValueException){verify(true,$message);return;}
    throw new RuntimeException('Expected rejection. '.$message);
}

$base=['html'=>'<section class="art"><div class="screen"></div></section>','css'=>'.art{color:#fff}'];
verify(sf_agent_scene_code($base)===$base,'Existing HTML and CSS canvases retain their code shape');
verify(sf_agent_scene_code($base+['js'=>" \n\t"])===$base,'Optional blank JavaScript adds no executable scene fields');
foreach([null,false,[],str_repeat('x',12001),str_repeat('音',4001),"let x=1;\0", "let x=1;\x7f", "\xff"] as $invalid)
    verify(sf_agent_scene_code($base+['js'=>$invalid])===null,'Invalid, oversized or control-containing JavaScript is rejected');
$js="const tracks = await StonefellowAudio.catalog();\r\nconst label = '音楽';\rconsole.log(tracks.length, label);";
$canonical=str_replace(["\r\n","\r"],"\n",$js);
$hash=base64_encode(hash('sha256',$canonical,true));
$code=sf_agent_scene_code($base+['js'=>$js,'js_hash'=>'untrusted-model-hash']);
verify($code['js']===$canonical&&$code['js_hash']===$hash&&!str_contains($code['js'],"\r"),
    'JavaScript line endings are canonical before its exact UTF-8 CSP hash is derived');
$tagSource='const opening = "<script>"; const closing = "</ScRiPt >"; const escapedState = "<!--<script>";';
$tagCode=sf_agent_scene_code($base+['js'=>$tagSource]);
verify($tagCode['js']===$tagSource&&$tagCode['js_hash']===base64_encode(hash('sha256',$tagSource,true)),
    'Literal script and HTML-comment tag strings retain their exact source bytes and hash');
verify(sf_agent_scene_code($base+['js'=>str_repeat(' ',11999).';'])!==null,
    'The 12000-byte JavaScript boundary is inclusive');
$context=['canvas_kind'=>'custom','design_brief'=>'A musical instrument','canvas_title'=>'Custom Canvas','track_id'=>'',
    'title'=>'','release'=>'','story'=>null,'lyric_section'=>null,'catalog_refs'=>[],'trusted_canvas_markup'=>true];
$rendered=sf_agent_scene_render(json_encode($base+['js'=>$js,'js_hash'=>'forged'],JSON_THROW_ON_ERROR),$context);
verify($rendered['js']===$canonical&&$rendered['js_hash']===$hash&&!isset($rendered['player_controls']),
    'Normal custom rendering retains authored JavaScript with a server-derived hash and no forced controls');
rejects(fn()=>sf_agent_scene_render(json_encode($base+['js'=>';']),array_replace($context,['canvas_kind'=>'listening_room'])),
    'Only custom canvases accept authored JavaScript');
$nested='const tree = '.str_repeat('{value:',20).'1'.str_repeat('}',20).';';
verify(sf_agent_scene_decode(json_encode($base+['js'=>$nested]))['js']===$nested,
    'JavaScript object nesting remains a JSON string and fits the existing JSON decoder depth');
$escaped='const value = "'.str_repeat('\\',11000).'";';
$large=$base;$large['html']='<p>'.str_repeat('x',11000).'</p>';$large['css']=str_repeat(' ',11000);$large['js']=$escaped;
$raw=json_encode($large,JSON_THROW_ON_ERROR);
verify(strlen($raw)>36000&&sf_agent_scene_decode($raw)['js']===$escaped,
    'Encoded JSON accommodates the optional third code string without shrinking decoded field limits');
$legacy=sf_agent_scene_render(json_encode(['html'=>'<section>'.SF_AGENT_SCENE_PLAYER_SLOT.SF_AGENT_SCENE_PLAYER_SLOT.'</section>','css'=>'']),$context);
verify($legacy['html']==='<section></section>'&&!isset($legacy['player_controls']),
    'Legacy empty host slots disappear without injecting replacement UI');
$db->beginTransaction();
rejects(fn()=>sf_agent_scene_history_ensure_schema(),'An additive JavaScript schema upgrade never runs inside a transaction');
$db->rollBack();sf_agent_scene_history_ensure_schema();
$old=sf_agent_scene_history_get(1,1);
verify(!isset($old['js'])&&!isset($old['js_hash'])&&$old['html']==='<p>Legacy</p>',
    'An additive history upgrade preserves legacy rows and their response shape');
$scene=$base+['js'=>$js,'js_hash'=>'forged','canvas_kind'=>'custom','track_id'=>'','title'=>'Interactive instrument'];
$saved=sf_agent_scene_history_save(1,$scene,null,0);
verify($saved['js']===$canonical&&$saved['js_hash']===$hash&&sf_agent_scene_history_get(1,$saved['id'])===$saved,
    'History saves and restores the canonical authored source and independently derives its hash');
verify(sf_agent_scene_history_get(2,$saved['id'])===null,'Authored JavaScript remains private to its canvas owner');
verify(!isset(sf_agent_scene_history_list(1)[0]['js']),'History navigation stays lightweight');
$revision=sf_agent_scene_history_save(1,array_replace($scene,['js'=>'console.log("revised");']),$saved['id'],0);
verify($revision['previous_id']===$saved['id']&&sf_agent_scene_history_get(1,$saved['id'])['js']===$canonical,
    'A revision appends its own JavaScript without modifying the earlier source');
$export=sf_agent_scene_history_export(1);
verify($export[1]['js']===$canonical&&$export[1]['js_hash']===$hash&&sf_agent_scene_history_export(2)===[],
    'Owner-scoped privacy exports contain authored JavaScript and its exact source hash');
rejects(fn()=>sf_agent_scene_history_save(1,array_replace($scene,['js'=>"const x=1;\0"])),
    'Direct history callers cannot bypass the JavaScript control-byte validation');
$tagScene=sf_agent_scene_history_save(1,array_replace($scene,['js'=>$tagSource]));
verify(sf_agent_scene_history_get(1,$tagScene['id'])['js']===$tagSource&&
    sf_agent_scene_history_get(1,$tagScene['id'])['js_hash']===$tagCode['js_hash'],
    'History round trips legitimate script tag strings with their exact-byte CSP hash');
rejects(fn()=>sf_agent_scene_history_save(1,array_replace($scene,['canvas_kind'=>'listening_room','track_id'=>'legacy'])),
    'Direct history callers cannot attach JavaScript to a standard listening room');
$legacySaved=sf_agent_scene_history_save(2,['canvas_kind'=>'custom','track_id'=>'','title'=>'Old transport',
    'html'=>'<section>'.SF_AGENT_SCENE_PLAYER_SLOT.'</section>','css'=>'']);
$display=sf_agent_scene_display($legacySaved+['player_controls'=>true]);
verify($display['html']==='<section></section>'&&!isset($display['player_controls']),
    'Restoring an old canvas removes its empty legacy transport binding and flag');
verify(sf_agent_scene_strip_legacy_player('<section><div class="transport-row">'.SF_AGENT_SCENE_PLAYER_SLOT.'</div><ul><li>Track</li></ul></section>')===
    '<section><ul><li>Track</li></ul></section>'&&
    sf_agent_scene_strip_legacy_player('<div class="panel"><p>Keep this</p>'.SF_AGENT_SCENE_PLAYER_SLOT.'</div>')===
    '<div class="panel"><p>Keep this</p></div>',
    'Retiring the transport removes its empty strip while preserving the requested track list and neighboring content');
verify(sf_agent_scene_history_clear(1)===4&&sf_agent_scene_history_export(1)===[]&&sf_agent_scene_history_get(2,$legacySaved['id'])!==null,
    'History clearing removes this owner’s executable source without affecting another canvas owner');
