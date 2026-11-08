<?php
declare(strict_types=1);

// Browser capabilities are private to their account and persistent sign-in session.
function sf_web_push_ensure_schema(): void {
    static $ready=false;
    if($ready) return;
    sf_auth_ensure_schema();
    $db=sf_db();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS web_push_subscriptions (
            id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, session_hash TEXT NOT NULL UNIQUE, endpoint TEXT NOT NULL,
            p256dh TEXT NOT NULL, auth TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(session_hash) REFERENCES user_sessions(token_hash) ON DELETE CASCADE)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_web_push_user ON web_push_subscriptions(user_id)');
    }else{
        $db->exec('CREATE TABLE IF NOT EXISTS web_push_subscriptions (
            id CHAR(64) NOT NULL PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, session_hash CHAR(64) NOT NULL UNIQUE,
            endpoint VARCHAR(2048) NOT NULL, p256dh VARCHAR(87) NOT NULL, auth VARCHAR(22) NOT NULL,
            INDEX idx_web_push_user(user_id),
            CONSTRAINT fk_web_push_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_web_push_session FOREIGN KEY(session_hash) REFERENCES user_sessions(token_hash) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    $ready=true;
}

function sf_web_push_endpoint_origin(string $endpoint): ?string {
    // Treat client-provided endpoints as capabilities, not general-purpose URLs.
    if(strlen($endpoint)>2048 || preg_match('/[^\x21-\x7e]|[\\\\#]/',$endpoint)) return null;
    if(!preg_match('~\Ahttps://([a-z0-9.-]+)/[^/].*\z~D',$endpoint,$parts)) return null;
    $host=$parts[1];
    if(strlen($host)>253) return null;
    $browserProvider=in_array($host,['fcm.googleapis.com','updates.push.services.mozilla.com'],true);
    $regionalProvider=preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:push\.apple\.com|notify\.windows\.com)\z/D',$host);
    return $browserProvider || $regionalProvider ? 'https://'.$host : null;
}

function sf_web_push_b64(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
}

function sf_web_push_unb64(mixed $value,int $length): ?string {
    if(!is_string($value) || strlen($value)!==(int)ceil($length*8/6) || !preg_match('/\A[A-Za-z0-9_-]+\z/D',$value)) return null;
    $bytes=base64_decode(strtr($value,'-_','+/'),true);
    return is_string($bytes) && strlen($bytes)===$length && sf_web_push_b64($bytes)===$value ? $bytes : null;
}

function sf_web_push_public_key(string $point): OpenSSLAsymmetricKey|false {
    if(strlen($point)!==65 || $point[0]!=="\x04") return false;
    // SubjectPublicKeyInfo for an uncompressed prime256v1 point.
    $der=hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n");
}

function sf_web_push_point(OpenSSLAsymmetricKey $key): string {
    $details=openssl_pkey_get_details($key);
    if(($details['ec']['curve_name']??'')!=='prime256v1') throw new RuntimeException('Web Push requires a P-256 key.');
    return "\x04".str_pad($details['ec']['x'],32,"\x00",STR_PAD_LEFT).str_pad($details['ec']['y'],32,"\x00",STR_PAD_LEFT);
}

function sf_web_push_generate_key(): OpenSSLAsymmetricKey {
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
    if($key===false) throw new RuntimeException('Could not generate a Web Push key.');
    return $key;
}

function sf_web_push_supported(): bool {
    return function_exists('curl_init') && function_exists('openssl_pkey_derive') && function_exists('openssl_encrypt');
}

