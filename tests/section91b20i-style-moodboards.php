<?php
declare(strict_types=1);
/** Real SQLite/MySQL integration of the production board, media, approval and audit functions. */
$root=dirname(__DIR__);define('SF_ROOT',$root);
$mysql=getenv('SF_MOODBOARD_DB')==='mysql';$child=($argv[1]??'')==='--child';
if($mysql){
    $pass=(string)getenv('SF_MOODBOARD_DB_PASS');
    if($pass===''||getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('Disposable CI MySQL credentials required.');
    $dsn='mysql:host=127.0.0.1;port=3307;dbname=stonefellow_moodboard_test;charset=utf8mb4';
    $user='stonefellow';
}else{
    $path=(string)getenv('SF_MOODBOARD_SQLITE');
    if($path===''){$path=sys_get_temp_dir().'/sf-moodboard-'.bin2hex(random_bytes(8)).'.sqlite';putenv('SF_MOODBOARD_SQLITE='.$path);}
    $dsn='sqlite:'.$path;$user=null;$pass=null;
}
$pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if(!$mysql){$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA busy_timeout=8000');}
function sf_db():PDO{return $GLOBALS['pdo'];}
function sf_db_config():array{return ['driver'=>$GLOBALS['mysql']?'mysql':'sqlite'];}
function sf_clean_text(mixed $v,int $max=120):string{return substr(preg_replace('/[\x00-\x1F\x7F]/u','',trim((string)$v))??'',0,$max);}
require_once $root.'/api/media-core.php';
require_once $root.'/api/operations.php';
require_once $root.'/api/image-generation-core.php';
require_once $root.'/api/image-studio-styles.php';
if($child){
    try{sf_image_studio_moodboard_change(1,(int)$argv[2],'board_add',['asset_id'=>(int)$argv[3]]);echo "added\n";exit(0);}
    catch(InvalidArgumentException $e){echo $e->getMessage()."\n";exit(3);}
}
$count=0;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['count']++;echo 'PASS '.$label.PHP_EOL;}
function fails(callable $f,string $label):void{try{$f();}catch(Throwable $e){check(true,$label);return;}check(false,$label);}
$pdo->exec($mysql?'CREATE TABLE users(id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB':'CREATE TABLE users(id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users(id) VALUES(1)');
sf_media_ensure_schema();sf_ops_ensure_schema();sf_image_gen_schema();sf_image_studio_style_schema();
sf_image_studio_style_schema();
check(count(sf_image_studio_styles())===4,'schema initialization and built-in seeding are idempotent');
$q=$pdo->prepare('INSERT INTO media_assets(id,uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,width,height,title,caption,copyright_text,technical_json,variants_json,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
for($i=1;$i<=12;$i++)$q->execute([$i,'MTEST'.$i,'image','image/png','png','fixture-'.$i.'.png','storage/media/test-'.$i.'.png',hash('sha256','fixture-'.$i),500,1024,1024,'Image '.$i,'','','{}','{}',1,'now','now']);
$style=sf_image_studio_save_style(1,['name'=>'Test custom style','mood'=>'Cinematic','guidance'=>str_repeat('Creative visual direction. ',3),'primary_hex'=>'#112233','secondary_hex'=>'#445566','accent_hex'=>'#778899']);$id=$style['id'];
$change=static fn($action,$input=[])=>sf_image_studio_moodboard_change(1,$id,$action,$input);
$a=$change('board_add',['asset_id'=>1,'caption'=>str_repeat('x',200)]);
check(count($a)===1&&strlen($a[0]['caption'])===160,'reference insert and caption bound execute on the real database');
check((int)$pdo->query("SELECT COUNT(*) FROM admin_audit_log WHERE action='image_style_board_added'")->fetchColumn()===1,'reference and Admin audit receipt are persisted together');
fails(fn()=>$change('board_add',['asset_id'=>1]),'duplicate reference is rejected');
fails(fn()=>$change('board_add',['asset_id'=>999]),'missing asset is rejected');
fails(fn()=>sf_image_studio_moodboard_change(1,999,'board_add',['asset_id'=>2]),'unknown style is rejected');
fails(fn()=>$change('board_remove',['item_id'=>$a[0]['id'],'confirmed'=>'true']),'removal requires strict explicit confirmation');
fails(fn()=>$change('board_move',['item_id'=>$a[0]['id'],'direction'=>'sideways']),'arbitrary reorder direction is rejected');
$b=$change('board_add',['asset_id'=>2]);$b=$change('board_move',['item_id'=>$b[1]['id'],'direction'=>'up']);
check(array_column($b,'asset_id')===[2,1],'up moves return the persisted order');
$b=$change('board_move',['item_id'=>$b[0]['id'],'direction'=>'down']);
check(array_column($b,'asset_id')===[1,2],'down moves return the persisted order');
$before=(int)$pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn();
$change('board_move',['item_id'=>$b[0]['id'],'direction'=>'up']);
check((int)$pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn()===$before,'boundary reorder is a no-op, not a false change receipt');
fails(fn()=>sf_image_studio_moodboard_change(1,1,'board_remove',['item_id'=>$b[0]['id'],'confirmed'=>true]),'item from another style cannot be removed');
$change('board_remove',['item_id'=>$b[0]['id'],'confirmed'=>true]);
check(sf_media_asset(1)!==null&&sf_image_studio_moodboard_items($id)[0]['position']===1,'remove preserves the original asset and normalizes remaining positions');
fails(fn()=>sf_image_studio_moodboard_change(999,$id,'board_add',['asset_id'=>3]),'audit foreign-key failure aborts the change');
check(count(sf_image_studio_moodboard_items($id))===1,'failed audit rolls back the reference write');
for($i=3;$i<=8;$i++)$change('board_add',['asset_id'=>$i]);
check(count(sf_image_studio_moodboard_items($id))===7,'seven references leave one available slot');
// Two independent PHP connections race for the final slot; no paid provider is involved.
$children=[];
foreach([9,10] as $asset){
    $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--child',(string)$id,(string)$asset],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Could not start concurrency worker.');
    fclose($pipes[0]);$children[]=[$proc,$pipes];
}
$codes=[];
foreach($children as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$codes[]=proc_close($proc);if($err!=='')echo $err;}
sort($codes);check($codes===[0,3]&&count(sf_image_studio_moodboard_items($id))===8,'two concurrent requests cannot exceed the eight-image cap');
fails(fn()=>$change('board_add',['asset_id'=>11]),'ninth reference is rejected');
$items=sf_image_studio_moodboard_items($id);$remove=$items[0];$change('board_remove',['item_id'=>$remove['id'],'confirmed'=>true]);
// Exercise the real generated-image approval guard and revocation behavior.
$pdo->prepare("UPDATE media_assets SET technical_json=? WHERE id=11")->execute([json_encode(['source'=>'openai_image_generation','image_job_id'=>1])]);
$pdo->exec("INSERT INTO image_generation_jobs(id,request_key,created_by,prompt,model,size,quality,status,media_asset_id,usage_json,created_at,updated_at) VALUES(1,'moodboard-generated-fixture',1,'test','fixture','1024x1024','low','needs_review',11,'{}','now','now')");
fails(fn()=>$change('board_add',['asset_id'=>11]),'unapproved generated image cannot join a board');
$pdo->exec("UPDATE image_generation_jobs SET status='ready',asset_approved_by=1 WHERE id=1");
$change('board_add',['asset_id'=>11]);$pdo->exec("UPDATE image_generation_jobs SET status='needs_review' WHERE id=1");
$revoked=array_values(array_filter(sf_image_studio_moodboard_items($id),fn($x)=>$x['asset_id']===11))[0];
check($revoked['uuid']===''&&!$revoked['available'],'revoked approval removes the thumbnail and blocks its use');
$change('board_remove',['item_id'=>$revoked['id'],'confirmed'=>true]);
$pdo->prepare('DELETE FROM media_assets WHERE id=?')->execute([$items[1]['asset_id']]);
check(count(sf_image_studio_moodboard_items($id))===6,'source deletion cascades only its board reference');
sf_image_studio_archive_style($id);
fails(fn()=>$change('board_add',['asset_id'=>12]),'archived style cannot be edited');
check(sf_image_studio_style($id)===null,'archived style is absent from new prompt selection');
check((int)$pdo->query('SELECT COUNT(*) FROM image_generation_jobs')->fetchColumn()===1,'board operations created no generation jobs');
check((int)$pdo->query('SELECT COUNT(*) FROM media_links')->fetchColumn()===0,'board operations created no public or private publication links');
echo '91B20I '.($mysql?'MySQL':'SQLite').': '.$count.' assertions passed'.PHP_EOL;
if(!$mysql){$pdo=null;@unlink($path);}
