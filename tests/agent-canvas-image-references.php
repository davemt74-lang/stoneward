<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/stonefellow-image-references-'.bin2hex(random_bytes(6));
mkdir($root.'/storage/media/library/image',0700,true);
define('SF_ROOT',$root);
function sf_db_config(): array {return ['driver'=>'sqlite'];}
function sf_db(): PDO {
    static $db=null;
    return $db??=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function sf_clean_text(mixed $value,int $max=120): string {return substr(trim((string)$value),0,$max);}
function verify(bool $value,string $message): void {
    if(!$value)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
require_once __DIR__.'/../api/agent-canvas-image-references.php';
require_once __DIR__.'/../api/agent-canvas-images.php';

function fixture_image(string $name): array {
    static $index=0;
    $index++;
    $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $pixels='';
    for($row=0;$row<128;$row++)$pixels.="\0".str_repeat(chr($row).chr($index)."\xff",128);
    $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',128,128,8,2,0,0,0))
        .$chunk('IDAT',gzcompress($pixels)).$chunk('IEND','');
    $path='storage/media/library/image/'.$index.'.png';
    file_put_contents(SF_ROOT.'/'.$path,$png);
    sf_db()->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,
        sha256,size_bytes,title,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(['M'.$index,'image','image/png','png','private-filename-'.$index.'.png',$path,
            hash('sha256',$png),strlen($png),$name,gmdate('c'),gmdate('c')]);
    return sf_media_asset((int)sf_db()->lastInsertId());
}
function fixture_link(int $id,string $entity,string $role): void {
    sf_db()->prepare('INSERT INTO media_links(asset_id,entity_type,entity_id,role,created_at,updated_at) VALUES(?,?,?,?,?,?)')
        ->execute([$id,$entity,'fixture',$role,gmdate('c'),gmdate('c')]);
}
function publication_rejected(int $id,string $message): void {
    try{sf_agent_canvas_reference_set($id,true);}
    catch(InvalidArgumentException){verify(true,$message);return;}
    throw new RuntimeException($message);
}

try{
    $db=sf_db();$db->exec('PRAGMA foreign_keys=ON');
    $db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT NOT NULL DEFAULT 'active')");
    $db->exec('INSERT INTO users(id) VALUES(1),(2)');
    sf_agent_canvas_reference_ensure_schema();
    $photo=fixture_image('Band portrait');
    verify(sf_agent_canvas_references_page()['images']===[]&&
        sf_agent_canvas_reference_admin_assets([$photo])[0]['in_reference_gallery']===false,
        'Media Library images default to excluded from the public reference gallery');
    $published=sf_agent_canvas_reference_set((int)$photo['id'],true);
    $id=$published['reference_image_id'];$public=sf_agent_canvas_reference_get($id);
    verify(sf_agent_canvas_reference_id($id)&&$published['in_reference_gallery']&&
        $public===['id'=>$id,'title'=>'Band portrait','width'=>128,'height'=>128,
            'created_at'=>$public['created_at'],'url'=>'api/agent-canvas-image.php?id='.$id,'source'=>'reference']&&
        sf_agent_canvas_reference_file($id)['sha256']===$photo['sha256'],
        'Explicit opt-in publishes verified image bytes and only the public gallery metadata');
    verify(sf_agent_canvas_reference_set((int)$photo['id'],true)['reference_image_id']===$id,
        'Repeated publication retains the same gallery identity');

    $image=['bytes'=>file_get_contents(SF_ROOT.'/'.$photo['stored_path']),'mime'=>'image/png','width'=>128,'height'=>128];
    $privateId=substr($id,4);
    sf_agent_canvas_image_store(1,$privateId,'Private generated image',$image);
    verify(sf_agent_canvas_image_get(1,$privateId)!==null&&sf_agent_canvas_image_get(2,$privateId)===null&&
        sf_agent_canvas_reference_get($privateId)===null&&sf_agent_canvas_image_get(1,$id)===null&&
        count(sf_agent_canvas_references_page()['images'])===1,
        'Private saved images stay owner-scoped in a separate ID namespace from public references');

    sf_agent_canvas_reference_set((int)$photo['id'],false);
    verify(sf_agent_canvas_reference_get($id)===null&&sf_agent_canvas_reference_file($id)===null&&
        sf_agent_canvas_references_page()['images']===[],
        'Revocation removes both public inventory and the previously issued pixel URL');
    $newId=sf_agent_canvas_reference_set((int)$photo['id'],true)['reference_image_id'];
    verify($newId!==$id&&sf_agent_canvas_reference_get($id)===null,
        'Republishing issues a fresh identity without restoring revoked references');

    $second=fixture_image('Stage photo');$third=fixture_image('Studio photo');
    sf_agent_canvas_reference_set((int)$second['id'],true);
    sf_agent_canvas_reference_set((int)$third['id'],true);
    $seen=[];$cursor=null;
    do{
        $page=sf_agent_canvas_references_page(1,$cursor);
        array_push($seen,...array_column($page['images'],'id'));
        $cursor=$page['next_cursor'];
    }while($cursor!==null);
    verify(count($seen)===3&&count(array_unique($seen))===3&&
        sf_agent_canvas_references_page(24,'ref-'.str_repeat('0',32))['images']===[],
        'Gallery pagination handles timestamp ties and never accepts an unknown cursor');

    fixture_link((int)$second['id'],'member_content','hero');
    verify(count(sf_agent_canvas_references_page()['images'])===2,
        'Adding a member-only use immediately withdraws an already shared image');
    publication_rejected((int)$second['id'],'Member-only content cannot be explicitly shared as a reference');
    $master=fixture_image('Protected original');fixture_link((int)$master['id'],'track','master_audio');
    publication_rejected((int)$master['id'],'Private original media cannot be published through the reference gallery');
    $mask=fixture_image('Edit mask');
    $db->prepare('INSERT INTO image_generation_options(job_id,mask_asset_id,created_at) VALUES(?,?,?)')
        ->execute([1,$mask['id'],gmdate('c')]);
    publication_rejected((int)$mask['id'],'Image Studio masks remain private');

    $incomplete=fixture_image('Incomplete generation');
    mkdir(SF_ROOT.'/storage/media/generated',0700);
    rename(SF_ROOT.'/'.$incomplete['stored_path'],SF_ROOT.'/storage/media/generated/incomplete.png');
    $db->prepare('UPDATE media_assets SET stored_path=? WHERE id=?')
        ->execute(['storage/media/generated/incomplete.png',$incomplete['id']]);
    publication_rejected((int)$incomplete['id'],'Incomplete generation storage cannot bypass approval through missing provenance');

    $generated=fixture_image('Approved art');
    $db->prepare('UPDATE media_assets SET technical_json=? WHERE id=?')
        ->execute([json_encode(['source'=>'openai_image_generation','image_job_id'=>7]),$generated['id']]);
    $db->prepare("INSERT INTO image_generation_jobs(id,request_key,created_by,prompt,model,size,quality,status,
        media_asset_id,created_at,updated_at) VALUES(7,'fixture',1,'fixture','fixture','1024x1024','medium','needs_review',?,?,?)")
        ->execute([$generated['id'],gmdate('c'),gmdate('c')]);
    publication_rejected((int)$generated['id'],'Image Studio results require asset review before public opt-in');
    $db->exec("UPDATE image_generation_jobs SET status='ready' WHERE id=7");
    publication_rejected((int)$generated['id'],'A ready status alone does not replace an administrator approval');
    $db->exec('UPDATE image_generation_jobs SET asset_approved_by=1 WHERE id=7');
    $generatedId=sf_agent_canvas_reference_set((int)$generated['id'],true)['reference_image_id'];
    verify(sf_agent_canvas_reference_file($generatedId)!==null,'Approved generated art can be explicitly published');
    $db->exec("UPDATE image_generation_jobs SET status='rejected' WHERE id=7");
    verify(sf_agent_canvas_reference_get($generatedId)===null&&sf_agent_canvas_reference_file($generatedId)===null,
        'Revoked Image Studio approval blocks existing gallery references immediately');

    $invalid=fixture_image('Invalid file');
    file_put_contents(SF_ROOT.'/'.$invalid['stored_path'],str_repeat('x',(int)$invalid['size_bytes']));
    publication_rejected((int)$invalid['id'],'A declared raster MIME cannot publish invalid image bytes');
    $missing=fixture_image('Missing file');unlink(SF_ROOT.'/'.$missing['stored_path']);
    publication_rejected((int)$missing['id'],'Missing files cannot enter the reference gallery');
    $outside=fixture_image('Outside storage');
    $db->prepare('UPDATE media_assets SET stored_path=? WHERE id=?')->execute(['storage/media/../secret.png',$outside['id']]);
    publication_rejected((int)$outside['id'],'Traversal paths cannot resolve as managed references');
    $symlink=fixture_image('Symlink');$path=SF_ROOT.'/'.$symlink['stored_path'];unlink($path);
    symlink(SF_ROOT.'/'.$photo['stored_path'],$path);
    publication_rejected((int)$symlink['id'],'Symlinked managed reference paths are rejected');

    $file=sf_agent_canvas_reference_file($newId);
    $bytes=file_get_contents($file['path']);$changed=$bytes;$changed[strlen($bytes)-1]="x";
    file_put_contents($file['path'],$changed);
    verify(sf_agent_canvas_reference_file($newId)===null,'Changed image bytes cannot reuse an approved stored hash');
    file_put_contents($file['path'],$bytes);
    verify(sf_media_delete_asset((int)$photo['id'])&&sf_agent_canvas_reference_get($newId)===null&&
        !sf_db()->query('SELECT 1 FROM agent_canvas_reference_images WHERE asset_id='.(int)$photo['id'])->fetchColumn(),
        'Deleting an unused source asset cascades its gallery publication and closes the reference URL');
}finally{
    $tree=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($tree as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($root);
}
