<?php
declare(strict_types=1);

const SF_AI_SECRET_CONFIG = SF_ROOT . '/config/app-secret.php';

function sf_ai_providers(): array {
    return [
        'openai'=>['label'=>'OpenAI / ChatGPT API','kind'=>'llm'],
        'anthropic'=>['label'=>'Anthropic / Claude','kind'=>'llm'],
        'elevenlabs'=>['label'=>'ElevenLabs','kind'=>'voice'],
        'jev'=>['label'=>'JEV AI','kind'=>'decision'],
    ];
}

function sf_ai_ensure_schema(): void {
    $pdo=sf_db(); $driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_provider_settings (provider TEXT PRIMARY KEY,enabled INTEGER NOT NULL DEFAULT 0,secret_payload TEXT NULL,key_hint TEXT NOT NULL DEFAULT '',model TEXT NOT NULL DEFAULT '',voice_id TEXT NOT NULL DEFAULT '',endpoint_url TEXT NOT NULL DEFAULT '',updated_at TEXT NOT NULL,last_verified_at TEXT NULL,last_status TEXT NOT NULL DEFAULT '')");
        $cols=$pdo->query('PRAGMA table_info(ai_provider_settings)')->fetchAll();$names=array_column($cols,'name');if(!in_array('endpoint_url',$names,true))$pdo->exec("ALTER TABLE ai_provider_settings ADD COLUMN endpoint_url TEXT NOT NULL DEFAULT ''");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_provider_settings (provider VARCHAR(40) PRIMARY KEY,enabled TINYINT(1) NOT NULL DEFAULT 0,secret_payload LONGTEXT NULL,key_hint VARCHAR(32) NOT NULL DEFAULT '',model VARCHAR(160) NOT NULL DEFAULT '',voice_id VARCHAR(180) NOT NULL DEFAULT '',endpoint_url VARCHAR(500) NOT NULL DEFAULT '',updated_at VARCHAR(40) NOT NULL,last_verified_at VARCHAR(40) NULL,last_status VARCHAR(40) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $q=$pdo->query("SHOW COLUMNS FROM ai_provider_settings LIKE 'endpoint_url'");if(!$q->fetch())$pdo->exec("ALTER TABLE ai_provider_settings ADD COLUMN endpoint_url VARCHAR(500) NOT NULL DEFAULT '' AFTER voice_id");
    }
}
function sf_ai_secret_key(): string {
    if(is_file(SF_AI_SECRET_CONFIG)){
        $v=require SF_AI_SECRET_CONFIG;
        if(is_string($v)){
            $raw=base64_decode($v,true);
            if(is_string($raw)&&strlen($raw)===32)return $raw;
        }
        throw new RuntimeException('Stonefellow AI encryption key is invalid.');
    }
    $raw=random_bytes(32);
    $export="<?php\ndeclare(strict_types=1);\nreturn ".var_export(base64_encode($raw),true).";\n";
    if(file_put_contents(SF_AI_SECRET_CONFIG,$export,LOCK_EX)===false)throw new RuntimeException('Could not create config/app-secret.php. Check config directory permissions.');
    @chmod(SF_AI_SECRET_CONFIG,0640);
    return $raw;
}

function sf_ai_encrypt_with_key(string $plain,string $key): string {
    if($plain==='')return '';
    if(function_exists('sodium_crypto_secretbox')){
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=sodium_crypto_secretbox($plain,$nonce,$key);
        return 'v1:sodium:'.base64_encode($nonce).':'.base64_encode($cipher);
    }
    if(!function_exists('openssl_encrypt'))throw new RuntimeException('OpenSSL or Sodium is required to encrypt API keys.');
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'stonefellow-ai-v1');
    if($cipher===false)throw new RuntimeException('Could not encrypt API key.');
    return 'v1:gcm:'.base64_encode($iv).':'.base64_encode($tag).':'.base64_encode($cipher);
}

function sf_ai_decrypt_with_key(string $payload,string $key): string {
    if($payload==='')return '';
    $p=explode(':',$payload);
    if(count($p)===4&&$p[0]==='v1'&&$p[1]==='gcm'){
        [$v,$alg,$iv64,$tag64,$cipher64]=array_pad($p,5,'');
    }
    if(count($p)===4&&$p[0]==='v1'&&$p[1]==='sodium'){
        $nonce=base64_decode($p[2],true);$cipher=base64_decode($p[3],true);
        if(!is_string($nonce)||!is_string($cipher)||!function_exists('sodium_crypto_secretbox_open'))throw new RuntimeException('Stored API key cannot be decrypted on this server.');
        $plain=sodium_crypto_secretbox_open($cipher,$nonce,$key);
        if($plain===false)throw new RuntimeException('Stored API key failed integrity validation.');
        return $plain;
    }
    if(count($p)===5&&$p[0]==='v1'&&$p[1]==='gcm'){
        $iv=base64_decode($p[2],true);$tag=base64_decode($p[3],true);$cipher=base64_decode($p[4],true);
        if(!is_string($iv)||!is_string($tag)||!is_string($cipher)||!function_exists('openssl_decrypt'))throw new RuntimeException('Stored API key cannot be decrypted on this server.');
        $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'stonefellow-ai-v1');
        if($plain===false)throw new RuntimeException('Stored API key failed integrity validation.');
        return $plain;
    }
    throw new RuntimeException('Stored API key uses an unsupported encryption format.');
}

