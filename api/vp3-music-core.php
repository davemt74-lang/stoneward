<?php
declare(strict_types=1);

/** ML2C: one private VP3 provider connection and recoverable explicit catalog imports. */
const SF_VP3_MUSIC_PROTOCOL = 'stoneward_vp3_music_v1';
const SF_VP3_PROVIDER_PROTOCOL = 'vp3_music_provider_v1';

final class SfVp3MusicException extends RuntimeException {
    public function __construct(public readonly string $publicCode, string $message,
        public readonly int $httpStatus=422, public readonly bool $retryable=false) { parent::__construct($message); }
}
function sf_vp3_music_fail(string $code,string $message,int $status=422,bool $retryable=false): never {
    throw new SfVp3MusicException($code,$message,$status,$retryable);
}
function sf_vp3_music_uuid(mixed $value): string {
    if(!is_string($value)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value))
        sf_vp3_music_fail('invalid_identifier','Use the exact VP3 identifier.');
    return $value;
}
function sf_vp3_music_int(mixed $value,int $minimum=0,int $maximum=PHP_INT_MAX): int {
    if(!is_int($value)||$value<$minimum||$value>$maximum)sf_vp3_music_fail('invalid_request','A request number is invalid.');
    return $value;
}
function sf_vp3_music_text(mixed $value,int $limit=240): string {
    if(!is_string($value))return '';
    $value=preg_replace('/[\x00-\x1f\x7f]/u','',trim($value))??'';
    return function_exists('mb_substr')?mb_substr($value,0,$limit):substr($value,0,$limit);
}
function sf_vp3_music_json(array $value): string {
    return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
function sf_vp3_music_fields(array $input,array $allowed): void {
    if(array_diff(array_keys($input),$allowed))sf_vp3_music_fail('invalid_request','This request contains unsupported fields.');
}

/** Restrict server transport to canonical public HTTPS DNS names and the one provider path. */
function sf_vp3_music_provider_url(mixed $value): string {
    if(!is_string($value)||strlen($value)>512||preg_match('/[\x00-\x20\x7f\\\\]/',$value))
        sf_vp3_music_fail('invalid_provider_url','Enter the public HTTPS VP3 address.');
    $p=parse_url(trim($value));
    if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'
        ||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])
        ||(isset($p['port'])&&(int)$p['port']!==443))
        sf_vp3_music_fail('invalid_provider_url','Use HTTPS without credentials, query strings or an alternate port.');
    $host=strtolower((string)($p['host']??''));
    if(strlen($host)>253||!str_contains($host,'.')||filter_var($host,FILTER_VALIDATE_IP)
        ||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D',$host)
        ||preg_match('/(?:^|\.)(?:localhost|local|internal|invalid|test)$/D',$host))
        sf_vp3_music_fail('invalid_provider_url','Use a public DNS hostname for VP3.');
    $path=rtrim((string)($p['path']??''),'/');
    $suffix='/api/music-provider-v1.php';
    if(str_ends_with($path,$suffix))$path=substr($path,0,-strlen($suffix));
    if($path!==''&&!preg_match('#^(?:/[A-Za-z0-9_-]+)+$#D',$path))
        sf_vp3_music_fail('invalid_provider_url','The VP3 installation path is invalid.');
    return 'https://'.$host.$path.$suffix;
}
function sf_vp3_music_origin(mixed $value): string {
    $endpoint=sf_vp3_music_provider_url($value);
    $origin='https://'.parse_url($endpoint,PHP_URL_HOST);
    $path=(string)(parse_url($value,PHP_URL_PATH)??'');
    if(($path!==''&&$path!=='/')||$endpoint!==$origin.'/api/music-provider-v1.php')
        sf_vp3_music_fail('invalid_site_origin','The artist site needs an HTTPS origin without a path.');
    return $origin;
}
function sf_vp3_music_in_cidr(string $ip,string $network,int $bits): bool {
    $a=@inet_pton($ip);$b=@inet_pton($network);
    if($a===false||$b===false||strlen($a)!==strlen($b))return false;
    $bytes=intdiv($bits,8);$rest=$bits%8;
    return substr($a,0,$bytes)===substr($b,0,$bytes)
        &&(!$rest||((ord($a[$bytes])& (255<<(8-$rest)))===(ord($b[$bytes])&(255<<(8-$rest)))));
}
function sf_vp3_music_public_ip(string $ip): bool {
    if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
    if(str_contains($ip,':')){
        if(!sf_vp3_music_in_cidr($ip,'2000::',3))return false;
        foreach([['2001::',23],['2001:db8::',32],['2002::',16],['3fff::',20]] as [$net,$bits])
            if(sf_vp3_music_in_cidr($ip,$net,$bits))return false;
    }else{
        foreach([['0.0.0.0',8],['10.0.0.0',8],['100.64.0.0',10],['127.0.0.0',8],['169.254.0.0',16],
            ['172.16.0.0',12],['192.0.0.0',24],['192.0.2.0',24],['192.168.0.0',16],['198.18.0.0',15],
            ['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4],['240.0.0.0',4]] as [$net,$bits])
            if(sf_vp3_music_in_cidr($ip,$net,$bits))return false;
    }
    return true;
}
function sf_vp3_music_resolve(string $host,?callable $lookup=null): array {
    $records=$lookup?$lookup($host):@dns_get_record($host,DNS_A|DNS_AAAA);
    if(!is_array($records)||!$records||count($records)>64)
        sf_vp3_music_fail('provider_unreachable','VP3 could not be reached.',503,true);
    $ips=[];
    foreach($records as $row){
        $ip=is_array($row)?($row['ip']??$row['ipv6']??null):null;
        if($ip===null)continue;
        if(!is_string($ip)||!sf_vp3_music_public_ip($ip))
            sf_vp3_music_fail('unsafe_provider_address','The VP3 address does not resolve exclusively to public addresses.');
        $ips[$ip]=true;
    }
    if(!$ips)sf_vp3_music_fail('provider_unreachable','VP3 could not be reached.',503,true);
    return array_keys($ips);
}
function sf_vp3_music_transport(string $url,string $method,?array $body,?string $bearer,array $context=[]): array {
    if(sf_vp3_music_provider_url($url)!==$url)sf_vp3_music_fail('invalid_provider_url','The saved VP3 address is invalid.');
    if(!in_array($method,['GET','POST'],true))sf_vp3_music_fail('invalid_request','Unsupported provider request.');
    if($bearer!==null&&!preg_match('/^vmp1_[a-f0-9]{64}$/D',$bearer))
        sf_vp3_music_fail('invalid_credential','Reconnect using the VP3 music-site credential.',401);
    $encoded=$body===null?null:sf_vp3_music_json($body);
    if($encoded!==null&&strlen($encoded)>32768)sf_vp3_music_fail('request_too_large','The selected music request is too large.');
    // Only a trusted PHP caller can supply this seam. HTTP fields and environment variables cannot enable it.
    if(isset($context['transport'])&&is_callable($context['transport'])){
        $response=($context['transport'])($url,$method,$body,$bearer);
    }else{
        if(!function_exists('curl_init'))sf_vp3_music_fail('transport_unavailable','This server needs PHP cURL to connect to VP3.',503);
        $host=(string)parse_url($url,PHP_URL_HOST);$ips=sf_vp3_music_resolve($host);$ip=$ips[0];
        $ch=curl_init($url);$bytes='';$tooLarge=false;
        $headers=['Accept: application/json'];
        if($body!==null)$headers[]='Content-Type: application/json';
        if($bearer!==null)$headers[]='Authorization: Bearer '.$bearer;
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROXY=>'',CURLOPT_NETRC=>CURL_NETRC_IGNORED,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
            CURLOPT_RESOLVE=>[$host.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip)],
            CURLOPT_WRITEFUNCTION=>static function($unused,string $chunk)use(&$bytes,&$tooLarge):int{
                if(strlen($bytes)+strlen($chunk)>2097152){$tooLarge=true;return 0;}$bytes.=$chunk;return strlen($chunk);
            }]);
        if($encoded!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$encoded);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $contentType=strtolower((string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE));curl_close($ch);
        if($tooLarge)sf_vp3_music_fail('provider_response_invalid','VP3 returned an oversized response.',502);
        if($ok===false)sf_vp3_music_fail('provider_unreachable','VP3 could not be reached. Retry the saved request.',503,true);
        if(trim(explode(';',$contentType)[0])!=='application/json')sf_vp3_music_fail('provider_response_invalid','VP3 returned an unsupported response.',502);
        try{$decoded=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);}catch(Throwable){$decoded=null;}
        $response=['status'=>$status,'body'=>$decoded];
    }
    if(!is_array($response)||!is_int($response['status']??null)||!is_array($response['body']??null)
        ||strlen(sf_vp3_music_json($response['body']))>2097152)
        sf_vp3_music_fail('provider_response_invalid','VP3 returned an invalid response.',502);
    $status=$response['status'];$result=$response['body'];
    if(($result['protocol']??null)!==SF_VP3_PROVIDER_PROTOCOL)
        sf_vp3_music_fail('provider_protocol_mismatch','This VP3 installation does not support the music provider protocol.',502);
    if($status!==200||($result['ok']??null)!==true){
        $code=is_string($result['code']??null)?$result['code']:'';
        if(in_array($status,[401,403],true))sf_vp3_music_fail('connection_unavailable','Verify this site in VP3 or reconnect with a current music-site credential.',$status);
        if($status===409)sf_vp3_music_fail('provider_conflict','The VP3 selection changed. Refresh and review it before importing.',409);
        if($status>=500||$status===429)sf_vp3_music_fail('provider_unreachable','VP3 is temporarily unavailable. Retry the saved request.',503,true);
        if($status===404||$code==='unavailable')sf_vp3_music_fail('recording_unavailable','A selected recording is no longer available. Refresh the selection.',409);
        sf_vp3_music_fail('provider_response_invalid','VP3 did not accept this music request.',502);
    }
    return $result;
}

