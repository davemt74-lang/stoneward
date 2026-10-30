<?php
declare(strict_types=1);
require_once __DIR__.'/speech-engine-core.php';

function sf_speech_session_schema():void {
    sf_speech_engine_ensure_schema();$pdo=sf_db();
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $pdo->exec('CREATE TABLE IF NOT EXISTS speech_engine_claims (claim_hash TEXT PRIMARY KEY,user_id INTEGER NOT NULL,expires_at INTEGER NOT NULL,created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS speech_engine_sessions (conversation_id TEXT PRIMARY KEY,user_id INTEGER NULL,created_at TEXT NOT NULL,expires_at INTEGER NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS speech_engine_bridge_nonces (nonce TEXT PRIMARY KEY,expires_at INTEGER NOT NULL)');
    }else{
        $pdo->exec('CREATE TABLE IF NOT EXISTS speech_engine_claims (claim_hash CHAR(64) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,expires_at BIGINT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_speech_claim_owner(user_id),CONSTRAINT fk_speech_claim_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE IF NOT EXISTS speech_engine_sessions (conversation_id VARCHAR(128) PRIMARY KEY,user_id BIGINT UNSIGNED NULL,created_at VARCHAR(40) NOT NULL,expires_at BIGINT NOT NULL,INDEX idx_speech_session_owner(user_id),CONSTRAINT fk_speech_session_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE IF NOT EXISTS speech_engine_bridge_nonces (nonce VARCHAR(64) PRIMARY KEY,expires_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}

function sf_speech_session_authorized(int $userId):bool {
    return $userId>0&&sf_speech_engine_user_choice($userId)==='speech_engine'&&sf_speech_engine_pilot_config()['ready'];
}

function sf_speech_provider_token(string $key,string $engineId):string {
    if($key===''||!preg_match('/^seng_[A-Za-z0-9_-]{8,96}$/',$engineId))throw new RuntimeException('Speech Engine provider is unavailable.');
    if(!function_exists('curl_init'))throw new RuntimeException('Secure voice connection support is not installed.');
    $url='https://api.elevenlabs.io/v1/convai/conversation/token?'.http_build_query(['agent_id'=>$engineId]);
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>['xi-api-key: '.$key,'Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if(!is_string($raw)||$status!==200||strlen($raw)>8192)throw new RuntimeException('Speech Engine token request failed.');
    $payload=json_decode($raw,true);$token=(string)($payload['token']??'');
    if(strlen($token)<20||strlen($token)>4096)throw new RuntimeException('Speech Engine token response was invalid.');
    return $token;
}

function sf_speech_issue_claim(int $userId,int $now=0):string {
    if(!sf_speech_session_authorized($userId))throw new RuntimeException('Speech Engine is not enabled for this account.');
    sf_speech_session_schema();$now=$now?:time();$pdo=sf_db();
    $pdo->prepare('DELETE FROM speech_engine_claims WHERE expires_at<?')->execute([$now]);
    $q=$pdo->prepare('SELECT COUNT(*) FROM speech_engine_claims WHERE user_id=? AND expires_at>?');$q->execute([$userId,$now]);
    if((int)$q->fetchColumn()>=3)throw new RuntimeException('Too many pending voice sessions. Wait for an earlier session to expire.');
    $claim=bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO speech_engine_claims(claim_hash,user_id,expires_at,created_at) VALUES(?,?,?,?)')->execute([hash('sha256',$claim),$userId,$now+120,gmdate('c',$now)]);
    return $claim;
}

function sf_speech_register_conversation(string $conversationId,int $now=0):void {
    if(!preg_match('/^[A-Za-z0-9_-]{12,128}$/',$conversationId))throw new InvalidArgumentException('Invalid conversation ID.');
    sf_speech_session_schema();$now=$now?:time();$pdo=sf_db();
    $q=$pdo->prepare('SELECT conversation_id FROM speech_engine_sessions WHERE conversation_id=?');$q->execute([$conversationId]);
    if($q->fetchColumn()!==false)return;
    $pdo->prepare('INSERT INTO speech_engine_sessions(conversation_id,user_id,created_at,expires_at) VALUES(?,NULL,?,?)')->execute([$conversationId,gmdate('c',$now),$now+180]);
}

function sf_speech_bind_account(int $userId,string $conversationId,string $claim,int $now=0):void {
    if(!sf_speech_session_authorized($userId))throw new RuntimeException('Speech Engine is disabled for this account.');
    if(!preg_match('/^[A-Za-z0-9_-]{12,128}$/',$conversationId)||!preg_match('/^[a-f0-9]{64}$/',$claim))throw new InvalidArgumentException('Invalid voice-session binding.');
    sf_speech_session_schema();$now=$now?:time();$pdo=sf_db();$pdo->beginTransaction();
    try{
        $hash=hash('sha256',$claim);
        $q=$pdo->prepare('SELECT user_id FROM speech_engine_claims WHERE claim_hash=? AND user_id=? AND expires_at>?');$q->execute([$hash,$userId,$now]);
        if($q->fetchColumn()===false)throw new RuntimeException('Voice-session claim expired. Restart the conversation.');
        $q=$pdo->prepare('UPDATE speech_engine_sessions SET user_id=?,expires_at=? WHERE conversation_id=? AND user_id IS NULL AND expires_at>?');
        $q->execute([$userId,$now+2400,$conversationId,$now]);
        if($q->rowCount()!==1)throw new RuntimeException('Voice connection is not ready or already assigned.');
        $pdo->prepare('DELETE FROM speech_engine_claims WHERE claim_hash=? AND user_id=?')->execute([$hash,$userId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function sf_speech_bound_user(string $conversationId,int $now=0):?array {
    sf_speech_session_schema();$now=$now?:time();
    $q=sf_db()->prepare('SELECT u.id,u.email,u.display_name,u.role,u.status,u.created_at,u.updated_at,u.last_login_at FROM speech_engine_sessions s JOIN users u ON u.id=s.user_id WHERE s.conversation_id=? AND s.expires_at>? AND u.status=?');
    $q->execute([$conversationId,$now,'active']);$u=$q->fetch();
    if(!$u||!sf_speech_session_authorized((int)$u['id']))return null;
    return $u;
}

function sf_speech_close_conversation(string $conversationId):void {
    sf_speech_session_schema();sf_db()->prepare('DELETE FROM speech_engine_sessions WHERE conversation_id=?')->execute([$conversationId]);
}

function sf_speech_bridge_signature(string $raw,int $timestamp,string $secret):string {
    return hash_hmac('sha256',$timestamp.'.'.$raw,$secret);
}

function sf_speech_verify_bridge(string $raw,array $headers,int $now=0):void {
    $secret=(string)getenv('SF_SPEECH_BRIDGE_SECRET');
    if(strlen($secret)<32)throw new RuntimeException('Voice bridge is not configured.');
    $now=$now?:time();$timestamp=(string)($headers['timestamp']??'');$nonce=(string)($headers['nonce']??'');$signature=(string)($headers['signature']??'');
    if(!preg_match('/^[0-9]{10}$/',$timestamp)||abs((int)$timestamp-$now)>60||!preg_match('/^[a-f0-9]{32}$/',$nonce)||!preg_match('/^[a-f0-9]{64}$/',$signature)||strlen($raw)>8000)throw new RuntimeException('Voice bridge authentication failed.');
    $expected=sf_speech_bridge_signature($raw,(int)$timestamp,$secret.'|'.$nonce);
    if(!hash_equals($expected,$signature))throw new RuntimeException('Voice bridge authentication failed.');
    sf_speech_session_schema();$pdo=sf_db();$pdo->prepare('DELETE FROM speech_engine_bridge_nonces WHERE expires_at<?')->execute([$now]);
    try{$pdo->prepare('INSERT INTO speech_engine_bridge_nonces(nonce,expires_at) VALUES(?,?)')->execute([$nonce,$now+70]);}
    catch(PDOException $e){throw new RuntimeException('Voice bridge replay rejected.',0,$e);}
}
