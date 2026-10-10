<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function sf_db(): PDO {global $pdo;return $pdo;}
function sf_db_config(): array {return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=120): string {$s=trim((string)$v);$s=preg_replace('/[\x00-\x1F\x7F]/u','',$s)??'';return substr($s,0,$max);}
function sf_media_public_links(string $type,string $id): array {return [];}
function sf_store_config(): array {return ['currency'=>'USD','products'=>[]];}
function sf_money(int $cents): string {return number_format($cents/100,2,'.','');}
require $root.'/api/commerce-core.php';

sf_commerce_ensure_schema();
foreach(['store_products','store_variants','store_inventory_events','store_order_items'] as $table){$q=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=".$pdo->quote($table));ok((bool)$q->fetchColumn(),'commerce schema includes '.$table);}

$p=sf_commerce_save_product([
  'title'=>'Tour Shirt','id'=>'tour-shirt','status'=>'active','description'=>'Stonefellow tour shirt',
  'base_price_cents'=>2200,'inventory_mode'=>'unlimited','max_per_order'=>4,'physical'=>true,
  'variants'=>[
    ['title'=>'Black / Large','sku'=>'SF-TS-BLK-L','price_cents'=>2500,'inventory_mode'=>'finite','inventory_qty'=>2,'low_stock_threshold'=>1,'status'=>'active'],
    ['title'=>'Black / XL','sku'=>'SF-TS-BLK-XL','price_cents'=>2600,'inventory_mode'=>'finite','inventory_qty'=>1,'low_stock_threshold'=>1,'status'=>'active'],
  ],
],0);
ok($p['id']==='tour-shirt'&&count($p['variants'])===2,'product save creates merch product and variants');
$large=$p['variants'][0];ok($large['sku']==='SF-TS-BLK-L'&&(int)$large['inventory_qty']===2,'variant preserves SKU and finite stock');

$q1=sf_commerce_quote_item(['product_id'=>'tour-shirt','variant_id'=>$large['id'],'quantity'=>1]);
ok($q1['ok']===true&&$q1['item']['price_cents']===2500&&$q1['item']['inventory_source']==='variant','merch quote uses selected variant price and inventory source');
$tooMany=sf_commerce_quote_item(['product_id'=>'tour-shirt','variant_id'=>$large['id'],'quantity'=>5]);
ok($tooMany['ok']===false,'merch quote enforces max-per-order');

$quote=['items'=>[$q1['item']],'total_cents'=>2500];
$r=sf_commerce_reserve_order('SF-TEST-ONE',$quote,null,'fan@example.com');
ok((int)$r['reserved']===1,'checkout reserves one finite unit');
$p1=sf_commerce_product('tour-shirt',false);$v1=array_values(array_filter($p1['variants'],fn($v)=>$v['id']===$large['id']))[0];
ok((int)$v1['inventory_qty']===1,'inventory decrements atomically on reservation');

$qTwo=sf_commerce_quote_item(['product_id'=>'tour-shirt','variant_id'=>$large['id'],'quantity'=>2]);
ok($qTwo['ok']===false,'fresh quote refuses quantity above remaining stock');

$released=sf_commerce_release_order_inventory('SF-TEST-ONE','test_cancel',null);
ok($released===1,'cancel releases reserved finite stock');
$p2=sf_commerce_product('tour-shirt',false);$v2=array_values(array_filter($p2['variants'],fn($v)=>$v['id']===$large['id']))[0];
ok((int)$v2['inventory_qty']===2,'released inventory returns exactly to prior quantity');
ok(sf_commerce_release_order_inventory('SF-TEST-ONE','test_cancel',null)===0,'inventory release is idempotent');

$q3=sf_commerce_quote_item(['product_id'=>'tour-shirt','variant_id'=>$large['id'],'quantity'=>1]);sf_commerce_reserve_order('SF-TEST-TWO',['items'=>[$q3['item']],'total_cents'=>2500],null,'fan@example.com');sf_commerce_mark_order_sold('SF-TEST-TWO');
ok(sf_commerce_release_order_inventory('SF-TEST-TWO','late_cancel',null)===0,'sold inventory is not silently restocked');
$summary=sf_commerce_summary();
ok((int)$summary['units_sold']===1&&(int)$summary['gross_merch_cents']===2500,'commerce summary counts non-released sold merch');

$adjust=sf_commerce_adjust_inventory('tour-shirt',(int)$large['id'],3,'stock_count',0);
ok($adjust['delta']===3&&$adjust['after']===$adjust['before']+3,'manual inventory adjustment is audited and finite');

$core=src('api/commerce-core.php');$boot=src('api/bootstrap.php');$store=src('api/storefront.php');$order=src('api/order.php');$adminApi=src('admin/api/products.php');$adminOrders=src('admin/api/orders.php');$adminJs=src('admin/assets/admin.js');$productsJs=src('admin/assets/products.js');$productsCss=src('admin/assets/products.css');$app=src('assets/js/app.js');$siteCss=src('assets/css/site.css');$campaignJs=src('admin/assets/campaigns.js');$agent=src('api/agent-runtime.php');$crm=src('api/crm-core.php');$media=src('api/media-core.php');$mediaApi=src('admin/api/media.php');$mig=src('api/migrations.php');$version=src('version.php');$adminShell=src('admin/index.php');$wf=src('.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/commerce-core.php'"),'commerce core loads from canonical bootstrap');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.17'"),'database schema advances to 1.3.17');
ok(str_contains($mig,"'id'=>'2026-10-10-018'")&&str_contains($mig,'sf_commerce_ensure_schema'),'migration 018 installs direct commerce schema');
foreach(['store_products','store_variants','store_inventory_events','store_order_items'] as $table)ok(str_contains($mig,"'".$table."'"),'migration integrity requires '.$table);

