<?php
declare(strict_types=1);
/** Section 78: customer commerce privacy + request replay certification. */
$root=dirname(__DIR__);
require_once $root.'/api/customer-commerce-view.php';
$pass=0;$fail=0;
function ok78(bool $value,string $name):void {
    global $pass,$fail;
    $value?$pass++:$fail++;
    echo ($value?'PASS ':'FAIL ').$name.PHP_EOL;
}
function deny78(callable $fn):bool {
    try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return true;}
}
$viewer=['id'=>7,'email'=>'fan@example.net'];
$other=['id'=>8,'email'=>'other@example.net'];
$order=[
    'id'=>'SF-REAL-123','user_id'=>7,'certification_probe'=>false,
    'customer'=>['email'=>'fan@example.net','name'=>'SECRET BUYER',
        'shipping'=>['line1'=>'123 Road','city'=>'Phoenix','postal'=>'85001','country'=>'US']],
    'status'=>'paid','created_at'=>'2026-10-14T12:00:00Z',
    'quote'=>['total_cents'=>4300,'currency'=>'USD','physical'=>true,
        'items'=>[['type'=>'merch','label'=>'T-shirt','price_cents'=>4300,
            'wholesale_cost'=>120,'vendor_token'=>'SECRET_VENDOR']]],
    'payment'=>['method'=>'stripe','provider'=>'stripe','status'=>'paid',
        'payment_intent'=>'SECRET_INTENT','checkout_session_id'=>'SECRET_SESSION',
        'refund_requests'=>['SECRET_REFUND_REQUEST']],
    'fulfillment'=>['status'=>'shipped','carrier'=>'USPS','tracking_number'=>'TRK123',
        'tracking_url'=>'https://carrier.example/track/123',
        'note'=>'SECRET INTERNAL STAFF NOTE','updated_by'=>99],
    'pod'=>[['status'=>'in_production','file'=>'SECRET_VENDOR_PATH','api_key'=>'SECRET_POD_KEY']],
    'timeline'=>[['type'=>'shipment','label'=>'In transit','created_at'=>'2026-10-14T12:00:00Z',
        'private_payload'=>'SECRET_TIMELINE']],
    'confirmation_token_hash'=>'SECRET_CONFIRMATION'
];
$safe=sf_customer_commerce_order($order,$viewer);
$json=json_encode($safe,JSON_THROW_ON_ERROR);
ok78($safe['id']==='SF-REAL-123'&&$safe['payment']['status']==='paid','Existing order canvas contract preserved');
ok78(($safe['quote']['items'][0]['label']??'')==='T-shirt','Purchased item labels and prices remain visible');
ok78($safe['fulfillment']['tracking_url']==='https://carrier.example/track/123','Safe HTTPS tracking link preserved');
ok78(($safe['pod'][0]['status']??'')==='in_production','Vinyl/merch production status is visible');
foreach(['SECRET_BUYER','SECRET_VENDOR','SECRET_INTENT','SECRET_SESSION','SECRET_REFUND_REQUEST',
    'SECRET INTERNAL STAFF NOTE','SECRET_VENDOR_PATH','SECRET_POD_KEY',
    'SECRET_TIMELINE','SECRET_CONFIRMATION'] as $secret){
    ok78(!str_contains($json,$secret),'Internal field not disclosed: '.$secret);
}
ok78(deny78(fn()=>sf_customer_commerce_order($order,$other)),'Customer B cannot read customer A order');
ok78(!sf_customer_commerce_owns($order,['id'=>0,'email'=>'fan@example.net']),'Anonymous visitor cannot read orders');
$probe=$order;$probe['id']='SF-CERT-TEST001';
ok78(!sf_customer_commerce_owns($probe,$viewer),'Certification probe never appears in customer history');
$probe=$order;$probe['certification_probe']=true;
ok78(!sf_customer_commerce_owns($probe,$viewer),'Cert probe flag cannot be bypassed');
$guest=$order;$guest['user_id']=null;
ok78(sf_customer_commerce_owns($guest,$viewer),'Guest checkout can be recovered by authenticated exact email');
ok78(!sf_customer_commerce_owns($guest,$other),'Other account cannot claim guest checkout');
$claimed=$order;$claimed['user_id']=8;
ok78(!sf_customer_commerce_owns($claimed,$viewer),'Order linked to another account cannot be claimed by email');
foreach(['javascript:alert(1)','http://carrier.example/track','https://alice:password@carrier.example/track',
    'https://alice@carrier.example/track','https://carrier.example/a#script'] as $url){
    ok78(sf_customer_commerce_tracking_url($url)==='','Unsafe tracking target rejected: '.$url);
}
$shipments=[['id'=>11,'order_id'=>'SF-REAL-123','status'=>'in_transit','carrier'=>'USPS',
    'tracking_url'=>'https://carrier.example/123','note'=>'SECRET SHIP NOTE','created_by'=>99],
    ['id'=>12,'order_id'=>'SF-OTHER','status'=>'delivered','tracking_number'=>'SECRET_OTHER_SHIPMENT']];
$refunds=[['order_id'=>'SF-REAL-123','amount_cents'=>1000,'status'=>'requested',
    'provider_reference'=>'SECRET_PROVIDER_REFERENCE','created_by'=>99,'reason'=>'Return requested'],
    ['order_id'=>'SF-OTHER','amount_cents'=>100,'status'=>'confirmed','reason'=>'SECRET_OTHER_REFUND']];