function sf_vp3_music_state_path(): string { return SF_ROOT.'/storage/vp3-music/state.json'; }
function sf_vp3_music_empty_state(): array {
    return ['schema'=>SF_VP3_MUSIC_PROTOCOL,'revision'=>0,'connection'=>null,'imports'=>[],
        'receipts'=>[],'pending'=>null,'last_import'=>null];
}
function sf_vp3_music_read_state(): array {
    $path=sf_vp3_music_state_path();
    if(is_link(dirname($path))||is_link($path))sf_vp3_music_fail('storage_unavailable','VP3 connection storage is unavailable.',503);
    if(!is_file($path))return sf_vp3_music_empty_state();
    if(filesize($path)>67108864)sf_vp3_music_fail('storage_unavailable','VP3 connection storage needs maintenance.',503);
    $raw=@file_get_contents($path);$state=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($state)||($state['schema']??null)!==SF_VP3_MUSIC_PROTOCOL
        ||!is_int($state['revision']??null)||!is_array($state['imports']??null)||!is_array($state['receipts']??null))
        sf_vp3_music_fail('storage_unavailable','VP3 connection storage needs recovery.',503);
    return $state;
}
function sf_vp3_music_write_state(array $state): void {
    $path=sf_vp3_music_state_path();$bytes=sf_vp3_music_json($state);
    if(strlen($bytes)>67108864)sf_vp3_music_fail('storage_unavailable','VP3 connection storage needs maintenance.',503);
    $tmp=$path.'.tmp.'.bin2hex(random_bytes(8));$handle=@fopen($tmp,'x');
    if(!$handle)sf_vp3_music_fail('storage_unavailable','VP3 connection changes could not be saved.',503,true);
    @chmod($tmp,0600);
    try{if(fwrite($handle,$bytes)!==strlen($bytes)||!fflush($handle))throw new RuntimeException('write');}
    catch(Throwable){fclose($handle);@unlink($tmp);sf_vp3_music_fail('storage_unavailable','VP3 connection changes could not be saved.',503,true);}
    fclose($handle);
    if(!rename($tmp,$path)){@unlink($tmp);sf_vp3_music_fail('storage_unavailable','VP3 connection changes could not be saved.',503,true);}
}
function sf_vp3_music_mutate(callable $callback): mixed {
    $dir=dirname(sf_vp3_music_state_path());
    if(is_link($dir)||(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)))
        sf_vp3_music_fail('storage_unavailable','VP3 connection storage is unavailable.',503);
    $lockPath=$dir.'/.writer.lock';
    if(is_link($lockPath))sf_vp3_music_fail('storage_unavailable','VP3 connection storage is unavailable.',503);
    $lock=@fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX))sf_vp3_music_fail('storage_unavailable','VP3 connection is busy.',503,true);
    try{$state=sf_vp3_music_read_state();$result=$callback($state);sf_vp3_music_write_state($state);return $result;}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sf_vp3_music_catalog_snapshot(): array {
    $path=SF_ROOT.'/data/catalog.json';$raw=@file_get_contents($path);
    if(!is_string($raw)||strlen($raw)>33554432)sf_vp3_music_fail('catalog_unavailable','The local catalog is unavailable.',503);
    $rows=json_decode($raw,true);
    if(!is_array($rows)||!array_is_list($rows))sf_vp3_music_fail('catalog_unavailable','The local catalog needs recovery.',503);
    return ['hash'=>hash('sha256',$raw),'rows'=>$rows];
}
function sf_vp3_music_connection_projection(array $state): array {
    $c=$state['connection']??null;
    if(!is_array($c))return ['configured'=>false,'revision'=>(int)$state['revision'],'state'=>'disconnected','credential_saved'=>false];
    return ['configured'=>true,'revision'=>(int)$state['revision'],'state'=>(string)($c['state']??'pending_verification'),
        'provider_url'=>(string)$c['provider_url'],'installation_id'=>(string)$c['installation_id'],
        'site_id'=>(string)$c['site_id'],'origin'=>(string)$c['origin'],'credential_saved'=>true,
        'verification_url'=>(string)$c['origin'].'/api/vp3-music-site.php',
        'selection_revision'=>(int)($c['selection_revision']??0),'environment'=>$c['environment']??null,
        'last_checked_at'=>$c['last_checked_at']??null];
}
function sf_vp3_music_pending_projection(array $state): ?array {
    $p=$state['pending']??null;
    return is_array($p)?['request_id'=>$p['request_id'],'state'=>$p['phase'],
        'recording_ids'=>$p['input']['recording_ids'],'remote_selection_may_have_changed'=>true]:null;
}
function sf_vp3_music_status(): array {
    $state=sf_vp3_music_read_state();
    return ['ok'=>true,'protocol'=>SF_VP3_MUSIC_PROTOCOL,'connection'=>sf_vp3_music_connection_projection($state),
        'catalog_hash'=>sf_vp3_music_catalog_snapshot()['hash'],'pending'=>sf_vp3_music_pending_projection($state),
        'last_import'=>$state['last_import']??null];
}
function sf_vp3_music_receipt(array $state,string $request,string $hash,int $actor): ?array {
    $prior=$state['receipts'][$request]??null;
    if(!is_array($prior))return null;
    if(!hash_equals((string)$prior['hash'],$hash)||(int)$prior['actor_id']!==$actor)
        sf_vp3_music_fail('request_conflict','This request identifier was already used for a different operation.',409);
    return $prior['result'];
}
function sf_vp3_music_import_receipt(array $state,string $request): ?array {
    $result=$state['receipts'][$request]['result']??null;
    return is_array($result)&&($result['request_id']??null)===$request
        &&in_array($result['status']??null,['complete','discarded'],true)?$result:null;
}
function sf_vp3_music_expect_revision(array $state,int $expected): void {
    if((int)$state['revision']!==$expected)sf_vp3_music_fail('connection_conflict','The connection changed. Refresh before continuing.',409);
}
function sf_vp3_music_active_connection(array $state): array {
    $c=$state['connection']??null;
    if(!is_array($c))sf_vp3_music_fail('connection_required','Connect this site to VP3 first.',409);
    return $c;
}
function sf_vp3_music_backend(array $connection,array $body,array $context=[]): array {
    require_once __DIR__.'/ai.php';
    try{$credential=sf_ai_decrypt((string)$connection['credential_payload']);}
    catch(Throwable){sf_vp3_music_fail('credential_unavailable','The saved connection cannot be unlocked. Reconnect to VP3.',503);}
    return sf_vp3_music_transport((string)$connection['provider_url'],'POST',
        ['protocol'=>SF_VP3_PROVIDER_PROTOCOL,'site_id'=>$connection['site_id']]+$body,$credential,$context);
}
function sf_vp3_music_validate_site(array $result,array $connection): array {
    $site=$result['site']??null;
    if(($result['installation_id']??$connection['installation_id'])!==$connection['installation_id']
        ||!is_array($site)||($site['site_id']??null)!==$connection['site_id']||($site['origin']??null)!==$connection['origin']
        ||($site['status']??null)!=='active'||!is_int($site['selection_revision']??null)||$site['selection_revision']<0
        ||!is_int($site['revision']??null)||$site['revision']<1||!is_int($site['verified_ms']??null)||$site['verified_ms']<1
        ||!in_array($site['environment']??null,['production','staging'],true))
        sf_vp3_music_fail('provider_identity_mismatch','The VP3 response does not match this verified site.',502);
    return ['site_id'=>$connection['site_id'],'origin'=>$connection['origin'],'status'=>'active',
        'revision'=>$site['revision'],'selection_revision'=>$site['selection_revision'],
        'verified_ms'=>$site['verified_ms'],'environment'=>$site['environment']];
}
function sf_vp3_music_ids(mixed $raw): array {
    if(!is_array($raw)||!array_is_list($raw)||count($raw)>500)sf_vp3_music_fail('invalid_selection','Choose up to 500 recordings.');
    $ids=array_map('sf_vp3_music_uuid',$raw);
    if(count($ids)!==count(array_unique($ids)))sf_vp3_music_fail('invalid_selection','Choose each recording only once.');
    sort($ids,SORT_STRING);return $ids;
}
function sf_vp3_music_public_item(array $item,array $connection): array {
    $id=sf_vp3_music_uuid($item['recording_id']??null);
    $availability=$item['availability']??'';
    if(!in_array($availability,['ready','temporarily_unavailable','unavailable'],true))sf_vp3_music_fail('provider_response_invalid','A VP3 catalog entry is invalid.',502);
    if($availability==='unavailable')return ['recording_id'=>$id,'availability'=>'unavailable','selected'=>!empty($item['selected'])];
    $m=$item['metadata']??null;if(!is_array($m))sf_vp3_music_fail('provider_response_invalid','VP3 metadata is unavailable.',502);
    $metadata=[];
    foreach(['title','album','recording_version','isrc','isrc_status','work_title','iswc','iswc_status','identifier_validation','language','explicit_content','copyright_notice','phonogram_notice'] as $key)
        $metadata[$key]=sf_vp3_music_text($m[$key]??'',in_array($key,['copyright_notice','phonogram_notice'],true)?500:240);
    foreach(['writers','publishers','performers'] as $kind){
        $metadata[$kind]=[];
        foreach(array_slice(is_array($m[$kind]??null)?$m[$kind]:[],0,100) as $row)
            if(is_array($row))$metadata[$kind][]=['name'=>sf_vp3_music_text($row['name']??'',160),'role'=>sf_vp3_music_text($row['role']??'',80)];
    }
    $metadata['label']=['name'=>sf_vp3_music_text($m['label']['name']??'',160)];
    if($metadata['title']==='')sf_vp3_music_fail('provider_response_invalid','A VP3 recording title is unavailable.',502);
    $artwork=null;
    if(is_array($item['artwork']??null)){
        $url=substr($connection['provider_url'],0,-strlen('music-provider-v1.php')).'music-provider-artwork-v1.php?site_id='.rawurlencode($connection['site_id']).'&recording_id='.rawurlencode($id);
        if(($item['artwork']['url']??null)!==$url)sf_vp3_music_fail('provider_response_invalid','VP3 returned an unexpected artwork address.',502);
        $artwork=['url'=>$url,'alt'=>sf_vp3_music_text($item['artwork']['alt']??'',240)];
    }
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($item['catalog_revision']??'')))sf_vp3_music_fail('provider_response_invalid','VP3 catalog revision is invalid.',502);
    return ['recording_id'=>$id,'work_id'=>sf_vp3_music_uuid($item['work_id']??null),'asset_id'=>sf_vp3_music_uuid($item['asset_id']??null),
        'metadata_revision'=>sf_vp3_music_int($item['metadata_revision']??null,1),'work_revision'=>sf_vp3_music_int($item['work_revision']??null,1),
        'catalog_revision'=>$item['catalog_revision'],'album_id'=>isset($item['album_id'])?sf_vp3_music_int($item['album_id'],1):null,
        'track_number'=>sf_vp3_music_int($item['track_number']??0,0,100000),'duration_hint_seconds'=>isset($item['duration_hint_seconds'])?sf_vp3_music_int($item['duration_hint_seconds'],1,604800):null,
        'availability'=>$availability,'availability_reason'=>$availability==='ready'?null:'audio_requires_repair',
        'metadata'=>$metadata,'artwork'=>$artwork,'selected'=>!empty($item['selected'])];
}

