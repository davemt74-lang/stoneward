<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__,2).'/api/user-usage-report.php';
require_once SF_ROOT.'/api/image-generation-core.php';
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$me = sf_admin_require_auth($method !== 'GET');
sf_entitlements_ensure_schema();
sf_customer_lifecycle_ensure_schema();
sf_billing_ensure_schema();

function sf_admin_user_record(int $id): ?array {
    $q = sf_db()->prepare('SELECT id,email,display_name,role,status,created_at,last_login_at,email_verified_at FROM users WHERE id=? LIMIT 1');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function sf_admin_user_image_quota(int $id): array {
    $quota = sf_image_gen_user_quota($id);
    return [
        'image_daily_cap' => $quota['daily_cap'],
        'image_default_daily_cap' => $quota['default_daily_cap'],
        'image_daily_cap_override' => $quota['daily_cap_override'],
        'image_attempts_today' => $quota['today_attempts'],
    ];
}

function sf_admin_user_invite(array $user): array {
    if (($user['status'] ?? '') !== 'active') {
        throw new RuntimeException('Activate this account before sending an invitation.');
    }
    $token = sf_account_action_token_issue((int)$user['id'], 'password_reset', 3600);
    $base = sf_public_base_url();
    $link = ($base !== '' ? $base : '') . '/?view=reset-password&token=' . rawurlencode($token);
    $body = "Welcome to Stonefellow\n\nAn administrator created an account for you. To set your password securely, open:\n{$link}\n\nThis invitation expires in 60 minutes. If you did not expect an account, you may ignore this message.";
    return sf_transactional_email((int)$user['id'], (string)$user['email'], 'Your Stonefellow invitation', $body, 'admin_user_invitation');
}

if ($method === 'GET') {
    if (isset($_GET['summary'])) {
        $days = (int)($_GET['days'] ?? 30);
        try { sf_json_response(['ok'=>true,'report'=>sf_admin_user_usage_report($days)]); }
        catch (InvalidArgumentException $e) { sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422); }
    }
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $user = sf_admin_user_record($id);
        if (!$user) sf_json_response(['ok'=>false,'message'=>'User not found.'],404);
        $entitlement = sf_entitlement_state($id);
        $offset = max(0, min(10000, (int)($_GET['offset'] ?? 0)));
        $ledger = sf_db()->prepare('SELECT id,delta,balance_after,entry_type,source,provider,model,input_tokens,output_tokens,voice_chars,note,created_at FROM token_ledger WHERE user_id=? ORDER BY id DESC LIMIT 100 OFFSET '.$offset);
        $ledger->execute([$id]);
        $since = gmdate('c', time()-30*86400);
        $totals = sf_db()->prepare("SELECT COALESCE(SUM(CASE WHEN delta<0 THEN -delta ELSE 0 END),0) consumed, COALESCE(SUM(CASE WHEN entry_type='admin_grant' THEN delta ELSE 0 END),0) free_grants, COALESCE(SUM(CASE WHEN entry_type='monthly_grant' THEN delta ELSE 0 END),0) package_grants, COALESCE(SUM(input_tokens),0) input_tokens, COALESCE(SUM(output_tokens),0) output_tokens, COALESCE(SUM(voice_chars),0) voice_chars, COUNT(*) events FROM token_ledger WHERE user_id=? AND created_at>=?");
        $totals->execute([$id,$since]);
        $byProvider = sf_db()->prepare("SELECT provider,model,COALESCE(SUM(CASE WHEN delta<0 THEN -delta ELSE 0 END),0) consumed,COALESCE(SUM(input_tokens),0) input_tokens,COALESCE(SUM(output_tokens),0) output_tokens,COALESCE(SUM(voice_chars),0) voice_chars FROM token_ledger WHERE user_id=? AND created_at>=? AND entry_type='usage' GROUP BY provider,model ORDER BY consumed DESC LIMIT 30");
        $byProvider->execute([$id,$since]);
        $byFeature = sf_db()->prepare("SELECT CASE
            WHEN source IN ('agent-chat','agent-llm-intent') THEN 'Agent Chat + Routing'
            WHEN source IN ('agent-jev-route','agent-jev-eval') THEN 'Agent Brain + Evaluation'
            WHEN source='agent-voice' THEN 'Voice Synthesis'
            WHEN source='song-section-transcription' THEN 'Song Transcription'
            WHEN source='song-section-analysis' THEN 'Lyric Analysis'
            WHEN source='image-generation' THEN 'Image Generation'
            WHEN source='image-analysis' THEN 'Image Analysis'
            WHEN source='image-prompt-composer' THEN 'Image Prompt Refinement'
            ELSE 'Other AI' END feature, COUNT(*) events,
            COALESCE(SUM(CASE WHEN delta<0 THEN -delta ELSE 0 END),0) consumed
            FROM token_ledger WHERE user_id=? AND created_at>=? AND entry_type='usage'
            GROUP BY feature ORDER BY consumed DESC LIMIT 12");
        $byFeature->execute([$id,$since]);
        $user['email_verified'] = !empty($user['email_verified_at']);
        $user['active_sessions'] = count(sf_auth_sessions($id));
        $user += sf_admin_user_image_quota($id);
        sf_json_response(['ok'=>true,'user'=>$user,'entitlement'=>$entitlement,'ledger'=>$ledger->fetchAll(),'next_offset'=>$offset+100,'usage_30d'=>$totals->fetch() ?: [],'by_provider'=>$byProvider->fetchAll(),'by_feature'=>$byFeature->fetchAll()]);
    }
    $rows = sf_db()->query('SELECT id,email,display_name,role,status,created_at,last_login_at,email_verified_at FROM users ORDER BY created_at DESC')->fetchAll();
    foreach ($rows as &$user) {
        $e = sf_entitlement_state((int)$user['id']);
        $user['active_tokens'] = $user['role']==='admin' ? null : $e['balance'];
        $user['subscription'] = $e['subscription'];
        $user['email_verified'] = !empty($user['email_verified_at']);
        $user['active_sessions'] = count(sf_auth_sessions((int)$user['id']));
    }
    unset($user);
    sf_json_response(['ok'=>true,'users'=>$rows]);
}