function sf_web_push_vapid_key(): OpenSSLAsymmetricKey {
    static $key=null;
    if($key!==null) return $key;

    $dir=SF_ROOT.'/storage';
    if(!is_dir($dir) && !@mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('Web Push storage is unavailable.');
    $path=$dir.'/web-push-vapid.php';
    if(!is_file($path)){
        // Serialize first-time creation and publish a complete private file atomically.
        $lock=@fopen($dir.'/web-push-vapid.lock','c');
        if($lock===false) throw new RuntimeException('Web Push storage is unavailable.');
        try{
            if(!flock($lock,LOCK_EX)) throw new RuntimeException('Could not lock Web Push storage.');
            clearstatcache(true,$path);
            if(!is_file($path)){
                $created=sf_web_push_generate_key();
                if(!openssl_pkey_export($created,$pem)) throw new RuntimeException('Could not export a Web Push key.');
                $temp=tempnam($dir,'web-push-');
                if($temp===false) throw new RuntimeException('Web Push storage is unavailable.');
                try{
                    $file="<?php\nreturn ".var_export($pem,true).";\n";
                    if(dirname($temp)!==$dir || !chmod($temp,0600) || file_put_contents($temp,$file)!==strlen($file) || !rename($temp,$path)){
                        throw new RuntimeException('Could not save the Web Push key.');
                    }
                }finally{
                    if(is_file($temp)) @unlink($temp);
                }
            }
        }finally{
            fclose($lock);
        }
    }
    $pem=require $path;
    $stored=is_string($pem)?openssl_pkey_get_private($pem):false;
    if($stored===false) throw new RuntimeException('The stored Web Push key is invalid.');
    sf_web_push_point($stored);
    return $key=$stored;
}

function sf_web_push_validate_subscription(array $subscription): array {
    $endpoint=$subscription['endpoint']??null;
    if(!is_string($endpoint) || sf_web_push_endpoint_origin($endpoint)===null){
        throw new InvalidArgumentException('This browser push service is unsupported.');
    }
    $keys=$subscription['keys']??null;
    $publicKey=is_array($keys)?sf_web_push_unb64($keys['p256dh']??null,65):null;
    $auth=is_array($keys)?sf_web_push_unb64($keys['auth']??null,16):null;
    if($publicKey===null || $auth===null || sf_web_push_public_key($publicKey)===false){
        throw new InvalidArgumentException('This browser provided an invalid push subscription.');
    }
    return ['id'=>hash('sha256',$endpoint),'endpoint'=>$endpoint,'p256dh'=>sf_web_push_b64($publicKey),'auth'=>sf_web_push_b64($auth)];
}

function sf_web_push_subscribe(int $userId,string $sessionHash,array $subscription): void {
    $device=sf_web_push_validate_subscription($subscription);
    sf_web_push_ensure_schema();
    $db=sf_db();
    $sql='INSERT INTO web_push_subscriptions(id,user_id,session_hash,endpoint,p256dh,auth) VALUES(?,?,?,?,?,?)';
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $sql.=' ON CONFLICT(id) DO UPDATE SET user_id=excluded.user_id,session_hash=excluded.session_hash,p256dh=excluded.p256dh,auth=excluded.auth';
    }else{
        $sql.=' ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),session_hash=VALUES(session_hash),p256dh=VALUES(p256dh),auth=VALUES(auth)';
    }
    // A browser can rotate its endpoint. Keep only its current subscription.
    $db->beginTransaction();
    try{
        $db->prepare('DELETE FROM web_push_subscriptions WHERE session_hash=? AND id<>?')->execute([$sessionHash,$device['id']]);
        $db->prepare($sql)->execute([$device['id'],$userId,$sessionHash,$device['endpoint'],$device['p256dh'],$device['auth']]);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function sf_web_push_device_status(int $userId,string $sessionHash,string $endpoint): bool {
    sf_web_push_ensure_schema();
    $q=sf_db()->prepare('SELECT 1 FROM web_push_subscriptions WHERE id=? AND user_id=? AND session_hash=?');
    $q->execute([hash('sha256',$endpoint),$userId,$sessionHash]);
    return (bool)$q->fetchColumn();
}

function sf_web_push_unsubscribe(int $userId,string $sessionHash,string $endpoint): void {
    sf_web_push_ensure_schema();
    sf_db()->prepare('DELETE FROM web_push_subscriptions WHERE id=? AND user_id=? AND session_hash=?')
        ->execute([hash('sha256',$endpoint),$userId,$sessionHash]);
}

function sf_web_push_subscriptions(int $userId,?string $deviceId=null): array {
    sf_web_push_ensure_schema();
    $sql="SELECT d.* FROM web_push_subscriptions d
        JOIN user_sessions s ON s.token_hash=d.session_hash AND s.user_id=d.user_id
        JOIN users u ON u.id=d.user_id
        WHERE d.user_id=? AND u.status='active' AND s.expires_at>?";
    $params=[$userId,gmdate('c')];
    if($deviceId!==null){
        $sql.=' AND d.id=?';
        $params[]=$deviceId;
    }
    $q=sf_db()->prepare($sql.' ORDER BY d.id');
    $q->execute($params);
    return $q->fetchAll();
}

// RFC 8291 and RFC 8188, single final record. OpenSSL provides the P-256 ECDH and AES-GCM primitives.
function sf_web_push_encrypt(string $message,string $receiver,string $auth,OpenSSLAsymmetricKey $sender,string $salt): string {
    $peer=sf_web_push_public_key($receiver);
    if($peer===false || strlen($auth)!==16 || strlen($salt)!==16 || strlen($message)>3993){
        throw new InvalidArgumentException('Invalid Web Push encryption input.');
    }
    $point=sf_web_push_point($sender);
    $shared=openssl_pkey_derive($peer,$sender,32);
    if($shared===false || strlen($shared)!==32) throw new RuntimeException('Could not derive the Web Push encryption key.');
    $ikm=hash_hkdf('sha256',$shared,32,"WebPush: info\0".$receiver.$point,$auth);
    $cek=hash_hkdf('sha256',$ikm,16,"Content-Encoding: aes128gcm\0",$salt);
    $nonce=hash_hkdf('sha256',$ikm,12,"Content-Encoding: nonce\0",$salt);
    $encrypted=openssl_encrypt($message."\x02",'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag,'',16);
    if($encrypted===false) throw new RuntimeException('Could not encrypt a Web Push message.');
    return $salt.pack('N',4096).chr(strlen($point)).$point.$encrypted.$tag;
}

// OpenSSL emits ASN.1 DER ECDSA signatures. ES256 uses fixed-width R || S.
function sf_web_push_jose_signature(string $der): string {
    if(strlen($der)<8 || $der[0]!=="\x30" || ord($der[1])!==strlen($der)-2){
        throw new RuntimeException('Invalid Web Push signature.');
    }
    $offset=2;
    $raw='';
    for($i=0;$i<2;$i++){
        if(($der[$offset]??'')!=="\x02" || !isset($der[$offset+1])) throw new RuntimeException('Invalid Web Push signature.');
        $length=ord($der[$offset+1]);
        $integer=substr($der,$offset+2,$length);
        if($length<1 || $length>33 || strlen($integer)!==$length) throw new RuntimeException('Invalid Web Push signature.');
        $unsigned=ltrim($integer,"\x00");
        if(strlen($unsigned)>32) throw new RuntimeException('Invalid Web Push signature.');
        $raw.=str_pad($unsigned,32,"\x00",STR_PAD_LEFT);
        $offset+=2+$length;
    }
    if($offset!==strlen($der)) throw new RuntimeException('Invalid Web Push signature.');
    return $raw;
}

// RFC 8292. The signing key is distinct from the per-message ECDH key.
function sf_web_push_authorization(string $origin,string $subject,OpenSSLAsymmetricKey $key,int $now): string {
    $claims=json_encode(['aud'=>$origin,'exp'=>$now+12*3600,'sub'=>$subject],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $input=sf_web_push_b64('{"typ":"JWT","alg":"ES256"}').'.'.sf_web_push_b64($claims);
    if(!openssl_sign($input,$signature,$key,OPENSSL_ALGO_SHA256)) throw new RuntimeException('Could not sign a Web Push message.');
    $jwt=$input.'.'.sf_web_push_b64(sf_web_push_jose_signature($signature));
    return 'vapid t='.$jwt.', k='.sf_web_push_b64(sf_web_push_point($key));
}

function sf_web_push_subject(): string {
    $url=sf_ai_meta_get('site.base_url');
    $parts=parse_url($url);
    return filter_var($url,FILTER_VALIDATE_URL) && ($parts['scheme']??'')==='https' && isset($parts['host'])
        && !isset($parts['user']) && !isset($parts['pass']) && !in_array($parts['host'],['localhost','127.0.0.1','[::1]'],true)
        ? $url : 'https://stonefellow.com';
}

function sf_web_push_payload(array $payload): string {
    $url=$payload['url']??null;
    $title=$payload['title']??null;
    $body=$payload['body']??null;
    $tag=$payload['tag']??'';
    if(!is_string($url) || !preg_match('~\A/?[a-z0-9_.-]+(?:/[a-z0-9_.-]+)*(?:[?#][^\x00-\x20\x7f\\\\]*)?\z~iD',$url)
        || preg_match('~(?:\A|/)\.\.(?:/|[?#]|\z)~D',$url) || strlen($url)>600
        || !is_string($title) || trim($title)==='' || strlen($title)>300
        || !is_string($body) || strlen($body)>1200 || !is_string($tag) || strlen($tag)>120){
        throw new InvalidArgumentException('Invalid Web Push message.');
    }
    $json=json_encode(['title'=>$title,'body'=>$body,'url'=>$url,'tag'=>$tag],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($json)>3993) throw new InvalidArgumentException('Web Push message is too large.');
    return $json;
}

function sf_web_push_delivery_result(int $code,?int $retryAfter): array {
    $status=match(true){
        $code>=200 && $code<300 => 'delivered',
        $code===404 || $code===410 => 'gone',
        $code===401 || $code===403 => 'unavailable',
        $code===0 || $code===408 || $code===425 || $code===429 || $code>=500 => 'retry',
        default => 'invalid',
    };
    return ['status'=>$status,'status_code'=>$code,'retry_after'=>$status==='retry'?$retryAfter:null];
}

function sf_web_push_send(array $device,array $payload,int $ttl=180): array {
    if(!sf_web_push_supported()) return ['status'=>'unavailable'];
    $current=sf_web_push_subscriptions((int)$device['user_id'],(string)$device['id'])[0]??null;
    if($current!==$device) return ['status'=>'gone'];
    $endpoint=$device['endpoint'];
    $origin=sf_web_push_endpoint_origin($endpoint);
    $receiver=sf_web_push_unb64($device['p256dh'],65);
    $auth=sf_web_push_unb64($device['auth'],16);
    if($origin===null || $receiver===null || $auth===null) return ['status'=>'invalid'];
    try{
        $message=sf_web_push_payload($payload);
        $encrypted=sf_web_push_encrypt($message,$receiver,$auth,sf_web_push_generate_key(),random_bytes(16));
        $authorization=sf_web_push_authorization($origin,sf_web_push_subject(),sf_web_push_vapid_key(),time());
    }catch(InvalidArgumentException|JsonException $e){
        return ['status'=>'invalid'];
    }catch(RuntimeException $e){
        return ['status'=>'unavailable'];
    }
    $headers=['Authorization: '.$authorization,'Content-Type: application/octet-stream','Content-Encoding: aes128gcm','TTL: '.max(0,min(86400,$ttl))];
    if(($payload['tag']??'')!=='') $headers[]='Topic: '.substr(sf_web_push_b64(hash('sha256',$payload['tag'],true)),0,32);
    $retryAfter=null;
    $curl=curl_init($endpoint);
    if($curl===false) return ['status'=>'unavailable'];
    $options=[
        CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$encrypted,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_WRITEFUNCTION=>static fn($handle,string $chunk):int=>strlen($chunk),
        CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use(&$retryAfter): int {
            if(stripos($line,'Retry-After:')===0){
                $value=trim(substr($line,12));
                $seconds=ctype_digit($value)?(int)$value:(int)strtotime($value)-time();
                if($seconds>0) $retryAfter=min(86400,$seconds);
            }
            return strlen($line);
        },
    ];
    if(defined('CURLOPT_PROTOCOLS_STR')) $options[CURLOPT_PROTOCOLS_STR]='https';
    else $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
    if(!curl_setopt_array($curl,$options)){
        curl_close($curl);
        return ['status'=>'unavailable'];
    }
    $success=curl_exec($curl)!==false;
    $code=$success?(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE):0;
    curl_close($curl);
    $result=sf_web_push_delivery_result($code,$retryAfter);
    if($result['status']==='gone'){
        // A late reply must never remove a device that has since been re-registered.
        sf_db()->prepare('DELETE FROM web_push_subscriptions WHERE id=? AND user_id=? AND session_hash=? AND p256dh=? AND auth=?')
            ->execute([$device['id'],$device['user_id'],$device['session_hash'],$device['p256dh'],$device['auth']]);
    }
    return $result;
}
