<?php
declare(strict_types=1);

function sf_schema_column_exists(string $table,string $column): bool {
    $pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $rows=$pdo->query('PRAGMA table_info('.$table.')')->fetchAll();
        foreach($rows as $r) if((string)($r['name']??'')===$column) return true;
        return false;
    }
    $q=$pdo->query("SHOW COLUMNS FROM `".str_replace('`','',$table)."` LIKE ".$pdo->quote($column));
    return (bool)$q->fetch();
}
function sf_schema_add_column(string $table,string $column,string $sqliteDefinition,string $mysqlDefinition): void {
    if(sf_schema_column_exists($table,$column)) return;
    $driver=(string)(sf_db_config()['driver']??'');
    sf_db()->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.($driver==='sqlite'?$sqliteDefinition:$mysqlDefinition));
}
function sf_customer_lifecycle_ensure_schema(): void {
    static $done=false;if($done)return;
    $pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    sf_schema_add_column('users','email_verified_at','TEXT NULL','VARCHAR(40) NULL');
    sf_schema_add_column('users','email_verification_sent_at','TEXT NULL','VARCHAR(40) NULL');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS account_action_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,purpose TEXT NOT NULL,token_hash TEXT NOT NULL UNIQUE,expires_at TEXT NOT NULL,used_at TEXT NULL,request_ip_hash TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_account_tokens_user_purpose ON account_action_tokens(user_id,purpose,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS transactional_email_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NULL,recipient_email TEXT NOT NULL,subject TEXT NOT NULL,body_text TEXT NOT NULL,kind TEXT NOT NULL,status TEXT NOT NULL,error_text TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,sent_at TEXT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_outbox_created ON transactional_email_outbox(created_at)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS account_action_tokens (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,purpose VARCHAR(40) NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,expires_at VARCHAR(40) NOT NULL,used_at VARCHAR(40) NULL,request_ip_hash CHAR(64) NOT NULL DEFAULT '',created_at VARCHAR(40) NOT NULL,INDEX idx_account_tokens_user_purpose(user_id,purpose,created_at),CONSTRAINT fk_account_token_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS transactional_email_outbox (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,recipient_email VARCHAR(190) NOT NULL,subject VARCHAR(255) NOT NULL,body_text LONGTEXT NOT NULL,kind VARCHAR(60) NOT NULL,status VARCHAR(32) NOT NULL,error_text TEXT NOT NULL,created_at VARCHAR(40) NOT NULL,sent_at VARCHAR(40) NULL,INDEX idx_email_outbox_created(created_at),CONSTRAINT fk_email_outbox_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // Existing accounts pre-date verification. Mark them verified once so v1.1 never locks out installed customers.
    if(sf_ai_meta_get('lifecycle.existing_users_verified','0')!=='1'){
        $pdo->exec("UPDATE users SET email_verified_at=COALESCE(email_verified_at,created_at)");
        sf_ai_meta_set('lifecycle.existing_users_verified','1');
    }
    $done=true;
}
function sf_request_ip_hash(): string { return hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'')); }
function sf_customer_email_settings(): array {
    sf_customer_lifecycle_ensure_schema();
    $mode=sf_ai_meta_get('email.delivery_mode','log');if(!in_array($mode,['log','php_mail'],true))$mode='log';
    return [
        'delivery_mode'=>$mode,
        'from_name'=>sf_ai_meta_get('email.from_name','Stonefellow'),
        'from_email'=>sf_ai_meta_get('email.from_email',''),
        'reply_to'=>sf_ai_meta_get('email.reply_to',''),
        'base_url'=>sf_ai_meta_get('site.base_url',''),
    ];
}
function sf_customer_email_settings_save(array $data): array {
    $mode=(string)($data['delivery_mode']??'log');if(!in_array($mode,['log','php_mail'],true))throw new InvalidArgumentException('Choose log or PHP mail delivery.');
    $fromName=sf_clean_text($data['from_name']??'Stonefellow',100);$fromEmail=sf_email((string)($data['from_email']??''));$reply=sf_email((string)($data['reply_to']??''));$base=trim((string)($data['base_url']??''));
    if($mode==='php_mail'&&!filter_var($fromEmail,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('A valid From email is required for PHP mail delivery.');
    if($reply!==''&&!filter_var($reply,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Reply-to email is invalid.');
    if($base!==''&&!filter_var($base,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Base URL is invalid.');
    $base=rtrim($base,'/');
    sf_ai_meta_set('email.delivery_mode',$mode);sf_ai_meta_set('email.from_name',$fromName);sf_ai_meta_set('email.from_email',$fromEmail);sf_ai_meta_set('email.reply_to',$reply);sf_ai_meta_set('site.base_url',$base);
    return sf_customer_email_settings();
}
function sf_public_base_url(): string {
    $configured=rtrim(sf_ai_meta_get('site.base_url',''),'/');if($configured!=='')return $configured;
    $host=(string)($_SERVER['HTTP_HOST']??'');if($host==='')return '';
    $scheme=sf_cookie_secure()?'https':'http';$script=(string)($_SERVER['SCRIPT_NAME']??'/');$base=preg_replace('#/(api|admin)(/.*)?$#','',$script)??'';$base=rtrim(dirname($base==='/'?'':$base),'/\\');
    return $scheme.'://'.$host.($base&&$base!=='.'?'/'.trim($base,'/'):'');
}
function sf_transactional_email(int $userId,string $email,string $subject,string $body,string $kind): array {
    sf_customer_lifecycle_ensure_schema();$settings=sf_customer_email_settings();$now=gmdate('c');$status='queued';$error='';$sentAt=null;
    if($settings['delivery_mode']==='php_mail'){
        $headers=[];$headers[]='Content-Type: text/plain; charset=UTF-8';
        if($settings['from_email']!=='')$headers[]='From: '.$settings['from_name'].' <'.$settings['from_email'].'>';
        if($settings['reply_to']!=='')$headers[]='Reply-To: '.$settings['reply_to'];
        try{$ok=@mail($email,$subject,$body,implode("\r\n",$headers));if($ok){$status='sent';$sentAt=$now;}else{$status='failed';$error='PHP mail() returned false.';}}catch(Throwable $e){$status='failed';$error=sf_clean_text($e->getMessage(),500);}
    }else{$status='logged';}
    $q=sf_db()->prepare('INSERT INTO transactional_email_outbox(user_id,recipient_email,subject,body_text,kind,status,error_text,created_at,sent_at) VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$userId?:null,$email,$subject,$body,$kind,$status,$error,$now,$sentAt]);
    return ['id'=>(int)sf_db()->lastInsertId(),'status'=>$status,'sent_at'=>$sentAt];
}
function sf_account_action_token_issue(int $userId,string $purpose,int $ttlSeconds): string {
    sf_customer_lifecycle_ensure_schema();$pdo=sf_db();$now=gmdate('c');$min=gmdate('c',time()-300);
    $q=$pdo->prepare('SELECT created_at FROM account_action_tokens WHERE user_id=? AND purpose=? ORDER BY id DESC LIMIT 1');$q->execute([$userId,$purpose]);$last=$q->fetchColumn();
    if($last!==false&&strtotime((string)$last)>strtotime($min))throw new RuntimeException('Please wait a few minutes before requesting another email.');
    $pdo->prepare('UPDATE account_action_tokens SET used_at=? WHERE user_id=? AND purpose=? AND used_at IS NULL')->execute([$now,$userId,$purpose]);
    $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$expires=gmdate('c',time()+$ttlSeconds);
    $i=$pdo->prepare('INSERT INTO account_action_tokens(user_id,purpose,token_hash,expires_at,used_at,request_ip_hash,created_at) VALUES(?,?,?,?,NULL,?,?)');$i->execute([$userId,$purpose,$hash,$expires,sf_request_ip_hash(),$now]);
    return $raw;
}
function sf_account_action_token_lookup(string $purpose,string $raw): ?array {
    sf_customer_lifecycle_ensure_schema();if(!preg_match('/^[a-f0-9]{64}$/i',$raw))return null;$q=sf_db()->prepare('SELECT * FROM account_action_tokens WHERE purpose=? AND token_hash=? AND used_at IS NULL LIMIT 1');$q->execute([$purpose,hash('sha256',$raw)]);$row=$q->fetch();if(!$row)return null;if(strtotime((string)$row['expires_at'])<time())return null;return $row;
}
function sf_send_email_verification(int $userId,string $email): array {
    $raw=sf_account_action_token_issue($userId,'verify_email',48*3600);$base=sf_public_base_url();$link=($base!==''?$base:'').'/'.'?view=verify-email&token='.rawurlencode($raw);
    $body="Verify your Stonefellow email address\n\nOpen this link to verify your email:\n{$link}\n\nThis link expires in 48 hours. If you did not create this account, you can ignore this message.";
    sf_db()->prepare('UPDATE users SET email_verification_sent_at=?,updated_at=? WHERE id=?')->execute([gmdate('c'),gmdate('c'),$userId]);
    return sf_transactional_email($userId,$email,'Verify your Stonefellow email',$body,'email_verification');
}
function sf_verify_email_token(string $token): array {
    $row=sf_account_action_token_lookup('verify_email',$token);if(!$row)throw new InvalidArgumentException('This verification link is invalid or expired.');$now=gmdate('c');$pdo=sf_db();$pdo->beginTransaction();try{$pdo->prepare('UPDATE users SET email_verified_at=?,updated_at=? WHERE id=?')->execute([$now,$now,(int)$row['user_id']]);$pdo->prepare('UPDATE account_action_tokens SET used_at=? WHERE id=?')->execute([$now,(int)$row['id']]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return ['verified'=>true,'user_id'=>(int)$row['user_id']];
}
function sf_request_password_reset(string $email): void {
    sf_customer_lifecycle_ensure_schema();$u=sf_user_by_email($email);if(!$u||($u['status']??'')!=='active')return;
    try{$raw=sf_account_action_token_issue((int)$u['id'],'password_reset',3600);}catch(Throwable $e){return;}
    $base=sf_public_base_url();$link=($base!==''?$base:'').'/?view=reset-password&token='.rawurlencode($raw);
    $body="Reset your Stonefellow password\n\nOpen this link to choose a new password:\n{$link}\n\nThis link expires in 60 minutes. If you did not request a reset, ignore this message.";
    sf_transactional_email((int)$u['id'],(string)$u['email'],'Reset your Stonefellow password',$body,'password_reset');
}
function sf_reset_password_token(string $token,string $password,string $confirm): void {
    if(!sf_valid_password($password))throw new InvalidArgumentException('Use a password of at least 10 characters.');if($password!==$confirm)throw new InvalidArgumentException('Passwords do not match.');$row=sf_account_action_token_lookup('password_reset',$token);if(!$row)throw new InvalidArgumentException('This password reset link is invalid or expired.');
    $now=gmdate('c');$pdo=sf_db();$pdo->beginTransaction();try{$pdo->prepare('UPDATE users SET password_hash=?,updated_at=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$now,(int)$row['user_id']]);$pdo->prepare('UPDATE account_action_tokens SET used_at=? WHERE id=?')->execute([$now,(int)$row['id']]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}sf_auth_revoke_all_user_sessions((int)$row['user_id']);
}
function sf_change_customer_email(array $user,string $newEmail,string $password): array {
    sf_customer_lifecycle_ensure_schema();$newEmail=sf_email($newEmail);if(!filter_var($newEmail,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');$full=sf_user_by_email((string)$user['email']);if(!$full||!password_verify($password,(string)$full['password_hash']))throw new InvalidArgumentException('Current password is incorrect.');$other=sf_user_by_email($newEmail);if($other&&(int)$other['id']!==(int)$user['id'])throw new InvalidArgumentException('That email address is already in use.');
    $now=gmdate('c');sf_db()->prepare('UPDATE users SET email=?,email_verified_at=NULL,email_verification_sent_at=NULL,updated_at=? WHERE id=?')->execute([$newEmail,$now,(int)$user['id']]);sf_send_email_verification((int)$user['id'],$newEmail);return sf_user_by_email($newEmail)?:[];
}
function sf_customer_lifecycle_state(int $userId): array {
    sf_customer_lifecycle_ensure_schema();$q=sf_db()->prepare('SELECT email,email_verified_at,email_verification_sent_at FROM users WHERE id=?');$q->execute([$userId]);$u=$q->fetch()?:[];return ['email'=>(string)($u['email']??''),'email_verified'=>!empty($u['email_verified_at']),'email_verified_at'=>$u['email_verified_at']??null,'verification_sent_at'=>$u['email_verification_sent_at']??null];
}
function sf_email_outbox(int $limit=100): array {sf_customer_lifecycle_ensure_schema();$limit=max(1,min(250,$limit));return sf_db()->query('SELECT id,user_id,recipient_email,subject,kind,status,error_text,created_at,sent_at FROM transactional_email_outbox ORDER BY id DESC LIMIT '.$limit)->fetchAll();}
