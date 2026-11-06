<?php
declare(strict_types=1);
require_once __DIR__.'/listening-playback-core.php';

require_once __DIR__.'/agent-mission-planner.php';
require_once __DIR__.'/agent-feedback-core.php';
require_once __DIR__.'/agent-customer-brain.php';
require_once __DIR__.'/agent-scene-requests.php';
require_once __DIR__.'/agent-canvas-images.php';
require_once __DIR__.'/ai-token-reservations.php';
require_once __DIR__.'/image-generation-core.php';
require_once __DIR__.'/image-analysis-batch-privacy.php';
function sf_privacy_json(array $v): string { return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?: '{}'; }

/** Optional canvas storage may not exist yet; privacy helpers never initialize its schema. */
function sf_privacy_canvas_project_helpers(): void {
    foreach([
        'canvas-projects-core.php'=>['sf_canvas_project_export','sf_canvas_project_clear'],
        'canvas-project-ideas.php'=>['sf_canvas_project_ideas_export','sf_canvas_project_ideas_clear'],
        'canvas-feedback-core.php'=>['sf_canvas_feedback_export','sf_canvas_feedback_clear'],
        'canvas-build-workflow-core.php'=>['sf_canvas_build_workflow_export','sf_canvas_build_workflow_clear'],
        'canvas-access-core.php'=>['sf_canvas_access_export','sf_canvas_access_clear'],
        'canvas-gallery-core.php'=>['sf_canvas_gallery_export','sf_canvas_gallery_clear'],
    ] as $file=>$functions){
        if(function_exists($functions[0])&&function_exists($functions[1]))continue;
        $path=__DIR__.'/'.$file;
        if(is_file($path))require_once $path;
    }
}