function sf_ai_encrypt(string $plain): string { return sf_ai_encrypt_with_key($plain,sf_ai_secret_key()); }
function sf_ai_decrypt(string $payload): string { return sf_ai_decrypt_with_key($payload,sf_ai_secret_key()); }
function sf_ai_hint(string $key): string { $key=trim($key);return $key===''?'':'••••'.substr($key,-4); }

function sf_ai_meta_get(string $key,string $default=''): string {
    $q=sf_db()->prepare('SELECT meta_value FROM app_meta WHERE meta_key=? LIMIT 1');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:(string)$v;
}
function sf_ai_meta_set(string $key,string $value): void {
    $pdo=sf_db();$q=$pdo->prepare('SELECT meta_key FROM app_meta WHERE meta_key=?');$q->execute([$key]);$now=gmdate('c');
    if($q->fetchColumn()!==false){$u=$pdo->prepare('UPDATE app_meta SET meta_value=?,updated_at=? WHERE meta_key=?');$u->execute([$value,$now,$key]);}
    else {$u=$pdo->prepare('INSERT INTO app_meta(meta_key,meta_value,updated_at) VALUES(?,?,?)');$u->execute([$key,$value,$now]);}
}

function sf_ai_provider_row(string $provider): array {
    if(!isset(sf_ai_providers()[$provider]))throw new InvalidArgumentException('Unsupported AI provider.');
    sf_ai_ensure_schema();$q=sf_db()->prepare('SELECT * FROM ai_provider_settings WHERE provider=? LIMIT 1');$q->execute([$provider]);$r=$q->fetch();
    return $r?:['provider'=>$provider,'enabled'=>0,'secret_payload'=>null,'key_hint'=>'','model'=>'','voice_id'=>'','endpoint_url'=>'','updated_at'=>'','last_verified_at'=>null,'last_status'=>''];
}

function sf_ai_provider_public(string $provider): array {
    $def=sf_ai_providers()[$provider]??null;if(!$def)throw new InvalidArgumentException('Unsupported AI provider.');$r=sf_ai_provider_row($provider);
    return ['provider'=>$provider,'label'=>$def['label'],'kind'=>$def['kind'],'enabled'=>(bool)($r['enabled']??false),'key_configured'=>!empty($r['secret_payload']),'key_hint'=>(string)($r['key_hint']??''),'model'=>(string)($r['model']??''),'voice_id'=>(string)($r['voice_id']??''),'endpoint_url'=>(string)($r['endpoint_url']??''),'updated_at'=>(string)($r['updated_at']??''),'last_verified_at'=>$r['last_verified_at']??null,'last_status'=>(string)($r['last_status']??'')];
}

function sf_ai_state(): array {
    sf_ai_ensure_schema();$providers=[];foreach(array_keys(sf_ai_providers()) as $p)$providers[$p]=sf_ai_provider_public($p);
    return ['providers'=>$providers,'routing'=>[
        'preferred_llm'=>sf_ai_meta_get('ai.preferred_llm','openai'),
        'fallback_enabled'=>sf_ai_meta_get('ai.fallback_enabled','1')==='1',
        'voice_provider'=>sf_ai_meta_get('ai.voice_provider','elevenlabs'),
        'decision_provider'=>sf_ai_meta_get('ai.decision_provider','none'),
        'jev_response_eval'=>sf_ai_meta_get('ai.jev_response_eval','0')==='1'
    ]];
}

