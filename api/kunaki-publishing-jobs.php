<?php
declare(strict_types=1);
/** 84B1: immutable, purchased-media-only Stonefellow -> HomeServer publishing jobs.
 * The worker receives catalog IDs and a design fingerprint, NOT payment data,
 * customer addresses, private media bytes or Kunaki credentials.
 * No browser upload or Kunaki submission is authorized by this contract.
 */
function sf_kunaki_publishing_track_ids(array $order,int $itemIndex):array {
    if(!sf_store_customer_order_visible($order))throw new RuntimeException('Real customer order required.');
    $item=$order['quote']['items'][$itemIndex]??null;
    if(!is_array($item)||($item['type']??'')!=='custom_media'||($item['format']??'')!=='vinyl')
        throw new RuntimeException('Purchased vinyl item not found.');
    $ids=[];
    foreach(['A','B'] as $side){
        foreach((array)($item['builder']['sides'][$side]['tracks']??[]) as $row){
            $id=(string)($row['id']??'');
            if($id===''||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$id)||isset($ids[$id]))
                throw new RuntimeException('Invalid or duplicate source track.');
            $ids[$id]=true;
        }
    }
    if(!$ids||count($ids)>40)throw new RuntimeException('Vinyl requires 1–40 catalog tracks.');
    return array_keys($ids);
}
/** Purchased digital rights must be evidenced by a genuine matching paid
 * source order AND the user's own canonical Library grant. Saved playlists,
 * favorites, catalog visibility and a paid vinyl printing fee never suffice.
 */
