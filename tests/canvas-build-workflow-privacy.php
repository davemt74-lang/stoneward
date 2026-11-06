<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-workflow-privacy-'.bin2hex(random_bytes(6));
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
function workflowTables(PDO $db):array {return $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'canvas_build_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);}
$db=sf_db();$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
for($id=1;$id<=3;$id++)$q->execute([$id,"fixture$id@example.invalid","Fixture $id",password_hash('fixture-password',PASSWORD_DEFAULT),$id===1?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
$export=sf_privacy_export(2,sf_user_by_email('fixture2@example.invalid'));
verify($export['canvas_build_workflows']===sf_canvas_build_workflow_export(2)&&workflowTables($db)===[],
    'Unused workflow export returns its empty owner projection without creating storage');
$empty=sf_privacy_clear_agent_history(2);
verify($empty['canvas_build_jobs']===0&&$empty['canvas_build_steps']===0&&workflowTables($db)===[],
    'Unused workflow cleanup joins the privacy transaction without creating job or step tables');

sf_canvas_build_workflow_ensure_schema();
$seed=static function(int $uid,string $requestId,string $label)use($db):array {
    $scene=sf_agent_scene_history_save($uid,['canvas_kind'=>'custom','track_id'=>'','title'=>$label.' canvas',
        'html'=>'<section class="private"></section>','css'=>'','design_brief'=>'Build a private Stonefellow composition.']);
    $proposal=sf_canvas_project_propose(['id'=>$uid,'role'=>'user'],['title'=>$label.' project','concept'=>$label.' private concept',
        'project'=>['canvas_kind'=>'custom','brief'=>'Build a private Stonefellow composition.']]);
    $steps=[str_repeat('b',32),str_repeat('c',32),str_repeat('d',32)];$now=gmdate('c');
    $db->prepare('INSERT INTO canvas_build_jobs(user_id,request_id,proposal_id,original_scene_id,candidate_scene_id,project_json,history_epoch,state,current_step_id,review_id,message,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$uid,$requestId,$proposal['id'],$scene['id'],$scene['id'],json_encode($proposal['project'],JSON_THROW_ON_ERROR),
            sf_agent_scene_history_epoch($uid),'repairing',$steps[2],$steps[1],$label.' private workflow',$now,$now]);
    $insert=$db->prepare('INSERT INTO canvas_build_steps(user_id,request_id,step_id,role,state,candidate_scene_id,result_scene_id,evidence_json,report_json,usage_json,reservation_key,active_tokens_used,active_tokens_remaining,fingerprint,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach(['coding','testing','repair'] as $index=>$role)$insert->execute([$uid,$requestId,$steps[$index],$role,$role==='repair'?'running':'completed',
        $scene['id'],$role==='coding'?$scene['id']:null,json_encode(['note'=>$label.' browser observation'],JSON_THROW_ON_ERROR),
        json_encode(['summary'=>$label.' assessment','verdict'=>'repair'],JSON_THROW_ON_ERROR),
        json_encode(['provider'=>'fixture','model'=>'fixture','input_tokens'=>2,'output_tokens'=>3],JSON_THROW_ON_ERROR),
        str_repeat('e',32),$role==='repair'?0:5,$role==='repair'?null:50,hash('sha256',$label.$role),$now,$now]);
    return ['scene'=>$scene,'proposal'=>$proposal,'request_id'=>$requestId,'steps'=>$steps];
};
// The same request and step IDs belong independently to different accounts.
$own=$seed(2,str_repeat('a',32),'Owner two');$foreign=$seed(3,str_repeat('a',32),'Other owner');
$export=sf_privacy_export(2,sf_user_by_email('fixture2@example.invalid'));
$workflows=$export['canvas_build_workflows'];
verify(count($workflows)===1&&$workflows[0]['request_id']===$own['request_id']&&count($workflows[0]['steps'])===3&&
    array_column($workflows[0]['steps'],'role')===['coding','testing','repair'],
    'Account export includes its saved build job and every ordered agent step');
$serialized=json_encode($workflows,JSON_THROW_ON_ERROR);
verify(str_contains($serialized,'Owner two')&&!str_contains($serialized,'Other owner')&&
    $workflows[0]['proposal_id']===$own['proposal']['id']&&$workflows[0]['candidate_id']===$own['scene']['id'],
    'Workflow export cannot cross accounts even when request and step IDs are identical');
verify($workflows[0]['total_active_tokens_used']===10&&$workflows[0]['active_tokens_remaining']===50,
    'Workflow export retains the owner step usage and settled token totals');
verify(!str_contains($serialized,'reservation_key')&&!str_contains($serialized,'fingerprint')&&!str_contains($serialized,str_repeat('e',32)),
    'Workflow export omits reservation keys and internal request fingerprints');
$before=sf_canvas_build_workflow_export(2);
$db->exec("CREATE TEMP TRIGGER fixture_reject_workflow_clear BEFORE INSERT ON user_privacy_events
    WHEN NEW.user_id=2 AND NEW.event_type='agent_history_cleared' BEGIN SELECT RAISE(ABORT,'fixture rejection'); END");
$failed=false;try{sf_privacy_clear_agent_history(2);}catch(PDOException){$failed=true;}
verify($failed&&sf_canvas_build_workflow_export(2)===$before&&sf_agent_scene_history_get(2,$own['scene']['id'])!==null&&
    sf_canvas_project_get(2,$own['proposal']['id'])!==null,
    'An audit failure rolls back build jobs, steps, scenes and projects as one privacy transaction');
$db->exec('DROP TRIGGER fixture_reject_workflow_clear');
$epoch=sf_agent_scene_history_epoch(2);$cleared=sf_privacy_clear_agent_history(2);
verify($cleared['canvas_build_jobs']===1&&$cleared['canvas_build_steps']===3&&sf_canvas_build_workflow_export(2)===[]&&
    sf_canvas_build_workflow_row(2,$own['request_id'])===null,
    'History reset deletes owned build jobs and steps with accurate separate purge counts');
verify(sf_agent_scene_history_epoch(2)>$epoch&&sf_canvas_build_workflow_export(3)[0]['candidate_id']===$foreign['scene']['id']&&
    count(sf_canvas_build_workflow_export(3)[0]['steps'])===3,
    'History reset advances the privacy epoch and preserves another account workflow and steps');
$closing=$seed(2,str_repeat('f',32),'Closing owner');
$closed=sf_privacy_close_account(2,sf_user_by_email('fixture2@example.invalid'),'fixture-password','DELETE');
verify($closed['closed']===true&&sf_canvas_build_workflow_export(2)===[]&&
    sf_canvas_build_workflow_row(2,$closing['request_id'])===null&&sf_canvas_build_workflow_step(2,$closing['request_id'],$closing['steps'][2])===null,
    'Account closure removes its build jobs and all agent-step data');
verify(count(sf_canvas_build_workflow_export(3))===1&&count(sf_canvas_build_workflow_export(3)[0]['steps'])===3&&
    sf_canvas_build_workflow_export(3)[0]['candidate_id']===$foreign['scene']['id'],
    'Closing an account leaves another fan workflow untouched');
echo "Canvas workflow privacy: $checks checks passed\n";
