<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/stonefellow-jukebox-follow-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js'] as $directory)mkdir($fixture.'/'.$directory,0700,true);
symlink($root.'/api',$fixture.'/api');
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/test.sqlite'],true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
file_put_contents($fixture.'/data/catalog.json','[]');
file_put_contents($fixture.'/data/releases.json','[]');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require $root.'/api/migrations.php';
require $root.'/api/jukebox-follow-core.php';

function verify(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
    echo "PASS $label\n";
}

function change(string $id,callable $callback): void {
    $db=sf_db();
    $db->beginTransaction();
    try{
        sf_jukebox_query('UPDATE jukebox_sessions SET revision=revision+1 WHERE id=?',[$id]);
        $callback(sf_jukebox_session($id));
        $db->commit();
    }catch(Throwable $error){$db->rollBack();throw $error;}
}

function control(string $id,string $event,array $details=[]): void {
    change($id,static function(array $before)use($id,$event,$details):void {
        sf_jukebox_controls($before,['event'=>$event]+$details);
        sf_jukebox_alert_session_change($before,sf_jukebox_session($id),$event);
    });
}

function notices(int $user): array {
    return sf_jukebox_query("SELECT title,body,link_url FROM user_notifications WHERE user_id=? AND kind='jukebox' ORDER BY id",[$user])->fetchAll();
}

$db=sf_db();
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$insert=$db->prepare('INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
$password='fixture-password';
for($id=1;$id<=4;$id++)$insert->execute([$id,"fixture$id@example.invalid","Fixture $id",password_hash($password,PASSWORD_DEFAULT),$id===1?'admin':'customer','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
$unused=sf_privacy_close_account(4,sf_user_by_email('fixture4@example.invalid'),$password,'DELETE');
verify($unused['closed']&&!sf_migration_table_exists('jukebox_follows')&&!sf_migration_table_exists('jukebox_alerts'),
    'Account closure leaves unused Jukebox storage uninitialized');

sf_jukebox_schema();
sf_jukebox_follow_schema();
$session=str_repeat('a',32);
$canceled=str_repeat('b',32);
$schedule=time()+1800;
foreach([$session,$canceled] as $id){
    sf_jukebox_query('INSERT INTO jukebox_sessions (id,owner_id,venue_id,title,listed,created) VALUES (?,1,1,?,0,?)',
        [$id,'Private venue night',time()]);
    sf_jukebox_schedule_create($id,$schedule);
}
change($session,static fn(array $row)=>sf_jukebox_follow_set($row,2,true));
change($session,static fn(array $row)=>sf_jukebox_follow_set($row,2,true));
verify(sf_jukebox_alert_run()['selected']===0&&!notices(2),'Following schedules a reminder without sending it before it is due');
sf_jukebox_query('UPDATE jukebox_alerts SET available_at=? WHERE session_id=?',[time(),$session]);
verify(sf_jukebox_alert_run()['done']===1&&sf_jukebox_alert_run()['selected']===0,'A due reminder produces one delivery across retries');
$sent=notices(2);
verify(count($sent)===1&&str_contains($sent[0]['body'],'Private venue night')&&$sent[0]['link_url']==='jukebox.php?id='.$session,
    'In-app reminder belongs to the follower and opens the exact session');
$alertId=(int)$db->query('SELECT id FROM jukebox_alerts')->fetchColumn();
$push=sf_jukebox_alert_payload(sf_jukebox_alert_context($alertId),true);
verify($push['title']==='Stonefellow'&&!str_contains($push['body'],'Private venue night')&&!str_contains($push['body'],'UTC'),
    'Private device notifications omit session title and time');

control($session,'reschedule',['scheduled_at'=>$schedule+600]);
verify(sf_jukebox_alert_run()['done']===1&&array_column(notices(2),'title')===['Jukebox reminder','Jukebox time changed'],
    'Rescheduling sends the current change and holds the replacement reminder');
change($session,static fn(array $row)=>sf_jukebox_follow_set($row,3,true));
change($session,static fn(array $row)=>sf_jukebox_follow_set($row,3,false));
control($session,'start');
control($session,'start');
verify(sf_jukebox_alert_run()['done']===1&&array_column(notices(2),'title')===['Jukebox reminder','Jukebox time changed','Jukebox is live']&&!notices(3),
    'Actual start supersedes reminders and sends once only to current followers');

require_once $root.'/api/agent-scene-core.php';
$follower=['id'=>2,'role'=>'customer'];
$inboxHtml='<section class="sf-dataset-my_notifications"></section>';
$inbox=sf_agent_scene_history_save(2,['canvas_kind'=>'custom','title'=>'Inbox','track_id'=>'',
    'html'=>$inboxHtml,'css'=>'','artist_inputs'=>['me_tools'=>['my_notifications']]]);
$inboxContext=['scene_id'=>$inbox['id']];
$readInbox=static fn():array=>sf_agent_canvas_data_for_scene($follower,$inboxHtml,'',null,null,$inboxContext)['my_notifications'];
verify($readInbox()['state']==='consent_required'&&$readInbox()['rows']===[],
    'Canvas delivery withholds Jukebox inbox alerts before consent');
$access=sf_canvas_access_summary($follower,$inboxContext);
sf_canvas_access_change($follower,$inboxContext,'grant',$access['fingerprint'],['canvas.data.my_notifications']);
$rows=$readInbox()['rows'];
verify(array_column($rows,'title')===['Jukebox is live','Jukebox time changed','Jukebox reminder']&&
    array_column($rows,'kind')===['jukebox','jukebox','jukebox']&&
    array_keys($rows[0])===['kind','title','created_at','is_read'],
    'Consented notification source reads actual Jukebox inbox deliveries without private session details');

foreach([2,3] as $userId)change($canceled,static fn(array $row)=>sf_jukebox_follow_set($row,$userId,true));
control($canceled,'cancel');
verify(sf_jukebox_alert_run()['done']===2&&array_column(notices(3),'title')===['Jukebox canceled'],
    'Canceling supersedes the reminder and informs each follower');
verify(array_column($readInbox()['rows'],'title')===['Jukebox canceled','Jukebox is live','Jukebox time changed','Jukebox reminder'],
    'The already authorized canvas receives new Jukebox alerts when it reloads');
$closed=sf_privacy_close_account(2,sf_user_by_email('fixture2@example.invalid'),$password,'DELETE');
verify($closed['closed']&&!sf_jukebox_following($session,2)&&!sf_jukebox_following($canceled,2)
    &&!sf_jukebox_query('SELECT 1 FROM jukebox_alerts WHERE user_id=2')->fetchColumn()&&!notices(2)
    &&sf_jukebox_following($canceled,3)&&count(notices(3))===1,
    'Account closure removes only its own follows, pending and completed alerts, and notifications');
