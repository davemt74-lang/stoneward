<?php
declare(strict_types=1);
if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,"pdo_sqlite required to certify purchase grants\n");exit(1);}
$root=sys_get_temp_dir().'/sf-72c-'.bin2hex(random_bytes(6));mkdir($root);
define('SF_ROOT',$root);
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT NOT NULL)");
$pdo->exec("INSERT INTO users(id,email) VALUES(7,'owner@example.com')");
function sf_db():PDO{global $pdo;return $pdo;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $s,int $limit=255):string{return mb_substr(trim((string)$s),0,$limit);}
require __DIR__.'/../api/account-data.php';
require __DIR__.'/../api/turntable-core.php';
function ck72c(bool $ok,string $name):void{if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}echo "PASS $name\n";}
$items=[
 ['type'=>'track','track_id'=>'song-1','label'=>'Track 1','price_cents'=>199],
 ['type'=>'custom_media','format'=>'vinyl','label'=>'Custom Record','price_cents'=>4200],
 ['type'=>'turntable_module','module_id'=>'pack-afterdark','label'=>'After Dark','price_cents'=>1499]
];
$order=['id'=>'SF-PAID-72C','status'=>'payment_pending','payment'=>['status'=>'pending'],'created_at'=>'2026-10-13T15:00:00Z','quote'=>['items'=>$items]];
sf_account_library_add_order(7,$order);
ck72c(count(sf_account_library(7))===0,'Pending payment adds no purchased tracks, vinyl, or player pack');
ck72c(!in_array('pack-afterdark',sf_turntable_owned(7),true),'Pending payment cannot unlock paid pack');
$order['status']='paid_test';$order['payment']['status']='test_paid';
sf_account_library_add_order(7,$order);
$lib=sf_account_library(7);
ck72c(count($lib)===3,'Paid purchase imports all three owned line items');
ck72c(in_array('pack-afterdark',sf_turntable_owned(7),true),'Paid pack unlocks itself');
ck72c(in_array('arm-precision',sf_turntable_owned(7),true),'Pack grants included tonearm');
ck72c(in_array('base-nocturne',sf_turntable_owned(7),true),'Pack grants included premium base');
sf_account_library_add_order(7,$order);
ck72c(count(sf_account_library(7))===3,'Payment callback retry does not duplicate entries');
$other=$order;$other['id']='SF-REFUNDED-72C';$other['status']='refunded';
sf_account_library_add_order(7,$other);
ck72c(count(sf_account_library(7))===3,'Refunded order cannot newly grant missing entitlements');
$other['status']='canceled';sf_account_library_add_order(7,$other);
ck72c(count(sf_account_library(7))===3,'Canceled order cannot grant new entries');
$other['status']='payment_pending';$other['payment']['status']='pending';sf_account_library_add_order(7,$other);
ck72c(count(sf_account_library(7))===3,'Another pending purchase grants nothing');
$other['status']='paid_cash_simulated';$other['payment']['status']='simulated_paid';$other['id']='SF-CASH-72C';
sf_account_library_add_order(7,$other);
ck72c(count(sf_account_library(7))===6,'Confirmed simulated cash is eligible as existing test payment');
ck72c(sf_order_paid_for_library(['status'=>'payment_pending','payment'=>['status'=>'pending']])===false,'Pending helper fails closed');
ck72c(sf_order_paid_for_library(['status'=>'fulfilled','payment'=>['status'=>'succeeded']])===true,'Settlement helper recognizes future confirmed payment');
ck72c(sf_order_paid_for_library(['status'=>'canceled','payment'=>['status'=>'succeeded']])===false,'Canceled orders cannot grant on settlement');
rmdir($root);
echo "Section 72C: 14/14 entitlement checks passed\n";
