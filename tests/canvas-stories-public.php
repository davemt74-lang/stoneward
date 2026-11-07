<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/stonefellow-canvas-stories-'.bin2hex(random_bytes(6));
foreach(['config','data','storage/media'] as $directory)mkdir($fixture.'/'.$directory,0700,true);
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }
    rmdir($fixture);
});
$config=getenv('SF_TEST_STORY_DB_CONFIG');
$config=$config?json_decode($config,true,8,JSON_THROW_ON_ERROR):['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'];
file_put_contents($fixture.'/config/database.php','<?php return '.var_export($config,true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
file_put_contents($fixture.'/data/catalog.json',json_encode([
    ['id'=>'song-a','title'=>'Published recording','state'=>'published','release_id'=>'released'],
    ['id'=>'secret-track','title'=>'PRIVATE_TRACK_MARKER','state'=>'hidden'],
    ['id'=>'secret-album-track','title'=>'PRIVATE_ALBUM_TRACK_MARKER','state'=>'published','release_id'=>'draft-album'],
],JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/data/releases.json',json_encode([
    ['id'=>'released','title'=>'Published album','state'=>'published'],
    ['id'=>'draft-album','title'=>'PRIVATE_RELEASE_MARKER','state'=>'draft'],
],JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/data/shows.json',json_encode([
    ['id'=>'show-a','title'=>'Public live show','status'=>'scheduled'],
    ['id'=>'show-hidden','title'=>'PRIVATE_SHOW_MARKER','status'=>'hidden'],
],JSON_THROW_ON_ERROR));
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require_once $root.'/api/canvas-stories-core.php';

$checks=0;
function story_check(bool $value,string $message): void {
    global $checks;
    if(!$value)throw new RuntimeException($message);
    $checks++;
    echo 'PASS '.$message.PHP_EOL;
}
function story_reject(callable $run,string $message): void {
    try{$run();}
    catch(InvalidArgumentException|RuntimeException){story_check(true,$message);return;}
    throw new RuntimeException('Expected rejection: '.$message);
}

$db=sf_db();
$mysql=$config['driver']==='mysql';
$db->exec($mysql?'CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB':'CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users(id) VALUES(9)');
$idColumn=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
$options=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
// Build a pre-tags deployment schema and preserve its existing record during the upgrade.
$db->exec("CREATE TABLE story_entries(id $idColumn,slug VARCHAR(160) NOT NULL UNIQUE,entry_type VARCHAR(40) NOT NULL,title VARCHAR(255) NOT NULL,eyebrow VARCHAR(120) NOT NULL,summary TEXT NOT NULL,body_text TEXT NOT NULL,status VARCHAR(24) NOT NULL,entity_type VARCHAR(40) NOT NULL,entity_id VARCHAR(160) NOT NULL,event_date VARCHAR(40) NULL,era VARCHAR(160) NOT NULL,featured INT NOT NULL,position INT NOT NULL,seo_title VARCHAR(255) NOT NULL,seo_description VARCHAR(500) NOT NULL,created_by BIGINT NULL,published_at VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL)$options");
$now=gmdate('c');
$seed=$db->prepare('INSERT INTO story_entries(slug,entry_type,title,eyebrow,summary,body_text,status,entity_type,entity_id,event_date,era,featured,position,seo_title,seo_description,created_by,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insert=static function(string $slug,string $type,string $title,string $body,string $status='published',string $relatedType='',string $relatedId='',string $summary='',int $featured=0,int $position=0)use($seed,$db,$now):string {
    $seed->execute([$slug,$type,$title,'',$summary,$body,$status,$relatedType,$relatedId,null,'', $featured,$position,'','',9,$status==='published'?$now:null,$now,$now]);
    return (string)$db->lastInsertId();
};
$legacy=$insert('legacy-story','essay','Existing story','Original published text');
story_check(!sf_schema_column_exists('story_entries','tags_json')&&sf_story_published_ready($legacy),'Legacy published story readiness needs no schema initialization');

