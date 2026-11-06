<?php
declare(strict_types=1);
require_once __DIR__.'/claude-model-routing.php';

const SF_AI_SECRET_CONFIG = SF_ROOT . '/config/app-secret.php';
const SF_AI_CODING_MODEL_LIMIT = 16;

final class SfAiCanvasModelUnavailable extends InvalidArgumentException {}

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

function sf_ai_coding_model_id_valid(mixed $model): bool {
    return is_string($model)&&preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,159}\z/',$model)===1;
}

function sf_ai_saved_coding_models(): array {
    $stored=sf_ai_meta_get('ai.coding_models');
    if($stored==='')return [];
    $models=json_decode($stored,true);
    return is_array($models)?$models:['openai'=>[],'anthropic'=>[]];
}

function sf_ai_coding_models(array $providers,array $routing): array {
    $defaults=[
        'openai'=>[trim($providers['openai']['model'])?:'gpt-6-luna'],
        'anthropic'=>[trim($providers['anthropic']['model']),$routing['claude_fast_model'],$routing['claude_reasoning_model']],
    ];
    $saved=sf_ai_saved_coding_models();
    $models=[];
    foreach($defaults as $provider=>$list){
        $entries=array_key_exists($provider,$saved)?(array)$saved[$provider]:$list;
        $models[$provider]=array_values(array_unique(array_filter($entries,'sf_ai_coding_model_id_valid')));
    }
    return $models;
}

/** Validate the whole change before the routing endpoint writes any settings. */
function sf_ai_validate_coding_models(mixed $input): array {
    $providers=['openai'=>[],'anthropic'=>[]];
    if(!is_array($input)||array_diff_key($input,$providers))
        throw new InvalidArgumentException('Choose coding model lists for OpenAI or Anthropic.');
    if(!$input)return $providers;
    foreach($input as $provider=>$models){
        if(!is_array($models)||!array_is_list($models)||count($models)>SF_AI_CODING_MODEL_LIMIT)
            throw new InvalidArgumentException('Enter up to '.SF_AI_CODING_MODEL_LIMIT.' coding model IDs per provider.');
        foreach($models as $model){
            if(!sf_ai_coding_model_id_valid($model))
                throw new InvalidArgumentException('Enter exact coding model IDs using letters, numbers, dots, colons, hyphens or underscores.');
        }
        $input[$provider]=array_values(array_unique($models));
    }
    return $input;
}

function sf_ai_state(): array {
    sf_ai_ensure_schema();$providers=[];foreach(array_keys(sf_ai_providers()) as $p)$providers[$p]=sf_ai_provider_public($p);
    $routing=[
        'preferred_llm'=>sf_ai_meta_get('ai.preferred_llm','openai'),
        'fallback_enabled'=>sf_ai_meta_get('ai.fallback_enabled','1')==='1',
        'voice_provider'=>sf_ai_meta_get('ai.voice_provider','elevenlabs'),
        'decision_provider'=>sf_ai_meta_get('ai.decision_provider','none'),
        'jev_response_eval'=>sf_ai_meta_get('ai.jev_response_eval','0')==='1',
        'claude_mode'=>sf_claude_effective_mode(sf_ai_meta_get('ai.claude_mode',''),(string)($providers['anthropic']['model']??'')),
        'claude_fast_model'=>sf_ai_meta_get('ai.claude_fast_model',SF_CLAUDE_FAST_MODEL),
        'claude_reasoning_model'=>sf_ai_meta_get('ai.claude_reasoning_model',SF_CLAUDE_REASONING_MODEL)
    ];
    $routing['coding_models']=sf_ai_coding_models($providers,$routing);
    return ['providers'=>$providers,'routing'=>$routing];
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
    // Validate all model choices before changing any persisted provider setting.
    $current=sf_ai_state()['routing'];
    $mode=(string)($data['claude_mode']??$current['claude_mode']);
    if(!in_array($mode,['auto','manual'],true))throw new InvalidArgumentException('Choose automatic or manual Claude model routing.');
    $fast=sf_claude_model_choice((string)($data['claude_fast_model']??$current['claude_fast_model']));
    $reasoning=sf_claude_model_choice((string)($data['claude_reasoning_model']??$current['claude_reasoning_model']));
    $codingModels=array_key_exists('coding_models',$data)?sf_ai_validate_coding_models($data['coding_models']):null;
    sf_ai_meta_set('ai.preferred_llm',$preferred);
    sf_ai_meta_set('ai.fallback_enabled',!empty($data['fallback_enabled'])?'1':'0');
    sf_ai_meta_set('ai.voice_provider',$voice);
    sf_ai_meta_set('ai.decision_provider',$decision);
    sf_ai_meta_set('ai.jev_response_eval',!empty($data['jev_response_eval'])?'1':'0');
    sf_ai_meta_set('ai.claude_mode',$mode);
    sf_ai_meta_set('ai.claude_fast_model',$fast);
    sf_ai_meta_set('ai.claude_reasoning_model',$reasoning);
    if($codingModels!==null)sf_ai_meta_set('ai.coding_models',json_encode($codingModels+sf_ai_saved_coding_models(),JSON_THROW_ON_ERROR));
    return sf_ai_state()['routing'];
}

