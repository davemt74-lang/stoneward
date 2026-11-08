<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/sf-canvas-account-data-'.bin2hex(random_bytes(6));
foreach(['config','data'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
$rawConfig=getenv('SF_TEST_ACCOUNT_DATA_DB_CONFIG');
$config=$rawConfig?json_decode($rawConfig,true,8,JSON_THROW_ON_ERROR):['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'];
file_put_contents($fixture.'/config/database.php','<?php return '.var_export($config,true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
$catalog=[
    ['id'=>'song-a','title'=>'Song A','release'=>'Published album','release_id'=>'published'],
    ['id'=>'song-b','title'=>'Song B'],
    ['id'=>'hidden','title'=>'Hidden track','state'=>'hidden'],
    ['id'=>'private-release','title'=>'Unreleased track','release_id'=>'draft'],
    ['id'=>'legacy-private-release','title'=>'Unreleased legacy track','release'=>'Draft album'],
];
$catalogPath=$fixture.'/data/catalog.json';
file_put_contents($catalogPath,json_encode($catalog,JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/data/releases.json',json_encode([
    ['id'=>'published','title'=>'Published album','state'=>'published'],
    ['id'=>'draft','title'=>'Draft album','state'=>'draft'],
],JSON_THROW_ON_ERROR));
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require_once $root.'/api/canvas-account-data-core.php';

$checks=0;
function account_data_check(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['checks']++;
    echo 'PASS '.$message.PHP_EOL;
}

$db=sf_db();$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$db->exec($mysql?'CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB':'CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users(id) VALUES(1),(2),(3)');
$tools=sf_canvas_account_data_inventory();
$expectedIds=['personal_listening_stats','my_listening_summary','my_listening_history','my_purchases','my_tickets','my_preferences','my_notifications'];
account_data_check(array_column($tools,'id')===$expectedIds,'Each registered account source has its own picker entry');
foreach($tools as $tool){
    account_data_check(array_keys($tool['fields'])===array_keys($tool['field_labels'])&&$tool['time_window']!==''&&
        $tool['permission_id']==='canvas.data.'.$tool['id']&&!array_intersect(array_keys($tool),['rows','values','endpoint','handler']),
        'Picker lists only typed field names, labels and usage metadata for '.$tool['id']);
}
$personalTables=$db->query($mysql?"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('listening_events','user_profiles','canvas_access_grants','user_notifications')":
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('listening_events','user_profiles','canvas_access_grants','user_notifications')")->fetchColumn();
account_data_check((int)$personalTables===0,'Discovery does not initialize or read personal record and grant tables');
$admin=['id'=>1,'role'=>'admin'];
sf_canvas_permission_save($admin,1,['canvas.data.my_tickets'=>false]);
account_data_check(array_column(sf_canvas_account_data_inventory(),'id')===array_values(array_diff($expectedIds,['my_tickets'])),
    'The inventory respects a disabled scope without disabling other account categories');
account_data_check(sf_canvas_account_data_ids([])===[]&&
    sf_canvas_account_data_ids(['my_preferences','my_purchases','my_preferences'])===['my_preferences','my_purchases'],
    'Account selections normalize duplicates while retaining explicit clear and selection order');
foreach([['published_catalog'],['crm_aggregate'],['MY_PREFERENCES'],['my_preferences'=>true],[42],null] as $invalid){
    try{sf_canvas_account_data_ids($invalid);throw new RuntimeException('Invalid account data source was accepted.');}
    catch(InvalidArgumentException){}
}
account_data_check(true,'Unregistered, public, admin, and malformed selections cannot become account data attachments');

sf_ops_ensure_schema();
$public=sf_canvas_account_public_tracks();
account_data_check(array_keys($public)===['song-a','song-b'],'Hidden songs and both exact and legacy draft-release links are excluded');
$now=time();
$event=$db->prepare('INSERT INTO listening_events(user_id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at) VALUES(?,?,?,?,?,?,?,?)');
foreach([[1,'song-a','start',-80,'first'],[1,'song-a','complete',-70,'first'],[1,'song-b','start',-60,'first'],
    [1,'song-a','pause',-50,'second'],[1,'song-b','progress',-40,'second'],[1,'song-a','start',-31*86400,'old'],
    [1,'song-a','start',3600,'future'],[1,'SONG-A','start',-35,'invalid-case'],[1,'hidden','start',-30,'hidden'],
    [1,'private-release','start',-29,'draft'],[1,'legacy-private-release','start',-28,'legacy-draft'],[2,'song-a','start',-25,'other']] as [$uid,$track,$type,$offset,$session]){
    $event->execute([$uid,'private-session-'.$session,$track,$type,32,180,'PRIVATE_SOURCE_SENTINEL',gmdate('c',$now+$offset)]);
}
account_data_check(sf_canvas_account_listening_summary(1,$public)===[['window_days'=>30,'starts'=>2,'completes'=>1,'sessions'=>2,'tracks'=>2,
    'listened_seconds'=>0,'measured_events'=>0,'legacy_events'=>5]],
    'Legacy overview counts only the owner’s recent qualifying public-song events and distinct sessions');
account_data_check(sf_canvas_account_listening_summary(2,$public)[0]['starts']===1&&sf_canvas_account_listening_summary(3,$public)[0]['starts']===0,
    'Overview isolates accounts and truthfully returns zeros for an empty enabled account');
$history=sf_canvas_account_listening_history(1,$public);
$historyFields=sf_canvas_account_data_tools()['my_listening_history']['canvas_data']['fields'];
account_data_check(count($history)===5&&array_column($history,'event_type')===['pause','start','complete','start','start']&&
    count($historyFields)===count($history[0])&&!array_diff(array_keys($historyFields),array_keys($history[0]))&&
    !str_contains(json_encode($history,JSON_THROW_ON_ERROR),'PRIVATE_SOURCE_SENTINEL')&&
    !str_contains(json_encode($history,JSON_THROW_ON_ERROR),'private-session'),
    'Recent history excludes progress reports, private catalog entries and raw transport metadata');
account_data_check(count(sf_canvas_account_listening_history(2,$public))===1&&sf_canvas_account_listening_history(0,$public)===[]&&
    sf_canvas_account_listening_summary(0,$public)===[]&&sf_canvas_account_preferences(0)===[],
    'History stays account-scoped and source readers return no guest rows');

sf_listening_playback_schema();
$native=$db->prepare("INSERT INTO listening_events(user_id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at,listened_seconds,evidence)
    VALUES(?,?,'song-a','progress',30,180,'native',?,?,'host_media_report')");
$native->execute([1,'private-session-first',gmdate('c',$now-2),7.75]);
$native->execute([2,'private-session-other',gmdate('c',$now-1),40]);
$overview=sf_canvas_account_listening_summary(1,$public)[0];
account_data_check($overview['listened_seconds']===7&&$overview['measured_events']===1&&$overview['legacy_events']===5&&$overview['sessions']===2,
    'Overview preserves native measurement coverage and whole-second totals separately from legacy counts');

$profile=$db->prepare('INSERT INTO user_profiles(user_id,timezone,country_code,locale,listening_history_enabled,personalization_enabled,updated_at) VALUES(?,?,?,?,?,?,?)');
$profile->execute([1,'America/Phoenix','US','en-US',0,1,gmdate('c')]);
$profile->execute([2,'Europe/Paris','FR','fr-FR',1,0,gmdate('c')]);
account_data_check(sf_canvas_account_preferences(1)===[['locale'=>'en-US','timezone'=>'America/Phoenix','country_code'=>'US',
    'listening_history_enabled'=>0,'personalization_enabled'=>1]]&&sf_canvas_account_preferences(2)[0]['locale']==='fr-FR',
    'Preferences expose only the viewer’s five declared values');
account_data_check(sf_canvas_account_preferences(3)===[['locale'=>'en-US','timezone'=>'','country_code'=>'',
    'listening_history_enabled'=>1,'personalization_enabled'=>1]],'Missing preferences use existing account defaults without inventing a location');
account_data_check(sf_canvas_account_listening_summary(1,$public)===[]&&sf_canvas_account_listening_history(1,$public)===[],
    'Opted-out accounts receive no listening rows even if older linked events remain');
$db->exec('UPDATE user_profiles SET listening_history_enabled=1 WHERE user_id=1');
for($i=0;$i<125;$i++)$event->execute([1,'bounded','song-a','skip',$i,180,'native',gmdate('c',$now)]);
$event->execute([1,'private','hidden','start',0,120,'native',gmdate('c',$now)]);
$history=sf_canvas_account_listening_history(1,$public);
account_data_check(count($history)===120&&$history[0]['position_seconds']===124&&$history[119]['position_seconds']===5,
    'History applies its 120-row limit after owner, public song and event eligibility');
$catalog[0]['state']='hidden';
file_put_contents($catalogPath,json_encode($catalog,JSON_THROW_ON_ERROR));
$public=sf_canvas_account_public_tracks();
account_data_check(array_keys($public)===['song-b']&&sf_canvas_account_listening_summary(1,$public)[0]['tracks']===1&&
    !in_array('song-a',array_column(sf_canvas_account_listening_history(1,$public),'track_id'),true),
    'A fresh catalog read removes withdrawn songs from both listening datasets');

echo 'Canvas account data: '.$checks.' checks passed ('.$config['driver'].')'.PHP_EOL;