$child=$fixture.'/cold-story.php';
$boot=var_export($root.'/api/bootstrap.php',true);
$storyCore=var_export($root.'/api/canvas-stories-core.php',true);
$fixtureLiteral=var_export($fixture,true);
file_put_contents($child,<<<PHP_CHILD
<?php
define('SF_ROOT',$fixtureLiteral);
require $boot;
require_once $storyCore;
\$db=sf_db();
\$db->beginTransaction();
\$db->exec("UPDATE story_entries SET title='Rollback marker' WHERE id=1");
\$media=sf_story_published_ready('1');
\$rejected=false;
try{sf_canvas_story_rows(['mode'=>'selected','story_ids'=>['1']],false);}catch(PDOException){\$rejected=true;}
\$active=\$db->inTransaction();
if(\$active)\$db->rollBack();
echo json_encode(['media'=>\$media,'rejected'=>\$rejected,'active'=>\$active,'original'=>\$db->query('SELECT title FROM story_entries WHERE id=1')->fetchColumn()]);
PHP_CHILD);
$command=escapeshellarg(PHP_BINARY).(php_ini_loaded_file()?' -c '.escapeshellarg(php_ini_loaded_file()):'').' '.escapeshellarg($child);
$output=[];$status=0;exec($command,$output,$status);
$probe=json_decode(implode("\n",$output),true);
story_check($status===0&&$probe===['media'=>true,'rejected'=>true,'active'=>true,'original'=>'Existing story']&&!sf_schema_column_exists('story_entries','tags_json'),
    'A cold nested reader neither migrates a legacy schema nor commits its caller rollback');

sf_canvas_story_prepare();
$preserved=sf_story_get((int)$legacy,true);
story_check(sf_schema_column_exists('story_entries','tags_json')&&$preserved['tags']===[]&&$preserved['body_text']==='Original published text',
    'Adding tag storage preserves existing public stories and supplies an empty tag list');
$story=['entry_type'=>'track_story','title'=>'Behind a song','slug'=>'behind-a-song','entity_id'=>'song-a','status'=>'published',
    'summary'=>str_repeat('é',641),'body_text'=>"First paragraph.\n\n".str_repeat('é',4001),'tags'=>['Behind the Song',' behind--the_song ','Live Memories','all']];
$saved=sf_story_save($story,9);
$id=(string)$saved['id'];
story_check($saved['tags']===['behind-the-song','live-memories','all']&&!array_key_exists('tags_json',$saved),'Editor saves canonical deduplicated tag slugs');
$edit=$story+['id'=>$saved['id']];
unset($edit['tags']);
story_check(sf_story_save($edit,9)['tags']===$saved['tags'],'An older editor write that omits tags preserves existing tags');
story_reject(fn()=>sf_story_save(array_replace($edit,['tags'=>['!']]),9),'Unusable tag labels are rejected');
story_reject(fn()=>sf_story_save(array_replace($edit,['tags'=>[str_repeat('a',65)]]),9),'Overlong slugs are rejected');
story_reject(fn()=>sf_story_save(array_replace($edit,['tags'=>array_map(static fn(int $n):string=>'tag-'.$n,range(1,25))]),9),'Tag count is bounded explicitly');
story_check(sf_story_save($edit+['tags'=>[]],9)['tags']===[],'An explicit empty array clears tags');
sf_story_save($edit+['tags'=>$story['tags']],9);

$draft=$insert('draft-story','essay','PRIVATE_DRAFT_MARKER','Draft details','draft');
$archive=$insert('archive-story','essay','PRIVATE_ARCHIVE_MARKER','Archive details','archived');
$hidden=$insert('hidden-track-story','track_story','PRIVATE_HIDDEN_RELATION','Hidden song story','published','track','secret-track');
$hiddenAlbum=$insert('hidden-album-story','track_story','PRIVATE_ALBUM_RELATION','Hidden release track story','published','track','secret-album-track');
$hiddenShow=$insert('hidden-show-story','show_story','PRIVATE_SHOW_RELATION','Hidden show story','published','show','show-hidden');
$linkedRelease=$insert('release-story','release_story','Release notes','Release story','published','release','released');
$linkedShow=$insert('live-story','show_story','Live notes','Show story','published','show','show-a');
story_reject(fn()=>sf_story_save(array_replace($story,['slug'=>'cannot-publish','entity_id'=>'secret-album-track']),9),'Publishing a story checks the canonical linked track and its release');

