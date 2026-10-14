<?php
declare(strict_types=1);
/** Section 84B3: fixed album, owned downloads, finite vinyl-print packages,
 * and exactly-one-license-per-song-per-manufacturing-job.
 */
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/sf-licenses-'.bin2hex(random_bytes(7));
if(!mkdir($tmp,0700,true))throw new RuntimeException('temp unavailable');
define('SF_ROOT',$tmp);
$GLOBALS['test_orders']=[];
$GLOBALS['test_library']=[];
function sf_read_order(string $id):?array{return $GLOBALS['test_orders'][$id]??null;}
function sf_account_library(int $id):array{return $GLOBALS['test_library'][$id]??[];}
require_once $root.'/api/store-order-visibility.php';
require_once $root.'/api/kunaki-publishing-jobs.php';
require_once $root.'/api/vinyl-production-gates.php';
$passed=0;$failed=0;
function z83(bool $ok,string $name):void {global $passed,$failed;$ok?$passed++:$failed++;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
function no83(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}}
function paid83(string $id,array $lines,int $owner=31,bool $physical=false):array {
 return ['id'=>$id,'user_id'=>$owner,'status'=>'paid',
  'payment'=>['provider'=>'stripe','status'=>'paid','livemode'=>true,
   'payment_intent'=>'pi_test_fixture_signed','postpayment_done'=>['library'=>true,'pod'=>true]],
  'quote'=>['items'=>$lines,'physical'=>$physical]];
}
function grant83(string $type,string $key,string $order):array {
 return ['item_type'=>$type,'item_key'=>$key,'source_order_id'=>$order];
}
$catalog=[
 'song-a'=>['id'=>'song-a','title'=>'Song A','duration'=>175,'audio'=>'private/song-a.wav',
   'podEligible'=>true,'vinyl_license_packages'=>[
     ['licenses'=>1,'price_cents'=>250],['licenses'=>3,'price_cents'=>650],
     ['licenses'=>5,'price_cents'=>1000]]],
 'song-b'=>['id'=>'song-b','title'=>'Song B','duration'=>184,'audio'=>'private/song-b.wav',
   'podEligible'=>true,'vinyl_license_packages'=>[['licenses'=>2,'price_cents'=>450]]]
];
$release=['id'=>'my-album','state'=>'published','type'=>'album','title'=>'Locked Album',
 'track_ids'=>['song-a','song-b'],'purchasable'=>true,'public_visible'=>true,
 'vinyl_enabled'=>true,'vinyl_price_cents'=>4900,'vinyl_side_a_count'=>1,
 'artwork'=>['cover'=>'album-front.jpg','back'=>'album-back.jpg'],
 'vinyl_license_packages'=>[['licenses'=>1,'price_cents'=>950],['licenses'=>3,'price_cents'=>2550]]];
$album=sf_vinyl_locked_album($release,$catalog,1200);
z83($album['builder']['sides']['A']['tracks'][0]['id']==='song-a'
   &&$album['builder']['sides']['B']['tracks'][0]['id']==='song-b',
   'Locked album preserves canonical A/B sequence');
z83(strlen($album['edition_fingerprint'])===64&&str_starts_with($album['edition_id'],'ALB-'),
   'Album identity is immutable and reusable across physical orders');
z83(sf_vinyl_locked_album($release,$catalog,1200)['edition_id']===$album['edition_id'],
   'Identical album has one reusable edition identity');
$changed=$release;$changed['artwork']['cover']='new-cover.jpg';
z83(sf_vinyl_locked_album($changed,$catalog,1200)['edition_id']!==$album['edition_id'],
   'Changing artwork produces a new Kunaki edition identity');
z83(no83(fn()=>sf_vinyl_locked_album($release,$catalog,150)),
   'Side duration above pressing limit blocked');
$hidden=$release;$hidden['vinyl_enabled']=false;
z83(no83(fn()=>sf_vinyl_locked_album($hidden,$catalog,1200)),
   'Unpublished vinyl albums do not become purchasable');
$lic1=sf_vinyl_license_quote(['type'=>'vinyl_license_pack','track_id'=>'song-a','licenses'=>3],
 $catalog,['my-album'=>$release]);
