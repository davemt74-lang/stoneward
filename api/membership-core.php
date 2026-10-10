<?php
declare(strict_types=1);

function sf_membership_json(array $v): string {return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';}
function sf_membership_decode(mixed $v,array $fallback=[]): array {$d=is_array($v)?$v:json_decode((string)$v,true);return is_array($d)?$d:$fallback;}
function sf_membership_benefits(array $raw): array {
    return [
        'merch_discount_percent'=>max(0,min(100,(int)($raw['merch_discount_percent']??0))),
        'early_access_days'=>max(0,min(365,(int)($raw['early_access_days']??0))),
        'vip_access'=>!empty($raw['vip_access']),
        'priority_presale'=>!empty($raw['priority_presale']),
        'member_content'=>!empty($raw['member_content']),
        'exclusive_downloads'=>!empty($raw['exclusive_downloads']),
        'member_only_offers'=>!empty($raw['member_only_offers'])
    ];
}
function sf_membership_ensure_schema(): void {
    static $done=false;if($done)return;sf_entitlements_ensure_schema();$driver=(string)(sf_db_config()['driver']??'');
    sf_schema_add_column('subscription_packages','membership_enabled','INTEGER NOT NULL DEFAULT 0','TINYINT(1) NOT NULL DEFAULT 0');
    sf_schema_add_column('subscription_packages','membership_rank','INTEGER NOT NULL DEFAULT 0','INT NOT NULL DEFAULT 0');
    sf_schema_add_column('subscription_packages','membership_badge',"TEXT NOT NULL DEFAULT ''","VARCHAR(80) NOT NULL DEFAULT ''");
    sf_schema_add_column('subscription_packages','membership_benefits_json',"TEXT NOT NULL DEFAULT '{}'","LONGTEXT NOT NULL");
    $pdo=sf_db();
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS membership_content (id INTEGER PRIMARY KEY AUTOINCREMENT,slug TEXT NOT NULL UNIQUE,title TEXT NOT NULL,content_type TEXT NOT NULL DEFAULT 'post',status TEXT NOT NULL DEFAULT 'draft',minimum_package_id INTEGER NULL,minimum_rank INTEGER NOT NULL DEFAULT 0,teaser TEXT NOT NULL DEFAULT '',body_text TEXT NOT NULL DEFAULT '',external_url TEXT NOT NULL DEFAULT '',starts_at TEXT NULL,ends_at TEXT NULL,featured INTEGER NOT NULL DEFAULT 0,sort_order INTEGER NOT NULL DEFAULT 0,created_by INTEGER NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(minimum_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL,FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_membership_content_status_sort ON membership_content(status,featured,sort_order,starts_at)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS membership_content (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(160) NOT NULL UNIQUE,title VARCHAR(220) NOT NULL,content_type VARCHAR(40) NOT NULL DEFAULT 'post',status VARCHAR(24) NOT NULL DEFAULT 'draft',minimum_package_id BIGINT UNSIGNED NULL,minimum_rank INT NOT NULL DEFAULT 0,teaser TEXT NOT NULL,body_text LONGTEXT NOT NULL,external_url TEXT NOT NULL,starts_at VARCHAR(40) NULL,ends_at VARCHAR(40) NULL,featured TINYINT(1) NOT NULL DEFAULT 0,sort_order INT NOT NULL DEFAULT 0,created_by BIGINT UNSIGNED NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,UNIQUE KEY uq_membership_content_slug(slug),INDEX idx_membership_content_status_sort(status,featured,sort_order,starts_at),CONSTRAINT fk_membership_content_package FOREIGN KEY(minimum_package_id) REFERENCES subscription_packages(id) ON DELETE SET NULL,CONSTRAINT fk_membership_content_admin FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_membership_package(array $p): array {
    sf_membership_ensure_schema();$benefits=sf_membership_benefits(sf_membership_decode($p['membership_benefits_json']??'',[]));
    return ['enabled'=>!empty($p['membership_enabled']),'rank'=>max(0,(int)($p['membership_rank']??0)),'badge'=>(string)($p['membership_badge']??''),'benefits'=>$benefits];
}
function sf_membership_status_active(array $sub): bool {
    $status=(string)($sub['status']??'');if(in_array($status,['active','trialing'],true))return true;
    if($status==='past_due'&&!empty($sub['grace_ends_at'])&&strtotime((string)$sub['grace_ends_at'])>=time())return true;
    return false;
}
function sf_membership_state(int $userId,bool $syncCrm=false): array {
    sf_membership_ensure_schema();$sub=function_exists('sf_billing_subscription_row')?sf_billing_subscription_row($userId):null;$package=null;$membership=['enabled'=>false,'rank'=>0,'badge'=>'','benefits'=>sf_membership_benefits([])];
    if($sub&&!empty($sub['package_id'])){$package=sf_package((int)$sub['package_id']);if($package)$membership=sf_membership_package($package);}
    $active=(bool)($sub&&$membership['enabled']&&sf_membership_status_active($sub));$state=['active'=>$active,'status'=>(string)($sub['status']??'none'),'package_id'=>(int)($sub['package_id']??0),'package_name'=>(string)($sub['package_name']??($package['name']??'')),'package_slug'=>(string)($sub['package_slug']??($package['slug']??'')),'rank'=>(int)$membership['rank'],'badge'=>(string)$membership['badge'],'benefits'=>$membership['benefits'],'current_period_end'=>$sub['current_period_end']??null,'trial_ends_at'=>$sub['trial_ends_at']??null,'cancel_at_period_end'=>!empty($sub['cancel_at_period_end']),'grace_ends_at'=>$sub['grace_ends_at']??null];
    if($syncCrm)sf_membership_sync_crm($userId,$state);return $state;
}
function sf_membership_sync_crm(int $userId,?array $state=null): void {
    if($userId<1)return;$state=$state??sf_membership_state($userId,false);$contact=sf_crm_sync_user($userId);if(!$contact)return;$cid=(int)$contact['id'];$active=!empty($state['active']);$stage=(string)($contact['status']??'fan');
    if($active&&$stage!=='member'){sf_db()->prepare("UPDATE fan_contacts SET status='member',updated_at=? WHERE id=?")->execute([gmdate('c'),$cid]);sf_crm_log_event($cid,$userId,'membership_activated','Membership active: '.($state['package_name']?:'Stonefellow member'),'subscription',(string)$state['package_id'],['badge'=>$state['badge'],'rank'=>$state['rank']]);}
    elseif(!$active&&$stage==='member'){ $q=sf_db()->prepare("SELECT 1 FROM fan_crm_events WHERE contact_id=? AND event_type IN ('purchase','merch_purchase') LIMIT 1");$q->execute([$cid]);$fallback=$q->fetchColumn()?'customer':'fan';sf_db()->prepare('UPDATE fan_contacts SET status=?,updated_at=? WHERE id=?')->execute([$fallback,gmdate('c'),$cid]);sf_crm_log_event($cid,$userId,'membership_inactive','Membership is no longer active','subscription',(string)($state['package_id']??0),['fallback_stage'=>$fallback]); }
}
function sf_membership_can(int $userId,string $benefit): bool {$s=sf_membership_state($userId,false);return !empty($s['active'])&&!empty($s['benefits'][$benefit]);}
function sf_membership_discount_percent(?int $userId): int {if(!$userId)return 0;$s=sf_membership_state($userId,false);return $s['active']?max(0,min(100,(int)($s['benefits']['merch_discount_percent']??0))):0;}
function sf_membership_discount_amount(?int $userId,array $items): int {
    $pct=sf_membership_discount_percent($userId);if($pct<=0)return 0;$eligible=0;foreach($items as $i)if(($i['type']??'')==='merch')$eligible+=(int)($i['price_cents']??0);return min($eligible,(int)round($eligible*$pct/100));
}
function sf_membership_content_get(int|string $id): ?array {
    sf_membership_ensure_schema();$col=is_int($id)||ctype_digit((string)$id)?'id':'slug';$q=sf_db()->prepare("SELECT mc.*,p.name minimum_package_name FROM membership_content mc LEFT JOIN subscription_packages p ON p.id=mc.minimum_package_id WHERE mc.$col=? LIMIT 1");$q->execute([$id]);$r=$q->fetch();return $r?:null;
}
function sf_membership_content_list(bool $publicOnly=false): array {
    sf_membership_ensure_schema();$where=$publicOnly?" WHERE mc.status='published' AND (mc.ends_at IS NULL OR mc.ends_at='' OR mc.ends_at>=?)":'';$args=$publicOnly?[gmdate('c')]:[];$q=sf_db()->prepare('SELECT mc.*,p.name minimum_package_name FROM membership_content mc LEFT JOIN subscription_packages p ON p.id=mc.minimum_package_id'.$where.' ORDER BY mc.featured DESC,mc.sort_order,mc.id DESC');$q->execute($args);return $q->fetchAll();
}
function sf_membership_content_save(array $raw,int $adminId): array {
    sf_membership_ensure_schema();$id=max(0,(int)($raw['id']??0));$title=sf_clean_text($raw['title']??'',220);if($title==='')throw new InvalidArgumentException('Member content title is required.');$slug=trim(strtolower(preg_replace('/[^a-z0-9]+/','-',(string)($raw['slug']??$title))??''),'-')?:'member-content';$type=(string)($raw['content_type']??'post');if(!in_array($type,['post','audio','video','download','announcement','vip_offer'],true))$type='post';$status=(string)($raw['status']??'draft');if(!in_array($status,['draft','published','archived'],true))$status='draft';$pkg=max(0,(int)($raw['minimum_package_id']??0))?:null;$rank=max(0,(int)($raw['minimum_rank']??0));if($pkg){$p=sf_package($pkg);if(!$p)throw new InvalidArgumentException('Minimum membership package not found.');$rank=max($rank,(int)($p['membership_rank']??0));}$now=gmdate('c');$vals=[$slug,$title,$type,$status,$pkg,$rank,sf_clean_text($raw['teaser']??'',2000),sf_clean_text($raw['body_text']??'',30000),sf_clean_text($raw['external_url']??'',1200),sf_clean_text($raw['starts_at']??'',40)?:null,sf_clean_text($raw['ends_at']??'',40)?:null,!empty($raw['featured'])?1:0,(int)($raw['sort_order']??0),$now];
    try{if($id){$q=sf_db()->prepare('UPDATE membership_content SET slug=?,title=?,content_type=?,status=?,minimum_package_id=?,minimum_rank=?,teaser=?,body_text=?,external_url=?,starts_at=?,ends_at=?,featured=?,sort_order=?,updated_at=? WHERE id=?');$q->execute([...$vals,$id]);if(!$q->rowCount()&&!sf_membership_content_get($id))throw new InvalidArgumentException('Member content not found.');}else{$q=sf_db()->prepare('INSERT INTO membership_content(slug,title,content_type,status,minimum_package_id,minimum_rank,teaser,body_text,external_url,starts_at,ends_at,featured,sort_order,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([...array_slice($vals,0,13),$adminId?:null,$now,$now]);$id=(int)sf_db()->lastInsertId();}}catch(PDOException $e){if(in_array((string)$e->getCode(),['23000','19'],true))throw new InvalidArgumentException('Member content slug is already in use.');throw $e;}return sf_membership_content_get($id)?:[];
}
function sf_membership_content_delete(int $id): bool {$q=sf_db()->prepare("DELETE FROM membership_content WHERE id=? AND status IN ('draft','archived')");$q->execute([$id]);return $q->rowCount()>0;}
function sf_membership_content_access(?int $userId,array $content): array {
    $state=$userId?sf_membership_state($userId,false):['active'=>false,'rank'=>0,'package_id'=>0,'benefits'=>sf_membership_benefits([])];$requiredRank=max(0,(int)($content['minimum_rank']??0));$requiredPackage=(int)($content['minimum_package_id']??0);$benefits=(array)($state['benefits']??[]);$allowed=!empty($state['active'])&&!empty($benefits['member_content'])&&(int)$state['rank']>=$requiredRank;$reason=$allowed?'member_access':'membership_required';
    if($requiredPackage>0&&$allowed){$pkg=sf_package($requiredPackage);$need=max($requiredRank,(int)($pkg['membership_rank']??0));if((int)$state['rank']<$need){$allowed=false;$reason='higher_tier_required';}}
    $type=(string)($content['content_type']??'post');if($allowed&&$type==='vip_offer'&&empty($benefits['vip_access'])){$allowed=false;$reason='vip_benefit_required';}if($allowed&&$type==='download'&&empty($benefits['exclusive_downloads'])){$allowed=false;$reason='download_benefit_required';}
    $starts=(string)($content['starts_at']??'');$early=false;if($allowed&&$starts!==''&&strtotime($starts)>time()){$days=max(0,(int)($benefits['early_access_days']??0));$early=$days>0&&strtotime($starts)<=time()+$days*86400;if(!$early){$allowed=false;$reason='not_yet_available';}else$reason='early_access';}
    return ['allowed'=>$allowed,'reason'=>$reason,'early_access'=>$early,'state'=>$state,'required_rank'=>$requiredRank,'required_package_id'=>$requiredPackage,'required_package_name'=>(string)($content['minimum_package_name']??'')];
}
function sf_membership_public_content(?int $userId): array {
    $rows=[];foreach(sf_membership_content_list(true) as $c){$access=sf_membership_content_access($userId,$c);$media=function_exists('sf_media_public_links')?sf_media_public_links('member_content',(string)$c['id']):[];$rows[]=['id'=>(int)$c['id'],'slug'=>(string)$c['slug'],'title'=>(string)$c['title'],'content_type'=>(string)$c['content_type'],'teaser'=>(string)$c['teaser'],'body_text'=>$access['allowed']?(string)$c['body_text']:'','external_url'=>$access['allowed']?(string)$c['external_url']:'','featured'=>!empty($c['featured']),'access'=>$access,'media'=>$access['allowed']?$media:[]];}return $rows;
}
function sf_membership_summary(): array {
    sf_membership_ensure_schema();$pdo=sf_db();$active=0;$byTier=[];$q=$pdo->query("SELECT s.user_id,s.package_id,s.status,s.grace_ends_at,p.name,p.membership_enabled FROM user_subscriptions s LEFT JOIN subscription_packages p ON p.id=s.package_id WHERE p.membership_enabled=1");foreach($q->fetchAll() as $r){if(sf_membership_status_active($r)){$active++;$name=(string)($r['name']??'Membership');$byTier[$name]=($byTier[$name]??0)+1;}}return ['active_members'=>$active,'tiers'=>$byTier,'content'=>(int)$pdo->query("SELECT COUNT(*) FROM membership_content")->fetchColumn(),'published_content'=>(int)$pdo->query("SELECT COUNT(*) FROM membership_content WHERE status='published'")->fetchColumn()];
}
function sf_membership_agent_context(int $userId): string {
    $s=sf_membership_state($userId,false);if(!$s['active'])return "MEMBERSHIP\n- no active Stonefellow membership";$b=$s['benefits'];$benefits=[];if($b['merch_discount_percent'])$benefits[]=$b['merch_discount_percent'].'% merch discount';if($b['early_access_days'])$benefits[]=$b['early_access_days'].' days early access';foreach(['vip_access'=>'VIP access','priority_presale'=>'priority presale','member_content'=>'member content','exclusive_downloads'=>'exclusive downloads','member_only_offers'=>'member-only offers'] as $k=>$label)if(!empty($b[$k]))$benefits[]=$label;return "MEMBERSHIP\n- ".$s['package_name'].' | '.($s['badge']?:'member').' | status '.$s['status']."\n- benefits ".implode(', ',$benefits);
}
