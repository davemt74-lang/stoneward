<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/stonefellow-analysis-batch-'.bin2hex(random_bytes(6));
mkdir($root.'/storage/media/library/image',0700,true);
define('SF_ROOT',$root);
function sf_db_config(): array {return ['driver'=>'sqlite'];}
function sf_db(): PDO {
    static $db=null;
    return $db??=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function sf_clean_text(mixed $value,int $max=120): string {return substr(trim((string)$value),0,$max);}
function verify(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
function rejects(callable $run,string $message): void {
    try{$run();}catch(InvalidArgumentException|OutOfBoundsException|RuntimeException){verify(true,$message);return;}
    throw new LogicException('Expected rejection. '.$message);
}
require_once __DIR__.'/../api/image-analysis-batch-core.php';

try{
    $db=sf_db();$db->exec('PRAGMA foreign_keys=ON');
    $db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
    $db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active'),(4,'admin','active')");
    verify(sf_image_analysis_batch_export(2)===[]&&sf_image_analysis_batch_clear(2)===
        ['image_analysis_batch_jobs'=>0,'image_analysis_batch_items'=>0],
        'Unused privacy export and clear do not initialize batch storage');
    sf_image_analysis_batch_schema();
    $admin=['id'=>1,'role'=>'admin'];$fan=['id'=>2,'role'=>'user'];$other=['id'=>3,'role'=>'user'];
    $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',128,128,8,2,0,0,0))
        .$chunk('IDAT',gzcompress(str_repeat("\0".random_bytes(384),128))).$chunk('IEND','');
    $a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);$d=str_repeat('d',32);$foreign=str_repeat('f',32);
    foreach([$a,$b,$c,$d] as $image)sf_agent_canvas_image_store(2,$image,'Private image '.$image,['bytes'=>$png]);
    sf_agent_canvas_image_store(3,$foreign,'Foreign private image',['bytes'=>$png]);
    sf_token_adjust(2,100000,'admin_grant','batch-fixture');
    $fields=['description'=>'A guitar beside a show poster.','visible_text'=>"STONEFELLOW\nLIVE",'objects'=>['guitar','poster'],
        'scene'=>'A concert stage.','style'=>'Photograph.','colors'=>['blue'],'tags'=>['music'],'uncertainties'=>[]];
    $provider=['provider'=>'openai','model'=>'fixture-vision','api_key'=>'fixture-key'];$calls=0;
    $transport=static function(string $url,array $headers,array $body)use(&$calls,$fields):array {
        $calls++;
        verify(!str_contains($body['instructions'],'profile_suggestions separately'),
            'Batch analysis never proposes or restores personal photo preferences');
        return ['output_text'=>json_encode(['analysis'=>$fields]),'usage'=>['input_tokens'=>10,'output_tokens'=>20]];
    };
    $jobId=str_repeat('1',32);
    $job=sf_image_analysis_batch_create($fan,'private',$jobId,'selected',[$a,$b]);
    verify($job['id']===$jobId&&$job['status']==='running'&&$job['counts']['pending']===2&&$calls===0,
        'Creating a durable selected queue makes no provider call');
    $first=$job['items'][0]['request_id'];
    verify(sf_image_analysis_batch_id($first)&&$first!==$jobId&&
        sf_image_analysis_batch_create($fan,'private',$jobId,'selected',[$a,$b])===$job,
        'Create replay retains the original server-stored item request identities');
    rejects(fn()=>sf_image_analysis_batch_create($fan,'private',$jobId,'selected',[$a]),
        'A reused create request cannot silently change its images');
    rejects(fn()=>sf_image_analysis_batch_get($other,'private',$jobId),'Another account cannot open the saved queue');
    rejects(fn()=>sf_image_analysis_batch_create(['id'=>2,'role'=>'admin'],'media',str_repeat('2',32),'selected',[1]),
        'The database role controls managed-media batch access');
    rejects(fn()=>sf_image_analysis_batch_create($fan,'private',str_repeat('2',32),'selected',array_fill(0,201,$a)),
        'Selected queues enforce the 200-item request limit');
    sf_image_analysis_batch_action($fan,'private',$jobId,'pause');
    verify(sf_image_analysis_batch_step($fan,'private',$jobId,$transport,$provider)['counts']['pending']===2&&$calls===0,
        'Paused queues remain saved and do not dispatch');
    sf_image_analysis_batch_action($fan,'private',$jobId,'resume');
    $during=static function(string $url,array $headers,array $body)use($fan,$jobId,$provider,$transport):array {
        sf_image_analysis_batch_action($fan,'private',$jobId,'pause');
        $observed=sf_image_analysis_batch_step($fan,'private',$jobId,$transport,$provider);
        verify($observed['counts']['processing']===1&&$observed['status']==='paused',
            'A pause during a provider call blocks another dispatch and preserves in-flight progress');
        return $transport($url,$headers,$body);
    };
    $job=sf_image_analysis_batch_step($fan,'private',$jobId,$during,$provider,$first);
    verify($job['status']==='paused'&&$job['counts']['completed']===1&&$job['counts']['pending']===1&&
        $job['usage']===['input_tokens'=>10,'output_tokens'=>20,'active_tokens_used'=>30],
        'The in-flight result settles normally while remaining items stay paused');
    sf_image_analysis_batch_action($fan,'private',$jobId,'resume');
    sf_image_analysis_batch_step($fan,'private',$jobId,$transport,$provider,$first);
    verify($calls===1,'A completed item request replay never advances another image');
    $job=sf_image_analysis_batch_step($fan,'private',$jobId,$transport,$provider);
    verify($job['status']==='completed'&&$job['counts']['completed']===2&&$calls===2&&sf_token_balance(2)===99940,
        'Serial steps complete the queue and debit actual usage once per image');
    $reviews=[];
    foreach($job['items'] as $item)$reviews[]=['image_id'=>$item['image_id'],'analysis_id'=>$item['analysis_id'],
        'version'=>$item['version'],'analysis'=>$item['analysis']];
    $reviews[1]['version']=99;
    $reviewed=sf_image_analysis_batch_review($fan,'private',$jobId,$reviews);
    verify(array_column($reviewed['review_results'],'ok')===[true,false]&&$reviewed['job']['counts']['reviewed']===1&&$calls===2,
        'Batch review saves valid exact versions and reports conflicts per image without model calls');
    $second=$reviewed['job']['items'][1];
    sf_agent_canvas_image_analysis_save(2,$second['image_id'],$second['analysis_id'],$second['version'],$fields);
    verify(sf_image_analysis_batch_get($fan,'private',$jobId)['counts']['reviewed']===2,
        'Independent per-image review appears when a saved batch is reopened');

    $missing=sf_image_analysis_batch_create($fan,'private',str_repeat('3',32),'missing');
    verify(array_column($missing['items'],'image_id')===[$d,$c]&&$missing['counts']['total']===2,
        'Missing mode scans the full owner inventory and excludes matching drafts and approved analyses');
    $unavailable=sf_image_analysis_batch_create($fan,'private',str_repeat('4',32),'selected',[$foreign]);
    verify($unavailable['counts']['skipped']===1&&$unavailable['items'][0]['title']==='Image unavailable'&&$unavailable['items'][0]['image_url']==='',
        'Unavailable selections reveal no foreign metadata');
    sf_image_analysis_batch_action($fan,'private',$missing['id'],'cancel');
    verify(sf_image_analysis_batch_step($fan,'private',$missing['id'],$transport,$provider)['counts']['skipped']===2&&$calls===2,
        'Cancellation retires pending work without a provider request');
    for($index=0;$index<201;$index++){
        $image=substr(hash('sha256','inventory-fixture-'.$index),0,32);
        sf_agent_canvas_image_store(3,$image,'Inventory image '.$index,['bytes'=>$png]);
    }
    $inventory=sf_image_analysis_batch_create($other,'private',str_repeat('9',32),'missing');
    verify($inventory['counts']['pending']===200&&$inventory['remaining_count']===2,
        'Missing mode reaches beyond visible pages and caps a durable queue while reporting the remaining inventory');

    $creditJob=sf_image_analysis_batch_create($fan,'private',str_repeat('5',32),'selected',[$c]);
    $balance=sf_token_balance(2);sf_token_adjust(2,-$balance,'fixture','spend-before-batch');
    $creditJob=sf_image_analysis_batch_step($fan,'private',$creditJob['id'],$transport,$provider);
    verify($creditJob['status']==='paused'&&$creditJob['counts']['pending']===1&&$calls===2&&
        sf_agent_canvas_image_analysis_row(2,$creditJob['items'][0]['request_id'])===null,
        'Insufficient credits pause pending work before creating a provider attempt');
    sf_token_adjust(2,100000,'admin_grant','batch-topup');
    sf_image_analysis_batch_action($fan,'private',$creditJob['id'],'resume');
    $creditJob=sf_image_analysis_batch_step($fan,'private',$creditJob['id'],$transport,$provider);
    verify($creditJob['counts']['completed']===1&&$calls===3,'An explicit resume after adding credits completes the saved item');
    $file=sf_agent_canvas_image_file(2,$c);$changed=$png;$changed[strlen($changed)-1]="x";file_put_contents($file['path'],$changed);
    $stale=sf_image_analysis_batch_get($fan,'private',$creditJob['id']);
    verify($stale['counts']['skipped']===1&&$stale['items'][0]['analysis']===null&&$stale['usage']['active_tokens_used']===30,
        'Source changes hide stale analysis while preserving its already-settled usage receipt');
    file_put_contents($file['path'],$png);

    $unknown=sf_image_analysis_batch_create($fan,'private',str_repeat('6',32),'selected',[$d]);
    $before=$calls;
    $unknown=sf_image_analysis_batch_step($fan,'private',$unknown['id'],static function()use(&$calls):array {$calls++;throw new RuntimeException('fixture lost provider response');},$provider);
    $again=sf_image_analysis_batch_step($fan,'private',$unknown['id'],$transport,$provider);
    verify($unknown['counts']['failed']===1&&$again['counts']['failed']===1&&$calls===$before+1,
        'An uncertain provider outcome is terminal for the queue and is never automatically retried');

    $relative='storage/media/library/image/band.png';file_put_contents(SF_ROOT.'/'.$relative,$png);
    $db->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,title,created_at,updated_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute(['MBAND','image','image/png','png','band.png',$relative,hash('sha256',$png),strlen($png),'Band',gmdate('c'),gmdate('c')]);
    $mediaId=(int)$db->lastInsertId();
    $media=sf_image_analysis_batch_create($admin,'media',str_repeat('7',32),'selected',[$mediaId]);
    $media=sf_image_analysis_batch_step($admin,'media',$media['id'],$transport,$provider);
    verify($media['counts']['completed']===1&&$media['usage']['input_tokens']===10&&$media['usage']['active_tokens_used']===0,
        'Managed media uses the same queue contract and meters the initiating unlimited Admin');
    rejects(fn()=>sf_media_image_analysis_analyze(1,$mediaId,str_repeat('e',32),$transport,$provider,str_repeat('0',64)),
        'The managed adapter rejects a changed queue fingerprint before a provider request');
    try{sf_media_image_analysis_analyze(1,$mediaId,str_repeat('e',32),static fn()=>['output_text'=>'invalid JSON'],$provider);}
    catch(Throwable $error){}
    verify(sf_image_analysis_batch_create($admin,'media',str_repeat('b',32),'missing')['counts']['total']===0,
        'An unsuccessful later analysis does not requeue an earlier matching complete draft in missing mode');
    $db->prepare("UPDATE image_analysis_batch_items SET status='processing',result_json='{}' WHERE user_id=1 AND job_id=?")->execute([$media['id']]);
    sf_agent_canvas_image_store(1,$a,'Admin private image',['bytes'=>$png]);
    $adminPrivate=sf_image_analysis_batch_create($admin,'private',str_repeat('8',32),'selected',[$a]);
    $adminPrivate=sf_image_analysis_batch_step($admin,'private',$adminPrivate['id'],$transport,$provider);
    verify($adminPrivate['counts']['completed']===1&&sf_image_analysis_batch_get($admin,'media',$media['id'])['counts']['completed']===1,
        'A settled older media attempt is reconciled before another scope claims the account');

    $export=sf_image_analysis_batch_export(1);
    verify(count($export)===3&&!str_contains(json_encode($export),SF_ROOT),
        'Privacy exports include actor-owned scopes and omit filesystem paths');
    $db->beginTransaction();$cleared=sf_image_analysis_batch_clear(1);$db->commit();
    verify($cleared===['image_analysis_batch_jobs'=>3,'image_analysis_batch_items'=>2]&&
        sf_image_analysis_batch_list($admin,'media')['job']===null&&sf_image_analysis_batch_get($fan,'private',$jobId)['counts']['total']===2,
        'Closure helpers clear the actor queues inside an existing transaction and preserve another account');
}finally{
    $tree=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($tree as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($root);
}