function sf_ai_save_provider(string $provider,array $data): array {
    if(!isset(sf_ai_providers()[$provider]))throw new InvalidArgumentException('Unsupported AI provider.');sf_ai_ensure_schema();$pdo=sf_db();$old=sf_ai_provider_row($provider);
    $enabled=!empty($data['enabled'])?1:0;$model=sf_clean_text($data['model']??'',160);$voice=sf_clean_text($data['voice_id']??'',180);$endpoint=sf_clean_text($data['endpoint_url']??'',500);$payload=(string)($old['secret_payload']??'');$hint=(string)($old['key_hint']??'');
    if(!empty($data['clear_key'])){$payload='';$hint='';$enabled=0;}
    $newKey=trim((string)($data['api_key']??''));
    if($newKey!==''){
        if(strlen($newKey)<8||strlen($newKey)>4096)throw new InvalidArgumentException('API key length is invalid.');
        $payload=sf_ai_encrypt($newKey);$hint=sf_ai_hint($newKey);
    }
    if($enabled&&$payload==='')throw new InvalidArgumentException('Add an API key before enabling this provider.');
    $now=gmdate('c');$q=$pdo->prepare('SELECT provider FROM ai_provider_settings WHERE provider=?');$q->execute([$provider]);
    if($q->fetchColumn()!==false){$u=$pdo->prepare('UPDATE ai_provider_settings SET enabled=?,secret_payload=?,key_hint=?,model=?,voice_id=?,endpoint_url=?,updated_at=? WHERE provider=?');$u->execute([$enabled,$payload,$hint,$model,$voice,$endpoint,$now,$provider]);}
    else {$u=$pdo->prepare('INSERT INTO ai_provider_settings(provider,enabled,secret_payload,key_hint,model,voice_id,endpoint_url,updated_at,last_verified_at,last_status) VALUES(?,?,?,?,?,?,?,?,NULL,?)');$u->execute([$provider,$enabled,$payload,$hint,$model,$voice,$endpoint,$now,'']);}
    return sf_ai_provider_public($provider);
}

function sf_ai_save_routing(array $data): array {
    $preferred=(string)($data['preferred_llm']??'openai');if(!in_array($preferred,['openai','anthropic'],true))throw new InvalidArgumentException('Choose OpenAI or Anthropic as the preferred LLM.');
    $voice=(string)($data['voice_provider']??'elevenlabs');if(!in_array($voice,['elevenlabs','none'],true))throw new InvalidArgumentException('Choose a supported voice provider.');
    $decision=(string)($data['decision_provider']??'none');if(!in_array($decision,['jev','none'],true))throw new InvalidArgumentException('Choose a supported decision provider.');
    sf_ai_meta_set('ai.preferred_llm',$preferred);
    sf_ai_meta_set('ai.fallback_enabled',!empty($data['fallback_enabled'])?'1':'0');
    sf_ai_meta_set('ai.voice_provider',$voice);
    sf_ai_meta_set('ai.decision_provider',$decision);
    sf_ai_meta_set('ai.jev_response_eval',!empty($data['jev_response_eval'])?'1':'0');
    return sf_ai_state()['routing'];
}

function sf_ai_provider_secret(string $provider): string {
    $r=sf_ai_provider_row($provider);$payload=(string)($r['secret_payload']??'');return $payload===''?'':sf_ai_decrypt($payload);
}

function sf_ai_resolve_llm(): ?array {
    $state=sf_ai_state();$first=$state['routing']['preferred_llm'];$order=[$first];if($state['routing']['fallback_enabled'])$order[]=($first==='openai'?'anthropic':'openai');
    foreach(array_unique($order) as $provider){$r=sf_ai_provider_row($provider);if(empty($r['enabled'])||empty($r['secret_payload']))continue;return ['provider'=>$provider,'api_key'=>sf_ai_decrypt((string)$r['secret_payload']),'model'=>(string)($r['model']??'')];}return null;
}


function sf_ai_llm_candidates(): array {
    $state=sf_ai_state();$first=$state['routing']['preferred_llm'];$order=[$first];if($state['routing']['fallback_enabled'])$order[]=($first==='openai'?'anthropic':'openai');$out=[];foreach(array_unique($order) as $provider){$r=sf_ai_provider_row($provider);if(empty($r['enabled'])||empty($r['secret_payload']))continue;$out[]=['provider'=>$provider,'api_key'=>sf_ai_decrypt((string)$r['secret_payload']),'model'=>(string)($r['model']??''),'endpoint_url'=>(string)($r['endpoint_url']??'')];}return $out;
}
function sf_ai_resolve_decision(): ?array {
    if(sf_ai_meta_get('ai.decision_provider','none')!=='jev')return null;$r=sf_ai_provider_row('jev');if(empty($r['enabled'])||empty($r['secret_payload']))return null;return ['provider'=>'jev','api_key'=>sf_ai_decrypt((string)$r['secret_payload']),'model'=>(string)($r['model']??''),'endpoint_url'=>(string)($r['endpoint_url']??'')];
}
function sf_ai_resolve_voice(): ?array {
    $provider=sf_ai_meta_get('ai.voice_provider','elevenlabs');if($provider==='none')return null;$r=sf_ai_provider_row($provider);if(empty($r['enabled'])||empty($r['secret_payload']))return null;return ['provider'=>$provider,'api_key'=>sf_ai_decrypt((string)$r['secret_payload']),'model'=>(string)($r['model']??''),'voice_id'=>(string)($r['voice_id']??'')];
}