function sf_privacy_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_profiles (user_id INTEGER PRIMARY KEY,timezone TEXT NOT NULL DEFAULT '',country_code TEXT NOT NULL DEFAULT '',locale TEXT NOT NULL DEFAULT 'en-US',listening_history_enabled INTEGER NOT NULL DEFAULT 1,personalization_enabled INTEGER NOT NULL DEFAULT 1,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_privacy_events (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NULL,event_type TEXT NOT NULL,metadata_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_privacy_events_user_created ON user_privacy_events(user_id,created_at)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_profiles (user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,timezone VARCHAR(80) NOT NULL DEFAULT '',country_code CHAR(2) NOT NULL DEFAULT '',locale VARCHAR(20) NOT NULL DEFAULT 'en-US',listening_history_enabled TINYINT(1) NOT NULL DEFAULT 1,personalization_enabled TINYINT(1) NOT NULL DEFAULT 1,updated_at VARCHAR(40) NOT NULL,CONSTRAINT fk_user_profile_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_privacy_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,event_type VARCHAR(60) NOT NULL,metadata_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_privacy_events_user_created(user_id,created_at),CONSTRAINT fk_privacy_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_privacy_default_profile(int $userId): array {return ['user_id'=>$userId,'timezone'=>'','country_code'=>'','locale'=>'en-US','listening_history_enabled'=>true,'personalization_enabled'=>true,'updated_at'=>''];}
function sf_privacy_profile(int $userId): array {
    sf_privacy_ensure_schema();$q=sf_db()->prepare('SELECT user_id,timezone,country_code,locale,listening_history_enabled,personalization_enabled,updated_at FROM user_profiles WHERE user_id=? LIMIT 1');$q->execute([$userId]);$r=$q->fetch();if(!$r)return sf_privacy_default_profile($userId);
    $r['user_id']=(int)$r['user_id'];$r['listening_history_enabled']=!empty($r['listening_history_enabled']);$r['personalization_enabled']=!empty($r['personalization_enabled']);return $r;
}
function sf_privacy_save_profile(int $userId,array $raw): array {
    sf_privacy_ensure_schema();sf_ops_ensure_schema();$tz=sf_clean_text($raw['timezone']??'',80);if($tz!==''&&!in_array($tz,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Choose a valid timezone.');
    $country=strtoupper(sf_clean_text($raw['country_code']??'',2));if($country!==''&&!preg_match('/^[A-Z]{2}$/',$country))throw new InvalidArgumentException('Country code must use two letters.');
    $locale=sf_clean_text($raw['locale']??'en-US',20);if(!preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/',$locale))throw new InvalidArgumentException('Locale format is invalid.');
    $history=!empty($raw['listening_history_enabled'])?1:0;$personal=!empty($raw['personalization_enabled'])?1:0;$now=gmdate('c');$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite')$sql='INSERT INTO user_profiles(user_id,timezone,country_code,locale,listening_history_enabled,personalization_enabled,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET timezone=excluded.timezone,country_code=excluded.country_code,locale=excluded.locale,listening_history_enabled=excluded.listening_history_enabled,personalization_enabled=excluded.personalization_enabled,updated_at=excluded.updated_at';
    else $sql='INSERT INTO user_profiles(user_id,timezone,country_code,locale,listening_history_enabled,personalization_enabled,updated_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE timezone=VALUES(timezone),country_code=VALUES(country_code),locale=VALUES(locale),listening_history_enabled=VALUES(listening_history_enabled),personalization_enabled=VALUES(personalization_enabled),updated_at=VALUES(updated_at)';
    $db=sf_db();$owns=!$db->inTransaction();if($owns)$db->beginTransaction();
    try{
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$db->prepare('UPDATE users SET id=id WHERE id=?')->execute([$userId]);
        $q=$db->prepare("SELECT id FROM users WHERE id=? AND status='active'".($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$userId]);$active=$q->fetchColumn()!==false;$q->closeCursor();if(!$active)throw new InvalidArgumentException('Account unavailable.');
        $before=sf_listening_playback_enabled($userId);$db->prepare($sql)->execute([$userId,$tz,$country,$locale,$history,$personal,$now]);
        if($before!==((bool)$history))sf_listening_playback_forget($userId);
        if($owns)$db->commit();
    }catch(Throwable $e){if($owns&&$db->inTransaction())$db->rollBack();throw $e;}
    sf_privacy_log($userId,'privacy_preferences_updated',['listening_history_enabled'=>(bool)$history,'personalization_enabled'=>(bool)$personal,'timezone_set'=>$tz!=='','country_set'=>$country!=='']);sf_log_user_activity($userId,'privacy_preferences','Updated privacy and profile preferences','account',(string)$userId);return sf_privacy_profile($userId);
}
function sf_privacy_listening_history_enabled(int $userId): bool { return $userId<1?false:!empty(sf_privacy_profile($userId)['listening_history_enabled']); }
function sf_privacy_personalization_enabled(int $userId): bool { return $userId<1?false:!empty(sf_privacy_profile($userId)['personalization_enabled']); }
function sf_privacy_log(?int $userId,string $eventType,array $meta=[]): void {sf_privacy_ensure_schema();$safe=[];foreach(array_slice($meta,0,16,true) as $k=>$v)$safe[sf_clean_text((string)$k,60)]=is_scalar($v)?sf_clean_text((string)$v,300):'';sf_db()->prepare('INSERT INTO user_privacy_events(user_id,event_type,metadata_json,created_at) VALUES(?,?,?,?)')->execute([$userId?:null,sf_clean_text($eventType,60),sf_privacy_json($safe),gmdate('c')]);}
function sf_privacy_event_summary(int $userId,int $limit=25): array {$limit=max(1,min(100,$limit));$q=sf_db()->prepare('SELECT event_type,metadata_json,created_at FROM user_privacy_events WHERE user_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$userId]);$rows=$q->fetchAll();foreach($rows as &$r){$m=json_decode((string)$r['metadata_json'],true);$r['metadata']=is_array($m)?$m:[];unset($r['metadata_json']);}unset($r);return $rows;}

function sf_privacy_orders_for_user(int $userId,string $email): array {
    $rows=[];foreach(glob(SF_ROOT.'/storage/orders/*.json')?:[] as $p){$o=json_decode((string)file_get_contents($p),true);if(!is_array($o))continue;$oid=(int)($o['user_id']??0);$oe=strtolower((string)($o['customer']['email']??''));if($oid!==$userId&&($oid!==0||$oe!==strtolower($email)))continue;$rows[]=$o;}usort($rows,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));return $rows;
}
function sf_privacy_agent_export(int $userId): array {
    $out=[];try{$q=sf_db()->prepare('SELECT id,title,created_at,updated_at FROM agent_conversations WHERE user_id=? ORDER BY id ASC');$q->execute([$userId]);foreach($q->fetchAll() as $c){$m=sf_db()->prepare('SELECT role,content,created_at FROM agent_messages WHERE conversation_id=? AND user_id=? ORDER BY id ASC');$m->execute([(string)$c['id'],$userId]);$out[]=['id'=>(string)$c['id'],'title'=>(string)($c['title']??''),'created_at'=>(string)$c['created_at'],'updated_at'=>(string)$c['updated_at'],'messages'=>$m->fetchAll()];}}catch(Throwable $e){}return $out;
}
function sf_privacy_support_export(int $userId): array {
    $out=[];try{$q=sf_db()->prepare('SELECT id,case_key,order_id,issue_type,subject,status,created_at,updated_at FROM support_cases WHERE user_id=? ORDER BY id ASC');$q->execute([$userId]);foreach($q->fetchAll() as $c){$m=sf_db()->prepare('SELECT actor_type,message,created_at FROM support_messages WHERE case_id=? ORDER BY id ASC');$m->execute([(int)$c['id']]);$c['messages']=$m->fetchAll();$out[]=$c;}}catch(Throwable $e){}return $out;
}
function sf_privacy_telemetry_export(int $userId): array {
    $out=[];foreach(['catalog_search_events','discovery_events','artist_home_events','story_events','songbook_events','video_events','photo_gallery_events'] as $table){try{$q=sf_db()->prepare('SELECT * FROM '.$table.' WHERE user_id=? ORDER BY id ASC LIMIT 2000');$q->execute([$userId]);$out[$table]=$q->fetchAll();}catch(Throwable $e){}}return $out;
}
function sf_privacy_export(int $userId,array $user): array {
    sf_privacy_canvas_project_helpers();
    sf_privacy_ensure_schema();$profile=sf_privacy_profile($userId);$orders=sf_privacy_orders_for_user($userId,(string)$user['email']);
    $account=['id'=>$userId,'email'=>(string)$user['email'],'display_name'=>(string)$user['display_name'],'role'=>(string)($user['role']??'user'),'status'=>(string)($user['status']??''),'created_at'=>(string)($user['created_at']??''),'last_login_at'=>(string)($user['last_login_at']??''),'email_verified_at'=>(string)($user['email_verified_at']??'')];
    $out=['export_version'=>1,'generated_at'=>gmdate('c'),'account'=>$account,'profile'=>$profile,'sessions'=>function_exists('sf_auth_sessions')?sf_auth_sessions($userId):[],'notifications'=>function_exists('sf_notification_preferences')?sf_notification_preferences($userId):[],'fan_preferences'=>function_exists('sf_crm_newsletter_state_for_user')?sf_crm_newsletter_state_for_user($userId):[],'favorites'=>function_exists('sf_personalization_favorites')?sf_personalization_favorites($userId):[],'listening_playbacks'=>sf_listening_playback_export($userId),'listening_history'=>function_exists('sf_personalization_history')?sf_personalization_history($userId,200):[],'imported_guest_recent_signals'=>function_exists('sf_guest_migration_recent_for_user')?sf_guest_migration_recent_for_user($userId):[],'continue_listening'=>function_exists('sf_personalization_continue_listening')?sf_personalization_continue_listening($userId,50):[],'playlists'=>function_exists('sf_playlist_list')?sf_playlist_list($userId):[],'library'=>function_exists('sf_library_snapshot')?sf_library_snapshot($userId):[],'saved_builds'=>function_exists('sf_account_saved_builds')?sf_account_saved_builds($userId):[],'purchases'=>$orders,'invoices'=>function_exists('sf_billing_invoices')?sf_billing_invoices($userId):[],'membership'=>function_exists('sf_membership_state')?sf_membership_state($userId,false):null,'account_activity'=>function_exists('sf_user_history')?sf_user_history($userId,500):[],'agent_memories'=>function_exists('sf_agent_customer_memory_list')?sf_agent_customer_memory_list($userId):[],
        'agent_mission_plans'=>sf_agent_mission_plan_export('customer',$userId),'canvas_history'=>sf_agent_scene_history_export($userId),
        'canvas_projects'=>function_exists('sf_canvas_project_export')?sf_canvas_project_export($userId):[],
        'canvas_project_idea_requests'=>function_exists('sf_canvas_project_ideas_export')?sf_canvas_project_ideas_export($userId):[],
        'canvas_feedback'=>function_exists('sf_canvas_feedback_export')?sf_canvas_feedback_export($userId):[],
        'canvas_build_workflows'=>function_exists('sf_canvas_build_workflow_export')?sf_canvas_build_workflow_export($userId):[],
        'canvas_access_grants'=>function_exists('sf_canvas_access_export')?sf_canvas_access_export($userId):[],
        'canvas_gallery'=>function_exists('sf_canvas_gallery_export')?sf_canvas_gallery_export($userId):[],
        'photo_preferences'=>sf_image_profile_export($userId),'private_photo_analysis'=>sf_agent_canvas_photo_export($userId),
        'image_analysis_batches'=>sf_image_analysis_batch_export($userId),
        'agent_response_feedback'=>sf_agent_feedback_export('customer',$userId),'agent_conversations'=>sf_privacy_agent_export($userId),'agent_brain'=>function_exists('sf_user_brain_timeline')?sf_user_brain_timeline($userId,300):[],'support'=>sf_privacy_support_export($userId),'telemetry'=>sf_privacy_telemetry_export($userId),'privacy_events'=>sf_privacy_event_summary($userId,100),'retention_note'=>'Closing an account removes or anonymizes fan-behavior/profile data. Historical orders, invoices, refunds and support records may remain as operational/accounting records.'];
    sf_privacy_log($userId,'data_export_generated',['format'=>'json','orders'=>count($orders)]);return $out;
}
function sf_privacy_anonymize_user_events(int $userId): array {
    $listening=sf_listening_playback_forget($userId);
    $tables=['listening_events','catalog_search_events','discovery_events','artist_home_events','story_events','songbook_events','video_events','photo_gallery_events'];$updated=[];
    foreach($tables as $table){$q=sf_db()->prepare('UPDATE '.$table.' SET user_id=NULL WHERE user_id=?');$q->execute([$userId]);$updated[$table]=$q->rowCount()+($table==='listening_events'?$listening:0);}
    return $updated;
}
function sf_privacy_clear_search_activity(int $userId): array {$updated=[];foreach(['catalog_search_events','discovery_events'] as $table){try{$q=sf_db()->prepare('UPDATE '.$table.' SET user_id=NULL WHERE user_id=?');$q->execute([$userId]);$updated[$table]=$q->rowCount();}catch(Throwable $e){}}sf_privacy_log($userId,'search_activity_anonymized',['events'=>array_sum($updated)]);sf_log_user_activity($userId,'search_activity_cleared','Cleared identifiable search/discovery activity','account',(string)$userId);return $updated;}
function sf_privacy_reset_personalization(int $userId): array {
    if(function_exists('sf_agent_customer_memory_clear'))sf_agent_customer_memory_clear($userId);$photoPreferences=sf_image_profile_clear($userId);$history=function_exists('sf_personalization_clear_history')?sf_personalization_clear_history($userId):['anonymized_events'=>0];$feedback=0;try{$q=sf_db()->prepare('DELETE FROM user_track_feedback WHERE user_id=?');$q->execute([$userId]);$feedback=$q->rowCount();}catch(Throwable $e){}sf_privacy_log($userId,'personalization_reset',['history_events'=>$history['anonymized_events']??0,'feedback_rows'=>$feedback,'photo_preferences'=>$photoPreferences]);return ['history'=>$history,'feedback_rows'=>$feedback,'photo_preferences'=>$photoPreferences];
}
function sf_privacy_clear_agent_history(int $userId): array {
    sf_privacy_canvas_project_helpers();
    $pdo=sf_db();$ownTransaction=!$pdo->inTransaction();
    if($ownTransaction){
        sf_privacy_ensure_schema();sf_agent_scene_request_ensure_schema();sf_ops_ensure_schema();
        $pdo->beginTransaction();
    }
    try{
        sf_agent_scene_history_lock_account($pdo,$userId);
        $galleryIds=$ownTransaction&&function_exists('sf_canvas_gallery_owned_ids')?sf_canvas_gallery_owned_ids($userId):[];
        sf_agent_scene_request_clear($userId);
        $canvasGallery=function_exists('sf_canvas_gallery_clear')?sf_canvas_gallery_clear($userId):[];
        $canvasBuilds=function_exists('sf_canvas_build_workflow_clear')?sf_canvas_build_workflow_clear($userId):[];
        $canvasAccess=function_exists('sf_canvas_access_clear')?sf_canvas_access_clear($userId):0;
        $listeningPrivate=sf_listening_playback_forget($userId,null,true);
        $canvasScenes=sf_agent_scene_history_clear($userId);
        $canvasProjects=function_exists('sf_canvas_project_clear')?sf_canvas_project_clear($userId,false):[];
        $canvasIdeas=function_exists('sf_canvas_project_ideas_clear')?sf_canvas_project_ideas_clear($userId):0;
        $canvasFeedback=function_exists('sf_canvas_feedback_clear')?sf_canvas_feedback_clear($userId):0;
        $messages=0;$q=$pdo->prepare('SELECT id FROM agent_conversations WHERE user_id=?');$q->execute([$userId]);$ids=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
        if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$m=$pdo->prepare('DELETE FROM agent_messages WHERE conversation_id IN ('.$ph.')');$m->execute($ids);$messages=$m->rowCount();}
        $m=$pdo->prepare('DELETE FROM agent_messages WHERE user_id=?');$m->execute([$userId]);$messages+=$m->rowCount();
        $q=$pdo->prepare('DELETE FROM agent_conversations WHERE user_id=?');$q->execute([$userId]);$conversations=$q->rowCount();
        $q=$pdo->prepare('DELETE FROM agent_brain_decisions WHERE user_id=?');$q->execute([$userId]);sf_agent_mission_plan_clear('customer',$userId);sf_agent_feedback_clear('customer',$userId);if(function_exists('sf_agent_customer_memory_clear'))sf_agent_customer_memory_clear($userId);
        $result=['listening_private_links'=>$listeningPrivate,'conversations'=>$conversations,'messages'=>$messages,'canvas_scenes'=>$canvasScenes,'canvas_access_grants'=>$canvasAccess,'photo_preferences'=>sf_image_profile_clear($userId),
            'canvas_project_idea_requests'=>$canvasIdeas,'canvas_feedback'=>$canvasFeedback]+$canvasProjects+$canvasBuilds+$canvasGallery;
        sf_privacy_log($userId,'agent_history_cleared',$result);
        sf_log_user_activity($userId,'agent_history_cleared','Cleared personal Agent conversation history and canvas scenes','account',(string)$userId);
        if($ownTransaction){
            $pdo->commit();
            if(function_exists('sf_canvas_gallery_cleanup_files'))sf_canvas_gallery_cleanup_files($galleryIds);
        }
        return $result;
    }catch(Throwable $error){
        if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}
function sf_privacy_closure_blockers(int $userId,array $user): array {
    $block=[];if(($user['role']??'')==='admin')$block[]='Administrator accounts must be transferred or demoted before closure.';
    if(function_exists('sf_entitlement_state')){$e=sf_entitlement_state($userId);$s=(array)($e['subscription']??[]);if(in_array((string)($s['status']??''),['active','trialing','past_due'],true)&&!empty($s['provider_subscription_id']))$block[]='Cancel the active paid subscription before closing the account.';}
    foreach(sf_privacy_orders_for_user($userId,(string)$user['email']) as $o){if(empty($o['quote']['physical']))continue;$st=(string)($o['fulfillment']['status']??'pending');if(!in_array($st,['delivered','returned','refunded','cancelled','canceled','not_required'],true)){$block[]='A physical order is still in fulfillment. Close the account after delivery or resolution.';break;}}
    $q=sf_db()->prepare("SELECT 1 FROM support_cases WHERE user_id=? AND status NOT IN ('closed','resolved') LIMIT 1");$q->execute([$userId]);if($q->fetchColumn()!==false)$block[]='An open support case must be resolved before account closure.';
    return array_values(array_unique($block));
}
function sf_privacy_delete_rows(string $table,int $userId): int {$q=sf_db()->prepare('DELETE FROM '.$table.' WHERE user_id=?');$q->execute([$userId]);return $q->rowCount();}
function sf_privacy_close_account(int $userId,array $user,string $password,string $confirmation): array {
    sf_privacy_canvas_project_helpers();
    sf_privacy_ensure_schema();if($confirmation!=='DELETE')throw new InvalidArgumentException('Type DELETE exactly to confirm account closure.');$full=sf_user_by_email((string)$user['email']);if(!$full||!password_verify($password,(string)$full['password_hash']))throw new InvalidArgumentException('Current password is incorrect.');
    $block=sf_privacy_closure_blockers($userId,$full);if($block)throw new RuntimeException(implode(' ',$block));if(function_exists('sf_guest_migration_ensure_schema'))sf_guest_migration_ensure_schema();sf_agent_scene_request_ensure_schema();sf_ai_reservation_schema();sf_ops_ensure_schema();sf_agent_canvas_image_ensure_schema();sf_image_gen_schema();$pdo=sf_db();$pdo->beginTransaction();$purged=[];$anon='closed-'.bin2hex(random_bytes(12)).'@invalid.stonefellow.local';
    try{
      sf_agent_scene_history_lock_account($pdo,$userId);
      $galleryIds=function_exists('sf_canvas_gallery_owned_ids')?sf_canvas_gallery_owned_ids($userId):[];
      $anonymized=sf_privacy_anonymize_user_events($userId);
      $agent=sf_privacy_clear_agent_history($userId);
      $purged['canvas_scenes']=$agent['canvas_scenes'];
      $purged['photo_preferences']=$agent['photo_preferences'];
      foreach($agent as $key=>$count)if((str_starts_with((string)$key,'canvas_project')||str_starts_with((string)$key,'canvas_build_')||str_starts_with((string)$key,'canvas_gallery_')||$key==='canvas_access_grants'||$key==='canvas_feedback')&&is_int($count))$purged[$key]=$count;
      if(function_exists('sf_canvas_project_clear'))$purged+=sf_canvas_project_clear($userId,true);
      $purged+=sf_agent_canvas_photo_clear($userId);
      $purged+=sf_image_analysis_batch_clear($userId);
      $purged['canvas_images']=sf_agent_canvas_image_clear_metadata($userId);
      $purged+=sf_image_gen_clear_user_quota($pdo,$userId);
      foreach(['user_sessions','user_favorites','user_listening_progress','user_guest_recent_signals','user_guest_imports','user_track_feedback','user_play_queue','user_saved_builds','user_library','user_notification_preferences','notification_delivery_keys','notification_events','notification_generation_state','user_notifications','user_activity','user_collections','user_playlists','agent_listening_sessions','community_posts','account_action_tokens','user_token_balances','token_ledger','user_subscriptions','user_trial_history','ai_token_reservations'] as $table)$purged[$table]=sf_privacy_delete_rows($table,$userId);
      if(sf_listening_playback_exists())$purged['listening_account_state']=sf_privacy_delete_rows('listening_account_state',$userId);
      $q=$pdo->prepare('UPDATE fan_contacts SET marketing_opt_in=0,agent_auto_engage=0,status=?,display_name=?,email=?,user_id=NULL,unsubscribed_at=?,unsubscribe_token_hash=NULL,updated_at=? WHERE user_id=?');$q->execute(['archived','Closed account',$anon,gmdate('c'),gmdate('c'),$userId]);
      $q=$pdo->prepare('UPDATE transactional_email_outbox SET user_id=NULL,recipient_email=? WHERE user_id=?');$q->execute([$anon,$userId]);$purged['transactional_email_identity_anonymized']=$q->rowCount();
      sf_privacy_log($userId,'account_closed',['behavior_tables_purged'=>array_sum($purged),'events_anonymized'=>array_sum($anonymized),'agent_conversations'=>$agent['conversations']??0]);
      $q=$pdo->prepare("UPDATE users SET email=?,display_name='Closed account',password_hash=?,status='closed',updated_at=?,last_login_at=NULL,email_verified_at=NULL,email_verification_sent_at=NULL WHERE id=?");$q->execute([$anon,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),gmdate('c'),$userId]);
      $pdo->prepare('DELETE FROM user_profiles WHERE user_id=?')->execute([$userId]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(function_exists('sf_canvas_gallery_cleanup_files'))sf_canvas_gallery_cleanup_files($galleryIds);
    sf_agent_canvas_image_clear_files($userId);
    return ['closed'=>true,'retained'=>['historical_orders','billing_invoices','refunds','support_records'],'purged_rows'=>array_sum($purged),'anonymized_events'=>array_sum($anonymized)];
}
function sf_privacy_state(int $userId,array $user): array {return ['profile'=>sf_privacy_profile($userId),'closure_blockers'=>sf_privacy_closure_blockers($userId,$user),'recent_privacy_events'=>sf_privacy_event_summary($userId,8),'retention_note'=>'Account closure purges or anonymizes fan-behavior/profile data. Historical orders, invoices, refunds and support records remain as operational/accounting history.'];}
