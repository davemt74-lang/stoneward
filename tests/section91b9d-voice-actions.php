<?php
declare(strict_types=1);
/** 91B9D: authorized one-time voice actions never bypass account permission. */
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,display_name TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$db->exec("INSERT INTO users VALUES(2,'a@example.test','Alice','user','active','','',''),(3,'b@example.test','Bob','user','active','','','')");
$meta=['ai.voice_provider'=>'elevenlabs'];
function sf_db():PDO{global $db;return $db;}
function sf_ai_meta_get(string $key,string $fallback=''):string{global $meta;return $meta[$key]??$fallback;}
function sf_ai_meta_set(string $key,string $value):void{global $meta;$meta[$key]=$value;}
function sf_ai_provider_public(string $name):array{return ['enabled'=>true,'key_configured'=>true];}
require dirname(__DIR__).'/api/speech-engine-actions-core.php';
$count=0;$check=static function(bool $ok,string $label)use(&$count){if(!$ok){fwrite(STDERR,"91B9D FAIL: $label\n");exit(1);}echo "91B9D PASS: $label\n";$count++;};
sf_speech_engine_save_pilot_config('seng_abcdef123456',true);
sf_speech_engine_save_choice(2,'speech_engine');
$cid='conv_owner_91b9d_abcdefgh';
$claim=sf_speech_issue_claim(2);sf_speech_register_conversation($cid);sf_speech_bind_account(2,$cid,$claim);
$reply=['action'=>['type'=>'play_track','track_id'=>'verified_song_11'],'requires_confirmation'=>false,
    'audio_resolution'=>['method'=>'release_catalog','status'=>'verified','track_id'=>'verified_song_11']];
$check(sf_speech_queue_action(2,$cid,$reply,'Play my song'),'Owner can queue published-catalog playback only');
$check(!sf_speech_queue_action(3,$cid,$reply,'Steal song'),'Other account cannot enqueue owner action');
try{sf_speech_take_action(3,$cid);$check(false,'Cross-account action was delivered');}catch(RuntimeException){$check(true,'Other account cannot take voice actions');}
$taken=sf_speech_take_action(2,$cid);
$check(($taken['action']['type']??'')==='play_track'&&($taken['message']??'')==='Play my song','Owner receives exact policy action and original utterance');
$check(($taken['audio_resolution']['track_id']??'')==='verified_song_11','Published-catalog receipt accompanies playback');
$check(sf_speech_take_action(2,$cid)===null,'Action can only execute once');
foreach(['builder_save','create_playlist','set_favorite_track','checkout','purchase'] as $type){
    $check(!sf_speech_queue_action(2,$cid,['action'=>['type'=>$type]],'Do something risky'),'Sensitive '.$type.' is excluded from voice');
}
$check(!sf_speech_queue_action(2,$cid,['action'=>['type'=>'open_view','view'=>'admin']], 'Open admin'),'Voice page navigation cannot open Admin');
$check(!sf_speech_queue_action(2,$cid,['action'=>['type'=>'open_view','view'=>'builder'],'requires_confirmation'=>true],'Need approval'),'Confirmed actions are never auto-executed');
$check(sf_speech_queue_action(2,$cid,['action'=>['type'=>'open_view','view'=>'shows']],'Show upcoming shows'),'Public page action allowed');
$shown=sf_speech_take_action(2,$cid);$check(($shown['action']['view']??'')==='shows','Browser receives approved public page target');
$check(sf_speech_queue_action(2,$cid,['action'=>['type'=>'set_player_volume','mode'=>'set','percent'=>150]],'Volume max'),'Volume action allowed');
$volume=sf_speech_take_action(2,$cid);$check($volume['action']['percent']===100,'Voice volume clamps to safe range');
$check(sf_speech_queue_action(2,$cid,['action'=>['type'=>'next_track']],'Next song'),'Fresh voice action queued');
$meta['ai.voice_provider']='none';
try{sf_speech_take_action(2,$cid);$check(false,'Disabled voice account took pending action');}catch(RuntimeException){$check(true,'Provider off revokes queued action delivery');}
$meta['ai.voice_provider']='elevenlabs';
sf_speech_clear_actions($cid);$check(sf_speech_take_action(2,$cid)===null,'Closing a session destroys undelivered actions');
$bridge=(string)file_get_contents(dirname(__DIR__).'/api/speech-engine-bridge.php');
$endpoint=(string)file_get_contents(dirname(__DIR__).'/api/speech-engine.php');
$check(str_contains($bridge,'sf_speech_queue_action')&&str_contains($bridge,'sf_speech_bound_user'),'Gateway still binds actor before queueing policy action');
$check(str_contains($endpoint,"if(\$action==='take_action')")&&str_contains($endpoint,"sf_require_user(false,\$method!=='GET')"),'Browser delivery is authenticated and CSRF protected');
echo "Section 91B9D: $count one-use, permission and public-action checks passed\n";
