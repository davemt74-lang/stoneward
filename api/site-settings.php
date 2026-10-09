<?php
declare(strict_types=1);

function sf_site_settings_ensure_schema(): void {
    static $done=false;
    if($done) return;
    $pdo=sf_db();
    $driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_meta (meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_meta (meta_key VARCHAR(100) PRIMARY KEY,meta_value LONGTEXT NOT NULL,updated_at VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_meta_get(string $key,string $default=''): string {
    sf_site_settings_ensure_schema();
    $q=sf_db()->prepare('SELECT meta_value FROM app_meta WHERE meta_key=? LIMIT 1');
    $q->execute([$key]);
    $v=$q->fetchColumn();
    return $v===false?$default:(string)$v;
}
function sf_meta_set(string $key,string $value): void {
    sf_site_settings_ensure_schema();
    $pdo=sf_db();
    $q=$pdo->prepare('SELECT meta_key FROM app_meta WHERE meta_key=?');
    $q->execute([$key]);
    $now=gmdate('c');
    if($q->fetchColumn()!==false){
        $u=$pdo->prepare('UPDATE app_meta SET meta_value=?,updated_at=? WHERE meta_key=?');
        $u->execute([$value,$now,$key]);
    }else{
        $u=$pdo->prepare('INSERT INTO app_meta(meta_key,meta_value,updated_at) VALUES(?,?,?)');
        $u->execute([$key,$value,$now]);
    }
}
function sf_site_settings(): array {
    return [
        'splash_enabled'=>sf_meta_get('site.splash_enabled','0')==='1',
        'splash_revision'=>sf_meta_get('site.splash_revision','1'),
        'fan_community_enabled'=>sf_meta_get('site.fan_community_enabled','0')==='1',
        'announcement_enabled'=>sf_meta_get('site.announcement_enabled','0')==='1',
        'announcement_text'=>sf_meta_get('site.announcement_text',''),
        'maintenance_enabled'=>sf_meta_get('site.maintenance_enabled','0')==='1',
        'maintenance_message'=>sf_meta_get('site.maintenance_message','Stonefellow is briefly unavailable while we make an update.'),
        'seo_title'=>sf_meta_get('site.seo_title','Stonefellow — Listening Room'),
        'seo_description'=>sf_meta_get('site.seo_description','Stonefellow — an interactive listening room and custom record builder.'),
        'social_instagram'=>sf_meta_get('site.social_instagram',''),
        'social_youtube'=>sf_meta_get('site.social_youtube',''),
        'social_bandcamp'=>sf_meta_get('site.social_bandcamp',''),
        'social_spotify'=>sf_meta_get('site.social_spotify',''),
        'privacy_text'=>sf_meta_get('site.privacy_text',''),
        'terms_text'=>sf_meta_get('site.terms_text',''),
    ];
}
function sf_site_settings_update(array $data): array {
    $enabled=!empty($data['splash_enabled']);
    $before=sf_meta_get('site.splash_enabled','0')==='1';
    sf_meta_set('site.splash_enabled',$enabled?'1':'0');
    if($enabled!==$before || !sf_meta_get('site.splash_revision','')){
        sf_meta_set('site.splash_revision',(string)time());
    }
    foreach(['fan_community_enabled','announcement_enabled','maintenance_enabled'] as $key) if(array_key_exists($key,$data)) sf_meta_set('site.'.$key,!empty($data[$key])?'1':'0');
    foreach(['announcement_text','maintenance_message','seo_title','seo_description'] as $key) if(array_key_exists($key,$data)) sf_meta_set('site.'.$key,sf_clean_text($data[$key],$key==='seo_description'||$key==='maintenance_message'?500:180));
    foreach(['social_instagram','social_youtube','social_bandcamp','social_spotify'] as $key) if(array_key_exists($key,$data)){$url=trim((string)$data[$key]);if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)))throw new InvalidArgumentException('Social links must use a valid http/https URL.');sf_meta_set('site.'.$key,substr($url,0,500));}
    foreach(['privacy_text','terms_text'] as $key) if(array_key_exists($key,$data)) sf_meta_set('site.'.$key,substr(trim((string)$data[$key]),0,20000));
    return sf_site_settings();
}
