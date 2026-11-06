<?php
declare(strict_types=1);

/**
 * 91B19D: Pre-call Active Token escrow. Unlike a read-only balance preflight,
 * escrow is deducted before contacting a paid provider, so two simultaneous
 * requests cannot spend the same balance.
 */
function sf_ai_reservation_schema(): void {
    sf_entitlements_ensure_schema();$pdo=sf_db();
    if((sf_db_config()['driver']??'')==='sqlite')
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_token_reservations(
            request_key TEXT PRIMARY KEY,user_id INTEGER NOT NULL,source TEXT NOT NULL,
            reserved INTEGER NOT NULL,actual INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL,
            created_at TEXT NOT NULL,closed_at TEXT NULL)");
    else $pdo->exec("CREATE TABLE IF NOT EXISTS ai_token_reservations(
            request_key CHAR(32) NOT NULL PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(120) NOT NULL,reserved BIGINT NOT NULL,actual BIGINT NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL,created_at VARCHAR(40) NOT NULL,closed_at VARCHAR(40) NULL,
            INDEX idx_reservation_user_status(user_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Calls under unlimited Admin rights return null and remain metered on settle. */
function sf_ai_reserve_tokens(array $user,int $amount,string $source): ?string {
    if(($user['role']??'')==='admin')return null;
    if($amount<1||$amount>1000000)throw new InvalidArgumentException('Invalid token reservation amount.');
    sf_ai_reservation_schema();$pdo=sf_db();$userId=(int)$user['id'];$key=bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try{
        $now=gmdate('c');
        if((sf_db_config()['driver']??'')==='sqlite'){
            $lock=$pdo->prepare('INSERT OR IGNORE INTO user_token_balances(user_id,balance,updated_at) VALUES(?,0,?)');$lock->execute([$userId,$now]);
        }else{
            $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$userId]);
            if($lock->fetchColumn()===false)throw new RuntimeException('User account does not exist.');
        }
        $q=$pdo->prepare('SELECT balance FROM user_token_balances WHERE user_id=?');$q->execute([$userId]);
        if((int)$q->fetchColumn()<$amount)throw new RuntimeException('ACTIVE_TOKENS_REQUIRED');
        $q=$pdo->prepare("INSERT INTO ai_token_reservations(request_key,user_id,source,reserved,status,created_at) VALUES(?,?,?,?,'held',?)");
        $q->execute([$key,$userId,substr($source,0,120),$amount,$now]);
        sf_token_adjust($userId,-$amount,'reservation_hold','reserve:'.$key,[],'Pending provider request');
        $pdo->commit();return $key;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** The optional success callback receives the balance and must leave this transaction open. */
function sf_ai_finish_reservation(array $user,?string $key,int $actual,array $usage,string $source,bool $succeeded,?callable $afterSettle=null): int {
    $admin=($user['role']??'')==='admin';
    if($admin&&(!$succeeded||$afterSettle===null)){
        return $succeeded?sf_consume_ai($user,max(0,$actual),$usage,$source):PHP_INT_MAX;
    }
    if(!$admin&&(!$key||!preg_match('/^[a-f0-9]{32}$/',$key)))throw new InvalidArgumentException('Invalid token reservation.');
    sf_ai_reservation_schema();$pdo=sf_db();$userId=(int)$user['id'];$pdo->beginTransaction();
    try{
        if($admin){
            $balance=sf_consume_ai($user,max(0,$actual),$usage,$source);
            $afterSettle($balance);
            $pdo->commit();return $balance;
        }
        $now=gmdate('c');
        if((sf_db_config()['driver']??'')==='sqlite'){
            $lock=$pdo->prepare('INSERT OR IGNORE INTO user_token_balances(user_id,balance,updated_at) VALUES(?,0,?)');$lock->execute([$userId,$now]);
        }else{
            $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$userId]);
            if($lock->fetchColumn()===false)throw new RuntimeException('User account does not exist.');
        }
        $q=$pdo->prepare('SELECT reserved,status,source FROM ai_token_reservations WHERE request_key=? AND user_id=?');$q->execute([$key,$userId]);$hold=$q->fetch();
        if(!$hold||$hold['status']!=='held'||$hold['source']!==substr($source,0,120))throw new RuntimeException('Token reservation already settled or not owned by this request.');
        $reserved=(int)$hold['reserved'];
        sf_token_adjust($userId,$reserved,'reservation_release','release:'.$key,[],$succeeded?'Settling provider result':'Provider request failed');
        $charge=max(0,$actual);
        if($succeeded)$balance=sf_consume_ai($user,$charge,$usage,$source);
        else{$b=$pdo->prepare('SELECT balance FROM user_token_balances WHERE user_id=?');$b->execute([$userId]);$balance=(int)$b->fetchColumn();}
        $q=$pdo->prepare('UPDATE ai_token_reservations SET actual=?,status=?,closed_at=? WHERE request_key=? AND status=\'held\'');
        $q->execute([$succeeded?$charge:0,$succeeded?'settled':'released',$now,$key]);
        if($q->rowCount()!==1)throw new RuntimeException('Token reservation status changed during settlement.');
        if($succeeded&&$afterSettle!==null)$afterSettle($balance);
        $pdo->commit();return $balance;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