function sf_vp3_music_connect(array $input,int $actor,array $context): array {
    sf_vp3_music_fields($input,['action','request_id','expected_revision','provider_url','credential','verification']);
    $request=sf_vp3_music_uuid($input['request_id']??null);$expected=sf_vp3_music_int($input['expected_revision']??null);
    $url=sf_vp3_music_provider_url($input['provider_url']??null);$credential=$input['credential']??null;
    if(!is_string($credential)||!preg_match('/^vmp1_[a-f0-9]{64}$/D',$credential))sf_vp3_music_fail('invalid_credential','Paste the VP3 music-site credential.');
    $proof=$input['verification']??null;
    if(!is_array($proof)||array_diff(array_keys($proof),['ok','protocol','site_id','origin','challenge'])
        ||($proof['ok']??null)!==true||($proof['protocol']??null)!=='vp3_music_site_v1'
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)($proof['challenge']??'')))
        sf_vp3_music_fail('invalid_verification','Paste the verification response supplied by VP3.');
    $proof=['ok'=>true,'protocol'=>'vp3_music_site_v1','site_id'=>sf_vp3_music_uuid($proof['site_id']??null),
        'origin'=>sf_vp3_music_origin($proof['origin']??null),'challenge'=>$proof['challenge']];
    if(isset($context['site_base_url'])){
        try{$localOrigin=sf_vp3_music_origin($context['site_base_url']);}
        catch(SfVp3MusicException){sf_vp3_music_fail('unsupported_site_address','Set this Stoneward installation to its root HTTPS address before connecting to VP3.');}
        if(!hash_equals($localOrigin,$proof['origin']))
            sf_vp3_music_fail('site_origin_mismatch','This verification response belongs to a different artist site. Register this Stoneward HTTPS origin in VP3.');
    }
    $hash=hash('sha256',sf_vp3_music_json(['action'=>'connect','expected'=>$expected,'provider'=>$url,'credential_hash'=>hash('sha256',$credential),'proof'=>$proof]));
    $state=sf_vp3_music_read_state();if($prior=sf_vp3_music_receipt($state,$request,$hash,$actor))return $prior;
    sf_vp3_music_expect_revision($state,$expected);
    if($state['pending'])sf_vp3_music_fail('import_pending','Finish or explicitly discard the pending import before reconnecting.',409);
    $discovery=sf_vp3_music_transport($url,'GET',null,null,$context);
    if(($discovery['api_url']??null)!==$url||!in_array(SF_VP3_PROVIDER_PROTOCOL,(array)($discovery['supported_protocols']??[]),true)
        ||($discovery['site_verification']['path']??null)!=='/api/vp3-music-site.php'
        ||array_diff(['catalog.read','selection.update','import.manifest','playback.begin','playback.events','playback.renew'],(array)($discovery['capabilities']??[])))
        sf_vp3_music_fail('provider_protocol_mismatch','The VP3 provider does not support this connection.',502);
    $installation=sf_vp3_music_uuid($discovery['installation_id']??null);
    return sf_vp3_music_mutate(function(array &$state)use($request,$expected,$url,$credential,$proof,$hash,$actor,$installation):array{
        if($prior=sf_vp3_music_receipt($state,$request,$hash,$actor))return $prior;
        sf_vp3_music_expect_revision($state,$expected);
        if($state['pending'])sf_vp3_music_fail('import_pending','Finish or discard the pending import first.',409);
        require_once __DIR__.'/ai.php';
        $state['revision']++;
        $state['connection']=['provider_url'=>$url,'installation_id'=>$installation,'site_id'=>$proof['site_id'],'origin'=>$proof['origin'],
            'verification'=>$proof,'credential_payload'=>sf_ai_encrypt($credential),'state'=>'pending_verification','selection_revision'=>0,
            'recording_ids'=>[],'created_at'=>gmdate('c'),'updated_by'=>$actor];
        $result=['ok'=>true,'protocol'=>SF_VP3_MUSIC_PROTOCOL,'connection'=>sf_vp3_music_connection_projection($state),
            'message'=>'Connection saved. Choose Verify site in VP3, then check this connection.'];
        $state['receipts'][$request]=['hash'=>$hash,'actor_id'=>$actor,'result'=>$result];return $result;
    });
}