$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=',true);
file_put_contents($fixture.'/storage/media/hero.png',$png);
file_put_contents($fixture.'/storage/media/private.txt','PRIVATE_ATTACHMENT_CONTENT');
$hero=sf_media_register_existing_file('storage/media/hero.png','hero.png',9);
$private=sf_media_register_existing_file('storage/media/private.txt','private-attachment.txt',9);
sf_media_attach((int)$hero['id'],'story_entry',$id,'hero',['public_visible'=>true]);
sf_media_attach((int)$private['id'],'story_entry',$id,'inline',['public_visible'=>false]);

$selection=['mode'=>'selected','story_ids'=>[$id,$linkedShow,$legacy,$draft,$archive,$hidden,$hiddenAlbum,$hiddenShow,$linkedRelease]];
$rows=sf_canvas_story_rows($selection);
story_check(array_column($rows,'story_id')===[$id,$linkedShow,$legacy,$linkedRelease],'Selected rows keep their requested order and omit draft, archived and unready relations');
$row=$rows[0];
story_check($row['track_id']==='song-a'&&$rows[1]['show_id']==='show-a'&&$rows[3]['release_id']==='released'&&$row['story_url']==='story/behind-a-song',
    'Published story and related routes use canonical identities');
story_check(preg_match_all('/./us',$row['body_text'])===4000&&$row['body_truncated']===1&&str_starts_with($row['body_text'],"First paragraph.\n\n")
    &&preg_match_all('/./us',$row['summary'])===640&&$row['summary_truncated']===1,'Narrative excerpts preserve Unicode and paragraph structure and flag truncated text');
story_check($row['image_url']==='api/media.php?id='.$hero['uuid']&&count($row['media'])===1&&$row['media'][0]['category']==='image',
    'The picker and agent use a governed public hero URL');
$encoded=json_encode($rows,JSON_THROW_ON_ERROR);
story_check(!str_contains($encoded,'PRIVATE_')&&!str_contains($encoded,'storage/media')&&!str_contains($encoded,'created_by')
    &&!str_contains($encoded,$private['uuid'])&&!str_contains($encoded,'technical_json'),'Projected story metadata omits unpublished attachments, internal data and private relations');

$db->beginTransaction();
for($i=0;$i<1002;$i++)$insert('bulk-story-'.$i,'essay','Bulk published story '.$i,'Public catalog text');
$db->commit();
$inventory=sf_canvas_story_inventory();
story_check(count($inventory)===1006&&in_array($legacy,array_column($inventory,'id'),true),'Complete public inventory exceeds the legacy 1000-story list cap');
story_check(count(sf_canvas_story_rows(['mode'=>'all'],false))===1006,'All Stories resolves every currently published ready story');
story_check(array_column(sf_canvas_story_rows(['mode'=>'tag','tag'=>'behind-the-song'],false),'story_id')===[$id]
    &&array_column(sf_canvas_story_rows(['mode'=>'tag','tag'=>'all'],false),'story_id')===[$id],'Tag scopes use exact slugs and a tag named all is distinct from All Stories');
story_check(sf_canvas_story_rows(['mode'=>'tag','tag'=>'behind'],false)===[]&&sf_canvas_story_rows(['mode'=>'selected','story_ids'=>[]],false)===[],
    'Partial tags and explicit clears do not expand into the full catalog');

if($mysql){
    $other=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    $snapshot=$db->query("SELECT status FROM story_entries WHERE id=".(int)$linkedShow)->fetchColumn();
    $other->prepare("UPDATE story_entries SET status='archived' WHERE id=?")->execute([$linkedShow]);
    $fresh=sf_canvas_story_rows(['mode'=>'selected','story_ids'=>[$linkedShow]],false);
    $active=$db->inTransaction();
    if($active)$db->rollBack();
    story_check($snapshot==='published'&&$fresh===[]&&$active,'MariaDB approval reads observe a committed archive despite an older transaction snapshot');
}
sf_story_save(array_replace($edit,['status'=>'archived']),9);
story_check(!sf_story_published_ready($id)&&sf_canvas_story_rows(['mode'=>'tag','tag'=>'behind-the-song'],false)===[]
    &&!in_array($id,array_column(sf_canvas_story_inventory(),'id'),true)&&sf_canvas_story_media((int)$id)['media']===[],
    'Archiving withdraws the story immediately from tagged inventory, complete inventory and attachment metadata');

echo 'Canvas public stories: '.$checks.' checks passed ('.$config['driver'].')'.PHP_EOL;
