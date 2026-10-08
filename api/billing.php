<?php
declare(strict_types=1);

function sf_billing_ensure_schema(): void {
    static $done=false;if($done)return;
    if(function_exists('sf_entitlements_ensure_schema'))sf_entitlements_ensure_schema();
    $pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    // Package mapping to the recurring price configured at the payment provider.
    sf_schema_add_column('subscription_packages','provider_price_id',"TEXT NOT NULL DEFAULT ''","VARCHAR(180) NOT NULL DEFAULT ''");
    // Provider-owned subscription state. Local/test subscriptions leave these blank.
    sf_schema_add_column('user_subscriptions','provider',"TEXT NOT NULL DEFAULT 'local'","VARCHAR(40) NOT NULL DEFAULT 'local'");
    sf_schema_add_column('user_subscriptions','provider_customer_id',"TEXT NOT NULL DEFAULT ''","VARCHAR(180) NOT NULL DEFAULT ''");
    sf_schema_add_column('user_subscriptions','provider_subscription_id',"TEXT NOT NULL DEFAULT ''","VARCHAR(180) NOT NULL DEFAULT ''");
    sf_schema_add_column('user_subscriptions','cancel_at_period_end','INTEGER NOT NULL DEFAULT 0','TINYINT(1) NOT NULL DEFAULT 0');
    sf_schema_add_column('user_subscriptions','canceled_at','TEXT NULL','VARCHAR(40) NULL');
    sf_schema_add_column('user_subscriptions','grace_ends_at','TEXT NULL','VARCHAR(40) NULL');
    sf_schema_add_column('user_subscriptions','last_invoice_id',"TEXT NOT NULL DEFAULT ''","VARCHAR(180) NOT NULL DEFAULT ''");
    sf_schema_add_column('user_subscriptions','last_payment_at','TEXT NULL','VARCHAR(40) NULL');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS billing_webhook_events (event_id TEXT PRIMARY KEY,provider TEXT NOT NULL,event_type TEXT NOT NULL,status TEXT NOT NULL,error_text TEXT NOT NULL DEFAULT '',payload_sha256 TEXT NOT NULL,created_at TEXT NOT NULL,processed_at TEXT NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS billing_invoices (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,package_id INTEGER NULL,provider TEXT NOT NULL,provider_invoice_id TEXT NOT NULL UNIQUE,provider_subscription_id TEXT NOT NULL DEFAULT '',amount_paid_cents INTEGER NOT NULL DEFAULT 0,currency TEXT NOT NULL DEFAULT 'USD',status TEXT NOT NULL,hosted_invoice_url TEXT NOT NULL DEFAULT '',invoice_pdf TEXT NOT NULL DEFAULT '',period_start TEXT NULL,period_end TEXT NULL,created_at TEXT NOT NULL,paid_at TEXT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_billing_invoices_user ON billing_invoices(user_id,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS billing_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NULL,action TEXT NOT NULL,provider TEXT NOT NULL DEFAULT '',reference_id TEXT NOT NULL DEFAULT '',details_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS billing_webhook_events (event_id VARCHAR(180) NOT NULL PRIMARY KEY,provider VARCHAR(40) NOT NULL,event_type VARCHAR(120) NOT NULL,status VARCHAR(32) NOT NULL,error_text TEXT NOT NULL,payload_sha256 CHAR(64) NOT NULL,created_at VARCHAR(40) NOT NULL,processed_at VARCHAR(40) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS billing_invoices (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,package_id BIGINT UNSIGNED NULL,provider VARCHAR(40) NOT NULL,provider_invoice_id VARCHAR(180) NOT NULL UNIQUE,provider_subscription_id VARCHAR(180) NOT NULL DEFAULT '',amount_paid_cents INT NOT NULL DEFAULT 0,currency VARCHAR(8) NOT NULL DEFAULT 'USD',status VARCHAR(32) NOT NULL,hosted_invoice_url TEXT NOT NULL,invoice_pdf TEXT NOT NULL,period_start VARCHAR(40) NULL,period_end VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,paid_at VARCHAR(40) NULL,INDEX idx_billing_invoices_user(user_id,created_at),CONSTRAINT fk_billing_invoice_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_billing_invoice_package FOREIGN KEY(package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS billing_audit_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,action VARCHAR(80) NOT NULL,provider VARCHAR(40) NOT NULL DEFAULT '',reference_id VARCHAR(180) NOT NULL DEFAULT '',details_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_billing_audit_created(created_at),CONSTRAINT fk_billing_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_billing_log(?int $userId,string $action,string $provider='',string $reference='',array $details=[]): void {
    sf_billing_ensure_schema();$q=sf_db()->prepare('INSERT INTO billing_audit_log(user_id,action,provider,reference_id,details_json,created_at) VALUES(?,?,?,?,?,?)');$q->execute([$userId,$action,$provider,$reference,json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}',gmdate('c')]);
}
function sf_billing_settings(): array {
    sf_billing_ensure_schema();$mode=sf_ai_meta_get('billing.mode',(string)(sf_store_config()['mode']??'test'));if(!in_array($mode,['test','production'],true))$mode='test';$provider=sf_ai_meta_get('billing.provider','test');if(!in_array($provider,['test','stripe'],true))$provider='test';
    return [
        'mode'=>$mode,'provider'=>$provider,
        'stripe_enabled'=>sf_ai_meta_get('billing.stripe_enabled','0')==='1',
        'stripe_key_configured'=>sf_ai_meta_get('billing.stripe_secret_payload','')!=='',
        'stripe_key_hint'=>sf_ai_meta_get('billing.stripe_key_hint',''),
        'stripe_webhook_configured'=>sf_ai_meta_get('billing.stripe_webhook_payload','')!=='',
        'stripe_webhook_hint'=>sf_ai_meta_get('billing.stripe_webhook_hint',''),
    ];
}
function sf_billing_settings_save(array $data): array {
    $mode=(string)($data['mode']??'test');if(!in_array($mode,['test','production'],true))throw new InvalidArgumentException('Billing mode must be test or production.');$provider=(string)($data['provider']??'test');if(!in_array($provider,['test','stripe'],true))throw new InvalidArgumentException('Unsupported billing provider.');
    $enabled=!empty($data['stripe_enabled']);$secretPayload=sf_ai_meta_get('billing.stripe_secret_payload','');$secretHint=sf_ai_meta_get('billing.stripe_key_hint','');$webhookPayload=sf_ai_meta_get('billing.stripe_webhook_payload','');$webhookHint=sf_ai_meta_get('billing.stripe_webhook_hint','');
    $secret=trim((string)($data['stripe_secret_key']??''));if($secret!==''){$secretPayload=sf_ai_encrypt($secret);$secretHint=sf_ai_hint($secret);}if(!empty($data['clear_stripe_secret'])){$secretPayload='';$secretHint='';$enabled=false;}
    $webhook=trim((string)($data['stripe_webhook_secret']??''));if($webhook!==''){$webhookPayload=sf_ai_encrypt($webhook);$webhookHint=sf_ai_hint($webhook);}if(!empty($data['clear_webhook_secret'])){$webhookPayload='';$webhookHint='';}
    if($enabled&&($secretPayload===''||$webhookPayload===''))throw new InvalidArgumentException('Stripe requires both the secret API key and webhook signing secret before it can be enabled.');
    sf_ai_meta_set('billing.mode',$mode);sf_ai_meta_set('billing.provider',$provider);sf_ai_meta_set('billing.stripe_enabled',$enabled?'1':'0');sf_ai_meta_set('billing.stripe_secret_payload',$secretPayload);sf_ai_meta_set('billing.stripe_key_hint',$secretHint);sf_ai_meta_set('billing.stripe_webhook_payload',$webhookPayload);sf_ai_meta_set('billing.stripe_webhook_hint',$webhookHint);return sf_billing_settings();
}
function sf_billing_secret(string $key): string {$payload=sf_ai_meta_get($key,'');return $payload===''?'':sf_ai_decrypt($payload);}
function sf_stripe_secret(): string { return sf_billing_secret('billing.stripe_secret_payload'); }
function sf_stripe_webhook_secret(): string { return sf_billing_secret('billing.stripe_webhook_payload'); }
function sf_stripe_configured(): bool {$s=sf_billing_settings();return $s['provider']==='stripe'&&$s['stripe_enabled']&&$s['stripe_key_configured']&&$s['stripe_webhook_configured'];}
function sf_http_form_request(string $method,string $url,array $headers=[],array $fields=[]): array {
    $body=http_build_query($fields,'','&',PHP_QUERY_RFC3986);$method=strtoupper($method);
    if(function_exists('curl_init')){
        $ch=curl_init($url);$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],$headers),CURLOPT_USERAGENT=>'Stonefellow/1.1'];if($method==='POST'){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=$body;}curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($raw===false)throw new RuntimeException('Billing provider connection failed: '.$err);
    }else{$header=implode("\r\n",array_merge(['Accept: application/json','Content-Type: application/x-www-form-urlencoded','User-Agent: Stonefellow/1.1'],$headers));$ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$header,'content'=>$method==='POST'?$body:'','timeout'=>45,'ignore_errors'=>true]]);$raw=@file_get_contents($url,false,$ctx);if($raw===false)throw new RuntimeException('Billing provider connection failed.');$status=200;foreach($http_response_header??[] as $line)if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m))$status=(int)$m[1];}
    $json=json_decode((string)$raw,true);if(!is_array($json))throw new RuntimeException('Billing provider returned an invalid response.');if($status<200||$status>=300)throw new RuntimeException((string)($json['error']['message']??$json['message']??('Billing HTTP '.$status)));return $json;
}
function sf_stripe_request(string $method,string $path,array $fields=[]): array {$key=sf_stripe_secret();if($key==='')throw new RuntimeException('Stripe is not configured.');return sf_http_form_request($method,'https://api.stripe.com/v1/'.ltrim($path,'/'),['Authorization: Bearer '.$key],$fields);}
function sf_billing_subscription_row(int $userId): ?array {sf_billing_ensure_schema();$q=sf_db()->prepare('SELECT s.*,p.name package_name,p.slug package_slug,p.monthly_price_cents,p.monthly_tokens,p.provider_price_id FROM user_subscriptions s LEFT JOIN subscription_packages p ON p.id=s.package_id WHERE s.user_id=? LIMIT 1');$q->execute([$userId]);$r=$q->fetch();return $r?:null;}
function sf_billing_invoices(int $userId,int $limit=30): array {sf_billing_ensure_schema();$q=sf_db()->prepare('SELECT provider_invoice_id,amount_paid_cents,currency,status,hosted_invoice_url,invoice_pdf,period_start,period_end,created_at,paid_at FROM billing_invoices WHERE user_id=? ORDER BY id DESC LIMIT '.max(1,min(100,$limit)));$q->execute([$userId]);return $q->fetchAll();}
function sf_billing_checkout(array $user,int $packageId): array {
    sf_billing_ensure_schema();$p=sf_package($packageId);if(!$p||empty($p['is_active'])||empty($p['is_public']))throw new InvalidArgumentException('Package is unavailable.');if((int)$p['monthly_price_cents']<=0)return ['local'=>true,'entitlement'=>sf_activate_package((int)$user['id'],$packageId,'free_package')];
    $lifecycle=sf_customer_lifecycle_state((int)$user['id']);if(empty($lifecycle['email_verified']))throw new RuntimeException('Verify your email before starting a paid subscription.');$settings=sf_billing_settings();
    if($settings['mode']==='test'||$settings['provider']==='test')return ['local'=>true,'entitlement'=>sf_activate_package((int)$user['id'],$packageId,'billing_test'),'simulated'=>true];
    if(!sf_stripe_configured())throw new RuntimeException('Production subscription billing is not configured.');$price=trim((string)($p['provider_price_id']??''));if($price==='')throw new RuntimeException('This package does not have a Stripe Price ID configured.');
    $existing=sf_billing_subscription_row((int)$user['id']);if($existing&&!empty($existing['provider_subscription_id'])&&in_array((string)$existing['status'],['active','trialing','past_due'],true))return sf_billing_portal($user);
    $base=sf_public_base_url();if($base==='')throw new RuntimeException('Configure the public Base URL in Admin before enabling production billing.');$fields=['mode'=>'subscription','success_url'=>$base.'/?view=account&billing=success','cancel_url'=>$base.'/?view=plans&billing=canceled','line_items[0][price]'=>$price,'line_items[0][quantity]'=>'1','client_reference_id'=>(string)$user['id'],'customer_email'=>(string)$user['email'],'metadata[stonefellow_user_id]'=>(string)$user['id'],'metadata[stonefellow_package_id]'=>(string)$packageId,'subscription_data[metadata][stonefellow_user_id]'=>(string)$user['id'],'subscription_data[metadata][stonefellow_package_id]'=>(string)$packageId,'allow_promotion_codes'=>'true'];$session=sf_stripe_request('POST','checkout/sessions',$fields);if(empty($session['url']))throw new RuntimeException('Stripe did not return a checkout URL.');sf_billing_log((int)$user['id'],'checkout_created','stripe',(string)($session['id']??''),['package_id'=>$packageId]);return ['checkout_url'=>(string)$session['url'],'provider'=>'stripe'];
}
function sf_billing_portal(array $user): array {
    sf_billing_ensure_schema();$sub=sf_billing_subscription_row((int)$user['id']);if(!$sub||empty($sub['provider_customer_id']))throw new RuntimeException('No managed billing account is available yet.');if(!sf_stripe_configured())throw new RuntimeException('Stripe is not configured.');$base=sf_public_base_url();if($base==='')throw new RuntimeException('Configure the public Base URL in Admin.');$session=sf_stripe_request('POST','billing_portal/sessions',['customer'=>(string)$sub['provider_customer_id'],'return_url'=>$base.'/?view=account']);if(empty($session['url']))throw new RuntimeException('Stripe did not return a billing portal URL.');sf_billing_log((int)$user['id'],'portal_created','stripe',(string)($session['id']??''));return ['portal_url'=>(string)$session['url'],'provider'=>'stripe'];
}
function sf_billing_cancel(array $user): array {
    sf_billing_ensure_schema();$uid=(int)$user['id'];$sub=sf_billing_subscription_row($uid);if(!$sub)throw new RuntimeException('No subscription is active.');if(($sub['provider']??'local')==='stripe'&&!empty($sub['provider_subscription_id'])){$r=sf_stripe_request('POST','subscriptions/'.rawurlencode((string)$sub['provider_subscription_id']),['cancel_at_period_end'=>'true']);sf_db()->prepare('UPDATE user_subscriptions SET cancel_at_period_end=1,updated_at=? WHERE user_id=?')->execute([gmdate('c'),$uid]);sf_billing_log($uid,'cancel_at_period_end','stripe',(string)$sub['provider_subscription_id']);}else{
        if(($sub['status']??'')!=='active')throw new RuntimeException('Only an active subscription can be scheduled for cancellation.');
        sf_db()->prepare("UPDATE user_subscriptions SET cancel_at_period_end=1,canceled_at=NULL,updated_at=? WHERE user_id=?")->execute([gmdate('c'),$uid]);
        sf_billing_log($uid,'cancel_at_period_end','local','',['period_end'=>$sub['current_period_end']??null]);
    }sf_billing_notify($uid,'Stonefellow subscription cancellation scheduled','Your subscription is scheduled to end at the close of the current billing period. You can resume it from My Account before then.','subscription_cancel_scheduled');return sf_entitlement_state($uid);
}
function sf_billing_resume(array $user): array {
    sf_billing_ensure_schema();$uid=(int)$user['id'];$sub=sf_billing_subscription_row($uid);if(!$sub)throw new RuntimeException('No subscription is available.');if(($sub['provider']??'local')==='stripe'&&!empty($sub['provider_subscription_id'])){sf_stripe_request('POST','subscriptions/'.rawurlencode((string)$sub['provider_subscription_id']),['cancel_at_period_end'=>'false']);sf_db()->prepare('UPDATE user_subscriptions SET cancel_at_period_end=0,canceled_at=NULL,updated_at=? WHERE user_id=?')->execute([gmdate('c'),$uid]);sf_billing_log($uid,'resume','stripe',(string)$sub['provider_subscription_id']);}else{sf_db()->prepare("UPDATE user_subscriptions SET status='active',cancel_at_period_end=0,canceled_at=NULL,updated_at=? WHERE user_id=?")->execute([gmdate('c'),$uid]);sf_billing_log($uid,'resume','local','');}sf_billing_notify($uid,'Stonefellow subscription resumed','Your subscription will continue at the end of the current billing period.','subscription_resumed');return sf_entitlement_state($uid);
}
function sf_stripe_verify_signature(string $payload,string $header,string $secret,int $tolerance=300): bool {
    if($secret===''||$header==='')return false;$timestamp=0;$signatures=[];foreach(explode(',',$header) as $part){[$k,$v]=array_pad(explode('=',trim($part),2),2,'');if($k==='t')$timestamp=(int)$v;elseif($k==='v1'&&$v!=='')$signatures[]=$v;}if($timestamp<=0||abs(time()-$timestamp)>$tolerance)return false;$expected=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);foreach($signatures as $sig)if(hash_equals($expected,$sig))return true;return false;
}
function sf_stripe_subscription_id_from_invoice(array $invoice): string {
    if(is_string($invoice['subscription']??null))return (string)$invoice['subscription'];$p=$invoice['parent']['subscription_details']['subscription']??null;if(is_string($p))return $p;foreach((array)($invoice['lines']['data']??[]) as $line){$s=$line['parent']['subscription_item_details']['subscription']??null;if(is_string($s))return $s;}return '';
}
function sf_timestamp_iso(mixed $v): ?string {$n=(int)$v;return $n>0?gmdate('c',$n):null;}
function sf_billing_find_user_by_provider_subscription(string $subscriptionId): ?array {if($subscriptionId==='')return null;$q=sf_db()->prepare('SELECT * FROM user_subscriptions WHERE provider_subscription_id=? LIMIT 1');$q->execute([$subscriptionId]);$r=$q->fetch();return $r?:null;}
function sf_billing_store_invoice(int $userId,?int $packageId,array $invoice,string $subscriptionId): bool {
    $providerId=(string)($invoice['id']??'');if($providerId==='')return false;$q=sf_db()->prepare('SELECT id FROM billing_invoices WHERE provider_invoice_id=?');$q->execute([$providerId]);if($q->fetchColumn()!==false)return false;$amount=(int)($invoice['amount_paid']??0);$currency=strtoupper((string)($invoice['currency']??'USD'));$status=(string)($invoice['status']??'paid');$hosted=(string)($invoice['hosted_invoice_url']??'');$pdf=(string)($invoice['invoice_pdf']??'');$periodStart=sf_timestamp_iso($invoice['period_start']??0);$periodEnd=sf_timestamp_iso($invoice['period_end']??0);$created=sf_timestamp_iso($invoice['created']??0)??gmdate('c');$paid=sf_timestamp_iso($invoice['status_transitions']['paid_at']??0)??($status==='paid'?gmdate('c'):null);$i=sf_db()->prepare('INSERT INTO billing_invoices(user_id,package_id,provider,provider_invoice_id,provider_subscription_id,amount_paid_cents,currency,status,hosted_invoice_url,invoice_pdf,period_start,period_end,created_at,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$i->execute([$userId,$packageId,'stripe',$providerId,$subscriptionId,$amount,$currency,$status,$hosted,$pdf,$periodStart,$periodEnd,$created,$paid]);return true;
}

function sf_billing_customer(int $userId): ?array {$q=sf_db()->prepare('SELECT id,email,display_name FROM users WHERE id=? LIMIT 1');$q->execute([$userId]);$r=$q->fetch();return $r?:null;}
function sf_billing_notify(int $userId,string $subject,string $body,string $kind): void {$u=sf_billing_customer($userId);if(!$u)return;try{sf_transactional_email($userId,(string)$u['email'],$subject,$body,$kind);}catch(Throwable $e){sf_billing_log($userId,'email_failed','', '',['kind'=>$kind,'error'=>$e->getMessage()]);}if(function_exists('sf_notify_user'))sf_notify_user($userId,'billing',$subject,$body,'?view=account');if(function_exists('sf_log_user_activity'))sf_log_user_activity($userId,'billing',$subject,'subscription','',['kind'=>$kind]);}
function sf_billing_event_identity(array $obj): array {
    $meta=(array)($obj['metadata']??[]);
    $uid=(int)($meta['stonefellow_user_id']??0);$pkg=(int)($meta['stonefellow_package_id']??0);
    if($uid<=0||$pkg<=0){
        $subMeta=(array)($obj['parent']['subscription_details']['metadata']??$obj['subscription_details']['metadata']??[]);
        if($uid<=0)$uid=(int)($subMeta['stonefellow_user_id']??0);
        if($pkg<=0)$pkg=(int)($subMeta['stonefellow_package_id']??0);
    }
    return [$uid,$pkg];
}
function sf_billing_bind_stripe_subscription(int $uid,int $pkg,string $customer,string $subId,string $status,string $now): void {
    if($uid<=0)return;$pdo=sf_db();$q=$pdo->prepare('SELECT id FROM user_subscriptions WHERE user_id=?');$q->execute([$uid]);
    if($q->fetchColumn()!==false){$pdo->prepare("UPDATE user_subscriptions SET package_id=CASE WHEN ? > 0 THEN ? ELSE package_id END,status=?,provider='stripe',provider_customer_id=?,provider_subscription_id=?,cancel_at_period_end=0,canceled_at=NULL,updated_at=? WHERE user_id=?")->execute([$pkg,$pkg,$status,$customer,$subId,$now,$uid]);}
    else{$pdo->prepare("INSERT INTO user_subscriptions(user_id,package_id,status,trial_started_at,trial_ends_at,current_period_start,current_period_end,created_at,updated_at,provider,provider_customer_id,provider_subscription_id,cancel_at_period_end) VALUES(?,?,?,NULL,NULL,NULL,NULL,?,?,'stripe',?,?,0)")->execute([$uid,$pkg?:null,$status,$now,$now,$customer,$subId]);}
}
function sf_billing_process_stripe_event(array $event,string $payloadHash=''): void {
    sf_billing_ensure_schema();$eventId=(string)($event['id']??'');$type=(string)($event['type']??'');if($eventId===''||$type==='')throw new InvalidArgumentException('Stripe event is missing id or type.');$pdo=sf_db();$q=$pdo->prepare('SELECT status,created_at FROM billing_webhook_events WHERE event_id=?');$q->execute([$eventId]);$existing=$q->fetch();$now=gmdate('c');
    if($existing){
        $status=(string)($existing['status']??'');
        if($status==='processed')return;
        if($status==='processing'&&!empty($existing['created_at'])&&strtotime((string)$existing['created_at'])>time()-300)return;
        // Failed or stale in-progress deliveries are safe to retry. The Stripe event id remains the idempotency key.
        $pdo->prepare("UPDATE billing_webhook_events SET provider='stripe',event_type=?,status='processing',error_text='',payload_sha256=?,created_at=?,processed_at=NULL WHERE event_id=?")->execute([$type,$payloadHash,$now,$eventId]);
    }else{
        $i=$pdo->prepare('INSERT INTO billing_webhook_events(event_id,provider,event_type,status,error_text,payload_sha256,created_at,processed_at) VALUES(?,?,?,? ,?,?,?,NULL)');$i->execute([$eventId,'stripe',$type,'processing','',$payloadHash,$now]);
    }
    try{$obj=(array)($event['data']['object']??[]);
        if($type==='checkout.session.completed'){
            [$uid,$pkg]=sf_billing_event_identity($obj);if($uid<=0)$uid=(int)($obj['client_reference_id']??0);$customer=(string)($obj['customer']??'');$subId=(string)($obj['subscription']??'');$status=((string)($obj['payment_status']??''))==='paid'?'active':'pending_payment';if($uid>0){sf_billing_bind_stripe_subscription($uid,$pkg,$customer,$subId,$status,$now);sf_billing_log($uid,'checkout_completed','stripe',(string)($obj['id']??''),['package_id'=>$pkg]);}
        }
        elseif($type==='customer.subscription.created'){
            [$uid,$pkg]=sf_billing_event_identity($obj);$subId=(string)($obj['id']??'');$customer=is_string($obj['customer']??null)?(string)$obj['customer']:'';$status=(string)($obj['status']??'active');if($uid>0){sf_billing_bind_stripe_subscription($uid,$pkg,$customer,$subId,$status,$now);$start=sf_timestamp_iso($obj['current_period_start']??0);$end=sf_timestamp_iso($obj['current_period_end']??0);$pdo->prepare('UPDATE user_subscriptions SET current_period_start=COALESCE(?,current_period_start),current_period_end=COALESCE(?,current_period_end),updated_at=? WHERE user_id=?')->execute([$start,$end,$now,$uid]);sf_billing_log($uid,'customer.subscription.created','stripe',$subId,['package_id'=>$pkg,'status'=>$status]);}
        }
        elseif($type==='invoice.paid'){
            $subId=sf_stripe_subscription_id_from_invoice($obj);$local=sf_billing_find_user_by_provider_subscription($subId);
            if(!$local){[$uid,$pkg]=sf_billing_event_identity($obj);if($uid>0){$customer=is_string($obj['customer']??null)?(string)$obj['customer']:'';sf_billing_bind_stripe_subscription($uid,$pkg,$customer,$subId,'active',$now);$local=sf_billing_find_user_by_provider_subscription($subId);}}
            if($local){$uid=(int)$local['user_id'];$pkg=(int)($local['package_id']??0);$isNew=sf_billing_store_invoice($uid,$pkg?:null,$obj,$subId);$start=sf_timestamp_iso($obj['period_start']??0);$end=sf_timestamp_iso($obj['period_end']??0);$invoiceId=(string)($obj['id']??'');$pdo->prepare("UPDATE user_subscriptions SET status='active',current_period_start=COALESCE(?,current_period_start),current_period_end=COALESCE(?,current_period_end),grace_ends_at=NULL,last_invoice_id=?,last_payment_at=?,updated_at=? WHERE user_id=?")->execute([$start,$end,$invoiceId,$now,$now,$uid]);$grantSource='stripe_invoice:'.$invoiceId;if($pkg>0&&!sf_token_source_exists($uid,'monthly_grant',$grantSource)){$p=sf_package($pkg);$tokens=max(0,(int)($p['monthly_tokens']??0));if($tokens>0)sf_token_adjust($uid,$tokens,'monthly_grant',$grantSource,[],'Stripe subscription renewal');}sf_billing_log($uid,'invoice_paid','stripe',$invoiceId,['new_invoice'=>$isNew]);if($isNew){$amount=(int)($obj['amount_paid']??0);$currency=strtoupper((string)($obj['currency']??'USD'));$receipt=(string)($obj['hosted_invoice_url']??'');sf_billing_notify($uid,'Stonefellow subscription payment received',"Your Stonefellow subscription payment was received.\n\nAmount: ".number_format($amount/100,2).' '.$currency.($receipt!==''?"\nReceipt: ".$receipt:'')."\n\nThank you for supporting Stonefellow.",'subscription_payment');}}
        }
        elseif($type==='invoice.payment_failed'){
            $subId=sf_stripe_subscription_id_from_invoice($obj);$local=sf_billing_find_user_by_provider_subscription($subId);if($local){$uid=(int)$local['user_id'];$grace=gmdate('c',time()+7*86400);$pdo->prepare("UPDATE user_subscriptions SET status='past_due',grace_ends_at=?,last_invoice_id=?,updated_at=? WHERE user_id=?")->execute([$grace,(string)($obj['id']??''),$now,$uid]);sf_billing_log($uid,'invoice_payment_failed','stripe',(string)($obj['id']??''),['grace_ends_at'=>$grace]);sf_billing_notify($uid,'Stonefellow subscription payment needs attention',"We could not complete your Stonefellow subscription payment.\n\nYour account remains in a short grace period through ".$grace.". Open My Account → Manage billing to update your payment method.",'subscription_payment_failed');}
        }
        elseif($type==='customer.subscription.updated'||$type==='customer.subscription.deleted'){
            $subId=(string)($obj['id']??'');$local=sf_billing_find_user_by_provider_subscription($subId);if(!$local&&$type==='customer.subscription.updated'){[$uid,$pkg]=sf_billing_event_identity($obj);if($uid>0){$customer=is_string($obj['customer']??null)?(string)$obj['customer']:'';sf_billing_bind_stripe_subscription($uid,$pkg,$customer,$subId,(string)($obj['status']??'active'),$now);$local=sf_billing_find_user_by_provider_subscription($subId);}}
            if($local){$uid=(int)$local['user_id'];$status=$type==='customer.subscription.deleted'?'canceled':(string)($obj['status']??$local['status']);$cancel=!empty($obj['cancel_at_period_end'])?1:0;$canceled=sf_timestamp_iso($obj['canceled_at']??0);$start=sf_timestamp_iso($obj['current_period_start']??0);$end=sf_timestamp_iso($obj['current_period_end']??0);$pdo->prepare('UPDATE user_subscriptions SET status=?,cancel_at_period_end=?,canceled_at=?,current_period_start=COALESCE(?,current_period_start),current_period_end=COALESCE(?,current_period_end),updated_at=? WHERE user_id=?')->execute([$status,$cancel,$canceled,$start,$end,$now,$uid]);sf_billing_log($uid,$type,'stripe',$subId,['status'=>$status,'cancel_at_period_end'=>$cancel]);if($type==='customer.subscription.deleted')sf_billing_notify($uid,'Stonefellow subscription ended',"Your Stonefellow subscription has ended. Your account, purchases, library, and saved builds remain available.",'subscription_canceled');}
        }
        $pdo->prepare('UPDATE billing_webhook_events SET status=?,processed_at=? WHERE event_id=?')->execute(['processed',gmdate('c'),$eventId]);
    }catch(Throwable $e){$pdo->prepare('UPDATE billing_webhook_events SET status=?,error_text=?,processed_at=? WHERE event_id=?')->execute(['failed',sf_clean_text($e->getMessage(),1000),gmdate('c'),$eventId]);throw $e;}
}
function sf_billing_webhook_events(int $limit=100): array {sf_billing_ensure_schema();$limit=max(1,min(250,$limit));return sf_db()->query('SELECT event_id,provider,event_type,status,error_text,created_at,processed_at FROM billing_webhook_events ORDER BY created_at DESC LIMIT '.$limit)->fetchAll();}
function sf_billing_audit(int $limit=100): array {sf_billing_ensure_schema();$limit=max(1,min(250,$limit));return sf_db()->query('SELECT * FROM billing_audit_log ORDER BY id DESC LIMIT '.$limit)->fetchAll();}