z83($lic1['price_cents']===650&&$lic1['digital_access_granted']===false,
   '3-license song package uses Admin-listed server price and grants no digital access');
$licAlbum=sf_vinyl_license_quote(['type'=>'album_vinyl_license_pack','release_id'=>'my-album','licenses'=>3],
 $catalog,['my-album'=>$release]);
z83($licAlbum['price_cents']===2550&&count($licAlbum['track_ids'])===2,
 '3-license album package grants three prints of every fixed track');
z83(no83(fn()=>sf_vinyl_license_quote(['type'=>'vinyl_license_pack','track_id'=>'song-a','licenses'=>10],
 $catalog,['my-album'=>$release])),'Browser cannot invent discount or package count');
z83(no83(fn()=>sf_vinyl_license_quote(['type'=>'album_vinyl_license_pack','release_id'=>'my-album','licenses'=>5],
 $catalog,['my-album'=>$release])),'Unavailable album package never charges a fabricated amount');
z83(sf_vinyl_license_packages([['licenses'=>1,'price_cents'=>0],['licenses'=>5,'price_cents'=>1200]])[0]['licenses']===5,
 'Missing/unpriced packages remain unavailable instead of default charging');
$digital=paid83('SF-ALBUM-PAID',[['type'=>'release','release_id'=>'my-album','track_ids'=>['song-a','song-b'],
  'vinyl_print_licenses_included_per_song'=>1]]);
$physical=paid83('SF-VINYL-PAID',[['type'=>'album_vinyl','format'=>'vinyl',
 'release_id'=>'my-album','edition_id'=>$album['edition_id'],
 'edition_fingerprint'=>$album['edition_fingerprint'],
 'track_ids'=>['song-a','song-b'],'builder'=>$album['builder']]],31,true);
$GLOBALS['test_orders'][$digital['id']]=$digital;
$GLOBALS['test_orders'][$physical['id']]=$physical;
$GLOBALS['test_library'][31]=[
 grant83('release','my-album',$digital['id']),
 grant83('track','song-a',$digital['id']),
 grant83('track','song-b',$digital['id'])
];
$grants=sf_vinyl_license_grants(31,$GLOBALS['test_library'][31],$GLOBALS['test_orders']);
z83($grants['song-a']===1&&$grants['song-b']===1,
 'One purchased digital album grants exactly one vinyl print license per song (not duplicated by streaming grants)');
$record=['status'=>'artwork_approved','proof_sha256'=>str_repeat('b',64)];
$proof=['ready'=>true,'fingerprint'=>str_repeat('b',64)];
$eligible=sf_kunaki_publishing_entitlements($physical,0,
 $GLOBALS['test_library'][31],$GLOBALS['test_orders'],$catalog);
z83(count($eligible)===2&&$eligible[1]['track_id']==='song-b',
 'Fixed album is eligible through original paid digital album rights');
$contract=sf_kunaki_publishing_contract($physical,0,$record,$proof,$eligible);
z83($contract['edition']==='album'&&$contract['edition_id']===$album['edition_id']
 &&$contract['license_track_ids']===['song-a','song-b'],'Worker contract carries edition and specific debited song IDs');
$stored=sf_kunaki_job_enqueue_licensed($contract);
z83($stored['job_id']===$contract['job_id'],'First paid album pressing reserves one credit per song');
$balances=sf_vinyl_license_balances(31,$GLOBALS['test_library'][31],$GLOBALS['test_orders'],
 sf_kunaki_job_for_order($physical['id']));
z83($balances['song-a']['available']===0&&$balances['song-a']['reserved_or_used']===1,
 'First pressing consumes available print balance but does not revoke digital music access');
z83(count($GLOBALS['test_library'][31])===3,'Streaming/downloading entitlements remain in customer library');
$physical2=$physical;$physical2['id']='SF-VINYL-SECOND';
$GLOBALS['test_orders'][$physical2['id']]=$physical2;
$second=sf_kunaki_publishing_contract($physical2,0,$record,$proof,$eligible);
z83(no83(fn()=>sf_kunaki_job_enqueue_licensed($second)),
 'Second pressing blocked when customer has only one album license');
