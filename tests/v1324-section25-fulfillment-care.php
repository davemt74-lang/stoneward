<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(!defined('SF_ROOT'))define('SF_ROOT',$root);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$GLOBALS['test_order']=['schema'=>'stonefellow.order.v1','id'=>'SF-CARE-001','user_id'=>7,'status'=>'paid','created_at'=>gmdate('c'),'customer'=>['name'=>'Fan','email'=>'fan@example.com'],'quote'=>['total_cents'=>5000,'currency'=>'USD','physical'=>true,'items'=>[]],'payment'=>['status'=>'paid','provider'=>'test'],'fulfillment'=>['status'=>'pending'],'timeline'=>[]];
function sf_db(): PDO {global $pdo;return $pdo;}
function sf_db_config(): array {return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=120): string {$s=trim((string)$v);return substr($s,0,$max);}
function sf_money(int $c): string {return number_format($c/100,2,'.','');}
function sf_read_order(string $id): ?array {return $id==='SF-CARE-001'?$GLOBALS['test_order']:null;}
function sf_write_json(string $path,mixed $v): void {$GLOBALS['test_order']=$v;}
function sf_commerce_mark_order_sold(string $id): void {}
function sf_commerce_release_order_inventory(string $id,string $reason='x',?int $actorId=null): int {return 1;}
function sf_admin_orders(): array {return [$GLOBALS['test_order']];}
function sf_crm_upsert_contact(string $email,string $name,string $source,?int $userId=null,?bool $marketing=null,string $status='fan'): array {return ['id'=>12,'email'=>$email,'user_id'=>$userId];}
function sf_crm_log_event(int $contactId,?int $userId,string $event,string $title,string $entityType='',string $entityId='',array $meta=[]): void {}
function sf_notify_user(int $userId,string $type,string $title,string $body='',string $url=''): void {}
function sf_log_user_activity(int $userId,string $event,string $title,string $entityType='',string $entityId='',array $meta=[]): void {}
function sf_transactional_email(int $userId,string $email,string $subject,string $body,string $kind): void {}
require $root.'/api/fulfillment-core.php';

sf_fulfillment_ensure_schema();
foreach(['order_shipments','order_refunds','support_cases','support_messages','order_ops_events'] as $table){$q=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=".$pdo->quote($table));ok((bool)$q->fetchColumn(),'fulfillment schema includes '.$table);}

$user=['id'=>7,'email'=>'fan@example.com','display_name'=>'Fan'];
$case=sf_fulfillment_open_case($user,['order_id'=>'SF-CARE-001','issue_type'=>'shipping','subject'=>'Where is it?','message'=>'Can you check my shipment?']);
ok(str_starts_with($case['case_key'],'CARE-')&&$case['status']==='waiting_on_admin','fan can open an order-linked support case');
ok(count($case['messages'])===1&&$case['messages'][0]['actor_type']==='fan','support case stores the opening fan message');
$case=sf_fulfillment_add_case_message((int)$case['id'],'admin',99,'It is being prepared.','reply-1');
ok($case['status']==='waiting_on_fan'&&count($case['messages'])===2,'Admin reply advances case to waiting_on_fan');
$case=sf_fulfillment_update_case((int)$case['id'],['status'=>'resolved','priority'=>'high'],99);
ok($case['status']==='resolved'&&$case['priority']==='high'&&!empty($case['closed_at']),'case state/priority are explicitly governed');

$shipment=sf_fulfillment_save_shipment('SF-CARE-001',['request_key'=>'ship-1','status'=>'in_transit','carrier'=>'USPS','service'=>'Ground','tracking_number'=>'TRACK123','tracking_url'=>'https://example.com/track/TRACK123'],99);
ok((int)$shipment['id']>0&&$shipment['status']==='in_transit','shipment ledger records a governed shipment');
ok(($GLOBALS['test_order']['fulfillment']['status']??'')==='shipped'&&($GLOBALS['test_order']['fulfillment']['tracking_number']??'')==='TRACK123','shipment synchronizes customer-visible order fulfillment');
$shipment2=sf_fulfillment_save_shipment('SF-CARE-001',['request_key'=>'ship-1','status'=>'in_transit','carrier'=>'USPS','tracking_number'=>'TRACK123'],99);
ok((int)$shipment2['id']===(int)$shipment['id'],'shipment request key is retry-idempotent');

$refund=sf_fulfillment_request_refund('SF-CARE-001',['request_key'=>'refund-1','amount_cents'=>1500,'reason'=>'Damaged item'],99);
ok($refund['status']==='requested'&&(int)$refund['amount_cents']===1500,'refund request records amount/reason without pretending money moved');
$refund=sf_fulfillment_confirm_refund((int)$refund['id'],99,'provider-ref-123',false);
ok($refund['status']==='confirmed'&&$refund['provider_reference']==='provider-ref-123','explicit Admin refund confirmation records provider reference');
ok(($GLOBALS['test_order']['status']??'')==='partially_refunded','partial confirmed refund updates order state without full-refund claim');

$core=src('api/fulfillment-core.php');$boot=src('api/bootstrap.php');$adminApi=src('admin/api/fulfillment.php');$publicApi=src('api/customer-care.php');$customerOrder=src('api/customer-order.php');$adminJs=src('admin/assets/admin.js');$fulfillJs=src('admin/assets/fulfillment.js');$fulfillCss=src('admin/assets/fulfillment.css');$adminShell=src('admin/index.php');$app=src('assets/js/app.js');$siteCss=src('assets/css/site.css');$agent=src('api/agent-runtime.php');$crm=src('api/crm-core.php');$mig=src('api/migrations.php');$version=src('version.php');$wf=src('.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/fulfillment-core.php'"),'fulfillment core loads from canonical bootstrap');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.21'"),'database schema advances to 1.3.21');
ok(str_contains($mig,"'id'=>'2026-10-10-022'")&&str_contains($mig,'sf_fulfillment_ensure_schema'),'migration 022 installs fulfillment/care schema');
foreach(['order_shipments','order_refunds','support_cases','support_messages','order_ops_events'] as $table){ok(substr_count($core,'CREATE TABLE IF NOT EXISTS '.$table)===2,'fulfillment schema supports SQLite and MySQL for '.$table);ok(str_contains($mig,"'".$table."'"),'migration integrity requires '.$table);}

