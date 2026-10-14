<?php
declare(strict_types=1);
/** 84B1: immutable, purchased-media-only Stonefellow -> HomeServer publishing jobs.
 * The worker receives catalog IDs and a design fingerprint, NOT payment data,
 * customer addresses, private media bytes or Kunaki credentials.
 * No browser upload or Kunaki submission is authorized by this contract.
 */
/** A future real manufacturer job must NEVER be derived from test, simulated cash,
 * merely reserved, unsigned or partially refunded payment evidence.
 */
require_once __DIR__.'/vinyl-print-licenses.php';
function sf_kunaki_live_purchase_confirmed(array $order,bool $physical=false):bool {
    $payment=(array)($order['payment']??[]);
    $done=(array)($payment['postpayment_done']??[]);
    if(!sf_store_customer_order_visible($order)||($payment['provider']??'')!=='stripe'
        ||($payment['status']??'')!=='paid'
        ||($payment['livemode']??null)!==true
        ||trim((string)($payment['payment_intent']??''))===''
        ||!in_array((string)($order['status']??''),['paid','fulfilled','completed'],true)
        ||empty($done['library'])||($physical&&empty($done['pod'])))return false;
    return true;
}
function sf_kunaki_publishing_track_ids(array $order,int $itemIndex):array {
    if(!sf_store_customer_order_visible($order))throw new RuntimeException('Real customer order required.');
    $item=$order['quote']['items'][$itemIndex]??null;
    if(!is_array($item)||!in_array((string)($item['type']??''),['custom_media','album_vinyl'],true)||($item['format']??'')!=='vinyl')
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
    if($uid<1||!sf_kunaki_live_purchase_confirmed($vinylOrder,true))
        throw new RuntimeException('Paid, signed-in customer required.');
    $ids=sf_kunaki_publishing_track_ids($vinylOrder,$itemIndex);
    $grants=sf_vinyl_license_grants($uid,$library,$paidOrderMap);
    $available=[];
    foreach($library as $entry){
        if(!is_array($entry)||!in_array((string)($entry['item_type']??''),['track','release'],true))continue;
        $type=(string)$entry['item_type'];
        $key=(string)($entry['item_key']??'');
        $sourceId=(string)($entry['source_order_id']??'');
        $source=$paidOrderMap[$sourceId]??null;
        if(!is_array($source)||(int)($source['user_id']??0)!==$uid
            ||!sf_kunaki_live_purchase_confirmed($source,false))continue;
        foreach((array)($source['quote']['items']??[]) as $line){
            if(!is_array($line))continue;
            if($type==='track'&&($line['type']??'')==='track'
               &&(string)($line['track_id']??'')===$key)
                $available[$key]=$sourceId;
            if($type==='release'&&($line['type']??'')==='release'
               &&(string)($line['release_id']??'')===$key){
                foreach((array)($line['track_ids']??[]) as $tid)
                    $available[(string)$tid]=$sourceId;
            }
        }
    }
    $proof=[];
    foreach($ids as $track){
        if(!isset($available[$track])||(int)($grants[$track]??0)<1)
            throw new RuntimeException('Music ownership and vinyl print license required for song '.$track.'.');
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
    if(!sf_kunaki_live_purchase_confirmed($order,true))
        throw new RuntimeException('Only a genuinely paid live Stripe order can stage a real vendor workflow.');
    if(!$tracks)throw new RuntimeException('Missing verified purchases.');
    $item=$order['quote']['items'][$itemIndex]??null;
    if(!is_array($item))throw new RuntimeException('Order item missing.');
    $identity=hash('sha256',$id.'|'.$itemIndex.'|'.$proof['fingerprint']);
    return [
        'schema'=>'stonefellow.kunaki.publish-job.v1','job_id'=>'KJP-'.strtoupper(substr($identity,0,24)),
        'order_id'=>$id,'item_index'=>$itemIndex,'owner_id'=>(int)$order['user_id'],
        'edition'=>($item['type']??'')==='album_vinyl'?'album':'custom',
        'edition_id'=>(string)($item['edition_id']??''),
        'edition_fingerprint'=>(string)($item['edition_fingerprint']??''),
        'license_track_ids'=>array_values(array_map(static fn($r)=>(string)$r['track_id'],$tracks)),
        'proof_sha256'=>(string)$proof['fingerprint'],
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
/** Never expose lease token, customer source-order IDs or provider internals in Admin responses. */
function sf_kunaki_job_public(array $job):array {
    $allowed=['job_id','order_id','item_index','edition','state','created_at','updated_at',
        'worker_id','review_reason','proof_sha256','browser_upload_enabled',
        'kunaki_submission_enabled','media_download_granted'];
    return array_intersect_key($job,array_fill_keys($allowed,true));
}
function sf_kunaki_job_for_order_public(string $orderId):array {
    return array_map('sf_kunaki_job_public',sf_kunaki_job_for_order($orderId));
}
function sf_kunaki_queue_path():string {
    $dir=(defined('SF_ROOT')?SF_ROOT:dirname(__DIR__)).'/storage/kunaki';
    if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))
        throw new RuntimeException('Cannot create secure Kunaki queue.');
    $deny=$dir.'/.htaccess';
    if(!is_file($deny))@file_put_contents($deny,"Require all denied\\nDeny from all\\n",LOCK_EX);
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
function sf_kunaki_jobs_for_owner(int $ownerId):array {
    if($ownerId<1)return [];
    return sf_kunaki_queue_operation(static function(array &$jobs)use($ownerId):array {
        return [array_values(array_filter($jobs,
            static fn($r):bool=>is_array($r)&&(int)($r['owner_id']??0)===$ownerId)),false];
    });
}
function sf_kunaki_job_claim(string $workerId,?callable $licenseCheck=null):?array {
    if(!preg_match('/^HS-[a-zA-Z0-9_-]{5,64}$/D',$workerId))
        throw new InvalidArgumentException('Invalid worker ID.');
    return sf_kunaki_queue_operation(static function(array &$jobs)use($workerId,$licenseCheck):array {
        $now=time();
        $changed=false;
        foreach($jobs as &$job){
            if(($job['state']??'')==='leased'&&(int)($job['lease_expires']??0)<$now){
                $job['state']='needs_review';$job['review_reason']='worker_lease_expired';
                $job['updated_at']=gmdate('c');$changed=true;
            }
        }unset($job);
        foreach($jobs as &$job){
            if(($job['state']??'')!=='queued')continue;
            if($licenseCheck!==null&&!$licenseCheck($job,$jobs)){
                $job['state']='needs_review';
                $job['review_reason']='print_entitlement_or_order_changed';
                $job['updated_at']=gmdate('c');
                $changed=true;
                continue;
            }
            $job['state']='leased';$job['worker_id']=$workerId;
            $job['lease_token']=bin2hex(random_bytes(24));
            $job['lease_expires']=$now+600;$job['updated_at']=gmdate('c');
            $claimed=$job;unset($job);
            return [$claimed,true];
        }unset($job);
        return [null,$changed];
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