$pack=paid83('SF-LICENSE-3',[$licAlbum]);
$GLOBALS['test_orders'][$pack['id']]=$pack;
$GLOBALS['test_library'][31][]=grant83('album_vinyl_license_pack','my-album',$pack['id']);
$withPack=sf_vinyl_license_grants(31,$GLOBALS['test_library'][31],$GLOBALS['test_orders']);
z83($withPack['song-a']===4&&$withPack['song-b']===4,
 'Purchase of three album licenses adds three per song, not three shared total');
$stored2=sf_kunaki_job_enqueue_licensed($second);
z83($stored2['job_id']===$second['job_id'],'Second pressing succeeds after extra print licenses are purchased');
z83(sf_kunaki_job_enqueue_licensed($second)['job_id']===$second['job_id'],
 'Retry of same pressing cannot reserve a second license');
$jobs=array_merge(sf_kunaki_job_for_order($physical['id']),sf_kunaki_job_for_order($physical2['id']));
$remaining=sf_vinyl_license_balances(31,$GLOBALS['test_library'][31],$GLOBALS['test_orders'],$jobs);
z83($remaining['song-a']['available']===2&&$remaining['song-b']['available']===2,
 'Two pressings reserve exactly two album print credits');
z83(sf_vinyl_worker_job_allowed($stored2,$jobs),'Worker can claim when real source rights are still valid');
$GLOBALS['test_orders'][$pack['id']]['status']='refunded';
z83(!sf_vinyl_worker_job_allowed($stored2,$jobs),
 'Refund before manufacturing invalidates staged job before HomeServer claim');
$GLOBALS['test_orders'][$pack['id']]=$pack;
$justPackLibrary=[grant83('album_vinyl_license_pack','my-album',$pack['id'])];
z83(no83(fn()=>sf_kunaki_publishing_entitlements($physical,0,$justPackLibrary,$GLOBALS['test_orders'],$catalog)),
 'Purchased print licenses alone do not grant digital music ownership');
$other=sf_vinyl_license_grants(99,$GLOBALS['test_library'][31],$GLOBALS['test_orders']);
z83($other===[],'Other account cannot use another fan’s print licenses');
$testOrder=$digital;$testOrder['id']='SF-TEST-ALBUM';$testOrder['payment']['livemode']=false;
z83(sf_vinyl_license_grants(31,[grant83('release','my-album','SF-TEST-ALBUM')],
 ['SF-TEST-ALBUM'=>$testOrder])===[], 'TEST-mode licenses cannot fund production pressing');
$source=file_get_contents($root.'/admin/api/vinyl-production.php');
$js=file_get_contents($root.'/assets/js/app.js');
$admin=file_get_contents($root.'/admin/assets/admin.js');
$worker=file_get_contents($root.'/api/kunaki-worker.php');
$bootstrap=file_get_contents($root.'/api/bootstrap.php');
z83(str_contains($source,'sf_kunaki_job_enqueue_licensed($job)'),
 'Admin staging uses atomic license reservation, not unguarded queue insertion');
z83(str_contains($worker,"'sf_vinyl_worker_job_allowed'"),
 'Worker claim uses latest rights/refunds and cannot silently proceed on revoked license');
z83(str_contains($bootstrap,"$type==='vinyl_license_pack'")&&str_contains($bootstrap,"$type==='album_vinyl'"),
 'Original checkout quotes digital packs and fixed physical album products');
z83(str_contains($js,'storyPrintLicenseSelect')&&str_contains($js,'data-buy-album-licenses'),
 'Customer song and album screens expose additional license package purchase options');
z83(str_contains($admin,'releaseVinylLicenses')&&str_contains($admin,'data-vinyl-packages'),
 'Admin can price per-song and fixed-album license packages');
$path=$tmp.'/storage/kunaki/publishing-jobs.json';
@unlink($path);@unlink($path.'.lock');@unlink($tmp.'/storage/kunaki/.htaccess');
@rmdir($tmp.'/storage/kunaki');@rmdir($tmp.'/storage');@rmdir($tmp);
echo 'Section 84B3 finite vinyl print licenses: '.$passed.'/'.($passed+$failed).' passed'.PHP_EOL;
exit($failed?1:0);
