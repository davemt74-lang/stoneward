<?php
declare(strict_types=1);
/** Worker transport: env-provisioned shared secret, short signed requests and
 * one-time nonces; NEVER a cloud session cookie or Kunaki password.
 */
function sf_kunaki_worker_sign(string $secret,string $timestamp,string $nonce,
    string $method,string $route,string $raw):string {
    return hash_hmac('sha256',$timestamp."\n".$nonce."\n".$method."\n".$route."\n".
        hash('sha256',$raw),$secret);
}
function sf_kunaki_worker_verify(array $headers,string $raw,string $secret,
    int $now,string $route='/api/kunaki-worker.php'):string {
    if(strlen($secret)<32||strlen($raw)>16384)
        throw new RuntimeException('Worker signing configuration or request invalid.');
    $timestamp=(string)($headers['timestamp']??'');
    $nonce=(string)($headers['nonce']??'');
    $signature=strtolower((string)($headers['signature']??''));
    $worker=(string)($headers['worker']??'');
    if(!preg_match('/^[0-9]{10}$/D',$timestamp)||abs($now-(int)$timestamp)>300
       ||!preg_match('/^[a-f0-9]{32}$/D',$nonce)
       ||!preg_match('/^[a-f0-9]{64}$/D',$signature)
       ||!preg_match('/^HS-[a-zA-Z0-9_-]{5,64}$/D',$worker))
        throw new RuntimeException('Worker credentials missing or expired.');
    $expected=sf_kunaki_worker_sign($secret,$timestamp,$nonce,'POST',$route,$raw);
    if(!hash_equals($expected,$signature))throw new RuntimeException('Worker signature rejected.');
    return $worker;
}
function sf_kunaki_worker_nonce_once(string $nonce):void {
    $dir=(defined('SF_ROOT')?SF_ROOT:dirname(__DIR__)).'/storage/kunaki/nonce';
    if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))
        throw new RuntimeException('Worker replay storage unavailable.');
    $path=$dir.'/'.$nonce;
    $fd=@fopen($path,'x');
    if(!$fd)throw new RuntimeException('Worker nonce has already been used.');
    try{fwrite($fd,(string)time());}finally{fclose($fd);}
    // Expired nonce pruning is opportunistic; never delete a recent nonce.
    if(random_int(0,99)===0){
        foreach(glob($dir.'/*')?:[] as $old){
            if(is_file($old)&&(int)filemtime($old)<time()-3600)@unlink($old);
        }
    }
}
