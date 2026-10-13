<?php
declare(strict_types=1);
// Execute the actual pricing code in an environment without a database. A custom-vinyl
// order must not depend on optional membership tables, even for a signed-in customer.
$fixture=sys_get_temp_dir().'/stonefellow-cart-'.bin2hex(random_bytes(5));
mkdir($fixture.'/config',0770,true);mkdir($fixture.'/data',0770,true);
copy(__DIR__.'/../config/store.php',$fixture.'/config/store.php');
$catalog=[['id'=>'test-song','title'=>'The In Between','duration'=>272,'podEligible'=>true,'price'=>1.49,'audio'=>'audio.mp3']];
file_put_contents($fixture.'/data/catalog.json',json_encode($catalog));
define('SF_ROOT',$fixture);
require __DIR__.'/../api/bootstrap.php';
function verifyCart(bool $ok,string $label):void{if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
$builder=['title'=>'My Stonefellow Record','A'=>['test-song'],'B'=>[],'coverFront'=>'stonefellow','coverBack'=>'stonefellow'];
$vinyl=['type'=>'custom_media','format'=>'vinyl','builder'=>$builder];
$track=['type'=>'track','track_id'=>'test-song'];
verifyCart(sf_membership_discount_amount(2345,[$vinyl])===0,'Vinyl never queries membership database');
verifyCart(sf_membership_discount_amount(2345,[['type'=>'turntable_module','module_id'=>'pack-afterdark','price_cents'=>1499]])===0,'Digital module never queries membership database');
verifyCart(sf_membership_discount_amount(2345,[$vinyl,$track])===0,'Mixed non-merch cart skips membership schema');
$quote=sf_quote([$vinyl,$track],'',2345);
verifyCart($quote['ok']===true,'Signed-in custom vinyl + track price works without membership schema');
verifyCart($quote['subtotal_cents']===5049,'Totals remain correct');
verifyCart($quote['member_discount_cents']===0,'No phantom membership discounts');
verifyCart($quote['physical']===true&&$quote['shipping_cents']===800,'Physical shipping preserved');
$guest=sf_quote([['type'=>'turntable_module','module_id'=>'pack-afterdark']],'',null);
verifyCart($guest['ok']===false,'Digital pack requires account authentication');
verifyCart(str_contains(implode(' ',$guest['errors']),'Sign in'),'Guest error is actionable instead of server_error');
$endpoint=file_get_contents(__DIR__.'/../api/quote.php');
verifyCart(str_contains($endpoint,'sf_quote_current_user_id')&&!str_contains($endpoint,'sf_current_user()'),'Quote avoids lifecycle schema writes');
verifyCart(str_contains($endpoint,'error_log(')&&str_contains($endpoint,'quote_temporarily_unavailable'),'Unexpected failures are logged with a safe reference');
$app=file_get_contents(__DIR__.'/../assets/js/app.js');
verifyCart(str_contains($app,'data-retry-quote')&&str_contains($app,'Your cart is saved'),'Cart offers retry without deleting items');
$css=file_get_contents(__DIR__.'/../assets/css/site.css');
verifyCart(str_contains($css,'.dynamic-canvas:has(> .cart-module)')&&str_contains($css,'.dynamic-canvas:has(> .checkout-module)'),'Both cart and checkout have dedicated responsive gutters');
verifyCart(str_contains($css,'max-width:1560px;')&&str_contains($css,'padding-inline:clamp(18px,3.6vw,58px)'),'Desktop commerce width and padding bounded');
unlink($fixture.'/data/catalog.json');unlink($fixture.'/config/store.php');rmdir($fixture.'/data');rmdir($fixture.'/config');rmdir($fixture);
echo "Section 71: 14/14 checks passed\n";
