<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');foreach(range(1,7) as $id)$db->exec('INSERT INTO users VALUES('.$id.')');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_privacy_personalization_enabled(int $uid):bool{return $uid!==7;}
function sf_privacy_ensure_schema():void{sf_db()->exec('CREATE TABLE IF NOT EXISTS user_profiles(user_id INTEGER PRIMARY KEY,personalization_enabled INTEGER NOT NULL DEFAULT 1)');}
require dirname(__DIR__).'/api/fan-conversion-intent-core.php';
$check=function(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException('87D2 FAIL '.$msg);};
$q=['ok'=>true,'items'=>[['type'=>'track','track_id'=>'public-song']]];
foreach(range(1,6) as $uid)$check(sf_87d_intent_record($uid,'cart_review',$q),'record');
$check(!sf_87d_intent_record(1,'cart_review',$q),'idempotent daily cart fingerprint');
$check(sf_87d_intent_record(1,'checkout_review',$q),'different stage');
$check(!sf_87d_intent_record(7,'cart_review',$q),'consent disabled');
$check(!sf_87d_intent_record(1,'purchase_confirmed',$q),'client cannot assert paid');
$x=sf_87d_intent_aggregate(30);$by=[];foreach($x['segments'] as $s)$by[$s['stage']]=$s;
$check($by['cart_review']['accounts']===6&&$by['cart_review']['events']===6,'aggregate with cohort');
$check($by['checkout_review']['accounts']===null&&$by['checkout_review']['insufficient_sample'],'small sample hidden');
$check($x['cart_to_payment_conversion_rate']===null&&!$x['is_payment_evidence'],'review cannot be payment');
sf_87d_intent_clear(1);$x=sf_87d_intent_aggregate(30);$check($x['segments'][0]['accounts']===5,'privacy deletion affects report');
$ui=file_get_contents(dirname(__DIR__).'/assets/js/app.js');
$privacy=file_get_contents(dirname(__DIR__).'/api/privacy.php');
$endpoint=file_get_contents(dirname(__DIR__).'/api/fan-conversion-intent.php');
$check(str_contains($ui,"recordConversionReview('cart_review')")&&str_contains($ui,"recordConversionReview('checkout_review')"),'existing cart and checkout connected');
$check(str_contains($privacy,'sf_87d_intent_clear($uid)'),'privacy reset and opt out purge');
$check(str_contains($endpoint,'sf_require_csrf()')&&str_contains($endpoint,'sf_quote($cart'), 'CSRF and server quote');
echo "87D2: 12+ guarded assertions passed\n";