$cases=[['id'=>10,'user_id'=>7,'order_id'=>'SF-REAL-123','case_key'=>'CARE-AAA',
    'subject'=>'Shipping','status'=>'open','email'=>'SECRET_CASE_EMAIL','assigned_to'=>99,
    'messages'=>[['actor_type'=>'admin','message'=>'Here is an update','actor_user_id'=>99,
        'created_at'=>'2026-10-14T12:00:00Z','request_key'=>'SECRET_MESSAGE_KEY']]],
    ['id'=>20,'user_id'=>8,'order_id'=>'SF-REAL-123','subject'=>'SECRET_OTHER_CASE'],
    ['id'=>21,'user_id'=>7,'order_id'=>'SF-OTHER','subject'=>'SECRET_WRONG_ORDER']];
$ops=sf_customer_commerce_operations(['shipments'=>$shipments,'refunds'=>$refunds,'cases'=>$cases],$viewer,'SF-REAL-123');
$opsJson=json_encode($ops,JSON_THROW_ON_ERROR);
ok78(count($ops['shipments'])===1&&count($ops['refunds'])===1&&count($ops['cases'])===1,'Only own-order operations and own support cases exposed');
ok78(($ops['cases'][0]['messages'][0]['message']??'')==='Here is an update','Customer support conversation remains readable');
foreach(['SECRET SHIP NOTE','SECRET_OTHER_SHIPMENT','SECRET_PROVIDER_REFERENCE','SECRET_OTHER_REFUND',
    'SECRET_CASE_EMAIL','SECRET_MESSAGE_KEY','SECRET_OTHER_CASE','SECRET_WRONG_ORDER'] as $secret)
    ok78(!str_contains($opsJson,$secret),'Internal or cross-order operations redacted: '.$secret);
$summary=sf_customer_commerce_summary($order,$viewer);
ok78(($summary['fulfillment']['tracking_number']??'')==='TRK123'
    &&!isset($summary['payment']),'Account purchase summary retains status without sensitive billing data');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function sf_db():PDO {global $pdo;return $pdo;}
$pdo->exec("CREATE TABLE order_shipments (id INTEGER PRIMARY KEY,order_id TEXT,request_key TEXT,status TEXT,carrier TEXT)");
$pdo->exec("CREATE TABLE order_refunds (id INTEGER PRIMARY KEY,order_id TEXT,request_key TEXT,amount_cents INTEGER,reason TEXT)");
$pdo->exec("INSERT INTO order_shipments VALUES (1,'SF-REAL-123','unique-ship-1','in_transit','USPS')");
$pdo->exec("INSERT INTO order_refunds VALUES (1,'SF-REAL-123','unique-refund-1',1000,'Damaged')");
require_once $root.'/api/fulfillment-core.php';
ok78((int)(sf_fulfillment_replay('order_shipments','SF-REAL-123','unique-ship-1',['status'=>'in_transit'])['id']??0)===1,'Identical shipment retry returns same record');
ok78(sf_fulfillment_replay('order_shipments','SF-REAL-123','unused-key',['status'=>'in_transit'])===null,'New operation key is available');
ok78(deny78(fn()=>sf_fulfillment_replay('order_shipments','SF-OTHER','unique-ship-1',[])),'Cross-order shipment replay rejected');
ok78(deny78(fn()=>sf_fulfillment_replay('order_shipments','SF-REAL-123','unique-ship-1',['status'=>'delivered'])),'Changed shipment replay rejected');
ok78((int)(sf_fulfillment_replay('order_refunds','SF-REAL-123','unique-refund-1',['amount_cents'=>1000,'reason'=>'Damaged'])['id']??0)===1,'Exact refund retry remains idempotent');
ok78(deny78(fn()=>sf_fulfillment_replay('order_refunds','SF-OTHER','unique-refund-1',[])),'Cross-order refund replay rejected');
ok78(deny78(fn()=>sf_fulfillment_replay('order_refunds','SF-REAL-123','unique-refund-1',['amount_cents'=>2000])),'Refund amount tampering rejected');
$care=(string)file_get_contents($root.'/api/customer-care.php');
$orders=(string)file_get_contents($root.'/api/customer-order.php');
$account=(string)file_get_contents($root.'/api/account.php');
ok78(str_contains($care,'sf_customer_care_visible_case')&&str_contains($care,'sf_customer_commerce_order($order,$viewer)'), 'Support API uses safe customer projection');
ok78(str_contains($orders,'sf_customer_commerce_owns')&&str_contains($orders,'sf_customer_commerce_order'), 'Order Detail checks ownership and projects fields');
ok78(str_contains($account,'sf_customer_commerce_summary')&&str_contains($account,'sf_customer_commerce_owns'),'Account purchases share owner and data boundary');
ok78(str_contains((string)file_get_contents($root.'/admin/api/fulfillment.php'),'Stripe refunds cannot be confirmed manually'),'Stripe signed-confirmation restriction retained');
echo 'Section 78 customer commerce privacy/replay: '.$pass.'/'.($pass+$fail).' passed'.PHP_EOL;
exit($fail?1:0);
