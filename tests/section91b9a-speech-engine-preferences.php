<?php
declare(strict_types=1);
/** Section 91B9A: saved credential reuse and per-user opt-in gates. */
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT NOT NULL)');
$db->exec("INSERT INTO users VALUES(1,'admin'),(2,'user'),(3,'user')");
$db->exec('CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY,meta_value TEXT,updated_at TEXT)');
$meta=[];$provider=['enabled'=>true,'key_configured'=>true];
function sf_db():PDO{global $db;return $db;}
function sf_ai_meta_get(string $key,string $fallback=''):string{global $meta;return $meta[$key]??$fallback;}
function sf_ai_meta_set(string $key,string $value):void{global $meta;$meta[$key]=$value;}
function sf_ai_provider_public(string $name):array{global $provider;if($name!=='elevenlabs')throw new RuntimeException('Wrong provider');return $provider;}
require dirname(__DIR__).'/api/speech-engine-core.php';
$count=0;
$check=static function(bool $ok,string $description)use(&$count){if(!$ok){fwrite(STDERR,"91B9A FAIL: $description\n");exit(1);}echo "91B9A PASS: $description\n";$count++;};
$check(sf_speech_engine_user_choice(2)==='legacy','Original conversational voice remains default');
$check(sf_speech_engine_public_state(2)['effective_engine']==='legacy','No implicit microphone migration');
try{sf_speech_engine_save_choice(2,'speech_engine');$check(false,'Premature pilot accepted');}catch(InvalidArgumentException){$check(true,'Pilot opt-in gated before provider and Admin setup');}
try{sf_speech_engine_save_pilot_config('invalid',true);$check(false,'Invalid ID accepted');}catch(InvalidArgumentException){$check(true,'Invalid Speech Engine ID rejected');}
try{sf_speech_engine_save_choice(2,'cloud-snoop');$check(false,'Unknown mode accepted');}catch(InvalidArgumentException){$check(true,'Unsupported provider selection rejected');}
$provider=['enabled'=>false,'key_configured'=>false];
try{sf_speech_engine_save_pilot_config('seng_abcdef1234',true);$check(false,'No-key setup accepted');}catch(InvalidArgumentException){$check(true,'Existing encrypted ElevenLabs provider required');}
$provider=['enabled'=>true,'key_configured'=>true];
$pilot=sf_speech_engine_save_pilot_config('seng_abcdef1234',true);
$check($pilot['ready']===true&&$pilot['engine_id']==='seng_abcdef1234','Admin can enable registered engine using existing provider');
$check(sf_speech_engine_save_choice(2,'speech_engine')==='speech_engine','Signed-in account can opt in');
$check(sf_speech_engine_user_choice(3)==='legacy','Other account remains on original engine');
$check(sf_speech_engine_public_state(2)['selected_engine']==='speech_engine'&&sf_speech_engine_public_state(2)['effective_engine']==='legacy','Preference does not route audio before authenticated transport');
$check(sf_speech_engine_save_choice(2,'legacy')==='legacy','Per-account rollback is immediate and explicit');
$meta['ai.voice_provider']='none';
$check(sf_speech_engine_public_state(2)['pilot_available']===false,'Admin voice-provider off switch also disables the pilot');
$meta['ai.voice_provider']='elevenlabs';
$provider=['enabled'=>false,'key_configured'=>false];
$check(sf_speech_engine_public_state(2)['pilot_available']===false,'Disabling credential blocks new pilot opt-ins');
$check(!array_key_exists('api_key',sf_speech_engine_public_state(2)),'Public account data contains no API key');
$route=(string)file_get_contents(dirname(__DIR__).'/api/speech-engine.php');
$admin=(string)file_get_contents(dirname(__DIR__).'/admin/api/ai-providers.php');
$account=(string)file_get_contents(dirname(__DIR__).'/api/account.php');
$check(str_contains($route,'sf_require_user(false,$method!==\'GET\')'),'Account preference changes require CSRF and auth');
$check(str_contains($admin,'sf_admin_require_auth($write)')&&str_contains($admin,"save_speech_engine"),'Only Admin can enable pilot');
$check(str_contains($account,'sf_speech_engine_public_state($uid)'),'Account bootstrap includes owner-scoped status');
echo "Section 91B9A: $count preference and privacy assertions passed\n";
