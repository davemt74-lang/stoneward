<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-project-privacy-'.bin2hex(random_bytes(6));
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
$db=sf_db();$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
for($id=1;$id<=3;$id++)$q->execute([$id,"fixture$id@example.invalid","Fixture $id",password_hash('fixture-password',PASSWORD_DEFAULT),$id===1?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
$fan=['id'=>2,'role'=>'user'];$other=['id'=>3,'role'=>'user'];
$export=sf_privacy_export(2,sf_user_by_email('fixture2@example.invalid'));
verify($export['canvas_projects']===['preferences'=>['ideas_enabled'=>true,'auto_approve'=>false],'proposals'=>[]]&&$export['canvas_project_idea_requests']===[],
    'Unused canvas project and idea data exports safely with explicit default preferences');
verify(!sf_canvas_project_table_exists('canvas_projects')&&!sf_canvas_project_table_exists('canvas_project_preferences'),
    'Privacy export never initializes optional project storage');
verify($export['canvas_feedback']===[]&&!sf_canvas_feedback_table_exists(),
    'Privacy export leaves unused optional feedback storage uninitialized');
$empty=sf_privacy_clear_agent_history(2);
verify($empty['canvas_projects']===0&&$empty['canvas_project_idea_requests']===0&&!sf_canvas_project_table_exists('canvas_projects')&&
    !sf_canvas_project_table_exists('canvas_project_preferences'),
    'Unused canvas cleanup joins the privacy transaction without creating optional tables');
verify($empty['canvas_feedback']===0&&!sf_canvas_feedback_table_exists(),
    'Unused feedback cleanup does not create a table inside the privacy transaction');

sf_canvas_project_preferences_save(2,['ideas_enabled'=>false,'auto_approve'=>true]);
sf_canvas_project_preferences_save(3,['ideas_enabled'=>true,'auto_approve'=>false]);
$request=['title'=>'My private canvas project','concept'=>'Combine only my own canvas ideas.',
    'project'=>['canvas_kind'=>'custom','brief'=>'Build an abstract paper sculpture for Stonefellow.']];
$proposal=sf_canvas_project_propose($fan,$request);
$foreign=sf_canvas_project_propose($other,array_replace($request,['title'=>'Other fan project']));
$scene=sf_agent_scene_history_save(2,['canvas_kind'=>'custom','track_id'=>'','title'=>'My private canvas',
    'html'=>'<section class="private"></section>','css'=>'','design_brief'=>'Build a private composition for Stonefellow.']);
sf_canvas_feedback_set(2,['proposal_id'=>$proposal['id'],'preference'=>'like','reason'=>'My own project preference.']);
sf_canvas_feedback_set(2,['scene_id'=>$scene['id'],'preference'=>'dislike','reason'=>'My own canvas preference.']);
sf_canvas_feedback_set(3,['proposal_id'=>$foreign['id'],'preference'=>'like','reason'=>'Other fan feedback stays private.']);
sf_canvas_project_ideas_schema();
$insertIdea=static function(int $uid,string $requestId)use($db):void {
    $db->prepare('INSERT INTO canvas_project_idea_requests(user_id,request_id,fingerprint,history_epoch,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
        ->execute([$uid,$requestId,hash('sha256',$requestId),sf_agent_scene_history_epoch($uid),'pending',gmdate('c'),gmdate('c')]);
};
$ownIdea=str_repeat('1',32);$otherIdea=str_repeat('2',32);$insertIdea(2,$ownIdea);$insertIdea(3,$otherIdea);
$export=sf_privacy_export(2,sf_user_by_email('fixture2@example.invalid'));
verify($export['canvas_projects']['preferences']===['ideas_enabled'=>false,'auto_approve'=>true]&&
    array_column($export['canvas_projects']['proposals'],'id')===[$proposal['id']],
    'Account export contains only its actual project preferences and proposal records');
verify(array_column($export['canvas_project_idea_requests'],'request_id')===[$ownIdea]&&
    !str_contains(json_encode($export['canvas_projects']),'Other fan project')&&!str_contains(json_encode($export['canvas_project_idea_requests']),$otherIdea),
    'Project and idea export cannot include another fan records');
verify(!str_contains(json_encode($export['canvas_project_idea_requests']),'reservation_key')&&
    !str_contains(json_encode($export['canvas_project_idea_requests']),'fingerprint'),
    'Idea export omits internal reservation and request fingerprint fields');
$feedbackBefore=$export['canvas_feedback'];
$feedbackReasons=array_column($feedbackBefore,'reason');sort($feedbackReasons);
verify(count($feedbackBefore)===2&&$feedbackReasons===['My own canvas preference.','My own project preference.']&&
    !str_contains(json_encode($feedbackBefore),'Other fan feedback'),
    'Feedback export contains only the caller explicit canvas and project preferences');
$rejected=false;try{sf_canvas_feedback_set(2,['proposal_id'=>$foreign['id'],'preference'=>'dislike','reason'=>'Not my target.']);}
catch(InvalidArgumentException){$rejected=true;}
verify($rejected&&sf_canvas_feedback_export(2)===$feedbackBefore,
    'A listener cannot attach feedback to another account project');

$db->exec("CREATE TEMP TRIGGER fixture_reject_project_clear BEFORE INSERT ON user_privacy_events
    WHEN NEW.user_id=2 AND NEW.event_type='agent_history_cleared' BEGIN SELECT RAISE(ABORT,'fixture rejection'); END");
$failed=false;try{sf_privacy_clear_agent_history(2);}catch(PDOException){$failed=true;}
verify($failed&&sf_canvas_project_get(2,$proposal['id'])!==null&&sf_canvas_project_ideas_row(2,$ownIdea)!==null&&
    sf_canvas_project_preferences(2)===['ideas_enabled'=>false,'auto_approve'=>true],
    'A failed privacy audit record rolls back proposal and idea deletion atomically');
verify(sf_canvas_feedback_export(2)===$feedbackBefore,
    'A failed privacy audit record also restores the caller feedback atomically');
$db->exec('DROP TRIGGER fixture_reject_project_clear');
$epoch=sf_agent_scene_history_epoch(2);$reset=sf_privacy_clear_agent_history(2);
verify($reset['canvas_projects']===1&&$reset['canvas_project_idea_requests']===1&&sf_canvas_project_list(2)===[]&&sf_canvas_project_ideas_export(2)===[],
    'Clearing Agent history deletes its saved proposals and pending idea jobs');
verify(sf_canvas_project_preferences(2)===['ideas_enabled'=>false,'auto_approve'=>true]&&sf_agent_scene_history_epoch(2)>$epoch,
    'History reset preserves deliberate account preferences and advances the late-write privacy epoch');
verify(sf_canvas_project_get(3,$foreign['id'])!==null&&sf_canvas_project_ideas_row(3,$otherIdea)!==null,
    'Another fan projects and idea jobs survive history reset');
verify($reset['canvas_feedback']===2&&sf_canvas_feedback_export(2)===[]&&
    array_column(sf_canvas_feedback_export(3),'reason')===['Other fan feedback stays private.'],
    'History reset removes its canvas feedback while preserving another listener preferences');

$closingProject=sf_canvas_project_propose($fan,$request);$insertIdea(2,str_repeat('3',32));
sf_canvas_feedback_set(2,['proposal_id'=>$closingProject['id'],'preference'=>'dislike','reason'=>'Remove this on closure.']);
$closed=sf_privacy_close_account(2,sf_user_by_email('fixture2@example.invalid'),'fixture-password','DELETE');
verify($closed['closed']===true&&sf_canvas_project_export(2)['proposals']===[]&&sf_canvas_project_ideas_export(2)===[],
    'Account closure removes proposals and pending idea request data');
$q=$db->prepare('SELECT COUNT(*) FROM canvas_project_preferences WHERE user_id=?');$q->execute([2]);
verify((int)$q->fetchColumn()===0,'Account closure also removes stored canvas preferences');
verify(sf_canvas_feedback_export(2)===[]&&array_column(sf_canvas_feedback_export(3),'reason')===['Other fan feedback stays private.'],
    'Account closure removes its feedback and preserves another fan feedback');
$lateRejected=false;try{sf_canvas_feedback_set(2,['proposal_id'=>$closingProject['id'],'preference'=>'like','reason'=>'Late write.']);}
catch(InvalidArgumentException){$lateRejected=true;}
verify($lateRejected&&sf_canvas_feedback_export(2)===[],
    'A late feedback action cannot recreate preferences for a closed account');
verify(sf_canvas_project_get(3,$foreign['id'])!==null&&sf_canvas_project_ideas_row(3,$otherIdea)!==null&&
    sf_canvas_project_preferences(3)===['ideas_enabled'=>true,'auto_approve'=>false],
    'Closing an account preserves every other fan project, idea job and preference');
echo "Canvas project privacy: $checks checks passed\n";
