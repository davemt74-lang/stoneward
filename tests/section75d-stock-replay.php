<?php
declare(strict_types=1);
/** Reservation identity rules run without a database driver or real inventory. */
$root=dirname(__DIR__);require $root.'/api/commerce-core.php';
$count=0;$failed=0;
function chk(bool $ok,string $label):void{global $count,$failed;$count++;if(!$ok)$failed++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;}
$items=[2=>['product_id'=>'poster','variant_id'=>null,'quantity'=>2,'unit_price_cents'=>1200,'price_cents'=>2400,'inventory_source'=>'product'],
 5=>['product_id'=>'tshirt','variant_id'=>3,'quantity'=>1,'unit_price_cents'=>2300,'price_cents'=>2300,'inventory_source'=>'variant']];
$records=[['line_index'=>2,'product_id'=>'poster','variant_id'=>null,'quantity'=>2,'unit_price_cents'=>1200,'subtotal_cents'=>2400,'inventory_source'=>'product','inventory_status'=>'reserved'],
 ['line_index'=>5,'product_id'=>'tshirt','variant_id'=>3,'quantity'=>1,'unit_price_cents'=>2300,'subtotal_cents'=>2300,'inventory_source'=>'variant','inventory_status'=>'reserved']];
chk(sf_commerce_reservation_matches_quote($records,$items),'Exact inventory lines safely reusable after crash');
chk(!sf_commerce_reservation_matches_quote([$records[0]],$items),'Missing inventory line blocked');
$alter=$records;$alter[1]['product_id']='different';chk(!sf_commerce_reservation_matches_quote($alter,$items),'Different product cannot reuse a stock hold');
$alter=$records;$alter[1]['variant_id']=4;chk(!sf_commerce_reservation_matches_quote($alter,$items),'Variant substitution cannot reuse a stock hold');
$alter=$records;$alter[1]['quantity']=2;chk(!sf_commerce_reservation_matches_quote($alter,$items),'Quantity increase cannot reuse a stock hold');
$alter=$records;$alter[1]['unit_price_cents']=500;chk(!sf_commerce_reservation_matches_quote($alter,$items),'Changed price cannot reuse reserved inventory');
$alter=$records;$alter[1]['subtotal_cents']=400;chk(!sf_commerce_reservation_matches_quote($alter,$items),'Changed subtotal cannot reuse reserved inventory');
$alter=$records;$alter[1]['inventory_source']='unlimited';chk(!sf_commerce_reservation_matches_quote($alter,$items),'Inventory source switch rejected');
$alter=$records;$alter[1]['inventory_status']='released';chk(!sf_commerce_reservation_matches_quote($alter,$items),'Released stock cannot be silently reused');
$alter=$records;$alter[1]['inventory_status']='sold';chk(!sf_commerce_reservation_matches_quote($alter,$items),'Already-sold stock cannot be silently reused');
$alter=$records;$alter[1]['line_index']=3;chk(!sf_commerce_reservation_matches_quote($alter,$items),'Changed cart line mapping cannot be reused');
$src=(string)file_get_contents($root.'/api/store-payments.php');
chk(str_contains($src,'sf_store_stripe_order_id_for_request(')&&str_contains($src,'hash_hmac('),'Checkout request derives a stable, secret-backed order ID');
chk(str_contains($src,'sf_store_stripe_validate_replay($existing,$uid,$customer,$quote)'),'Stale request-key recovery still validates quote and customer');
chk(str_contains($src,"sf_order_request_complete(\$requestKey,\$id)"),'Crash recovery reconnects the saved order with its idempotency key');
chk(str_contains((string)file_get_contents($root.'/api/commerce-core.php'),'sf_commerce_reservation_matches_quote($existing,$items)'),'Database inventory path uses exact reservation verifier');
$admin=(string)file_get_contents($root.'/admin/api/orders.php');
chk(str_contains($admin,'function sf_admin_order_mutation_lock(')&&substr_count($admin,'$orderMutationLock=sf_admin_order_mutation_lock($id);')===3,
    'All Admin shipping, cancellation, and POD mutations share the payment webhook order lock');
chk(str_contains($admin,"if(\$provider==='stripe')sf_json_response"),
    'Stripe order state cannot be locally marked refunded or canceled through legacy endpoint');
$js=(string)file_get_contents($root.'/admin/assets/admin.js');
chk(str_contains($admin,"'payment_review'=>")&&str_contains($js,'⚠ Payment review'),'Admin order list highlights pending payments, refunds and unfinished checkpoints');
chk(str_contains($js,'Stripe cancellation and refunds require provider verification.'),'Stripe Admin UI cannot mislead staff with locally finalized refund controls');
echo 'Section 75D inventory crash recovery: '.($count-$failed).'/'.$count.' passed'.PHP_EOL;
exit($failed?1:0);