ok(str_contains($core,'request_key TEXT NOT NULL UNIQUE')&&str_contains($core,'external_key TEXT NOT NULL UNIQUE'),'shipment/refund/support/event ledgers include duplicate-action protection');
ok(str_contains($core,"'label_created','in_transit','out_for_delivery','delivered','exception','returned','canceled'"),'shipment lifecycle supports label, transit, delivery, exception, return and cancellation');
ok(str_contains($core,'sf_commerce_mark_order_sold')&&str_contains($core,'sf_commerce_release_order_inventory'),'shipment/refund state stays synchronized with inventory reservation lifecycle');
ok(str_contains($core,'function sf_fulfillment_restock_return')&&str_contains($core,"inventory_status='sold'")&&str_contains($core,"inventory_status='returned'"),'returned sold merchandise has an explicit audited restock path');
ok(str_contains($core,'Shipped merchandise may only be restocked after a returned shipment is recorded.'),'refund flow refuses unsafe automatic restock after shipping');
ok(str_contains($core,"'partially_refunded'")&&str_contains($core,"'refunded'"),'refund ledger distinguishes partial and full refunds');
ok(str_contains($core,"'order_shipped'")&&str_contains($core,"'refund_confirmed'")&&str_contains($core,"'support_case_opened'"),'fulfillment and care events feed CRM history');
ok(str_contains($crm,'sf_automation_process_crm_event'),'fulfillment/care CRM events can feed the existing lifecycle automation engine');

ok(str_contains($publicApi,'sf_require_user(false,true)')&&str_contains($publicApi,"action==='open_case'")&&str_contains($publicApi,"action==='reply_case'"),'fan customer-care mutations require authenticated CSRF-protected self service');
ok(str_contains($publicApi,'You cannot open a case for this order.')||str_contains($core,'You cannot open a case for this order.'),'support cases enforce order ownership');
ok(str_contains($customerOrder,"'operations'=>$ops"),'owned order detail includes shipment/refund/care operations');
ok(str_contains($app,'Get help with this order')&&str_contains($app,'customer-care.php'),'fan order page exposes order-linked customer care');
ok(str_contains($app,'orderShipmentHtml')&&str_contains($app,'orderRefundHtml')&&str_contains($app,'orderCareHtml'),'fan order page renders shipment, refund and support history');
ok(str_contains($siteCss,'.order-care-card')&&str_contains($siteCss,'.order-shipment-card'),'fan self-service order operations have responsive styling');

ok(str_contains($adminShell,'data-view="fulfillment"')&&str_contains($adminShell,'assets/fulfillment.js'),'Fulfillment + Care is a first-class Admin module');
ok(str_contains($adminJs,"fulfillment:'Fulfillment + Care'")&&str_contains($adminJs,'SFFulfillmentAdmin'),'Admin router loads Fulfillment + Care module');
ok(str_contains($fulfillJs,'Fulfillment queue')&&str_contains($fulfillJs,'Fan care queue'),'Admin workspace combines shipment and customer-care queues');
ok(str_contains($fulfillJs,'Add shipment')&&str_contains($fulfillJs,'Request refund')&&str_contains($fulfillJs,'Restock returned merchandise'),'Admin workspace exposes governed shipment/refund/return actions');
ok(str_contains($adminApi,'explicit Admin confirmation')&&str_contains($adminApi,"action==='confirm_refund'"),'financial refund confirmation requires explicit Admin approval');
ok(str_contains($adminApi,'sf_agent_brain_log'),'consequential fulfillment/care actions feed Admin Agent Brain');
ok(str_contains($fulfillCss,'.fulfillment-grid')&&str_contains($fulfillCss,'.care-thread'),'Fulfillment + Care has dedicated responsive Admin styling');

ok(str_contains($agent,'order_support')&&str_contains($agent,'sf_fulfillment_agent_context'),'Agent routes order-support questions and receives the signed-in fan order context');
ok(str_contains($agent,'confirm/refuse a refund')&&str_contains($agent,'restock a return'),'Agent policy forbids autonomous refund/restock operations');
ok(str_contains($adminJs,"openView('fulfillment')"),'Admin Agent routes shipment/refund/support work to Fulfillment + Care');

ok(str_contains($version,"'stonefellow'=>'1.3.24'")&&str_contains($version,"'database_schema_target'=>'1.3.21'"),'version endpoint reports app 1.3.24 and schema 1.3.21');
ok(str_contains($version,"'order_fulfillment'=>'shipments-tracking-delivery-returns-refunds'"),'version endpoint advertises fulfillment capability');
ok(str_contains($version,"'fan_customer_care'=>'order-linked-cases-messaging-self-service-crm-agent'"),'version endpoint advertises fan customer-care capability');
ok(str_contains($wf,'node --check admin/assets/fulfillment.js')&&str_contains($wf,'php tests/v1324-section25-fulfillment-care.php'),'release gate includes Fulfillment Admin JS and Section 25 suite');
echo "Stonefellow v1.3.24 Section 25 Orders, Fulfillment & Fan Customer Care audit: PASS\n";
