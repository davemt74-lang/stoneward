<?php
declare(strict_types=1);
/**
 * Section 84B5: private catalog originals -> leased HomeServer only.
 * Catalog URLs must point to a media-library asset, never arbitrary HTTP URLs,
 * arbitrary files or user-controlled paths. Does not contact Kunaki.
 */
require_once __DIR__.'/kunaki-publishing-jobs.php';
require_once __DIR__.'/vinyl-print-licenses.php';
require_once __DIR__.'/vinyl-production-gates.php';
const SF_KUNAKI_MEDIA_MAX_BYTES = 268435456; // 256 MiB/file
const SF_KUNAKI_MEDIA_MAX_TOTAL = 1073741824; // 1 GiB/job
function sf_kunaki_media_resolve(string $reference,string $role,?callable $lookup=null):array {
    if(!in_array($role,['audio','front','back'],true))
        throw new RuntimeException('Unrecognized manufacturing asset role.');
    // The publishing pathway of Stonefellow's Media Library saves
    // api/media.php?id=M<20 HEX>[&variant=large] to the catalog.
    $parts=parse_url(trim($reference));
    if($parts===false||isset($parts['scheme'])||isset($parts['host'])
        ||isset($parts['fragment'])||($parts['path']??'')!=='api/media.php')
        throw new RuntimeException('Production media must be a Stonefellow Media Library asset.');
    $query=[];parse_str((string)($parts['query']??''),$query);
    $id=(string)($query['id']??'');
    if(!preg_match('/^M[A-F0-9]{20}$/D',$id)
       ||count(array_diff(array_keys($query),['id','variant']))>0
       ||!in_array((string)($query['variant']??'original'),['original','large'],true))
       throw new RuntimeException('Invalid Media Library reference.');
    $asset=$lookup?$lookup($id):sf_media_asset($id);
    if(!is_array($asset)||($asset['uuid']??'')!==$id
        ||($asset['category']??'')!==($role==='audio'?'audio':'image'))
        throw new RuntimeException('Media asset is missing or of the wrong category.');
    $ext=strtolower((string)($asset['extension']??''));
    $allowed=$role==='audio'?['wav','mp3']:['jpg','jpeg','png'];
    if(!in_array($ext,$allowed,true))throw new RuntimeException('Unsupported production media format.');
    // Images must be suitable to *review* for Kunaki production, not a
    // 1600px downscaled storefront preview. Original image dimensions required.
    if($role!=='audio'&&((int)($asset['width']??0)<1600||(int)($asset['height']??0)<1600))
        throw new RuntimeException('Original artwork resolution is insufficient for production review.');
    $base=realpath((defined('SF_ROOT')?SF_ROOT:dirname(__DIR__)).'/storage/media');
    $raw=(string)($asset['stored_path']??'');
    if($raw===''||str_contains($raw,'..')||str_contains($raw,'\\'))
        throw new RuntimeException('Unsafe production asset path.');
    $file=realpath((defined('SF_ROOT')?SF_ROOT:dirname(__DIR__)).'/'.ltrim($raw,'/'));
    if(!$base||!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)
        ||!is_file($file)||is_link($file))
        throw new RuntimeException('Master must be a private stored media asset.');
    $bytes=filesize($file);
    if($bytes===false||$bytes<1||$bytes>SF_KUNAKI_MEDIA_MAX_BYTES)
        throw new RuntimeException('Production master size is invalid.');
    $sha=hash_file('sha256',$file);
    if(!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha))
        throw new RuntimeException('Cannot hash the original media.');
    if(!empty($asset['sha256'])&&!hash_equals(strtolower((string)$asset['sha256']),$sha))
        throw new RuntimeException('Media asset was altered after catalog publication.');
    return ['uuid'=>$id,'role'=>$role,'extension'=>$ext,'size_bytes'=>$bytes,
        'sha256'=>$sha,'local_path'=>$file];
}
function sf_kunaki_media_job_assets(array $job,array $order,array $catalog,
    ?callable $lookup=null):array {
    if((string)($job['schema']??'')!=='stonefellow.kunaki.publish-job.v1'
       ||empty($job['proof_sha256'])||empty($job['license_track_ids']))
        throw new RuntimeException('Invalid protected vinyl job.');
    if(($order['id']??'')!==($job['order_id']??'')
       ||(int)($order['user_id']??0)!==(int)($job['owner_id']??-1)
       ||!sf_kunaki_live_purchase_confirmed($order,true))
        throw new RuntimeException('Original paid order is no longer eligible.');
    $index=(int)($job['item_index']??-1);
    $item=$order['quote']['items'][$index]??null;
    if(!is_array($item)||($item['format']??'')!=='vinyl'
        ||!in_array((string)($item['type']??''),['album_vinyl','custom_media'],true))
        throw new RuntimeException('Original vinyl item is missing.');
    $builder=(array)($item['builder']??[]);
    $rows=[];$songIds=[];$sum=0;
    foreach(['A','B'] as $side)foreach((array)($builder['sides'][$side]['tracks']??[]) as $n=>$line){
        $id=(string)($line['id']??'');$catalogRow=$catalog[$id]??null;
        if(!is_array($catalogRow)||empty($catalogRow['podEligible'])
           ||(string)($catalogRow['audio']??'')==='')throw new RuntimeException('Required catalog master unavailable.');
        $source=sf_kunaki_media_resolve((string)$catalogRow['audio'],'audio',$lookup);
        $sum+=$source['size_bytes'];
        $rows[]=['asset_key'=>'audio-'.$side.'-'.(count((array)$rows)+1),
            'side'=>$side,'position'=>(int)$n+1,'track_id'=>$id,
            'title'=>(string)($line['title']??$id),'duration_seconds'=>(int)($line['duration']??0)]
            +$source;
        $songIds[]=$id;
    }
    if(!$songIds||$songIds!==array_values((array)$job['license_track_ids']))
        throw new RuntimeException('Changed source sequence or missing print reservation.');
    // Both artwork sides must resolve to actual catalog/Media Library assets.
    // Custom template IDs without rendered artwork fail closed: never pretend
    // the cover template is a production JPEG.
    foreach(['front'=>'coverFront','back'=>'coverBack'] as $role=>$key){
        $source=sf_kunaki_media_resolve((string)($builder[$key]??''),$role,$lookup);
        $sum+=$source['size_bytes'];
        $rows[]=['asset_key'=>'artwork-'.$role,'role'=>$role]+$source;
    }
    if($sum>SF_KUNAKI_MEDIA_MAX_TOTAL)throw new RuntimeException('Manufacturing job media exceeds safe transfer budget.');
    return $rows;
}
function sf_kunaki_media_token(string $secret,array $claims):string {
    if(strlen($secret)<32)throw new RuntimeException('Media grant signing key is unavailable.');
    $payload=rtrim(strtr(base64_encode(json_encode($claims,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');
    return $payload.'.'.hash_hmac('sha256','sf-kunaki-media-v1.'.$payload,$secret);
}
function sf_kunaki_media_claims(string $secret,string $token,int $now):array {
    if(strlen($token)>1800||!preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$token,$m))
        throw new RuntimeException('Invalid media grant.');
    $expected=hash_hmac('sha256','sf-kunaki-media-v1.'.$m[1],$secret);
    if(!hash_equals($expected,$m[2]))throw new RuntimeException('Media grant signature rejected.');
    $body=base64_decode(strtr($m[1],'-_','+/'),true);
    $claims=$body!==false?json_decode($body,true):null;
    if(!is_array($claims)||($claims['v']??0)!==1
       ||(int)($claims['expires']??0)<$now
       ||(int)($claims['expires']??0)>$now+600
       ||!preg_match('/^KJP-[A-F0-9]{24}$/D',(string)($claims['job_id']??'')))
        throw new RuntimeException('Media grant expired or malformed.');
    return $claims;
}
function sf_kunaki_media_lease_job(string $jobId,string $worker,string $lease):array {
    if(!preg_match('/^KJP-[A-F0-9]{24}$/D',$jobId)||$lease==='')throw new RuntimeException('Invalid media worker lease.');
    return sf_kunaki_queue_operation(static function(array &$jobs)use($jobId,$worker,$lease):array {
        $job=$jobs[$jobId]??null;
        if(!is_array($job)||($job['state']??'')!=='leased'
           ||($job['worker_id']??'')!==$worker
           ||(int)($job['lease_expires']??0)<=time()
           ||!hash_equals((string)($job['lease_token']??''),$lease)
           ||!sf_vinyl_worker_job_allowed($job,$jobs))
            throw new RuntimeException('Media lease revoked, exhausted or no longer paid.');
        $order=sf_read_order((string)$job['order_id']);
        if(!is_array($order))throw new RuntimeException('Missing purchased artwork snapshot.');
        $proof=sf_vinyl_production_proof($order,(defined('SF_ROOT')?SF_ROOT:dirname(__DIR__)).'/storage');
        if(empty($proof['ready'])||!hash_equals((string)($job['proof_sha256']??''),
                (string)($proof['fingerprint']??'')))
            throw new RuntimeException('Protected vinyl design no longer matches its approval.');
        return [$job,false];
    });
}
function sf_kunaki_media_manifest(array $job,array $order,array $catalog,
    string $worker,string $lease,string $secret,?callable $lookup=null,int $now=0):array {
    $now=$now?:time();
    $assets=sf_kunaki_media_job_assets($job,$order,$catalog,$lookup);
    $expiration=min($now+300,(int)($job['lease_expires']??0));
    if($expiration<=$now)throw new RuntimeException('Media lease has expired.');
    $public=[];$fingerprints=[];
    foreach($assets as $asset){
        $fingerprints[]=$asset['asset_key'].'|'.$asset['sha256'].'|'.$asset['size_bytes'];
        $claims=['v'=>1,'job_id'=>$job['job_id'],'worker_id'=>$worker,
            'lease_hash'=>hash('sha256',$lease),'asset_key'=>$asset['asset_key'],
            'sha256'=>$asset['sha256'],'size_bytes'=>$asset['size_bytes'],
            'proof_sha256'=>$job['proof_sha256'],'expires'=>$expiration];
        $row=array_diff_key($asset,array_flip(['local_path','uuid']));
        $row['grant']=sf_kunaki_media_token($secret,$claims);
        $public[]=$row;
    }
    return ['schema'=>'stonefellow.kunaki.media-manifest.v1',
        'job_id'=>$job['job_id'],'proof_sha256'=>$job['proof_sha256'],
        'expires_at'=>$expiration,'assets'=>$public,
        'media_sha256'=>hash('sha256',implode("\n",$fingerprints)),
        'kunaki_submission_enabled'=>false,'browser_upload_enabled'=>false];
}
function sf_kunaki_media_granted_file(array $job,array $order,array $catalog,
    string $worker,string $lease,string $token,string $secret,?callable $lookup=null,int $now=0):array {
    $now=$now?:time();
    $claims=sf_kunaki_media_claims($secret,$token,$now);
    if(($claims['job_id']??'')!==($job['job_id']??'')
       ||($claims['worker_id']??'')!==$worker
       ||!hash_equals((string)($claims['lease_hash']??''),hash('sha256',$lease))
       ||($claims['proof_sha256']??'')!==($job['proof_sha256']??''))
        throw new RuntimeException('Media grant scope mismatch.');
    $assets=sf_kunaki_media_job_assets($job,$order,$catalog,$lookup);
    foreach($assets as $asset)if($asset['asset_key']===($claims['asset_key']??'')
       &&$asset['sha256']===($claims['sha256']??'')
       &&$asset['size_bytes']===(int)($claims['size_bytes']??-1))
        return $asset;
    throw new RuntimeException('Original media changed since the grant was issued.');
}
