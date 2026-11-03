<?php
declare(strict_types=1);
require_once __DIR__.'/subscription-policy.php';

function sf_entitlements_ensure_schema(): void {
    $pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_packages (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,slug TEXT NOT NULL UNIQUE,description TEXT NOT NULL DEFAULT '',monthly_price_cents INTEGER NOT NULL DEFAULT 0,monthly_tokens INTEGER NOT NULL DEFAULT 0,trial_days INTEGER NOT NULL DEFAULT 0,trial_tokens INTEGER NOT NULL DEFAULT 0,is_public INTEGER NOT NULL DEFAULT 1,is_active INTEGER NOT NULL DEFAULT 1,is_featured INTEGER NOT NULL DEFAULT 0,sort_order INTEGER NOT NULL DEFAULT 0,features_json TEXT NOT NULL DEFAULT '[]',created_at TEXT NOT NULL,updated_at TEXT NOT NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_token_balances (user_id INTEGER PRIMARY KEY,balance INTEGER NOT NULL DEFAULT 0,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS token_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,delta INTEGER NOT NULL,balance_after INTEGER NOT NULL,entry_type TEXT NOT NULL,source TEXT NOT NULL DEFAULT '',provider TEXT NOT NULL DEFAULT '',model TEXT NOT NULL DEFAULT '',input_tokens INTEGER NOT NULL DEFAULT 0,output_tokens INTEGER NOT NULL DEFAULT 0,voice_chars INTEGER NOT NULL DEFAULT 0,note TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL UNIQUE,package_id INTEGER NULL,status TEXT NOT NULL,trial_started_at TEXT NULL,trial_ends_at TEXT NULL,current_period_start TEXT NULL,current_period_end TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_trial_history (user_id INTEGER PRIMARY KEY,first_trial_package_id INTEGER NULL,first_trial_started_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(first_trial_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_conversations (id TEXT PRIMARY KEY,user_id INTEGER NOT NULL,title TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_messages (id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id TEXT NOT NULL,user_id INTEGER NOT NULL,role TEXT NOT NULL,content TEXT NOT NULL,provider TEXT NOT NULL DEFAULT '',model TEXT NOT NULL DEFAULT '',input_tokens INTEGER NOT NULL DEFAULT 0,output_tokens INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,FOREIGN KEY(conversation_id) REFERENCES agent_conversations(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_brain_decisions (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,conversation_id TEXT NOT NULL,phase TEXT NOT NULL,route TEXT NOT NULL DEFAULT '',action TEXT NOT NULL DEFAULT '',context_profile TEXT NOT NULL DEFAULT '',needs_llm INTEGER NOT NULL DEFAULT 0,requires_confirmation INTEGER NOT NULL DEFAULT 0,request_excerpt TEXT NOT NULL DEFAULT '',response_excerpt TEXT NOT NULL DEFAULT '',provider_run_id TEXT NOT NULL DEFAULT '',provider_status TEXT NOT NULL DEFAULT '',input_tokens INTEGER NOT NULL DEFAULT 0,output_tokens INTEGER NOT NULL DEFAULT 0,details_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_packages (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,slug VARCHAR(120) NOT NULL UNIQUE,description TEXT NOT NULL,monthly_price_cents INT NOT NULL DEFAULT 0,monthly_tokens INT NOT NULL DEFAULT 0,trial_days INT NOT NULL DEFAULT 0,trial_tokens INT NOT NULL DEFAULT 0,is_public TINYINT(1) NOT NULL DEFAULT 1,is_active TINYINT(1) NOT NULL DEFAULT 1,is_featured TINYINT(1) NOT NULL DEFAULT 0,sort_order INT NOT NULL DEFAULT 0,features_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_token_balances (user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,balance BIGINT NOT NULL DEFAULT 0,updated_at VARCHAR(40) NOT NULL,CONSTRAINT fk_token_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS token_ledger (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,delta BIGINT NOT NULL,balance_after BIGINT NOT NULL,entry_type VARCHAR(40) NOT NULL,source VARCHAR(120) NOT NULL DEFAULT '',provider VARCHAR(40) NOT NULL DEFAULT '',model VARCHAR(160) NOT NULL DEFAULT '',input_tokens INT NOT NULL DEFAULT 0,output_tokens INT NOT NULL DEFAULT 0,voice_chars INT NOT NULL DEFAULT 0,note VARCHAR(255) NOT NULL DEFAULT '',created_at VARCHAR(40) NOT NULL,INDEX idx_token_user_created(user_id,created_at),CONSTRAINT fk_ledger_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_subscriptions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL UNIQUE,package_id BIGINT UNSIGNED NULL,status VARCHAR(32) NOT NULL,trial_started_at VARCHAR(40) NULL,trial_ends_at VARCHAR(40) NULL,current_period_start VARCHAR(40) NULL,current_period_end VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,CONSTRAINT fk_sub_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_sub_package FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_trial_history (user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,first_trial_package_id BIGINT UNSIGNED NULL,first_trial_started_at VARCHAR(40) NOT NULL,CONSTRAINT fk_trial_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_trial_package FOREIGN KEY(first_trial_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_conversations (id VARCHAR(80) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,title VARCHAR(160) NOT NULL DEFAULT '',created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_conv_user(user_id,updated_at),CONSTRAINT fk_conv_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_messages (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,conversation_id VARCHAR(80) NOT NULL,user_id BIGINT UNSIGNED NOT NULL,role VARCHAR(20) NOT NULL,content LONGTEXT NOT NULL,provider VARCHAR(40) NOT NULL DEFAULT '',model VARCHAR(160) NOT NULL DEFAULT '',input_tokens INT NOT NULL DEFAULT 0,output_tokens INT NOT NULL DEFAULT 0,created_at VARCHAR(40) NOT NULL,INDEX idx_msg_conv(conversation_id,id),CONSTRAINT fk_msg_conv FOREIGN KEY(conversation_id) REFERENCES agent_conversations(id) ON DELETE CASCADE,CONSTRAINT fk_msg_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_brain_decisions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,conversation_id VARCHAR(80) NOT NULL,phase VARCHAR(32) NOT NULL,route VARCHAR(80) NOT NULL DEFAULT '',action VARCHAR(80) NOT NULL DEFAULT '',context_profile VARCHAR(40) NOT NULL DEFAULT '',needs_llm TINYINT(1) NOT NULL DEFAULT 0,requires_confirmation TINYINT(1) NOT NULL DEFAULT 0,request_excerpt VARCHAR(500) NOT NULL DEFAULT '',response_excerpt VARCHAR(500) NOT NULL DEFAULT '',provider_run_id VARCHAR(160) NOT NULL DEFAULT '',provider_status VARCHAR(40) NOT NULL DEFAULT '',input_tokens INT NOT NULL DEFAULT 0,output_tokens INT NOT NULL DEFAULT 0,details_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_brain_created(created_at),INDEX idx_brain_user(user_id,created_at),CONSTRAINT fk_brain_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // Preserve one-trial-per-account semantics for accounts that already used a trial before v1.1.
    if($driver==='sqlite'){
        $pdo->exec("INSERT OR IGNORE INTO user_trial_history(user_id,first_trial_package_id,first_trial_started_at) SELECT user_id,package_id,trial_started_at FROM user_subscriptions WHERE trial_started_at IS NOT NULL");
    }else{
        $pdo->exec("INSERT IGNORE INTO user_trial_history(user_id,first_trial_package_id,first_trial_started_at) SELECT user_id,package_id,trial_started_at FROM user_subscriptions WHERE trial_started_at IS NOT NULL");
    }
    sf_entitlements_seed_trial();
}

function sf_entitlements_seed_trial(): void {
    $pdo=sf_db();$count=(int)$pdo->query('SELECT COUNT(*) FROM subscription_packages')->fetchColumn();if($count>0)return;
    $now=gmdate('c');$q=$pdo->prepare('INSERT INTO subscription_packages(name,slug,description,monthly_price_cents,monthly_tokens,trial_days,trial_tokens,is_public,is_active,is_featured,sort_order,features_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute(['Free Trial','free-trial','Try the Stonefellow agent, explore the catalog, and build a custom record before choosing a monthly package.',0,0,7,10000,1,1,1,0,json_encode(['10,000 Active Tokens','7-day trial','Catalog + Knowledge agent','Voice when enabled']),$now,$now]);
}
function sf_package_public(array $p): array {if(function_exists('sf_billing_ensure_schema'))sf_billing_ensure_schema();if(function_exists('sf_membership_ensure_schema'))sf_membership_ensure_schema();$membership=function_exists('sf_membership_package')?sf_membership_package($p):['enabled'=>false,'rank'=>0,'badge'=>'','benefits'=>[]];return ['id'=>(int)$p['id'],'name'=>$p['name'],'slug'=>$p['slug'],'description'=>$p['description'],'monthly_price_cents'=>(int)$p['monthly_price_cents'],'monthly_tokens'=>(int)$p['monthly_tokens'],'trial_days'=>(int)$p['trial_days'],'trial_tokens'=>(int)$p['trial_tokens'],'is_featured'=>(bool)$p['is_featured'],'provider_price_id'=>(string)($p['provider_price_id']??''),'features'=>json_decode((string)$p['features_json'],true)?:[],'membership'=>$membership];}
function sf_packages(bool $publicOnly=true): array {sf_entitlements_ensure_schema();if(function_exists('sf_billing_ensure_schema'))sf_billing_ensure_schema();if(function_exists('sf_membership_ensure_schema'))sf_membership_ensure_schema();$sql='SELECT * FROM subscription_packages'.($publicOnly?' WHERE is_active=1 AND is_public=1':'').' ORDER BY sort_order ASC, monthly_price_cents ASC, id ASC';return sf_db()->query($sql)->fetchAll();}
function sf_package(int $id): ?array {sf_entitlements_ensure_schema();if(function_exists('sf_billing_ensure_schema'))sf_billing_ensure_schema();if(function_exists('sf_membership_ensure_schema'))sf_membership_ensure_schema();$q=sf_db()->prepare('SELECT * FROM subscription_packages WHERE id=? LIMIT 1');$q->execute([$id]);$r=$q->fetch();return $r?:null;}
function sf_token_balance(int $userId): int {sf_entitlements_ensure_schema();$q=sf_db()->prepare('SELECT balance FROM user_token_balances WHERE user_id=?');$q->execute([$userId]);$v=$q->fetchColumn();return $v===false?0:(int)$v;}
function sf_token_source_exists(int $userId,string $type,string $source): bool {
    if(!sf_db()->inTransaction())sf_entitlements_ensure_schema();$q=sf_db()->prepare('SELECT id FROM token_ledger WHERE user_id=? AND entry_type=? AND source=? LIMIT 1');$q->execute([$userId,$type,$source]);return $q->fetchColumn()!==false;
}
/**
 * All balance mutations serialize per user. Credit sources are idempotent,
 * including retries that arrive in parallel on different PHP workers.
 * $applied is false when an earlier matching credit is returned unchanged.
 */
function sf_token_adjust(int $userId,int $delta,string $type,string $source='',array $usage=[],string $note='',?bool &$applied=null): int {
    $pdo=sf_db();$ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)sf_entitlements_ensure_schema();
    $driver=(string)(sf_db_config()['driver']??'');
    $applied=false;if($ownsTransaction)$pdo->beginTransaction();
    try{
        $now=gmdate('c');
        if($driver==='sqlite'){
            // First statement takes SQLite's writer lock, including when the
            // balance row already exists. No stale SELECT can precede it.
            $lock=$pdo->prepare('INSERT OR IGNORE INTO user_token_balances(user_id,balance,updated_at) VALUES(?,0,?)');
            $lock->execute([$userId,$now]);
        }else{
            // The users row exists before any balance row. Its InnoDB lock
            // serializes first credits and debits across concurrent workers.
            $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$userId]);
            if($lock->fetchColumn()===false)throw new InvalidArgumentException('Token account does not exist.');
        }
        $q=$pdo->prepare('SELECT balance FROM user_token_balances WHERE user_id=?');$q->execute([$userId]);
        $v=$q->fetchColumn();$before=$v===false?0:(int)$v;
        $credit=in_array($type,['trial_grant','monthly_grant','admin_grant'],true)&&$delta>0;
        if($credit){
            if($source==='')throw new InvalidArgumentException('Token credit requires a unique source.');
            $prior=$pdo->prepare('SELECT delta,note FROM token_ledger WHERE user_id=? AND entry_type=? AND source=? ORDER BY id DESC LIMIT 1');
            $prior->execute([$userId,$type,$source]);$old=$prior->fetch();
            if($old){
                if($type==='admin_grant'&&((int)$old['delta']!==$delta||(string)$old['note']!==sf_clean_text($note,255)))
                    throw new InvalidArgumentException('Grant request conflicts with a previous credit.');
                if($ownsTransaction)$pdo->commit();return $before;
            }
        }
        $after=max(0,$before+$delta);
        if($v===false){$u=$pdo->prepare('INSERT INTO user_token_balances(user_id,balance,updated_at) VALUES(?,?,?)');$u->execute([$userId,$after,$now]);}
        else{$u=$pdo->prepare('UPDATE user_token_balances SET balance=?,updated_at=? WHERE user_id=?');$u->execute([$after,$now,$userId]);}
        $l=$pdo->prepare('INSERT INTO token_ledger(user_id,delta,balance_after,entry_type,source,provider,model,input_tokens,output_tokens,voice_chars,note,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $l->execute([$userId,$after-$before,$after,$type,$source,(string)($usage['provider']??''),(string)($usage['model']??''),(int)($usage['input_tokens']??0),(int)($usage['output_tokens']??0),(int)($usage['voice_chars']??0),sf_clean_text($note,255),$now]);
        if($ownsTransaction)$pdo->commit();$applied=true;return $after;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sf_enroll_user(int $userId,?int $packageId=null): array {
    return sf_subscription_enroll_user($userId,$packageId);
}
function sf_entitlement_state(int $userId): array {
    sf_entitlements_ensure_schema();sf_billing_ensure_schema();
    sf_subscription_refresh_local($userId);
    $q=sf_db()->prepare('SELECT s.*,p.name package_name,p.slug package_slug,p.monthly_price_cents,p.monthly_tokens,p.trial_days,p.trial_tokens,p.provider_price_id FROM user_subscriptions s LEFT JOIN subscription_packages p ON p.id=s.package_id WHERE s.user_id=? LIMIT 1');
    $q->execute([$userId]);$s=$q->fetch()?:null;
    if($s){$s['stored_status']=$s['status'];$s['status']=sf_subscription_effective_status($s);}
    $ledger=sf_db()->prepare('SELECT delta,balance_after,entry_type,source,provider,model,input_tokens,output_tokens,voice_chars,note,created_at FROM token_ledger WHERE user_id=? ORDER BY id DESC LIMIT 20');$ledger->execute([$userId]);$out=['balance'=>sf_token_balance($userId),'subscription'=>$s,'ledger'=>$ledger->fetchAll()];if(function_exists('sf_membership_state'))$out['membership']=sf_membership_state($userId,true);return $out;
}
function sf_can_use_ai(array $user,int $estimated=1): array {if(($user['role']??'')==='admin')return ['ok'=>true,'unlimited'=>true,'balance'=>PHP_INT_MAX];$state=sf_entitlement_state((int)$user['id']);if(!$state['subscription'])$state=sf_enroll_user((int)$user['id'],null);return ['ok'=>$state['balance']>=$estimated,'unlimited'=>false,'balance'=>$state['balance'],'state'=>$state];}
function sf_consume_ai(array $user,int $amount,array $usage,string $source='agent'): int {
    // Unlimited administrators are still metered for real provider activity.
    // Their ledger records provider usage with a zero charge to their balance.
    $admin=($user['role']??'')==='admin';
    $balance=sf_token_adjust((int)$user['id'],$admin?0:-max(0,$amount),'usage',$source,$usage,$admin?'Unlimited admin AI interaction':'AI interaction');
    return $admin?PHP_INT_MAX:$balance;
}

function sf_activate_package(int $userId,int $packageId,string $source='admin'): array {
    sf_entitlements_ensure_schema();
    $p=sf_package($packageId);if(!$p||empty($p['is_active']))throw new InvalidArgumentException('Package is unavailable.');
    $pdo=sf_db();$now=gmdate('c');$end=gmdate('c',strtotime('+1 month'));
    $pdo->beginTransaction();
    try{
        if((string)(sf_db_config()['driver']??'')==='sqlite'){
            $lock=$pdo->prepare('INSERT OR IGNORE INTO user_token_balances(user_id,balance,updated_at) VALUES(?,0,?)');$lock->execute([$userId,$now]);
        }else{
            $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$userId]);
            if($lock->fetchColumn()===false)throw new InvalidArgumentException('User not found.');
        }
        $q=$pdo->prepare('SELECT * FROM user_subscriptions WHERE user_id=? LIMIT 1');$q->execute([$userId]);$existing=$q->fetch()?:null;
        sf_subscription_guard_local_assignment($existing);
        // A locked subscription check prevents two concurrent assignments from
        // each granting the same billing-period allocation.
        $alreadyActive=$existing&&(int)$existing['package_id']===$packageId&&($existing['status']??'')==='active'&&!empty($existing['current_period_end'])&&strtotime((string)$existing['current_period_end'])>time();
        if(!$alreadyActive){
            if($existing){$u=$pdo->prepare("UPDATE user_subscriptions SET package_id=?,status='active',trial_started_at=NULL,trial_ends_at=NULL,current_period_start=?,current_period_end=?,provider='local',cancel_at_period_end=0,canceled_at=NULL,grace_ends_at=NULL,updated_at=? WHERE user_id=?");$u->execute([$packageId,$now,$end,$now,$userId]);}
            else{$u=$pdo->prepare("INSERT INTO user_subscriptions(user_id,package_id,status,trial_started_at,trial_ends_at,current_period_start,current_period_end,created_at,updated_at,provider,provider_customer_id,provider_subscription_id,cancel_at_period_end) VALUES(?,?,'active',NULL,NULL,?,?,?,?,'local','','',0)");$u->execute([$userId,$packageId,$now,$end,$now,$now]);}
            $tokens=max(0,(int)$p['monthly_tokens']);
            if($tokens>0)sf_token_adjust($userId,$tokens,'monthly_grant',$source.':'.$p['slug'].':'.bin2hex(random_bytes(12)),[],'Monthly package activation');
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return sf_entitlement_state($userId);
}