function sf_ai_provider_secret(string $provider): string {
    $r=sf_ai_provider_row($provider);$payload=(string)($r['secret_payload']??'');return $payload===''?'':sf_ai_decrypt($payload);
}

function sf_ai_resolve_llm(): ?array {
    return sf_ai_llm_candidates('chat')[0]??null;
}

function sf_ai_llm_candidate(array $row): ?array {
    if(empty($row['enabled'])||empty($row['secret_payload']))return null;
    return ['provider'=>(string)$row['provider'],'api_key'=>sf_ai_decrypt((string)$row['secret_payload']),
        'model'=>(string)($row['model']??''),'endpoint_url'=>(string)($row['endpoint_url']??'')];
}

function sf_ai_llm_candidates(string $workload='chat'): array {
    $state=sf_ai_state();$first=$state['routing']['preferred_llm'];$order=[$first];if($state['routing']['fallback_enabled'])$order[]=($first==='openai'?'anthropic':'openai');$out=[];
    foreach(array_unique($order) as $provider){
        $candidate=sf_ai_llm_candidate(sf_ai_provider_row($provider));
        if($candidate===null)continue;
        $out[]=sf_claude_choose_model($candidate,$state['routing'],$workload);
    }
    return $out;
}

/** The browser receives only configured model IDs and their provider names. */
function sf_ai_canvas_model_options(): array {
    $state=sf_ai_state();
    $options=[];
    foreach($state['routing']['coding_models'] as $provider=>$models){
        $row=$state['providers'][$provider];
        if(!$row['enabled']||!$row['key_configured'])continue;
        foreach($models as $model)$options[]=['provider'=>$provider,'model'=>$model];
    }
    return $options;
}

function sf_ai_canvas_llm_candidates(?array $selection=null): array {
    if($selection===null)return sf_ai_llm_candidates('chat');
    $provider=$selection['provider']??null;
    $model=$selection['model']??null;
    $message='That coding model is no longer available. Choose another model or use Default.';
    $models=sf_ai_state()['routing']['coding_models'];
    if(!is_string($provider)||!isset($models[$provider])||!is_string($model)||!in_array($model,$models[$provider],true))
        throw new SfAiCanvasModelUnavailable($message);
    $candidate=sf_ai_llm_candidate(sf_ai_provider_row($provider));
    if($candidate===null)throw new SfAiCanvasModelUnavailable($message);
    $candidate['model']=$model;
    return [$candidate];
}
function sf_ai_resolve_decision(): ?array {
    if(sf_ai_meta_get('ai.decision_provider','none')!=='jev')return null;$r=sf_ai_provider_row('jev');if(empty($r['enabled'])||empty($r['secret_payload']))return null;return ['provider'=>'jev','api_key'=>sf_ai_decrypt((string)$r['secret_payload']),'model'=>(string)($r['model']??''),'endpoint_url'=>(string)($r['endpoint_url']??'')];
}
function sf_ai_resolve_voice(): ?array {
    $provider=sf_ai_meta_get('ai.voice_provider','elevenlabs');if($provider==='none')return null;$r=sf_ai_provider_row($provider);if(empty($r['enabled'])||empty($r['secret_payload']))return null;return ['provider'=>$provider,'api_key'=>sf_ai_decrypt((string)$r['secret_payload']),'model'=>(string)($r['model']??''),'voice_id'=>(string)($r['voice_id']??'')];
}
