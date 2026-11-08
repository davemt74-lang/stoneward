<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/stonefellow-canvas-app-alerts-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js'] as $directory)mkdir($fixture.'/'.$directory,0700,true);
symlink($root.'/api',$fixture.'/api');
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>':memory:'],true).';');
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
require $root.'/api/canvas-app-alert-core.php';

function alert_verify(bool $passed,string $message): void {
    if(!$passed)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}

function alert_publish(string $title,?int $previousId=null): array {
    $creator=['id'=>1,'role'=>'admin'];
    $saved=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>$title,
        'html'=>'<section><span class="sf-action-app-notifications">Alerts</span></section>','css'=>''],$previousId);
    $entry=sf_canvas_gallery_submit($creator,['scene_id'=>$saved['id'],'request_id'=>bin2hex(random_bytes(16)),
        'title'=>$title,'description'=>'A verified app version.','creator_label'=>'Fixture creator','publication_consent'=>true]);
    return [$saved['id'],sf_canvas_gallery_moderate($creator,$entry['id'],'approve',$entry['snapshot_hash'])];
}

function alert_notices(int $userId): array {
    return sf_canvas_app_alert_query("SELECT title,body,link_url FROM user_notifications WHERE user_id=? AND kind='canvas_app' ORDER BY id",[$userId])->fetchAll();
}

$db=sf_db();
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$insert=$db->prepare('INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
foreach([1,2,3,4] as $id)$insert->execute([$id,"listener$id@example.invalid","Listener $id",'fixture',$id===1?'admin':'customer','active',gmdate('c'),gmdate('c')]);
alert_verify(sf_canvas_app_alert_run()['selected']===0&&!sf_canvas_app_exists(),'Existing Jukebox cron can run the canvas worker before any canvas app is installed');

[$firstScene,$first]=alert_publish('Challenge Studio');
[$otherScene,$other]=alert_publish('Another Canvas');
$appId=$first['id'];
foreach([2,3] as $userId){
    sf_canvas_app_change($userId,'install',$first['id'],null);
    sf_canvas_app_change($userId,'install',$other['id'],null);
}
sf_canvas_app_change(2,'set_notifications',$first['id'],null,true);
alert_verify(sf_canvas_app_alert_run()['selected']===0,'Enabling an app never queues the version already published');

sf_web_push_ensure_schema();
$login=$db->prepare('INSERT INTO user_sessions(token_hash,user_id,created_at,last_seen_at,expires_at) VALUES(?,?,?,?,?)');
foreach(['phone','tablet'] as $name){
    $hash=hash('sha256',$name);
    $login->execute([$hash,2,gmdate('c'),gmdate('c'),gmdate('c',time()+3600)]);
    sf_web_push_subscribe(2,$hash,['endpoint'=>'https://fcm.googleapis.com/fcm/send/fixture-'.$name,
        'keys'=>['p256dh'=>sf_web_push_b64(sf_web_push_point(sf_web_push_generate_key())),'auth'=>sf_web_push_b64(random_bytes(16))]]);
}
[$secondScene,$second]=alert_publish('New challenge is live',$firstScene);
alert_publish('Other canvas updated',$otherScene);
sf_canvas_gallery_moderate(['id'=>1,'role'=>'admin'],$second['id'],'approve',$second['snapshot_hash']);
$calls=[];
$send=static function(array $device,array $payload,int $ttl)use(&$calls): array {
    $calls[]=['user'=>(int)$device['user_id'],'id'=>$device['id'],'payload'=>json_decode(sf_web_push_payload($payload),true),'ttl'=>$ttl];
    if(count($calls)===2)return ['status'=>'retry','retry_after'=>120];
    return ['status'=>'delivered'];
};
$result=sf_canvas_app_alert_run(100,$send);
$notice=alert_notices(2);
$url='index.php?view=canvas-gallery&id='.$second['id'].'&open=1';
alert_verify($result['selected']===1&&$result['retry']===1&&count($notice)===1&&$notice[0]['link_url']===$url&&
    $notice[0]['title']==='New challenge is live'&&!alert_notices(3),
    'The approved revision reaches only the account opted in to that app, with an exact canvas launch link');
alert_verify(count($calls)===2&&$calls[0]['user']===2&&$calls[0]['payload']===['title'=>'Stonefellow',
    'body'=>'An installed canvas has an update.','url'=>$url,'tag'=>'canvas-app:'.$second['id'],'type'=>'canvas-app']&&$calls[0]['ttl']===300,
    'Each registered device receives a typed alert with neutral lock screen text');
sf_canvas_app_alert_query("UPDATE canvas_app_alerts SET available_at=? WHERE state='pending'",[time()]);
alert_verify(sf_canvas_app_alert_run(100,$send)['done']===1&&count($calls)===3&&$calls[2]['id']===$calls[1]['id']&&
    count(alert_notices(2))===1&&sf_canvas_app_alert_run(100,$send)['selected']===0,
    'Retry sends only to the device still pending and does not duplicate the inbox');

[$thirdScene]=alert_publish('Queued before opt-out',$secondScene);
sf_canvas_app_change(2,'set_notifications',null,$appId,false);
sf_canvas_app_change(2,'set_notifications',null,$appId,true);
sf_canvas_app_alert_run(100,$send);
alert_verify(count(alert_notices(2))===1&&count($calls)===3,
    'Turning notifications back on does not restore a job queued under an earlier consent');

[$fourthScene,$fourth]=alert_publish('Queued before uninstall',$thirdScene);
sf_canvas_app_change(2,'uninstall',null,$appId);
sf_canvas_app_change(2,'install',$fourth['id'],null);
sf_canvas_app_change(2,'set_notifications',null,$appId,true);
sf_canvas_app_alert_run(100,$send);
alert_verify(count(alert_notices(2))===1&&count($calls)===3,'Reinstalling and opting in never restores an older installation job');

[$fifthScene,$fifth]=alert_publish('Expired revision',$fourthScene);
sf_canvas_app_alert_query('UPDATE canvas_app_alerts SET expires_at=? WHERE gallery_id=?',[time()-1,$fifth['id']]);
sf_canvas_app_alert_run(100,$send);
alert_verify(count(alert_notices(2))===1&&count($calls)===3,'Expired jobs do not create inbox or phone notifications');

[$sixthScene]=alert_publish('Superseded revision',$fifthScene);
[$seventhScene,$seventh]=alert_publish('Latest revision',$sixthScene);
sf_canvas_app_alert_run(100,$send);
alert_verify(count(alert_notices(2))===2&&count($calls)===5&&str_contains($calls[3]['payload']['url'],$seventh['id']),
    'When two reviewed revisions are queued, only the current publication is sent');

[$eighthScene]=alert_publish('Opted out during delivery',$seventhScene);
$optOutDuringDelivery=static function(array $device,array $payload,int $ttl)use($appId,$send): array {
    $result=$send($device,$payload,$ttl);
    sf_canvas_app_change(2,'set_notifications',null,$appId,false);
    return $result;
};
alert_verify(sf_canvas_app_alert_run(100,$optOutDuringDelivery)['obsolete']===1&&count(alert_notices(2))===3&&count($calls)===6,
    'Opting out between two phone deliveries stops the second device');

sf_canvas_app_change(2,'set_notifications',null,$appId,true);
[, $ninth]=alert_publish('Withdrawn before delivery',$eighthScene);
sf_canvas_gallery_withdraw(['id'=>1,'role'=>'admin'],$ninth['id']);
sf_canvas_app_alert_run(100,$send);
alert_verify(count(alert_notices(2))===3&&count($calls)===6,'Withdrawing a publication invalidates its queued alerts');
