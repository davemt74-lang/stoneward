<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("PRAGMA foreign_keys=ON;CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT NOT NULL);INSERT INTO users VALUES(1,'active'),(2,'active'),(3,'active');
    CREATE TABLE user_privacy_events(id INTEGER PRIMARY KEY,user_id INTEGER,event_type TEXT)");
function sf_db(): PDO {return $GLOBALS['db'];}
require_once __DIR__.'/../api/canvas-feedback-core.php';
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function rejected(callable $run,string $message): void {
    try{$run();}catch(InvalidArgumentException){check(true,$message);return;}
    throw new RuntimeException('Expected rejection: '.$message);
}
check(sf_canvas_feedback_export(1)===[]&&sf_canvas_feedback_clear(1)===0&&!sf_canvas_feedback_table_exists(),
    'Unused feedback reads and privacy clearing never create optional storage');
$scene=['canvas_kind'=>'custom','track_id'=>'','title'=>'Owned design','html'=>'<section class="shape"></section>','css'=>''];
$own=sf_agent_scene_history_save(1,$scene);$foreign=sf_agent_scene_history_save(2,$scene);
$proposal=sf_canvas_project_propose(['id'=>1],['project'=>['brief'=>'A future composition'],'title'=>'Owned idea']);
$other=sf_canvas_project_propose(['id'=>2],['project'=>['brief'=>'Another account composition'],'title'=>'Private other idea']);
$liked=sf_canvas_feedback_set(1,['scene_id'=>$own['id'],'preference'=>'like','reason'=>'Keep the visual design.']);
check($liked['scene_id']===$own['id']&&$liked['proposal_id']===null&&$liked['preference']==='like'&&$liked['reason']==='Keep the visual design.',
    'An explicit Like records its exact owned canvas and reason');
$disliked=sf_canvas_feedback_set(1,['scene_id'=>$own['id'],'preference'=>'dislike','reason'=>'Rethink the interactions.']);
check(count(sf_canvas_feedback_export(1))===1&&$disliked['preference']==='dislike'&&$disliked['reason']==='Rethink the interactions.',
    'A changed explicit preference updates the same target without accumulating contradictory ratings');
foreach([
    ['scene_id'=>$foreign['id'],'preference'=>'like'],['proposal_id'=>$other['id'],'preference'=>'dislike'],
    ['scene_id'=>$own['id'],'proposal_id'=>$proposal['id'],'preference'=>'like'],['preference'=>'like'],
    ['scene_id'=>(string)$own['id'],'preference'=>'like'],['scene_id'=>$own['id'],'preference'=>'canceled'],
    ['scene_id'=>$own['id'],'preference'=>'like','reason'=>str_repeat('x',801)],
    ['scene_id'=>$own['id'],'preference'=>'like','reason'=>"bad\0text"],
] as $request)rejected(fn()=>sf_canvas_feedback_set(1,$request),'Foreign, ambiguous or invalid feedback is rejected');
$long=str_repeat('é',400);
check(sf_canvas_feedback_set(1,['scene_id'=>$own['id'],'preference'=>'like','reason'=>$long])['reason']===$long,
    'The exact 800-byte UTF-8 reason boundary is retained');
sf_canvas_project_cancel(1,$proposal['id']);
check(count(sf_canvas_feedback_export(1))===1,'Canceling a proposal records no Like or Dislike signal');
$ideaFeedback=sf_canvas_feedback_set(1,['proposal_id'=>$proposal['id'],'preference'=>'like','reason'=>'I like the concept even though I canceled it.']);
check($ideaFeedback['proposal_id']===$proposal['id']&&$ideaFeedback['scene_id']===null,
    'An explicit preference is independent of the proposal cancellation state');
sf_canvas_feedback_set(2,['scene_id'=>$foreign['id'],'preference'=>'dislike','reason'=>'Foreign preference']);
$context=sf_canvas_feedback_context(1);
check(count($context)===2&&in_array('Owned idea',array_column($context,'title'),true)&&!str_contains(json_encode($context),'Foreign preference'),
    'Creative feedback context contains only current owned targets and bounded explicit reasons');
check(sf_canvas_feedback_set(1,['scene_id'=>$own['id'],'preference'=>'clear'])===null&&count(sf_canvas_feedback_export(1))===1,
    'Clear removes a preference rather than recording a negative signal');
$db->beginTransaction();sf_canvas_feedback_clear(1);$db->rollBack();
check(count(sf_canvas_feedback_export(1))===1,'Feedback clearing joins the existing privacy transaction and rolls back with it');
check(sf_canvas_feedback_clear(1)===1&&count(sf_canvas_feedback_export(2))===1,
    'Clearing one listener preferences preserves another account feedback');
$db->exec("UPDATE users SET status='closed' WHERE id=1");
rejected(fn()=>sf_canvas_feedback_set(1,['proposal_id'=>$proposal['id'],'preference'=>'like']),
    'A late action cannot recreate feedback after account closure');