$body = sf_request_json();
$action = (string)($body['action'] ?? 'update');
$id = (int)($body['id'] ?? 0);

if ($action === 'create') {
    $name = sf_clean_text($body['display_name'] ?? '', 100);
    $email = sf_email((string)($body['email'] ?? ''));
    $role = (string)($body['role'] ?? 'user');
    $packageId = max(0, (int)($body['package_id'] ?? 0));
    $freeTokens = (int)($body['free_tokens'] ?? 0);
    $reason = sf_clean_text($body['grant_reason'] ?? '', 180);
    if ($name === '') sf_json_response(['ok'=>false,'message'=>'Display name is required.'],422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sf_json_response(['ok'=>false,'message'=>'Enter a valid email address.'],422);
    if (!in_array($role,['user','admin'],true)) sf_json_response(['ok'=>false,'message'=>'Invalid user role.'],422);
    if ($role === 'admin' && empty($body['confirm_admin'])) sf_json_response(['ok'=>false,'message'=>'Administrator access must be explicitly confirmed.'],422);
    if ($freeTokens < 0 || $freeTokens > 100000000) sf_json_response(['ok'=>false,'message'=>'Free tokens must be between 0 and 100,000,000.'],422);
    if ($freeTokens > 0 && $reason === '') sf_json_response(['ok'=>false,'message'=>'A reason is required for free-token grants.'],422);
    if (sf_user_by_email($email)) sf_json_response(['ok'=>false,'message'=>'An account already exists for that email.'],409);
    if ($packageId > 0) {
        $package = sf_package($packageId);
        if (!$package || empty($package['is_active'])) sf_json_response(['ok'=>false,'message'=>'Selected package is unavailable.'],422);
    }
    $now = gmdate('c');
    try {
        $q = sf_db()->prepare("INSERT INTO users(email,display_name,password_hash,role,status,created_at,updated_at,last_login_at) VALUES(?,?,?,?,?,?,?,NULL)");
        $q->execute([$email,$name,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$role,'active',$now,$now]);
    } catch (PDOException $e) {
        sf_json_response(['ok'=>false,'message'=>'Could not create this user. Check whether the email already exists.'],409);
    }
    $id = (int)sf_db()->lastInsertId();
    sf_log_admin_action((int)$me['id'],'user_created','user',(string)$id,['role'=>$role,'email'=>$email]);
    $warnings = [];
    if ($packageId > 0) {
        try {
            sf_activate_package($id,$packageId,'admin_user_create');
            sf_log_admin_action((int)$me['id'],'package_assigned','user',(string)$id,['package_id'=>$packageId,'complimentary'=>true]);
        } catch (Throwable $e) { $warnings[] = 'Account created, but package assignment failed: '.$e->getMessage(); }
    }
    if ($freeTokens > 0) {
        try {
            $balance = sf_token_adjust($id,$freeTokens,'admin_grant','admin_create:'.$id,[],$reason);
            sf_log_admin_action((int)$me['id'],'tokens_granted','user',(string)$id,['amount'=>$freeTokens,'balance'=>$balance,'reason'=>$reason]);
        } catch (Throwable $e) { $warnings[] = 'Account created, but free-token allocation failed.'; }
    }
    $delivery = 'not_requested';
    if (!empty($body['send_invite'])) {
        try {
            $mail = sf_admin_user_invite(sf_admin_user_record($id) ?: []);
            $delivery = (string)($mail['status'] ?? 'queued');
            if ($delivery === 'failed') $warnings[] = 'Account created, but email delivery failed. Check transactional email settings, then resend the invitation.';
        } catch (Throwable $e) { $delivery = 'failed'; $warnings[]='Account created, but the invitation could not be delivered. Use Resend invitation.'; }
    }
    sf_log_user_activity($id,'account_created','Account created by Stonefellow Admin','account',(string)$id);
    sf_json_response(['ok'=>true,'id'=>$id,'user'=>sf_admin_user_record($id),'invitation_status'=>$delivery,'warnings'=>$warnings],201);
}

if ($id < 1) sf_json_response(['ok'=>false,'message'=>'Invalid user.'],422);
$target = sf_admin_user_record($id);
if (!$target) sf_json_response(['ok'=>false,'message'=>'User not found.'],404);

if ($action === 'save_image_quota') {
    $cap = $body['image_daily_cap_override'] ?? null;
    if (!array_key_exists('image_daily_cap_override',$body) || ($cap !== null && (!is_int($cap) || $cap < 0 || $cap > 50))) {
        sf_json_response(['ok'=>false,'message'=>'Enter a daily image limit from 0 to 50, or leave it blank to inherit the default.'],422);
    }
    try {
        sf_image_gen_save_user_cap($id,$cap);
    } catch (InvalidArgumentException $e) {
        sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
    }
    sf_log_admin_action((int)$me['id'],'user_image_quota_updated','user',(string)$id,['daily_cap_override'=>$cap]);
    sf_json_response(['ok'=>true,'user'=>$target + sf_admin_user_image_quota($id)]);
}
if ($action === 'invite') {
    try {
        $mail = sf_admin_user_invite($target);
        sf_log_admin_action((int)$me['id'],'user_invited','user',(string)$id,['delivery_status'=>$mail['status'] ?? 'queued']);
        sf_json_response(['ok'=>true,'delivery_status'=>$mail['status'] ?? 'queued']);
    } catch (Throwable $e) { sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422); }
}
if ($action === 'grant_tokens') {
    $amount = (int)($body['amount'] ?? 0);
    $note = sf_clean_text($body['note'] ?? '',180);
    $requestId = strtolower(trim((string)($body['request_id'] ?? '')));
    if ($amount < 1 || $amount > 100000000 || $note === '') sf_json_response(['ok'=>false,'message'=>'Enter 1–100,000,000 tokens and a grant reason.'],422);
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',$requestId))
        sf_json_response(['ok'=>false,'message'=>'Grant request identifier is missing or invalid. Refresh Admin and try again.'],422);
    $applied=false;
    try{$balance=sf_token_adjust($id,$amount,'admin_grant','admin:'.(int)$me['id'].':'.$requestId,[],$note,$applied);}
    catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],409);}
    if($applied){
        sf_notify_user($id,'tokens','Active Tokens added',number_format($amount).' Active Tokens were added to your account.','?view=account');
        sf_log_user_activity($id,'token_grant','Received '.number_format($amount).' Active Tokens','tokens',(string)$amount);
        sf_log_admin_action((int)$me['id'],'tokens_granted','user',(string)$id,['amount'=>$amount,'balance'=>$balance,'reason'=>$note,'request_id'=>$requestId]);
    }
    sf_json_response(['ok'=>true,'balance'=>$balance,'applied'=>$applied]);
}
if ($action === 'revoke_sessions') {
    sf_auth_revoke_all_user_sessions($id);
    sf_notify_user($id,'security','Sessions revoked','An Administrator signed out all of your Stonefellow sessions.','?view=login');
    sf_log_admin_action((int)$me['id'],'user_sessions_revoked','user',(string)$id);
    sf_json_response(['ok'=>true]);
}
if ($action === 'send_verification') {
    if (!empty($target['email_verified_at'])) sf_json_response(['ok'=>true,'message'=>'Email already verified.']);
    try {
        $mail = sf_send_email_verification($id,(string)$target['email']);
        sf_json_response(['ok'=>true,'message'=>'Verification email queued.','delivery_status'=>$mail['status'] ?? 'queued']);
    } catch (Throwable $e) { sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422); }
}
if ($action === 'assign_package') {
    $packageId = (int)($body['package_id'] ?? 0);
    try {
        $managed = sf_billing_subscription_row($id);
        if ($managed && ($managed['provider'] ?? 'local')==='stripe' && !empty($managed['provider_subscription_id']) && in_array((string)($managed['status'] ?? ''),['active','trialing','past_due'],true)) throw new RuntimeException('This subscription is managed by Stripe. Change the plan through Stripe billing instead of replacing it locally.');
        $state = $packageId ? sf_activate_package($id,$packageId,'admin_package') : sf_enroll_user($id,null);
        $packageName = (string)($state['subscription']['package_name'] ?? 'Stonefellow plan');
        sf_notify_user($id,'subscription','Subscription updated','Your Stonefellow package is now '.$packageName.'.','?view=account');
        sf_log_user_activity($id,'subscription_update','Subscription changed to '.$packageName,'subscription',(string)$packageId);
        sf_log_admin_action((int)$me['id'],'package_assigned','user',(string)$id,['package_id'=>$packageId,'package_name'=>$packageName]);
        sf_json_response(['ok'=>true,'entitlement'=>$state]);
    } catch (Throwable $e) { sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422); }
}
if ($action !== 'update') sf_json_response(['ok'=>false,'message'=>'Unsupported user action.'],422);
$role = (string)($body['role'] ?? $target['role']);
$status = (string)($body['status'] ?? $target['status']);
$name = array_key_exists('display_name',$body) ? sf_clean_text($body['display_name'],100) : (string)$target['display_name'];
if (!in_array($role,['user','admin'],true) || !in_array($status,['active','disabled'],true) || $name==='') sf_json_response(['ok'=>false,'message'=>'Invalid user update.'],422);
if ($role === 'admin' && $target['role'] !== 'admin' && empty($body['confirm_admin'])) sf_json_response(['ok'=>false,'message'=>'Administrator access must be explicitly confirmed.'],422);
if ($id === (int)$me['id'] && $status !== 'active') sf_json_response(['ok'=>false,'message'=>'You cannot disable your own account.'],422);
if ($target['role']==='admin' && $target['status']==='active' && ($role!=='admin' || $status!=='active') && sf_count_active_admins()<=1) sf_json_response(['ok'=>false,'message'=>'You cannot remove or disable the last active administrator.'],422);
$q = sf_db()->prepare('UPDATE users SET display_name=?,role=?,status=?,updated_at=? WHERE id=?');
$q->execute([$name,$role,$status,gmdate('c'),$id]);
if ($status === 'disabled') sf_auth_revoke_all_user_sessions($id);
sf_log_admin_action((int)$me['id'],'user_access_updated','user',(string)$id,['display_name'=>$name,'role'=>$role,'status'=>$status]);
sf_json_response(['ok'=>true]);