function sf_kunaki_publishing_entitlements(array $vinylOrder,int $itemIndex,
    array $library,array $paidOrderMap,array $catalog):array {
    $uid=(int)($vinylOrder['user_id']??0);
    if($uid<1||!sf_order_paid_for_library($vinylOrder))
        throw new RuntimeException('Paid, signed-in customer required.');
    $ids=sf_kunaki_publishing_track_ids($vinylOrder,$itemIndex);
    $available=[];
    foreach($library as $entry){
        if(!is_array($entry)||($entry['item_type']??'')!=='track')continue;
        $track=(string)($entry['item_key']??'');$sourceId=(string)($entry['source_order_id']??'');
        $source=$paidOrderMap[$sourceId]??null;
        if(!$source||!sf_store_customer_order_visible($source)
           ||(int)($source['user_id']??0)!==$uid||!sf_order_paid_for_library($source))
            continue;
        foreach((array)($source['quote']['items']??[]) as $line)
            if(is_array($line)&&($line['type']??'')==='track'
                &&(string)($line['track_id']??'')===$track)
                $available[$track]=$sourceId;
    }
    $proof=[];
    foreach($ids as $track){
        if(!isset($available[$track]))throw new RuntimeException('Purchase required for catalog track '.$track.'.');
        $row=$catalog[$track]??null;
        if(!is_array($row)||empty($row['podEligible'])||empty($row['audio']))
            throw new RuntimeException('Catalog master is missing or not approved for vinyl.');
        $proof[]=['track_id'=>$track,'source_order_id'=>$available[$track],
            'title'=>substr((string)($row['title']??''),0,160),
            'duration_seconds'=>max(0,(int)($row['duration']??0))];
    }
    return $proof;
}
function sf_kunaki_publishing_verified_tracks(array $order,int $itemIndex):array {
    $uid=(int)($order['user_id']??0);
    if($uid<1)throw new RuntimeException('Authenticated purchaser required.');
    $library=sf_account_library($uid);
    $sources=[];
    foreach($library as $entry){
        $id=(string)($entry['source_order_id']??'');
        if($id!==''&&!isset($sources[$id]))$sources[$id]=sf_read_order($id);
    }
    return sf_kunaki_publishing_entitlements($order,$itemIndex,$library,$sources,sf_track_map());
}
function sf_kunaki_publishing_contract(array $order,int $itemIndex,array $record,
    array $proof,array $tracks):array {
    $id=(string)($order['id']??'');
    if(!preg_match('/^SF-[A-Z0-9-]{1,96}$/D',$id)||empty($proof['ready'])
        ||empty($proof['fingerprint'])||empty($record['proof_sha256'])
        ||!hash_equals((string)$proof['fingerprint'],(string)$record['proof_sha256'])
        ||!in_array((string)($record['status']??''),[
            'artwork_approved','in_production','quality_check','production_complete'],true))
        throw new RuntimeException('Exact purchased artwork must be approved before worker staging.');
    if(!$tracks)throw new RuntimeException('Missing verified purchases.');
    $item=$order['quote']['items'][$itemIndex]??null;
    if(!is_array($item))throw new RuntimeException('Order item missing.');
    $identity=hash('sha256',$id.'|'.$itemIndex.'|'.$proof['fingerprint']);
    return [
        'schema'=>'stonefellow.kunaki.publish-job.v1','job_id'=>'KJP-'.strtoupper(substr($identity,0,24)),
        'order_id'=>$id,'item_index'=>$itemIndex,'owner_id'=>(int)$order['user_id'],
        'edition'=>'custom','proof_sha256'=>(string)$proof['fingerprint'],
        'catalog_tracks'=>$tracks,
        'front_artwork_template'=>(string)($item['builder']['coverFront']??''),
        'back_artwork_template'=>(string)($item['builder']['coverBack']??''),
        'title'=>substr((string)($item['builder']['title']??''),0,120),
        'state'=>'queued','created_at'=>gmdate('c'),
        'publisher'=>'kunaki','worker'=>'homeserver',
        'browser_upload_enabled'=>false,'kunaki_submission_enabled'=>false,
        'media_download_granted'=>false
    ];
}
function sf_kunaki_queue_path():string {
    $dir=(defined('SF_ROOT')?SF_ROOT:dirname(__DIR__)).'/storage/kunaki';
    if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))
        throw new RuntimeException('Cannot create secure Kunaki queue.');
    return $dir.'/publishing-jobs.json';
}
/** One locked JSON document on shared PHP hosting; retries return same job. */
function sf_kunaki_queue_operation(callable $action):mixed {
    $path=sf_kunaki_queue_path();
    $lock=fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Kunaki publishing queue lock unavailable.');
    try{
        $jobs=is_file($path)?json_decode((string)file_get_contents($path),true):[];
        if(!is_array($jobs))throw new RuntimeException('Kunaki publishing queue corrupt.');
        [$result,$changed]=$action($jobs);
        if($changed){
            $tmp=$path.'.tmp.'.bin2hex(random_bytes(5));
            if(file_put_contents($tmp,json_encode($jobs,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),LOCK_EX)===false
                ||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Cannot persist Kunaki job.');}
            @chmod($path,0660);
        }
        return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sf_kunaki_job_enqueue(array $job):array {
    $key=(string)($job['job_id']??'');
    if(!preg_match('/^KJP-[A-F0-9]{24}$/D',$key))throw new InvalidArgumentException('Bad Kunaki job identity.');
    return sf_kunaki_queue_operation(static function(array &$jobs)use($job,$key):array {
        if(isset($jobs[$key])){
            $old=$jobs[$key];
            if(($old['proof_sha256']??'')!==($job['proof_sha256']??'')
               ||($old['owner_id']??null)!==($job['owner_id']??null))
                throw new RuntimeException('Kunaki request already belongs to another design.');
            return [$old,false];
        }
        $jobs[$key]=$job;
        return [$job,true];
    });
}
function sf_kunaki_job_for_order(string $id):array {
    return sf_kunaki_queue_operation(static function(array &$jobs)use($id):array {
        $rows=array_values(array_filter($jobs,static fn($r):bool=>($r['order_id']??'')===$id));
        return [$rows,false];
    });
}
/** Lease expiry is an unknown external outcome, never an automatic retry. */
function sf_kunaki_job_claim(string $workerId):?array {
    if(!preg_match('/^HS-[a-zA-Z0-9_-]{5,64}$/D',$workerId))
        throw new InvalidArgumentException('Invalid worker ID.');
    return sf_kunaki_queue_operation(static function(array &$jobs)use($workerId):array {
        $now=time();
        foreach($jobs as &$job){
            if(($job['state']??'')==='leased'&&(int)($job['lease_expires']??0)<$now){
                $job['state']='needs_review';$job['review_reason']='worker_lease_expired';
                $job['updated_at']=gmdate('c');
            }
        }unset($job);
        foreach($jobs as &$job){
            if(($job['state']??'')!=='queued')continue;
            $job['state']='leased';$job['worker_id']=$workerId;
            $job['lease_token']=bin2hex(random_bytes(24));
            $job['lease_expires']=$now+600;$job['updated_at']=gmdate('c');
            $claimed=$job;unset($job);
            return [$claimed,true];
        }unset($job);
        return [null,true];
    });
}
function sf_kunaki_job_report(string $jobId,string $workerId,string $leaseToken,
    string $state):array {
    if(!in_array($state,['awaiting_media_grant','awaiting_browser_approval','needs_review'],true))
        throw new InvalidArgumentException('Worker cannot publish or complete orders.');
    return sf_kunaki_queue_operation(static function(array &$jobs)use($jobId,$workerId,$leaseToken,$state):array {
        $job=$jobs[$jobId]??null;
        if(!is_array($job)||($job['state']??'')!=='leased'
            ||($job['worker_id']??'')!==$workerId
            ||!hash_equals((string)($job['lease_token']??''),$leaseToken)
            ||(int)($job['lease_expires']??0)<time())
            throw new RuntimeException('Stale or foreign worker lease.');
        $job['state']=$state;$job['updated_at']=gmdate('c');
        unset($job['lease_token'],$job['lease_expires']);
        $jobs[$jobId]=$job;
        return [$job,true];
    });
}