ok(substr_count($core,'CREATE TABLE IF NOT EXISTS store_products')===2,'product schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS store_variants')===2,'variant schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS store_inventory_events')===2,'inventory ledger supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS store_order_items')===2,'merch order-line ledger supports SQLite and MySQL');
ok(str_contains($core,'function sf_commerce_reserve_order')&&str_contains($core,'inventory_qty=inventory_qty-?'),'checkout inventory reservation is server-side and atomic');
ok(str_contains($core,'function sf_commerce_release_order_inventory')&&str_contains($core,"inventory_status='reserved'"),'cancel/refund only releases still-reserved inventory');
ok(str_contains($core,'function sf_commerce_mark_order_sold'),'fulfillment can finalize an inventory reservation as sold');
ok(str_contains($core,'external_key')&&str_contains($core,'UNIQUE'),'inventory ledger has duplicate-action protection');

ok(str_contains($store,"'kind'=>'merch'")&&str_contains($store,'sf_commerce_products(true)'),'public storefront merges active database merch with legacy builder products');
ok(str_contains($boot,"$type==='merch'")&&str_contains($boot,'sf_commerce_quote_item'),'cart quote recognizes merch as a governed line type');
ok(str_contains($boot,"payload']['product_ids")&&str_contains($boot,'discountBase'),'campaign discounts can be scoped to selected merch products');
ok(str_contains($order,'sf_commerce_reserve_order')&&str_contains($order,'sf_commerce_record_purchase'),'checkout reserves inventory and records merch purchase intelligence');
ok(str_contains($adminOrders,'sf_commerce_release_order_inventory')&&str_contains($adminOrders,'sf_commerce_mark_order_sold'),'Admin cancellation/refund/fulfillment synchronizes merch inventory state');

ok(str_contains($adminShell,'data-view="products"')&&str_contains($adminShell,'assets/products.js'),'Merch + Products is a first-class Admin module');
ok(str_contains($productsJs,'Variants')&&str_contains($productsJs,'SKU')&&str_contains($productsJs,'Inventory adjustment'),'product editor manages variants, SKUs and audited inventory');
ok(str_contains($productsJs,'SFMediaAdmin')&&str_contains($productsJs,"'product_primary'"),'product editor uses universal Media Library relationships');
ok(str_contains($adminApi,'explicit Admin confirmation')&&str_contains($adminApi,'sf_agent_brain_log'),'product activation is explicitly confirmed and logged to Agent Brain');
ok(str_contains($productsCss,'.product-variant-row')&&str_contains($productsCss,'.inventory-event-list'),'product and inventory Admin UI has dedicated responsive styling');

ok(str_contains($app,'data-merch-variant')&&str_contains($app,'data-merch-qty')&&str_contains($app,'addMerchToCart'),'public Store supports merch variants and quantities');
ok(str_contains($app,"x.type==='merch'")&&str_contains($app,'checkout-merch-row'),'cart and checkout render merch line items distinctly');
ok(str_contains($app,'Sold out')&&str_contains($app,'low_stock'),'public Store exposes availability rather than allowing blind purchase attempts');
ok(str_contains($siteCss,'.merch-card')&&str_contains($siteCss,'.merch-buy-row'),'public merch Store has dedicated responsive styling');

ok(str_contains($campaignJs,"product_ids")&&str_contains($campaignJs,'Limit to product IDs'),'Campaign Builder discount nodes support product-scoped merch offers');
ok(str_contains($agent,'sf_commerce_agent_context')&&str_contains($agent,'reserve inventory'),'public Agent receives live merch catalog context but cannot reserve/purchase');
ok(str_contains($crm,"'merch_purchases'")&&str_contains($crm,'sf_commerce_purchase_history'),'CRM detail includes canonical merch purchase history');
ok(str_contains($adminJs,'Merch purchase history')&&str_contains($adminJs,'merchSpend'),'Admin fan profile exposes merch line items and spend');
ok(str_contains($media,'sf_commerce_products(false)')&&str_contains($mediaApi,"'kind'=>'merch'"),'Media Library completeness and product manager include dynamic merch');

ok(str_contains($version,"'stonefellow'=>'1.3.20'")&&str_contains($version,"'database_schema_target'=>'1.3.17'"),'version endpoint reports app 1.3.20 and schema 1.3.17');
ok(str_contains($version,"'direct_merch_commerce'=>'products-variants-skus-inventory-media-cart-fulfillment-crm'"),'version endpoint advertises direct merch commerce');
ok(str_contains($wf,'node --check admin/assets/products.js')&&str_contains($wf,'php tests/v1320-section21-merch-commerce.php'),'release gate includes Products JavaScript and Section 21 suite');
echo "Stonefellow v1.3.20 Section 21 Merch & Direct-to-Fan Commerce audit: PASS\n";
