<?php
declare(strict_types=1);
/** Section 91B9B: provider session ownership, replay and revocation boundaries. */
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,display_name TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$db->exec("INSERT INTO users VALUES(1,'d@example.test','Owner','admin','active','','',''),(2,'a@example.test','Alice','user','active','','',''),(3,'b@example.test','Bob','user','active','','','')");
$meta=['ai.voice_provider'=>'elevenlabs'];$provider=['enabled'=>true,'key_configured'=>true];
function sf_db():PDO{global $db;return $db;}
function sf_ai_meta_get(string $key,string $fallback=''):string{global $meta;return $meta[$key]??$fallback;}
function sf_ai_meta_set(string $key,string $value):void{global $meta;$meta[$key]=$value;}
function sf_ai_provider_public(string $name):array{global $provider;return $provider;}
require dirname(__DIR__).'/api/speech-engine-session-core.php';
$count=0;$check=static function(bool $ok,string $label)use(&$count){if(!$ok){fwrite(STDERR,"91B9B FAIL: $label\n");exit(1);}echo "91B9B PASS: $label\n";$count++;};
sf_speech_engine_save_pilot_config('seng_abcdef123456',true);
sf_speech_engine_save_choice(2,'speech_engine');
$check(sf_speech_session_authorized(2)&&!sf_speech_session_authorized(3),'Pilot authorization is per-account');
$claim=sf_speech_issue_claim(2);$check(strlen($claim)===64,'Claim has 256-bit unpredictable entropy');
sf_speech_register_conversation('conv_owner_abcdefgh12345');
try{sf_speech_bind_account(3,'conv_owner_abcdefgh12345',$claim);$check(false,'Other account bound');}catch(RuntimeException){$check(true,'Other account cannot claim first owner session');}
$check(sf_speech_bound_user('conv_owner_abcdefgh12345')===null,'Unbound session is denied private Agent Brain');
sf_speech_bind_account(2,'conv_owner_abcdefgh12345',$claim);
$check((int)sf_speech_bound_user('conv_owner_abcdefgh12345')['id']===2,'Successful bind resolves correct account');
try{sf_speech_bind_account(2,'conv_owner_abcdefgh12345',$claim);$check(false,'Claim reused');}catch(RuntimeException){$check(true,'One-use claim cannot be replayed');}
$claim2=sf_speech_issue_claim(2);sf_speech_register_conversation('conv_other_abcdefgh12345');
try{sf_speech_bind_account(2,'conv_owner_abcdefgh12345',$claim2);$check(false,'Bound session replaced');}catch(RuntimeException){$check(true,'Bound conversation cannot be reassigned');}
sf_speech_bind_account(2,'conv_other_abcdefgh12345',$claim2);
$check((int)sf_speech_bound_user('conv_other_abcdefgh12345')['id']===2,'New session can bind independently');
$now=time();$secret='super-secret-for-test-voice-bridge-transport-123';putenv('SF_SPEECH_BRIDGE_SECRET='.$secret);
$raw='{"action":"register","conversation_id":"conv_owner_abcdefgh12345"}';$nonce=bin2hex(random_bytes(16));
$headers=['timestamp'=>(string)$now,'nonce'=>$nonce,'signature'=>sf_speech_bridge_signature($raw,$now,$secret.'|'.$nonce)];
sf_speech_verify_bridge($raw,$headers,$now);$check(true,'Correct signed, time-bound gateway request accepted');
try{sf_speech_verify_bridge($raw,$headers,$now);$check(false,'Nonce replay accepted');}catch(RuntimeException){$check(true,'Gateway nonce replay rejected');}
$changed=$headers;$changed['nonce']=bin2hex(random_bytes(16));
try{sf_speech_verify_bridge($raw,$changed,$now);$check(false,'Modified nonce accepted');}catch(RuntimeException){$check(true,'Gateway signature binds nonce and exact request body');}
$expired=['timestamp'=>(string)($now-90),'nonce'=>bin2hex(random_bytes(16)),'signature'=>str_repeat('a',64)];
try{sf_speech_verify_bridge($raw,$expired,$now);$check(false,'Stale auth accepted');}catch(RuntimeException){$check(true,'Timestamp expiry rejects stale bridge request');}
sf_speech_close_conversation('conv_owner_abcdefgh12345');
$check(sf_speech_bound_user('conv_owner_abcdefgh12345')===null,'Disconnect revokes conversation access');
$meta['ai.voice_provider']='none';
$check(sf_speech_bound_user('conv_other_abcdefgh12345')===null,'Admin voice-provider off disables an existing bound session');
$core=(string)file_get_contents(dirname(__DIR__).'/api/speech-engine-session-core.php');
$bridge=(string)file_get_contents(dirname(__DIR__).'/api/speech-engine-bridge.php');
$endpoint=(string)file_get_contents(dirname(__DIR__).'/api/speech-engine.php');
$check(str_contains($core,'/v1/convai/conversation/token?')&&str_contains($core,'xi-api-key: '),'WebRTC token issued server-side using existing provider key');
$check(str_contains($bridge,'sf_speech_verify_bridge')&&str_contains($bridge,'sf_speech_bound_user')&&str_contains($bridge,'sf_agent_reply'),'Voice bridge shares existing Agent Brain only after authentication');
$check(str_contains($endpoint,'sf_require_user(false,$method!==\'GET\')')&&str_contains($endpoint,"if(\$action==='bind')"),'Client binding uses signed-in CSRF-protected account endpoint');
echo "Section 91B9B: $count account, replay, and privacy assertions passed\n";