function sf_vp3_music_catalog(array $input,array $context): array {
    sf_vp3_music_fields($input,['action','offset','search']);
    $offset=sf_vp3_music_int($input['offset']??0,0,100000);$search=sf_vp3_music_text($input['search']??'',100);
    $state=sf_vp3_music_read_state();$revision=$state['revision'];$c=sf_vp3_music_active_connection($state);
    try{$raw=sf_vp3_music_backend($c,['action'=>'catalog.read','offset'=>$offset,'limit'=>50,'search'=>$search],$context);}
    catch(SfVp3MusicException $error){
        if($error->publicCode==='connection_unavailable')sf_vp3_music_mutate(function(array &$latest)use($revision):void{
            if($latest['revision']===$revision&&is_array($latest['connection']))$latest['connection']['state']='unavailable';
        });
        throw $error;
    }
    if(($raw['installation_id']??null)!==$c['installation_id'])sf_vp3_music_fail('provider_identity_mismatch','The VP3 catalog does not match this installation.',502);
    $site=sf_vp3_music_validate_site($raw,$c);$ids=sf_vp3_music_ids($raw['recording_ids']??null);
    if(!is_array($raw['items']??null)||!array_is_list($raw['items'])||count($raw['items'])>50)sf_vp3_music_fail('provider_response_invalid','VP3 catalog page is invalid.',502);
    $items=[];foreach($raw['items'] as $item){if(!is_array($item))sf_vp3_music_fail('provider_response_invalid','VP3 catalog entry is invalid.',502);$items[]=sf_vp3_music_public_item($item,$c);}
    $next=$raw['next_offset']??null;if($next!==null&&(!is_int($next)||$next!==$offset+50))sf_vp3_music_fail('provider_response_invalid','VP3 catalog pagination is invalid.',502);
    return sf_vp3_music_mutate(function(array &$latest)use($revision,$site,$ids,$items,$next):array{
        sf_vp3_music_expect_revision($latest,$revision);
        $latest['connection']['state']='ready';$latest['connection']['selection_revision']=$site['selection_revision'];
        $latest['connection']['recording_ids']=$ids;$latest['connection']['environment']=$site['environment']??null;
        $latest['connection']['last_checked_at']=gmdate('c');
        return ['ok'=>true,'protocol'=>SF_VP3_MUSIC_PROTOCOL,'connection'=>sf_vp3_music_connection_projection($latest),
            'items'=>$items,'recording_ids'=>$ids,'selection_revision'=>$site['selection_revision'],'next_offset'=>$next,
            'catalog_hash'=>sf_vp3_music_catalog_snapshot()['hash']];
    });
}

