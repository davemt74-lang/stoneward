<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/stonefellow-image-search-'.bin2hex(random_bytes(6));
mkdir($root.'/storage/media',0700,true);
define('SF_ROOT',$root);
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_db():PDO {
    static $db=null;
    return $db??=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function sf_clean_text(mixed $value,int $max=120):string{return substr(trim((string)$value),0,$max);}
function sf_public_catalog():array{return [['id'=>'earth-girl','title'=>'Earth Girl','release_id'=>'night-album']];}
function sf_seo_release_rows():array{return [
    ['id'=>'night-album','title'=>'Night Album','state'=>'published','track_ids'=>['earth-girl']],
    ['id'=>'private-album','title'=>'Unreleased Secret Album','state'=>'draft'],
];}
function verify(bool $value,string $message):void {
    if(!$value)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
require_once __DIR__.'/../api/ai.php';
require_once __DIR__.'/../api/entitlements.php';
require_once __DIR__.'/../api/agent-scene-core.php';
require_once __DIR__.'/../api/agent-canvas-image-references.php';
require_once __DIR__.'/../api/agent-canvas-image-analysis-core.php';

try{
    $db=sf_db();$db->exec('PRAGMA foreign_keys=ON');
    $db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT NOT NULL,role TEXT NOT NULL);
        INSERT INTO users VALUES(1,'active','admin'),(2,'active','user');
        CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY,meta_value TEXT,updated_at TEXT)");
    sf_agent_canvas_reference_ensure_schema();sf_media_image_analysis_ensure_schema();sf_agent_canvas_image_analysis_schema();
    $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $pixels='';
    for($line=0;$line<128;$line++)$pixels.="\0".str_repeat(chr($line)."\0\xff",128);
    $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',128,128,8,2,0,0,0)).$chunk('IDAT',gzcompress($pixels)).$chunk('IEND','');
    $oldId=str_pad('1',32,'0',STR_PAD_LEFT);
    for($number=1;$number<=105;$number++){
        $id=str_pad(dechex($number),32,'0',STR_PAD_LEFT);
        sf_agent_canvas_image_store(1,$id,$number===1?'Old Archive':'Catalog Portrait',['bytes'=>$png]);
        $db->prepare('UPDATE agent_canvas_images SET created_at=? WHERE user_id=1 AND id=?')
            ->execute([$number===1?'2020-01-01T00:00:00+00:00':'2026-01-01T00:00:00+00:00',$id]);
    }
    $foreign=str_repeat('f',32);
    sf_agent_canvas_image_store(2,$foreign,'Private Foreign Treasure',['bytes'=>$png]);
    $analysis=['description'=>'Angular obsidian geometry','visible_text'=>'','objects'=>['sculpture'],
        'scene'=>'A quiet room','style'=>'Abstract','colors'=>['black'],'tags'=>['obsidian','kinetic'],'uncertainties'=>[]];
    $privateInsert=$db->prepare("INSERT INTO agent_canvas_image_analyses(user_id,analysis_id,image_id,source_sha256,state,
        analysis_json,suggestions_json,provider,model,created_at,updated_at) VALUES(?,?,?,?,'approved',?,'[]','fixture','fixture',?,?)");
    $privateInsert->execute([1,str_repeat('a',32),$oldId,hash('sha256',$png),json_encode($analysis),gmdate('c'),gmdate('c')]);
    $privateInsert->execute([2,str_repeat('b',32),$foreign,hash('sha256',$png),json_encode($analysis+[]),gmdate('c'),gmdate('c')]);

    file_put_contents(SF_ROOT.'/storage/media/artist.png',$png);
    $asset=sf_media_register_existing_file('storage/media/artist.png','Artist archive portrait.png',1);
    $artist=sf_agent_canvas_reference_set((int)$asset['id'],true)['reference_image_id'];
    $artistAnalysis=['description'=>'A quartet under red studio lights','visible_text'=>str_repeat("Archive line\n",80)."INVISIBLE LANTERN\n",
        'objects'=>['guitars','microphones'],'scene'=>'Recording studio','style'=>'Documentary photograph','colors'=>['red'],
        'tags'=>['quartet','stage'],'uncertainties'=>['The last letter of the sign is faint']];
    $managedInsert=$db->prepare("INSERT INTO media_image_analyses(request_id,asset_id,source_sha256,source_size_bytes,status,
        analysis_json,original_json,provider,model,created_at) VALUES(?,?,?,?,?,?,?,'fixture','fixture',?)");
    $json=json_encode($artistAnalysis);
    $managedInsert->execute([str_repeat('c',32),$asset['id'],$asset['sha256'],$asset['size_bytes'],'approved',$json,$json,gmdate('c')]);
    $draft=array_replace($artistAnalysis,['description'=>'Unapproved Zebra Caption','visible_text'=>'','tags'=>[],'objects'=>[]]);
    $managedInsert->execute([str_repeat('d',32),$asset['id'],$asset['sha256'],$asset['size_bytes'],'pending_review',json_encode($draft),json_encode($draft),gmdate('c')]);
    foreach([['track','earth-girl'],['release','night-album'],['release','private-album']] as [$type,$entity]){
        $db->prepare('INSERT INTO media_links(asset_id,entity_type,entity_id,role,public_visible,created_at,updated_at) VALUES(?,?,?,\'artwork\',1,?,?)')
            ->execute([$asset['id'],$type,$entity,gmdate('c'),gmdate('c')]);
    }

    $old=sf_agent_canvas_image_search(1,'obsidian');
    verify(array_column($old['images'],'id')===[$oldId]&&$old['images'][0]['image_analysis']['description']===$analysis['description'],
        'Approved private analysis finds an older image beyond the first hundred inventory records');
    verify(sf_agent_canvas_image_search(1,'Private Foreign Treasure')['images']===[]&&
        sf_agent_canvas_image_search(2,'obsidian')['images'][0]['id']===$foreign,
        'Personal search uses only the signed-in owner’s inventory and reviewed metadata');
    $band=sf_agent_canvas_image_search(1,'quartet red stage');
    verify(array_column($band['images'],'id')===[$artist]&&$band['images'][0]['source']==='reference'&&
        !isset($band['images'][0]['image_analysis']['provider']),
        'Approved artist descriptions, objects and tags are searchable without exposing analysis provenance');
    verify(sf_agent_canvas_image_search(1,'Unapproved Zebra')['images']===[],
        'Unreviewed analysis is excluded from image search');
    verify(array_column(sf_agent_canvas_image_search(1,'Earth Girl')['images'],'id')===[$artist]&&
        array_column(sf_agent_canvas_image_search(1,'Night Album')['images'],'id')===[$artist]&&
        sf_agent_canvas_image_search(1,'Unreleased Secret')['images']===[],
        'Song and album image links resolve published catalog labels and hide unreleased labels');
    $db->prepare("DELETE FROM media_links WHERE asset_id=? AND entity_type='track'")->execute([$asset['id']]);
    verify(array_column(sf_agent_canvas_image_search(1,'Earth Girl')['images'],'id')===[$artist],
        'An album image can be discovered by one of its published songs through catalog membership');
    $ocr=sf_agent_canvas_image_search(1,'INVISIBLE LANTERN');
    $prompt=sf_agent_canvas_image_search_prompt($ocr['images'],'Use the Invisible Lantern design');
    verify(array_column($prompt,'id')===[$artist]&&str_contains($prompt[0]['visible_text'],'INVISIBLE LANTERN')&&
        strlen(json_encode($prompt))<2200&&!str_contains(json_encode($prompt),'storage/'),
        'The model sees a relevant bounded excerpt from approved image text');
    verify($prompt[0]['colors']===['red']&&$prompt[0]['style']==='Documentary photograph'&&$prompt[0]['scene']==='Recording studio',
        'Approved color and composition metadata reaches the coding model without another analysis call');
    verify(array_column(sf_agent_canvas_image_search(0,'quartet',24,null,'reference')['images'],'id')===[$artist],
        'The public gallery search exposes only artist-approved imagery');

    $seen=[];$cursor=null;
    do{
        $page=sf_agent_canvas_image_search(1,'Catalog Portrait',13,$cursor,'saved');
        $seen=array_merge($seen,array_column($page['images'],'id'));$cursor=$page['next_cursor'];
    }while($cursor!==null);
    verify(count($seen)===104&&count(array_unique($seen))===104&&$seen[0]===str_pad(dechex(105),32,'0',STR_PAD_LEFT),
        'Relevance pagination covers every matching image once across timestamp ties and multiple storage batches');
    verify(sf_agent_canvas_image_search(1,'obsidian',24,$foreign)['images']===[],
        'A cursor cannot expose another account’s image');
    $context=sf_agent_canvas_image_skill_context(['id'=>1,'role'=>'admin'],'Design a poster starring the quartet.',[]);
    $numbered=sf_agent_canvas_image_search_terms('Use canvas 17 with 33 images');
    verify(in_array('17',$numbered,true)&&in_array('33',$numbered,true)&&count(array_filter($numbered,'is_string'))===count($numbered)&&
        sf_agent_canvas_image_search_prompt($ocr['images'],'Use canvas 17 with 33 images')[0]['id']===$artist,
        'Numeric dialogue and resource identifiers remain string search terms for approved image metadata');
    verify(in_array($artist,$context['inventory_search']['reference_ids'],true)&&
        in_array($artist,array_column($context['saved_images'],'id'),true),
        'A natural design brief offers relevant authorized inventory for the coding model to choose');
    $plan=['prompt'=>'A new image composition','title'=>'New composition','reference_ids'=>[$artist]];
    verify(sf_agent_canvas_image_plan($plan,[],$context['inventory_search']['reference_ids'])['reference_ids']===[$artist]&&
        sf_agent_canvas_image_plan(['prompt'=>'A new image composition','title'=>'New composition'],[],$context['inventory_search']['reference_ids'])['reference_ids']===[],
        'The model chooses visual inputs per new image without the host assigning a reference automatically');
    $manual=sf_agent_canvas_image_skill_context(['id'=>1,'role'=>'admin'],'Design a poster starring the quartet.',[],[$oldId]);
    verify(!isset($manual['inventory_search'])&&array_column($manual['selected_references'],'id')===[$oldId]&&
        $manual['selected_references'][0]['description']===$analysis['description'],
        'Explicit picker selection overrides automatic search and includes approved metadata');
    $placed=sf_agent_canvas_image_skill_context(['id'=>1,'role'=>'admin'],'Use {image-'.$artist.'} as the background.',[]);
    verify($placed['saved_images'][0]['colors']===['red']&&$placed['saved_images'][0]['style']==='Documentary photograph',
        'An explicit placed background image carries its currently approved visual metadata into the build');
    $scene=['html'=>'<figure class="sf-image-'.$artist.'"></figure>','css'=>''];
    $assets=sf_agent_canvas_image_assets(1,$scene);
    verify($assets[0]['colors']===['red']&&$assets[0]['scene']==='Recording studio'&&$assets[0]['style']==='Documentary photograph'&&
        !isset($assets[0]['image_analysis'])&&!isset($assets[0]['provider'])&&!isset($assets[0]['visible_text']),
        'A reopened canvas receives only approved visual colors and context for automatic orb matching');
    verify(sf_agent_canvas_image_assets(1,['html'=>'<figure class="sf-image-'.$foreign.'"></figure>','css'=>''])===[],
        'Runtime palette inference cannot access another account image metadata');
    try{sf_agent_canvas_image_plan($plan,[$oldId],[$artist]);throw new LogicException('Expected explicit selection failure.');}
    catch(SfAgentSceneInvalidOutput){verify(true,'The model cannot expand an explicit reference selection with a search result');}

    $db->prepare('UPDATE agent_canvas_image_analyses SET source_sha256=? WHERE user_id=1')->execute([str_repeat('0',64)]);
    verify(sf_agent_canvas_image_search(1,'obsidian')['images']===[],
        'Private analysis for a stale source fingerprint stops participating in search');
    $db->prepare('UPDATE media_image_analyses SET source_sha256=? WHERE status=\'approved\'')->execute([str_repeat('0',64)]);
    verify(sf_agent_canvas_image_search(1,'quartet')['images']===[],
        'Artist analysis for a stale source fingerprint stops participating in search');
    verify(!isset(sf_agent_canvas_image_assets(1,$scene)[0]['colors']),
        'Stale image analysis stops contributing automatic canvas palette metadata');
    sf_agent_canvas_reference_set((int)$asset['id'],false);
    verify(sf_agent_canvas_image_search(1,'Artist archive',24,null,'reference')['images']===[],
        'Withdrawing artist approval removes the image from discovery immediately');
}finally{
    $tree=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($tree as $item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($root);
}
