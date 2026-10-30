<?php
declare(strict_types=1);

// Section 91B9A: optional, account-scoped Speech Engine pilot. The legacy
// microphone and existing ElevenLabs text-to-speech remain the default.
function sf_speech_engine_ensure_schema(): void {
    $pdo=sf_db();
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_speech_engine_preferences (user_id INTEGER PRIMARY KEY, engine TEXT NOT NULL DEFAULT 'legacy', updated_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_speech_engine_preferences (user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, engine VARCHAR(24) NOT NULL DEFAULT 'legacy', updated_at VARCHAR(40) NOT NULL, CONSTRAINT fk_speech_engine_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

function sf_speech_engine_choice(string $value): string {
    if(!in_array($value,['legacy','speech_engine'],true))throw new InvalidArgumentException('Choose a supported voice engine.');
    return $value;
}

function sf_speech_engine_pilot_config(): array {
    $id=sf_ai_meta_get('ai.speech_engine_id','');
    $enabled=sf_ai_meta_get('ai.speech_engine_enabled','0')==='1';
    $provider=sf_ai_provider_public('elevenlabs');
    $ready=$enabled&&preg_match('/^seng_[A-Za-z0-9_-]{8,96}$/',$id)===1&&$provider['enabled']&&$provider['key_configured']&&sf_ai_meta_get('ai.voice_provider','elevenlabs')==='elevenlabs';
    return ['pilot_enabled'=>$enabled,'engine_id'=>$id,'provider_configured'=>$provider['enabled']&&$provider['key_configured'],'ready'=>$ready];
}

function sf_speech_engine_save_pilot_config(string $engineId,bool $enabled): array {
    $engineId=trim($engineId);
    if($engineId!==''&&preg_match('/^seng_[A-Za-z0-9_-]{8,96}$/',$engineId)!==1)throw new InvalidArgumentException('Enter a valid ElevenLabs Speech Engine ID.');
    if($enabled&&$engineId==='')throw new InvalidArgumentException('Set a Speech Engine ID before enabling the pilot.');
    if($enabled){$provider=sf_ai_provider_public('elevenlabs');if(!$provider['enabled']||!$provider['key_configured'])throw new InvalidArgumentException('Enable the existing ElevenLabs provider and save its API key first.');}
    sf_ai_meta_set('ai.speech_engine_id',$engineId);
    sf_ai_meta_set('ai.speech_engine_enabled',$enabled?'1':'0');
    return sf_speech_engine_pilot_config();
}

function sf_speech_engine_user_choice(int $userId): string {
    if($userId<1)throw new InvalidArgumentException('A signed-in account is required.');
    sf_speech_engine_ensure_schema();
    $q=sf_db()->prepare('SELECT engine FROM user_speech_engine_preferences WHERE user_id=?');
    $q->execute([$userId]);$engine=$q->fetchColumn();
    return in_array($engine,['legacy','speech_engine'],true)?(string)$engine:'legacy';
}

function sf_speech_engine_save_choice(int $userId,string $engine): string {
    $engine=sf_speech_engine_choice($engine);
    if($engine==='speech_engine'&&!sf_speech_engine_pilot_config()['ready'])throw new InvalidArgumentException('The Speech Engine pilot is not configured yet. The original voice engine is still available.');
    sf_speech_engine_ensure_schema();
    $pdo=sf_db();$q=$pdo->prepare('SELECT user_id FROM user_speech_engine_preferences WHERE user_id=?');$q->execute([$userId]);
    if($q->fetchColumn()!==false)$pdo->prepare('UPDATE user_speech_engine_preferences SET engine=?,updated_at=? WHERE user_id=?')->execute([$engine,gmdate('c'),$userId]);
    else $pdo->prepare('INSERT INTO user_speech_engine_preferences(user_id,engine,updated_at) VALUES(?,?,?)')->execute([$userId,$engine,gmdate('c')]);
    return $engine;
}

function sf_speech_engine_public_state(int $userId): array {
    $pilot=sf_speech_engine_pilot_config();$choice=sf_speech_engine_user_choice($userId);
    // The account selects its own runtime; provider/gateway connection errors
    // leave the original voice path selectable without mutating the preference.
    return ['selected_engine'=>$choice,'effective_engine'=>$choice==='speech_engine'&&$pilot['ready']?'speech_engine':'legacy',
        'pilot_available'=>$pilot['ready'],'provider_configured'=>$pilot['provider_configured'],
        'pilot_enabled'=>$pilot['pilot_enabled']];
}