/** Preserve any field locally edited since its last imported value. */
function sf_vp3_music_merge_managed(array $current,array $baseline,array $incoming): array {
    foreach($incoming as $key=>$value){
        if(is_array($value)&&!array_is_list($value)&&is_array($current[$key]??null))
            $current[$key]=sf_vp3_music_merge_managed($current[$key],is_array($baseline[$key]??null)?$baseline[$key]:[],$value);
        elseif(!array_key_exists($key,$current)||(array_key_exists($key,$baseline)&&$current[$key]===$baseline[$key]))$current[$key]=$value;
    }
    return $current;
}
function sf_vp3_music_managed_fields(array $item): array {
    $m=$item['metadata'];$names=static fn(array $rows):string=>implode(', ',array_filter(array_column($rows,'name')));
    $credits=[];foreach(['writers'=>'Written by','performers'=>'Performed by','publishers'=>'Published by'] as $key=>$label)
        foreach($m[$key] as $party)if($party['name']!=='')$credits[]=$label.' '.$party['name'];
    return ['title'=>$m['title'],'release'=>$m['album'],'duration'=>(int)($item['duration_hint_seconds']??0),
        'artwork'=>$item['artwork']['url']??'','credits'=>$credits,
        'metadata'=>['isrc'=>$m['isrc'],'words_by'=>$names($m['writers']),'music_by'=>$names($m['writers']),
            'performing_artist'=>$names($m['performers']),'publisher'=>$names($m['publishers']),'label'=>$m['label']['name'],
            'language'=>$m['language'],'composition_copyright'=>$m['copyright_notice'],'master_copyright'=>$m['phonogram_notice']]];
}
function sf_vp3_music_stage(array $catalog,array $imports,array $items,array $c,array $ids): array {
    $index=[];foreach($catalog as $i=>$track){if(!is_array($track)||!is_string($track['id']??null))sf_vp3_music_fail('catalog_unavailable','The local catalog contains an invalid track.',503);$index[$track['id']]=$i;}
    $added=0;$updated=0;$unavailable=0;$tracks=[];
    foreach($catalog as &$track){$s=$track['audio_source']??null;if(is_array($s)&&($s['kind']??null)==='vp3'&&($s['installation_id']??null)===$c['installation_id'])
        $track['vp3_availability']=in_array($s['recording_id']??null,$ids,true)?'pending_refresh':'not_selected';}unset($track);
    foreach($items as $item){
        $recording=$item['recording_id'];$key=$c['installation_id'].':'.$recording;$known=$imports[$key]??null;
        $local=is_array($known)?(string)$known['track_id']:'';
        if($local===''||!isset($index[$local])){
            $matches=[];foreach($catalog as $candidate){$s=$candidate['audio_source']??[];
                if(($s['kind']??null)==='vp3'&&($s['installation_id']??null)===$c['installation_id']&&($s['recording_id']??null)===$recording)$matches[]=$candidate['id'];}
            if(count($matches)>1)sf_vp3_music_fail('catalog_identity_conflict','This VP3 recording has multiple local catalog identities.',409);
            $local=$matches[0]??('vp3-'.substr(hash('sha256',$c['installation_id'].':'.$recording),0,32));
        }
        $existing=isset($index[$local]);$track=$existing?$catalog[$index[$local]]:['id'=>$local,'year'=>0,'mood'=>[],'themes'=>[],'energy'=>3,'story'=>'','state'=>'published','public_visible'=>true];
        if($existing){$source=$track['audio_source']??[];if(($source['kind']??null)!=='vp3'||($source['installation_id']??null)!==$c['installation_id']||($source['recording_id']??null)!==$recording)
            sf_vp3_music_fail('catalog_identity_conflict','A local song already uses this imported identity.',409);}
        if($item['availability']!=='ready'){
            $unavailable++;if($existing){$track['vp3_availability']=$item['availability'];$catalog[$index[$local]]=$track;}
            if(is_array($known)){$known['availability']=$item['availability'];$imports[$key]=$known;}
            $tracks[]=['id'=>$existing?$local:null,'recording_id'=>$recording,'status'=>'unavailable'];continue;
        }
        $managed=sf_vp3_music_managed_fields($item);
        $track=sf_vp3_music_merge_managed($track,is_array($known['baseline']??null)?$known['baseline']:[],$managed);
        $track['audio']='';$track['audio_source']=['kind'=>'vp3','installation_id'=>$c['installation_id'],'site_id'=>$c['site_id'],'recording_id'=>$recording];
        $track['vp3_availability']='ready';$track['audio_publishing_mode']='vp3_stream_only';
        $track['digital_purchase_enabled']=false;$track['podEligible']=false;$track['price']=0;$track['vinyl_license_packages']=[];
        unset($track['private_master_audio']);
        if($existing){$catalog[$index[$local]]=$track;$updated++;}else{$index[$local]=count($catalog);$catalog[]=$track;$added++;}
        $imports[$key]=['track_id'=>$local,'baseline'=>$managed,'availability'=>'ready','metadata_revision'=>$item['metadata_revision'],
            'work_revision'=>$item['work_revision'],'catalog_revision'=>$item['catalog_revision'],'updated_at'=>gmdate('c')];
        $tracks[]=['id'=>$local,'recording_id'=>$recording,'status'=>$existing?'updated':'imported'];
    }
    return ['catalog'=>array_values($catalog),'imports'=>$imports,'result'=>['imported_count'=>$added,'updated_count'=>$updated,'unavailable_count'=>$unavailable,'tracks'=>$tracks]];
}
function sf_vp3_music_catalog_bytes(array $rows): string {
    return json_encode(array_values($rows),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
function sf_vp3_music_public_catalog_bytes(array $rows): string {
    if(!function_exists('sf_songbook_public_catalog_rows'))require_once __DIR__.'/songbook-core.php';
    return 'window.STONEFELLOW_CATALOG = '.sf_vp3_music_catalog_bytes(sf_songbook_public_catalog_rows($rows)).";\n";
}
function sf_vp3_music_manifest(array $c,array $selection,array $context): array {
    $ids=sf_vp3_music_ids($selection['recording_ids']??null);$expectedSite=sf_vp3_music_validate_site($selection,$c);
    $items=[];$offset=0;
    do{
        $page=sf_vp3_music_backend($c,['action'=>'import.manifest','offset'=>$offset,'limit'=>50],$context);
        if(($page['installation_id']??null)!==$c['installation_id'])sf_vp3_music_fail('provider_identity_mismatch','The VP3 import does not match this installation.',502);
        $site=sf_vp3_music_validate_site($page,$c);
        if($site['revision']!==$expectedSite['revision']||$site['selection_revision']!==$expectedSite['selection_revision']||sf_vp3_music_ids($page['recording_ids']??null)!==$ids)
            sf_vp3_music_fail('provider_conflict','The VP3 selection changed during import. Review it again.',409);
        if(!is_array($page['items']??null)||!array_is_list($page['items'])||count($page['items'])>50)
            sf_vp3_music_fail('provider_response_invalid','VP3 returned an invalid import page.',502);
        foreach($page['items'] as $raw){if(!is_array($raw))sf_vp3_music_fail('provider_response_invalid','VP3 returned an invalid import entry.',502);
            $item=sf_vp3_music_public_item($raw,$c);if(isset($items[$item['recording_id']]))sf_vp3_music_fail('provider_response_invalid','VP3 repeated an import entry.',502);$items[$item['recording_id']]=$item;}
        $next=$page['next_offset']??null;
        if($next!==null&&(!is_int($next)||$next!==$offset+count($page['items'])||$next<=$offset||$next>=count($ids)))
            sf_vp3_music_fail('provider_response_invalid','VP3 import pagination is invalid.',502);
        $offset=$next;
    }while($offset!==null);
    $actual=array_keys($items);sort($actual,SORT_STRING);
    if($actual!==$ids)sf_vp3_music_fail('provider_response_invalid','VP3 returned an incomplete import selection.',502);
    return array_values($items);
}

function sf_vp3_music_continue_import(string $request,int $revision,array $context): array {
    $state=sf_vp3_music_read_state();
    if($done=sf_vp3_music_import_receipt($state,$request))return $done;
    sf_vp3_music_expect_revision($state,$revision);
    $p=$state['pending']??null;
    if(!is_array($p)||$p['request_id']!==$request){
        sf_vp3_music_fail('import_not_found','This pending import is no longer available.',409);
    }
    if($p['connection_revision']!==$revision)sf_vp3_music_fail('connection_conflict','The connection changed. Discard and review the import again.',409);
    $c=sf_vp3_music_active_connection($state);
    if($p['phase']==='selection_pending'){
        $selection=sf_vp3_music_backend($c,['action'=>'selection.update','request_id'=>$request,
            'expected_selection_revision'=>$p['input']['expected_selection_revision'],'recording_ids'=>$p['input']['recording_ids']],$context);
        $site=sf_vp3_music_validate_site($selection,$c);
        if(sf_vp3_music_ids($selection['recording_ids']??null)!==$p['input']['recording_ids'])sf_vp3_music_fail('provider_response_invalid','VP3 selection receipt does not match the approved import.',502);
        // Retain only the receipt fields needed for recovery, never arbitrary provider extras or media tickets.
        $selection=['ok'=>true,'protocol'=>SF_VP3_PROVIDER_PROTOCOL,'installation_id'=>$c['installation_id'],
            'site'=>$site,'recording_ids'=>$p['input']['recording_ids']];
        $done=sf_vp3_music_mutate(function(array &$latest)use($request,$revision,$selection):?array{
            if($receipt=sf_vp3_music_import_receipt($latest,$request))return $receipt;
            sf_vp3_music_expect_revision($latest,$revision);
            if(($latest['pending']['request_id']??null)!==$request)sf_vp3_music_fail('import_conflict','The pending import changed.',409);
            if($latest['pending']['phase']==='selection_pending'){$latest['pending']['phase']='selected';$latest['pending']['selection']=$selection;}
            return null;
        });
        if($done!==null)return $done;
        $state=sf_vp3_music_read_state();$p=$state['pending'];
        if($done=sf_vp3_music_import_receipt($state,$request))return $done;
        if(!is_array($p)||$p['request_id']!==$request){
            sf_vp3_music_fail('import_conflict','The pending import changed.',409);
        }
    }
    if($p['phase']==='selected'){
        $items=sf_vp3_music_manifest($c,$p['selection'],$context);
        $done=sf_vp3_music_mutate(function(array &$latest)use($request,$revision,$items,$c):?array{
            if($receipt=sf_vp3_music_import_receipt($latest,$request))return $receipt;
            sf_vp3_music_expect_revision($latest,$revision);$pending=&$latest['pending'];
            if(!is_array($pending)||$pending['request_id']!==$request)sf_vp3_music_fail('import_conflict','The pending import changed.',409);
            if($pending['phase']!=='selected')return null;
            $snapshot=sf_vp3_music_catalog_snapshot();
            if(!hash_equals($pending['input']['catalog_hash'],$snapshot['hash']))sf_vp3_music_fail('catalog_conflict','The local catalog changed. Discard this pending import, then refresh and review again.',409);
            $stage=sf_vp3_music_stage($snapshot['rows'],$latest['imports'],$items,$c,$pending['input']['recording_ids']);
            $stage['source_hash']=$snapshot['hash'];$stage['target_hash']=hash('sha256',sf_vp3_music_catalog_bytes($stage['catalog']));
            $stage['public_hash']=hash('sha256',sf_vp3_music_public_catalog_bytes($stage['catalog']));
            $pending['stage']=$stage;$pending['phase']='staged';return null;
        });
        if($done!==null)return $done;
    }
    return sf_vp3_music_mutate(function(array &$latest)use($request,$revision):array{
        if($receipt=sf_vp3_music_import_receipt($latest,$request))return $receipt;
        sf_vp3_music_expect_revision($latest,$revision);$p=$latest['pending']??null;
        if(!is_array($p)||$p['request_id']!==$request)sf_vp3_music_fail('import_conflict','The pending import changed.',409);
        if($p['phase']!=='staged')sf_vp3_music_fail('import_pending','The import is still pending. Retry the saved request.',409,true);
        $stage=$p['stage'];$snapshot=sf_vp3_music_catalog_snapshot();
        if(!in_array($snapshot['hash'],[$stage['source_hash'],$stage['target_hash']],true))
            sf_vp3_music_fail('catalog_conflict','The local catalog changed. Discard this pending import, then refresh and review again.',409);
        if(!hash_equals($stage['public_hash'],hash('sha256',sf_vp3_music_public_catalog_bytes($stage['catalog']))))
            sf_vp3_music_fail('catalog_conflict','Catalog publication rules changed. Discard this import and review again.',409);
        if(!function_exists('sf_admin_write_catalog'))require_once SF_ROOT.'/admin/api/bootstrap.php';
        set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
        try{sf_admin_write_catalog($stage['catalog'],$snapshot['hash']);}
        catch(Throwable){sf_vp3_music_fail('import_pending','Catalog publication was interrupted. Retry this saved import to recover.',503,true);}
        finally{restore_error_handler();}
        if(sf_vp3_music_catalog_snapshot()['hash']!==$stage['target_hash']
            ||hash_file('sha256',SF_ROOT.'/assets/js/catalog.js')!==$stage['public_hash'])
            sf_vp3_music_fail('import_pending','Catalog publication needs recovery. Retry the saved import.',503,true);
        $latest['imports']=$stage['imports'];$latest['connection']['recording_ids']=$p['input']['recording_ids'];
        $latest['connection']['selection_revision']=$p['selection']['site']['selection_revision'];$latest['connection']['state']='ready';
        $result=['ok'=>true,'protocol'=>SF_VP3_MUSIC_PROTOCOL,'status'=>'complete','request_id'=>$request,
            'connection'=>sf_vp3_music_connection_projection($latest),'catalog_hash'=>$stage['target_hash'],
            'selection_revision'=>$p['selection']['site']['selection_revision']]+$stage['result'];
        $latest['receipts'][$request]=['hash'=>$p['hash'],'actor_id'=>$p['actor_id'],'result'=>$result];
        $latest['last_import']=$result;$latest['pending']=null;
        if(function_exists('sf_catalog'))sf_catalog(true);
        return $result;
    });
}

function sf_vp3_music_import(array $input,int $actor,array $context): array {
    sf_vp3_music_fields($input,['action','request_id','expected_revision','expected_selection_revision','catalog_hash','recording_ids']);
    $request=sf_vp3_music_uuid($input['request_id']??null);$revision=sf_vp3_music_int($input['expected_revision']??null);
    $selectionRevision=sf_vp3_music_int($input['expected_selection_revision']??null);$ids=sf_vp3_music_ids($input['recording_ids']??null);
    $catalogHash=$input['catalog_hash']??null;if(!is_string($catalogHash)||!preg_match('/^[a-f0-9]{64}$/D',$catalogHash))sf_vp3_music_fail('invalid_request','Refresh the local catalog before importing.');
    $normalized=['action'=>'import','expected_revision'=>$revision,'expected_selection_revision'=>$selectionRevision,'catalog_hash'=>$catalogHash,'recording_ids'=>$ids];
    $hash=hash('sha256',sf_vp3_music_json($normalized));
    $early=sf_vp3_music_mutate(function(array &$state)use($request,$revision,$hash,$actor,$normalized,$catalogHash):?array{
        if($prior=sf_vp3_music_receipt($state,$request,$hash,$actor))return $prior;
        sf_vp3_music_expect_revision($state,$revision);$c=sf_vp3_music_active_connection($state);
        if(($c['state']??null)!=='ready')sf_vp3_music_fail('connection_unavailable','Check the connection before importing.',409);
        if(is_array($state['pending'])){
            if($state['pending']['request_id']!==$request||!hash_equals($state['pending']['hash'],$hash)||(int)$state['pending']['actor_id']!==$actor)
                sf_vp3_music_fail('import_pending','Finish or discard the saved pending import first.',409);
            return null;
        }
        if(!hash_equals(sf_vp3_music_catalog_snapshot()['hash'],$catalogHash))sf_vp3_music_fail('catalog_conflict','The local catalog changed. Refresh before importing.',409);
        $state['pending']=['request_id'=>$request,'hash'=>$hash,'actor_id'=>$actor,'connection_revision'=>$revision,
            'phase'=>'selection_pending','input'=>$normalized,'created_at'=>gmdate('c')];return null;
    });
    return $early??sf_vp3_music_continue_import($request,$revision,$context);
}

function sf_vp3_music_action(array $input,int $actorId,array $context=[]): array {
    if($actorId<1)sf_vp3_music_fail('forbidden','Administrator access is required.',403);
    $action=$input['action']??null;
    if($action==='connect')return sf_vp3_music_connect($input,$actorId,$context);
    if($action==='catalog')return sf_vp3_music_catalog($input,$context);
    if($action==='import')return sf_vp3_music_import($input,$actorId,$context);
    if($action==='resume'){
        sf_vp3_music_fields($input,['action','request_id','expected_revision']);
        return sf_vp3_music_continue_import(sf_vp3_music_uuid($input['request_id']??null),sf_vp3_music_int($input['expected_revision']??null),$context);
    }
    if($action==='disconnect'){
        sf_vp3_music_fields($input,['action','request_id','expected_revision']);
        $id=sf_vp3_music_uuid($input['request_id']??null);$expected=sf_vp3_music_int($input['expected_revision']??null);
        $hash=hash('sha256',sf_vp3_music_json(['action'=>'disconnect','expected_revision'=>$expected]));
        return sf_vp3_music_mutate(function(array &$state)use($id,$expected,$hash,$actorId):array{
            if($prior=sf_vp3_music_receipt($state,$id,$hash,$actorId))return $prior;
            if(($state['pending']['request_id']??null)===$id)sf_vp3_music_fail('request_conflict','Use a new request identifier to disconnect.',409);
            sf_vp3_music_expect_revision($state,$expected);$state['connection']=null;$state['revision']++;
            $result=['ok'=>true,'protocol'=>SF_VP3_MUSIC_PROTOCOL,'connection'=>sf_vp3_music_connection_projection($state),
                'remote_revoked'=>false,'message'=>'Disconnected on this site. Revoke its registration in VP3 to stop all existing remote grants.'];
            $state['receipts'][$id]=['hash'=>$hash,'actor_id'=>$actorId,'result'=>$result];return $result;
        });
    }
    if($action==='discard'){
        sf_vp3_music_fields($input,['action','request_id','expected_revision','confirmed']);
        $id=sf_vp3_music_uuid($input['request_id']??null);$expected=sf_vp3_music_int($input['expected_revision']??null);
        if(($input['confirmed']??null)!==true)sf_vp3_music_fail('confirmation_required','Confirm discarding this pending import.');
        return sf_vp3_music_mutate(function(array &$state)use($id,$expected):array{
            sf_vp3_music_expect_revision($state,$expected);$p=$state['pending']??null;
            if(!is_array($p)||$p['request_id']!==$id){$result=$state['receipts'][$id]['result']??null;if(is_array($result))return $result;sf_vp3_music_fail('import_not_found','Pending import not found.',409);}
            $result=['ok'=>true,'protocol'=>SF_VP3_MUSIC_PROTOCOL,'status'=>'discarded','request_id'=>$id,
                'remote_selection_may_have_changed'=>true,'message'=>'Pending local import discarded. Refresh the VP3 selection before importing again.'];
            $state['receipts'][$id]=['hash'=>$p['hash'],'actor_id'=>$p['actor_id'],'result'=>$result];$state['pending']=null;return $result;
        });
    }
    sf_vp3_music_fail('unsupported_action','Choose a supported VP3 music action.');
}

/** Public fixed proof contains only the configured challenge, never a reflected request or credential. */
function sf_vp3_music_site_proof(): ?array {
    $state=sf_vp3_music_read_state();$c=$state['connection']??null;
    return is_array($c)&&is_array($c['verification']??null)?[
        'ok'=>true,'protocol'=>'vp3_music_site_v1','site_id'=>$c['site_id'],'origin'=>$c['origin'],
        'challenge'=>$c['verification']['challenge']]:null;
}
/** Called only after the caller's normal catalog/canvas scope authorization. No personal identity crosses to VP3. */
function sf_vp3_music_source_for_track(array $track): ?array {
    if(!function_exists('sf_track_is_public')||!sf_track_is_public($track))return null;
    $s=$track['audio_source']??null;if(!is_array($s)||($s['kind']??null)!=='vp3')return null;
    $state=sf_vp3_music_read_state();$c=$state['connection']??null;
    if(!is_array($c)||($c['state']??null)!=='ready'||($s['installation_id']??null)!==$c['installation_id']
        ||($s['site_id']??null)!==$c['site_id']||!in_array($s['recording_id']??null,(array)($c['recording_ids']??[]),true))return null;
    $import=$state['imports'][$c['installation_id'].':'.($s['recording_id']??'')]??null;
    if(!is_array($import)||($import['track_id']??null)!==($track['id']??null)||($import['availability']??null)!=='ready'
        ||($track['vp3_availability']??'ready')!=='ready')return null;
    try{
        $api=sf_vp3_music_provider_url($c['provider_url']??null);
        if($api!==($c['provider_url']??null))return null;
        return ['kind'=>'vp3','provider_api'=>$api,'installation_id'=>sf_vp3_music_uuid($c['installation_id']),
            'site_id'=>sf_vp3_music_uuid($c['site_id']),'recording_id'=>sf_vp3_music_uuid($s['recording_id'])];
    }catch(SfVp3MusicException){return null;}
}
