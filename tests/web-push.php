<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/stonefellow-web-push-'.bin2hex(random_bytes(6));
mkdir($root.'/config',0700,true);
define('SF_ROOT',$root);
file_put_contents($root.'/config/database.php',"<?php\nreturn ".var_export(['driver'=>'sqlite','path'=>':memory:'],true).";\n");
require __DIR__.'/../api/bootstrap.php';
require __DIR__.'/../api/web-push-core.php';

function verify_push(bool $passed,string $message): void {
    if(!$passed) throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}

try{
    // RFC 8291 section 5 and appendix A published interoperability vector.
    $receiver=sf_web_push_unb64('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',65);
    $senderPoint=sf_web_push_unb64('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8',65);
    $senderSecret=sf_web_push_unb64('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw',32);
    $senderDer=hex2bin('30770201010420').$senderSecret.hex2bin('a00a06082a8648ce3d030107a144034200').$senderPoint;
    $sender=openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($senderDer),64,"\n")."-----END EC PRIVATE KEY-----\n");
    $salt=sf_web_push_unb64('DGv6ra1nlYgDCS1FRnbzlw',16);
    $auth=sf_web_push_unb64('BTBZMqHH6r4Tts7J_aSIgg',16);
    $message=sf_web_push_unb64('V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24',41);
    $expected='DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';
    verify_push(sf_web_push_b64(sf_web_push_encrypt($message,$receiver,$auth,$sender,$salt))===$expected,'Web Push ciphertext matches the RFC 8291 vector');
    verify_push(sf_web_push_public_key("\x04".str_repeat("\0",64))===false,'OpenSSL rejects an off-curve receiver key');

    $key=sf_web_push_vapid_key();
    $stored=openssl_pkey_get_private(require $root.'/storage/web-push-vapid.php');
    verify_push(fileperms($root.'/storage/web-push-vapid.php')%512===0600 && sf_web_push_point($key)===sf_web_push_point($stored),'VAPID private key is persisted with owner-only permissions');
    $endpoint='https://fcm.googleapis.com/fcm/send/browser-fixture';
    $now=time();
    $header=sf_web_push_authorization(sf_web_push_endpoint_origin($endpoint),'https://stonefellow.example',$key,$now);
    preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=(.+)$/',$header,$jwt);
    $claims=json_decode(base64_decode(strtr($jwt[2],'-_','+/'),true),true,512,JSON_THROW_ON_ERROR);
    $signature=sf_web_push_unb64($jwt[3],64);
    $encodeInteger=static function(string $bytes):string {
        $bytes=ltrim($bytes,"\x00");
        if(ord($bytes[0])>=128) $bytes="\x00".$bytes;
        return "\x02".chr(strlen($bytes)).$bytes;
    };
    $derParts=$encodeInteger(substr($signature,0,32)).$encodeInteger(substr($signature,32,32));
    $signatureDer="\x30".chr(strlen($derParts)).$derParts;
    $public=sf_web_push_public_key(sf_web_push_unb64($jwt[4],65));
    verify_push($claims===['aud'=>'https://fcm.googleapis.com','exp'=>$now+43200,'sub'=>'https://stonefellow.example']
        && openssl_verify($jwt[1].'.'.$jwt[2],$signatureDer,$public,OPENSSL_ALGO_SHA256)===1,'VAPID audience, expiry and ES256 signature verify');

    foreach(['https://updates.push.services.mozilla.com/wpush/v2/abc','https://web.push.apple.com/abc','https://wns2-db5p.notify.windows.com/abc?token=xyz'] as $valid){
        verify_push(sf_web_push_endpoint_origin($valid)!==null,'Supported push service '.$valid);
    }
    foreach(['http://fcm.googleapis.com/token','https://127.0.0.1/test','https://fcm.googleapis.com@127.0.0.1/test',
        'https://fcm.googleapis.com:444/test','https://web.push.apple.com.evil.test/test','https://evilpush.apple.com/test',
        'https://web.push.apple.com\\@evil.test/test','https://web.push.apple.com/test#fragment',"https://web.push.apple.com/test\n"] as $blocked){
        if(sf_web_push_endpoint_origin($blocked)!==null) throw new RuntimeException('Unsafe push endpoint accepted.');
    }
    verify_push(true,'Push endpoint policy rejects arbitrary hosts and ambiguous URLs');
    $payload=['title'=>'Session live','body'=>'Open the Jukebox','url'=>'jukebox.php?id=example','tag'=>'jukebox-example-live'];
    verify_push(json_decode(sf_web_push_payload($payload),true)===$payload,'A valid session alert keeps its in-app link and stable tag');
    foreach(['https://evil.test','//evil.test','/\\evil.test','../outside'] as $blocked){
        try{
            sf_web_push_payload(array_replace($payload,['url'=>$blocked]));
        }catch(InvalidArgumentException $e){
            continue;
        }
        throw new RuntimeException('Unsafe notification link accepted.');
    }
    verify_push(true,'Push notification links stay in the Stonefellow application');

    $db=sf_db();
    $db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT NOT NULL); INSERT INTO users VALUES(1,'active'),(2,'active')");
    sf_web_push_ensure_schema();
    $sessionA=hash('sha256','browser-a');
    $sessionB=hash('sha256','browser-b');
    $created=gmdate('c');
    $expires=gmdate('c',time()+3600);
    $insert=$db->prepare('INSERT INTO user_sessions(token_hash,user_id,created_at,last_seen_at,expires_at) VALUES(?,?,?,?,?)');
    $insert->execute([$sessionA,1,$created,$created,$expires]);
    $insert->execute([$sessionB,2,$created,$created,$expires]);
    $registration=['endpoint'=>$endpoint,'keys'=>['p256dh'=>sf_web_push_b64($receiver),'auth'=>sf_web_push_b64($auth)]];
    sf_web_push_subscribe(1,$sessionA,$registration);
    sf_web_push_subscribe(1,$sessionA,$registration);
    $devices=sf_web_push_subscriptions(1);
    verify_push(count($devices)===1 && sf_web_push_device_status(1,$sessionA,$endpoint) && !sf_web_push_device_status(2,$sessionB,$endpoint),'A browser is registered once for its own account and login');
    sf_web_push_unsubscribe(2,$sessionB,$endpoint);
    verify_push(sf_web_push_device_status(1,$sessionA,$endpoint),'Another account cannot unsubscribe the device');
    $rotated=array_replace($registration,['endpoint'=>'https://fcm.googleapis.com/fcm/send/rotated-fixture']);
    sf_web_push_subscribe(1,$sessionA,$rotated);
    verify_push(count(sf_web_push_subscriptions(1))===1 && !sf_web_push_device_status(1,$sessionA,$endpoint),
        'A rotated browser endpoint replaces its prior subscription');
    sf_web_push_subscribe(1,$sessionA,$registration);
    sf_web_push_subscribe(2,$sessionB,$registration);
    verify_push(!sf_web_push_subscriptions(1) && count(sf_web_push_subscriptions(2))===1
        && sf_web_push_send($devices[0],$payload)['status']==='gone','An explicit device rebind invalidates prior-account delivery');
    sf_auth_revoke_session(2,$sessionB);
    verify_push(!sf_web_push_subscriptions(2) && !sf_web_push_device_status(2,$sessionB,$endpoint),'Revoking the login automatically deletes its device registration');
    sf_web_push_subscribe(1,$sessionA,$registration);
    $db->prepare('UPDATE user_sessions SET expires_at=? WHERE token_hash=?')->execute([gmdate('c',time()-1),$sessionA]);
    verify_push(!sf_web_push_subscriptions(1),'An expired login is excluded before its background cleanup');
    verify_push(sf_web_push_delivery_result(201,null)['status']==='delivered'
        && sf_web_push_delivery_result(410,null)['status']==='gone'
        && sf_web_push_delivery_result(302,null)['status']==='invalid'
        && sf_web_push_delivery_result(403,null)['status']==='unavailable'
        && sf_web_push_delivery_result(429,12)===['status'=>'retry','status_code'=>429,'retry_after'=>12],
        'Provider responses classify delivery, dead devices, redirects, configuration failure and retries');
}finally{
    foreach(glob($root.'/storage/*')?:[] as $file) unlink($file);
    if(is_dir($root.'/storage')) rmdir($root.'/storage');
    unlink($root.'/config/database.php');
    rmdir($root.'/config');
    rmdir($root);
}
