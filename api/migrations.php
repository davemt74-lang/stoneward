<?php
declare(strict_types=1);

const SF_DB_SCHEMA_TARGET = '1.3.19';

function sf_migration_driver(): string {
    return (string)(sf_db_config()['driver'] ?? '');
}
function sf_migration_table_exists(string $table): bool {
    $pdo=sf_db();$driver=sf_migration_driver();
    if($driver==='sqlite'){
        $q=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");$q->execute([$table]);return (bool)$q->fetchColumn();
    }
    $q=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table));return (bool)$q->fetchColumn();
}
function sf_migration_columns(string $table): array {
    if(!sf_migration_table_exists($table)) return [];
    $pdo=sf_db();$driver=sf_migration_driver();
    if($driver==='sqlite'){
        return array_values(array_map(fn($r)=>(string)$r['name'],$pdo->query('PRAGMA table_info(`'.str_replace('`','',$table).'`)')->fetchAll()));
    }
    return array_values(array_map(fn($r)=>(string)$r['Field'],$pdo->query('SHOW COLUMNS FROM `'.str_replace('`','',$table).'`')->fetchAll()));
}
function sf_migration_registry_ensure(): void {
    $pdo=sf_db();$driver=sf_migration_driver();
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (migration_id TEXT PRIMARY KEY,app_version TEXT NOT NULL,description TEXT NOT NULL,checksum TEXT NOT NULL,execution_ms INTEGER NOT NULL DEFAULT 0,applied_at TEXT NOT NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_upgrade_runs (id INTEGER PRIMARY KEY AUTOINCREMENT,app_version TEXT NOT NULL,status TEXT NOT NULL,backup_file TEXT NOT NULL DEFAULT '',admin_user_id INTEGER NULL,started_at TEXT NOT NULL,completed_at TEXT NULL,error_text TEXT NOT NULL DEFAULT '',FOREIGN KEY(admin_user_id) REFERENCES users(id) ON DELETE SET NULL)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (migration_id VARCHAR(80) NOT NULL PRIMARY KEY,app_version VARCHAR(40) NOT NULL,description VARCHAR(255) NOT NULL,checksum CHAR(64) NOT NULL,execution_ms INT NOT NULL DEFAULT 0,applied_at VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_upgrade_runs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,app_version VARCHAR(40) NOT NULL,status VARCHAR(32) NOT NULL,backup_file VARCHAR(500) NOT NULL DEFAULT '',admin_user_id BIGINT UNSIGNED NULL,started_at VARCHAR(40) NOT NULL,completed_at VARCHAR(40) NULL,error_text TEXT NOT NULL,INDEX idx_upgrade_runs_started(started_at),CONSTRAINT fk_upgrade_run_admin FOREIGN KEY(admin_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
function sf_migration_definitions(): array {
    return [
        [
            'id'=>'2026-10-07-001',
            'app_version'=>'0.7.0',
            'description'=>'Core application metadata, account library/builds, and AI provider settings',
            'revision'=>'1',
            'apply'=>function(): void {
                sf_site_settings_ensure_schema();
                sf_account_data_ensure_schema();
                sf_ai_ensure_schema();
            },
        ],
        [
            'id'=>'2026-10-07-002',
            'app_version'=>'0.8.0',
            'description'=>'Active Tokens, packages, subscriptions, agent conversations, and Agent Brain',
            'revision'=>'1',
            'apply'=>function(): void {
                sf_entitlements_ensure_schema();
            },
        ],
        [
            'id'=>'2026-10-07-003',
            'app_version'=>'1.0.0',
            'description'=>'Persistent authentication sessions and customer account lifecycle',
            'revision'=>'2',
            'apply'=>function(): void {
                sf_customer_lifecycle_ensure_schema();
                sf_auth_ensure_schema();
            },
        ],
        [
            'id'=>'2026-10-07-004',
            'app_version'=>'1.1.0',
            'description'=>'Recurring billing, invoices, Stripe webhook ledger, receipts, and billing audit',
            'revision'=>'2',
            'apply'=>function(): void {
                sf_billing_ensure_schema();
            },
        ],
        [
            'id'=>'2026-10-07-005',
            'app_version'=>'1.2.0',
            'description'=>'Notifications, user history, listening analytics, provider health, audit, throttling, and order idempotency',
            'revision'=>'2',
            'apply'=>function(): void {
                sf_ops_ensure_schema();
            },
        ],
        [
            'id'=>'2026-10-07-006',
            'app_version'=>'1.2.1',
            'description'=>'Final schema normalization and default-data verification',
            'revision'=>'1',
            'apply'=>function(): void {
                sf_site_settings_ensure_schema();
                sf_account_data_ensure_schema();
                sf_ai_ensure_schema();
                sf_entitlements_ensure_schema();
                sf_customer_lifecycle_ensure_schema();
                sf_auth_ensure_schema();
                sf_billing_ensure_schema();
                sf_ops_ensure_schema();
                sf_entitlements_seed_trial();
            },
        ],
        [
            'id'=>'2026-10-08-007',
            'app_version'=>'1.3.0',
            'description'=>'Favorites, My Library personalization, listening history and resume state',
            'revision'=>'1',
            'apply'=>function(): void { sf_personalization_ensure_schema(); },
        ],
        [
            'id'=>'2026-10-08-008',
            'app_version'=>'1.3.1',
            'description'=>'User playlists, ordered playlist tracks, public/private sharing, and agent playlist sessions',
            'revision'=>'1',
            'apply'=>function(): void { sf_playlists_ensure_schema(); },
        ],
        [
            'id'=>'2026-10-08-009',
            'app_version'=>'1.3.5',
            'description'=>'Persistent Agent listening sessions and track preference feedback',
            'revision'=>'1',
            'apply'=>function(): void { sf_listening_sessions_ensure_schema(); },
        ],
        [
            'id'=>'2026-10-08-010',
            'app_version'=>'1.3.6',
            'description'=>'Persistent user Up Next playback queue',
            'revision'=>'1',
            'apply'=>function(): void { sf_queue_ensure_schema(); },
        ],
        [
            'id'=>'2026-10-08-011',
            'app_version'=>'1.3.10',
            'description'=>'Smart notification preferences, dedupe, generation state, events and conversion analytics',
            'revision'=>'1',
            'apply'=>function(): void { sf_notification_ensure_schema(); },
        ],
        [
            'id'=>'2026-10-08-012',
            'app_version'=>'1.3.11',
            'description'=>'Catalog search telemetry, discovery analytics, and selected-result attribution',
            'revision'=>'1',
            'apply'=>function(): void { sf_search_ensure_schema(); },
        ],
        [
            'id'=>'2026-10-08-013',
            'app_version'=>'1.3.12',
            'description'=>'User My Library collections and mixed saved-item organization',
            'revision'=>'1',
            'apply'=>function(): void { sf_library_ensure_schema(); },
        ],
        [
            'id'=>'2026-10-09-014',
            'app_version'=>'1.3.15',
            'description'=>'Fan CRM, newsletter consent, community posts, and governed proactive Agent engagement',
            'revision'=>'1',
            'apply'=>function(): void { sf_crm_ensure_schema(); },
        ],
        [
            'id'=>'2026-10-09-015',
            'app_version'=>'1.3.17',
            'description'=>'Campaign builder, fan acquisition, offers, entitlements, message runs, participants, and attribution',
            'revision'=>'1',
            'apply'=>function(): void { sf_campaign_ensure_schema(); },
        ],
        [
            'id'=>'2026-10-09-016',
            'app_version'=>'1.3.18',
            'description'=>'Central Media Library, uploader metadata extraction, media relationships, and controlled delivery',
            'revision'=>'1',
            'apply'=>function(): void { sf_media_ensure_schema(); sf_media_backfill_catalog(); },
        ],
        [
            'id'=>'2026-10-09-017',
            'app_version'=>'1.3.19',
            'description'=>'Universal media integration for releases, shows, campaigns, store products, and site publishing',
            'revision'=>'1',
            'apply'=>function(): void { sf_media_ensure_schema(); sf_media_backfill_universal(); },
        ],
        [
            'id'=>'2026-10-10-018',
            'app_version'=>'1.3.20',
            'description'=>'Merch product catalog, variants, audited inventory reservations, direct commerce and CRM purchase intelligence',
            'revision'=>'1',
            'apply'=>function(): void { sf_commerce_ensure_schema(); },
        ],
        [
            'id'=>'2026-10-10-019',
            'app_version'=>'1.3.21',
            'description'=>'Dynamic fan segments, lifecycle automations, journey runs, waits, triggers, and governed CRM actions',
            'revision'=>'1',
            'apply'=>function(): void { sf_automation_ensure_schema(); sf_segment_refresh_all(); },
        ],
        [
            'id'=>'2026-10-10-020',
            'app_version'=>'1.3.22',
            'description'=>'Membership tiers, VIP benefits, member content, early access and CRM membership synchronization',
            'revision'=>'1',
            'apply'=>function(): void { sf_membership_ensure_schema(); foreach(sf_db()->query('SELECT user_id FROM user_subscriptions')->fetchAll(PDO::FETCH_COLUMN) as $uid) sf_membership_sync_crm((int)$uid); },
        ],
    ];
}
function sf_migration_checksum(array $m): string {
    return hash('sha256',(string)$m['id'].'|'.(string)$m['app_version'].'|'.(string)$m['description'].'|'.(string)$m['revision']);
}
function sf_migration_applied_map(): array {
    if(!sf_migration_table_exists('schema_migrations'))return [];
    $rows=sf_db()->query('SELECT migration_id,app_version,description,checksum,execution_ms,applied_at FROM schema_migrations ORDER BY migration_id ASC')->fetchAll();
    $out=[];foreach($rows as $r)$out[(string)$r['migration_id']]=$r;return $out;
}
function sf_migration_status(): array {
    $applied=sf_migration_applied_map();$rows=[];$pending=0;$drift=0;
    foreach(sf_migration_definitions() as $m){
        $id=(string)$m['id'];$expected=sf_migration_checksum($m);$old=$applied[$id]??null;
        $status='pending';
        if($old){
            $status=hash_equals($expected,(string)$old['checksum'])?'applied':'drift';
            if($status==='drift')$drift++;
        }else{$pending++;}
        $rows[]=[
            'id'=>$id,'app_version'=>$m['app_version'],'description'=>$m['description'],'checksum'=>$expected,
            'status'=>$status,'applied_at'=>$old['applied_at']??null,'execution_ms'=>(int)($old['execution_ms']??0),
        ];
    }
    return ['target'=>SF_DB_SCHEMA_TARGET,'pending'=>$pending,'drift'=>$drift,'current'=>$pending===0&&$drift===0,'migrations'=>$rows];
}
function sf_migration_core_preflight(): array {
    $checks=[];$errors=[];$cfg=sf_db_config();$driver=(string)($cfg['driver']??'');
    $checks[]=['name'=>'Database driver','ok'=>in_array($driver,['sqlite','mysql'],true),'detail'=>$driver?:'missing'];
    try{$pdo=sf_db();$pdo->query('SELECT 1');$checks[]=['name'=>'Database connection','ok'=>true,'detail'=>'Connected'];}
    catch(Throwable $e){$checks[]=['name'=>'Database connection','ok'=>false,'detail'=>$e->getMessage()];$errors[]=$e->getMessage();return ['ok'=>false,'checks'=>$checks,'errors'=>$errors];}
    $users=sf_migration_table_exists('users');$checks[]=['name'=>'Installed users table','ok'=>$users,'detail'=>$users?'Found':'Missing'];
    if(!$users){$errors[]='The users table is missing. This does not look like an installed Stonefellow database.';}
    else{
        $cols=sf_migration_columns('users');$required=['id','email','password_hash','display_name','role','status','created_at','updated_at','last_login_at'];$missing=array_values(array_diff($required,$cols));
        $ok=!$missing;$checks[]=['name'=>'Base account schema','ok'=>$ok,'detail'=>$ok?'Ready':'Missing: '.implode(', ',$missing)];
        if(!$ok)$errors[]='Base account schema is incomplete: '.implode(', ',$missing);
    }
    $backupDir=SF_ROOT.'/storage/backups';
    if(!is_dir($backupDir))@mkdir($backupDir,0770,true);
    $writable=is_dir($backupDir)&&is_writable($backupDir);
    $checks[]=['name'=>'Backup directory','ok'=>$writable,'detail'=>$writable?'storage/backups is writable':'storage/backups is not writable'];
    if(!$writable)$errors[]='The backup directory is not writable.';
    $free=@disk_free_space(SF_ROOT);$estimate=0;
    try{
        if($driver==='sqlite'){$dbPath=(string)($cfg['path']??'');if($dbPath!==''&&is_file($dbPath))$estimate=(int)filesize($dbPath);}
        elseif($driver==='mysql'){
            $dbName=(string)($cfg['database']??'');
            $q=$pdo->prepare('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.TABLES WHERE table_schema=?');$q->execute([$dbName]);$estimate=(int)$q->fetchColumn();
        }
    }catch(Throwable $e){}
    $required=max(50*1024*1024,(int)ceil($estimate*2.0)+20*1024*1024);
    $spaceOk=$free===false||$free>$required;
    $detail=$free===false?'Unknown':round($free/1048576).' MB free; approx. '.round($required/1048576).' MB recommended for backup';
    $checks[]=['name'=>'Free disk space','ok'=>$spaceOk,'detail'=>$detail];
    if(!$spaceOk)$errors[]='Not enough free disk space is available for a safe pre-upgrade backup.';
    return ['ok'=>!$errors,'checks'=>$checks,'errors'=>$errors];
}
function sf_upgrade_backup_dir(): string {
    $dir=SF_ROOT.'/storage/backups';
    if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Could not create storage/backups.');
    if(!is_writable($dir))throw new RuntimeException('storage/backups is not writable.');
    return $dir;
}
function sf_upgrade_backup_sqlite(): array {
    $cfg=sf_db_config();$source=(string)($cfg['path']??'');if($source===''||!is_file($source))throw new RuntimeException('SQLite database file could not be found.');
    $dir=sf_upgrade_backup_dir();$name='stonefellow-pre-upgrade-'.gmdate('Ymd-His').'.sqlite';$dest=$dir.'/'.$name;$pdo=sf_db();
    try{$pdo->exec('PRAGMA wal_checkpoint(FULL)');}catch(Throwable $e){}
    $created=false;
    try{$pdo->exec('VACUUM INTO '.$pdo->quote($dest));$created=is_file($dest);}catch(Throwable $e){}
    if(!$created){
        if(!@copy($source,$dest))throw new RuntimeException('Could not create the SQLite backup.');
    }
    @chmod($dest,0640);
    return ['file'=>'storage/backups/'.$name,'bytes'=>(int)@filesize($dest),'driver'=>'sqlite'];
}
function sf_upgrade_mysql_ident(string $name): string {return '`'.str_replace('`','``',$name).'`';}
function sf_upgrade_mysql_value(PDO $pdo,mixed $value): string {
    if($value===null)return 'NULL';
    if(is_bool($value))return $value?'1':'0';
    return $pdo->quote((string)$value);
}
function sf_upgrade_backup_mysql(): array {
    $pdo=sf_db();$cfg=sf_db_config();$dbName=(string)($cfg['database']??'stonefellow');$dir=sf_upgrade_backup_dir();
    $name='stonefellow-pre-upgrade-'.gmdate('Ymd-His').'.sql';$dest=$dir.'/'.$name;$fh=@fopen($dest,'xb');if(!$fh)throw new RuntimeException('Could not create MySQL backup file.');
    $started=false;
    try{
        fwrite($fh,"-- Stonefellow database backup\n-- Database: ".$dbName."\n-- Created: ".gmdate('c')."\n\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        try{$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');$started=true;}catch(Throwable $e){}
        $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach($tables as $tr){
            $table=(string)$tr[0];$ident=sf_upgrade_mysql_ident($table);
            $createRow=$pdo->query('SHOW CREATE TABLE '.$ident)->fetch(PDO::FETCH_NUM);
            $create=(string)($createRow[1]??'');
            fwrite($fh,"DROP TABLE IF EXISTS ".$ident.";\n".$create.";\n\n");
            $q=$pdo->query('SELECT * FROM '.$ident);$columns=null;
            while($row=$q->fetch(PDO::FETCH_ASSOC)){
                if($columns===null)$columns=array_keys($row);
                $colSql=implode(',',array_map('sf_upgrade_mysql_ident',$columns));
                $valSql=implode(',',array_map(fn($v)=>sf_upgrade_mysql_value($pdo,$v),array_values($row)));
                fwrite($fh,'INSERT INTO '.$ident.' ('.$colSql.') VALUES ('.$valSql.");\n");
            }
            fwrite($fh,"\n");
        }
        if($started)$pdo->exec('COMMIT');
        fwrite($fh,"SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);@chmod($dest,0640);
        return ['file'=>'storage/backups/'.$name,'bytes'=>(int)@filesize($dest),'driver'=>'mysql'];
    }catch(Throwable $e){
        if($started){try{$pdo->exec('ROLLBACK');}catch(Throwable $ignore){}}
        fclose($fh);@unlink($dest);throw $e;
    }
}
function sf_upgrade_backup_database(): array {
    return sf_migration_driver()==='sqlite'?sf_upgrade_backup_sqlite():sf_upgrade_backup_mysql();
}
function sf_migration_apply_one(array $m): array {
    $pdo=sf_db();$driver=sf_migration_driver();$start=microtime(true);$tx=false;
    try{
        if($driver==='sqlite'&&!$pdo->inTransaction()){$pdo->beginTransaction();$tx=true;}
        ($m['apply'])();
        $ms=(int)round((microtime(true)-$start)*1000);$now=gmdate('c');
        $q=$pdo->prepare('INSERT INTO schema_migrations(migration_id,app_version,description,checksum,execution_ms,applied_at) VALUES(?,?,?,?,?,?)');
        $q->execute([(string)$m['id'],(string)$m['app_version'],(string)$m['description'],sf_migration_checksum($m),$ms,$now]);
        if($tx)$pdo->commit();
        return ['id'=>$m['id'],'status'=>'pass','description'=>$m['description'],'execution_ms'=>$ms,'applied_at'=>$now];
    }catch(Throwable $e){
        if($tx&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
function sf_migration_run_all(int $adminUserId,string $backupFile): array {
    sf_migration_registry_ensure();$status=sf_migration_status();
    if($status['drift']>0)throw new RuntimeException('Migration checksum drift was detected. Do not continue until the changed migration is reviewed.');
    $pdo=sf_db();$started=gmdate('c');
    $q=$pdo->prepare('INSERT INTO schema_upgrade_runs(app_version,status,backup_file,admin_user_id,started_at,completed_at,error_text) VALUES(?,?,?,?,?,NULL,?)');
    $q->execute([SF_DB_SCHEMA_TARGET,'running',$backupFile,$adminUserId,$started,'']);$runId=(int)$pdo->lastInsertId();
    $results=[];
    try{
        $applied=sf_migration_applied_map();
        foreach(sf_migration_definitions() as $m){
            if(isset($applied[$m['id']])){$results[]=['id'=>$m['id'],'status'=>'skip','description'=>$m['description'],'execution_ms'=>(int)$applied[$m['id']]['execution_ms'],'applied_at'=>$applied[$m['id']]['applied_at']];continue;}
            $results[]=sf_migration_apply_one($m);
            $applied[(string)$m['id']]=true;
        }
        if(function_exists('sf_ai_meta_set'))sf_ai_meta_set('db.schema_version',SF_DB_SCHEMA_TARGET);
        $done=gmdate('c');$pdo->prepare("UPDATE schema_upgrade_runs SET status='completed',completed_at=? WHERE id=?")->execute([$done,$runId]);
        return ['ok'=>true,'run_id'=>$runId,'started_at'=>$started,'completed_at'=>$done,'results'=>$results];
    }catch(Throwable $e){
        $done=gmdate('c');$pdo->prepare("UPDATE schema_upgrade_runs SET status='failed',completed_at=?,error_text=? WHERE id=?")->execute([$done,substr($e->getMessage(),0,2000),$runId]);
        throw $e;
    }
}
function sf_migration_integrity_report(): array {
    $requiredTables=[
        'users','app_meta','user_sessions','user_saved_builds','user_library','ai_provider_settings',
        'subscription_packages','user_token_balances','token_ledger','user_subscriptions','user_trial_history',
        'agent_conversations','agent_messages','agent_brain_decisions','account_action_tokens',
        'transactional_email_outbox','billing_webhook_events','billing_invoices','billing_audit_log',
        'user_notifications','user_notification_preferences','notification_delivery_keys','notification_events','notification_generation_state','catalog_search_events','user_collections','user_collection_items','fan_contacts','fan_crm_events','fan_agent_engagements','community_posts','campaigns','campaign_segments','campaign_participants','campaign_events','campaign_entitlements','campaign_message_runs','media_assets','media_links','store_products','store_variants','store_inventory_events','store_order_items','user_activity','listening_events','provider_health_checks','admin_audit_log',
        'site_event_log','auth_attempts','order_request_keys','user_favorites','user_listening_progress','user_playlists','user_playlist_tracks','user_track_feedback','agent_listening_sessions','agent_listening_session_tracks','user_play_queue','schema_migrations','schema_upgrade_runs'
    ];
    $checks=[];$ok=true;
    foreach($requiredTables as $t){$exists=sf_migration_table_exists($t);$checks[]=['name'=>'Table '.$t,'ok'=>$exists,'detail'=>$exists?'Present':'Missing'];if(!$exists)$ok=false;}
    $userCols=sf_migration_columns('users');foreach(['email_verified_at','email_verification_sent_at'] as $c){$has=in_array($c,$userCols,true);$checks[]=['name'=>'users.'.$c,'ok'=>$has,'detail'=>$has?'Present':'Missing'];if(!$has)$ok=false;}
    $subCols=sf_migration_columns('user_subscriptions');foreach(['provider','provider_customer_id','provider_subscription_id','cancel_at_period_end','canceled_at','grace_ends_at','last_invoice_id','last_payment_at'] as $c){$has=in_array($c,$subCols,true);$checks[]=['name'=>'user_subscriptions.'.$c,'ok'=>$has,'detail'=>$has?'Present':'Missing'];if(!$has)$ok=false;}
    $pkgCols=sf_migration_columns('subscription_packages');foreach(['provider_price_id','membership_enabled','membership_rank','membership_badge','membership_benefits_json'] as $c){$has=in_array($c,$pkgCols,true);$checks[]=['name'=>'subscription_packages.'.$c,'ok'=>$has,'detail'=>$has?'Present':'Missing'];if(!$has)$ok=false;}
    $aiCols=sf_migration_columns('ai_provider_settings');$has=in_array('endpoint_url',$aiCols,true);$checks[]=['name'=>'ai_provider_settings.endpoint_url','ok'=>$has,'detail'=>$has?'Present':'Missing'];if(!$has)$ok=false;
    return ['ok'=>$ok,'checks'=>$checks];
}
function sf_upgrade_recent_runs(int $limit=12): array {
    if(!sf_migration_table_exists('schema_upgrade_runs'))return [];
    $limit=max(1,min(50,$limit));
    return sf_db()->query('SELECT id,app_version,status,backup_file,admin_user_id,started_at,completed_at,error_text FROM schema_upgrade_runs ORDER BY id DESC LIMIT '.$limit)->fetchAll();
}
