<?php
declare(strict_types=1);
/** Regression: public Store must remain JSON even when optional DBs are unavailable. */
$root=dirname(__DIR__);
require_once $root.'/api/storefront-core.php';
function sf_store_config():array{return ['currency'=>'USD','products'=>['custom_vinyl'=>['label'=>'Custom 12-inch vinyl','price_cents'=>4900,'format'=>'vinyl']]];}
function sf_media_public_links(string $type,string $id):array {
    if(!empty($GLOBALS['dbDown']))throw new PDOException('media tables missing',42);
    return [['role'=>'product_primary','category'=>'image','url'=>'cover.jpg']];
}
function sf_commerce_products(bool $publicOnly=false):array {
    if(!empty($GLOBALS['dbDown']))throw new PDOException('merch schema missing',42);
    return [['id'=>'shirt','slug'=>'shirt','title'=>'Band Shirt','description'=>'','category'=>'apparel',
      'base_price_cents'=>2500,'compare_at_price_cents'=>0,'physical'=>true,'featured'=>false,
      'available'=>true,'low_stock'=>false,'max_per_order'=>5,'image'=>'','media'=>[],'variants'=>[],'tags'=>[]]];
}
function sf_release_rows():array{return [
 ['id'=>'album','title'=>'Album One','type'=>'album','purchasable'=>true,'state'=>'published','public_visible'=>true,'digital_price'=>12,'artwork'=>['cover'=>'album.jpg']],
 ['id'=>'private','title'=>'Private','purchasable'=>true,'state'=>'published','public_visible'=>false,'digital_price'=>30],
 ['id'=>'draft','title'=>'Unreleased','purchasable'=>true,'state'=>'draft','digital_price'=>20],
 ['id'=>'unlisted','title'=>'Not for sale','purchasable'=>false,'state'=>'published','digital_price'=>15]
];}
function sf_commerce_summary():array{if(!empty($GLOBALS['dbDown']))throw new PDOException('summary database failed',42);return ['products'=>1];}
function check(bool $ok,string $description):void{if(!$ok){fwrite(STDERR,"FAIL ".$description.PHP_EOL);$GLOBALS['fail']++;}else echo "PASS ".$description.PHP_EOL;}
$GLOBALS['fail']=0;$GLOBALS['dbDown']=false;
$normal=sf_storefront_payload();$json=json_encode($normal,JSON_THROW_ON_ERROR);
check($normal['ok']===true&&!$normal['partial'],'Normal store emits successful JSON');
check(count($normal['products'])===2,'All builder and merchandise products load');
check($normal['merch_status']==='available','Merch status accurate when catalog available');
check(count($normal['releases'])===1,'Unpublished, hidden, and unsellable releases excluded');
check(($normal['releases'][0]['artwork']['cover']??'')==='album.jpg','Release artwork follows browser contract');
check(($normal['releases'][0]['digital_price']??0)===12.0,'Release digital price follows browser contract');
check(json_decode($json,true,512,JSON_THROW_ON_ERROR)['ok']===true,'Store payload is valid JSON');
$GLOBALS['dbDown']=true;
$partial=sf_storefront_payload();
check($partial['ok']===true&&$partial['partial']===true,'Optional database failure does not take down store');
check(count($partial['products'])===1&&$partial['products'][0]['kind']==='builder','Builder remains visible when merch tables fail');
check($partial['merch_status']==='unavailable','Unavailable merchandise is not labeled empty stock');
check(count($partial['releases'])===1,'Public releases remain visible when merch DB fails');
check($partial['merch_summary']===null,'Missing summary is never misreported as real zero revenue');
check(count($partial['warnings'])>=2,'Degraded storefront has truthful notices');
check(!str_contains(json_encode($partial),'schema missing'),'Raw SQL exception not exposed in response');
$endpoint=(string)file_get_contents($root.'/api/storefront.php');
check(str_contains($endpoint,'catch(Throwable $e)')&&str_contains($endpoint,"'error_ref'"),'Unhandled endpoint failures return JSON with safe reference');
$source=(string)file_get_contents($root.'/assets/js/app.js');
check(str_contains($source,'data.merch_status')&&str_contains($source,'data.warnings'),'Frontend distinguishes catalog outage from no stock');
check(!str_contains($endpoint,'sf_releases()'),'Undefined sf_releases call eliminated');
$ci=(string)file_get_contents($root.'/.github/workflows/release-gate.yml');
check(str_contains($ci,'php tests/section74-storefront-runtime.php'),'Live storefront regression mandatory in release gate');
echo 'Section 74 storefront: '.(18-$GLOBALS['fail']).'/18 checks passed'.PHP_EOL;
exit($GLOBALS['fail']?1:0);
