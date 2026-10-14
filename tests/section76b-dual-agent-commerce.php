<?php
declare(strict_types=1);
/** Section 76B dual-agent commerce certification; temporary SQLite/JSON only. */
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/sf76b-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);
define('SF_ROOT',$tmp);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users(id) VALUES(7),(8)');
function sf_db():PDO{global $pdo;return $pdo;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($value,int $length=180):string{return substr(trim((string)$value),0,$length);}
function sf_write_json(string $file,array $payload):void{
    if(!is_dir(dirname($file)))mkdir(dirname($file),0700,true);
    file_put_contents($file.'.tmp',json_encode($payload,JSON_THROW_ON_ERROR));rename($file.'.tmp',$file);
}
$base=['created_at'=>gmdate('c',time()-4000),'status'=>'paid',
    'quote'=>['total_cents'=>3000,'physical'=>true],
    'payment'=>['provider'=>'stripe','status'=>'paid',
       'postpayment_done'=>array_fill_keys(['library','crm','campaign','pod','notification'],true)],
    'fulfillment'=>['status'=>'preparing','tracking_number'=>'TRACK_A']];
$GLOBALS['orders']=[
 'SF-REAL-111'=>$base+['id'=>'SF-REAL-111','user_id'=>7],
 'SF-REAL-222'=>($base+['id'=>'SF-REAL-222','user_id'=>8]),
 'SF-CERT-X'=>($base+['id'=>'SF-CERT-X','user_id'=>7,'certification_probe'=>true])
];
$GLOBALS['orders']['SF-REAL-222']['fulfillment']['tracking_number']='PRIVATE_B';
function sf_read_order(string $id):?array{return $GLOBALS['orders'][$id]??null;}
function sf_admin_orders():array{return array_values($GLOBALS['orders']);}
function sf_fulfillment_recent_orders(int $limit=120):array{return array_map(static fn($o)=>['id'=>$o['id']],array_values($GLOBALS['orders']));}
function sf_crm_admin_summary():array{return ['contacts'=>11,'newsletter'=>5];}
function sf_campaign_admin_summary():array{return ['campaigns'=>4,'active'=>2];}
require $root.'/api/agent-commerce-bridge.php';
require $root.'/api/agent-actions-core.php';
require $root.'/api/agent-orchestration.php';
require $root.'/api/agent-admin-brain.php';
$pass=0;$fail=0;
function ok76b(bool $yes,string $name):void{global $pass,$fail;if($yes)$pass++;else $fail++;echo ($yes?'PASS ':'FAIL ').$name.PHP_EOL;}
function bad76b(callable $call):bool{try{$call();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}}
$a=sf_agent_commerce_customer_snapshot(7);
$b=sf_agent_commerce_customer_snapshot(8);
ok76b($a['count']===1&&$a['orders'][0]['id']==='SF-REAL-111','Customer A sees own real order only');
ok76b($b['count']===1&&$b['orders'][0]['id']==='SF-REAL-222','Customer B sees own real order only');
ok76b(!str_contains(json_encode($a,JSON_THROW_ON_ERROR),'PRIVATE_B'),'Customer A never receives B tracking ID');
ok76b(!str_contains(json_encode($b,JSON_THROW_ON_ERROR),'TRACK_A'),'Customer B never receives A tracking ID');
ok76b(sf_agent_commerce_customer_snapshot(0)['count']===0,'Guest receives no authenticated customer history');
ok76b(!str_contains(json_encode($a),'SF-CERT-'),'Internal Stripe probes never enter customer Agent Brain');
ok76b(str_contains(sf_agent_commerce_customer_context(7),'SF-REAL-111')
   &&!str_contains(sf_agent_commerce_customer_context(7),'SF-REAL-222'),'Signed-in customer Chat context is owner-scoped');
$admin=sf_agent_commerce_admin_snapshot();
ok76b($admin['summary']['customer_orders']===2&&$admin['summary']['fulfillment_pending']===2,
    'Admin Agent Brain uses Section 76 queue aggregates');
ok76b(!str_contains(json_encode($admin),'PRIVATE_B')
   &&!str_contains(json_encode($admin),'TRACK_A'),'Admin aggregate does not leak customer tracking IDs');
ok76b(!str_contains(json_encode($admin),'SF-CERT-'),'Admin snapshot omits Stripe certification orders');
$ctx=sf_admin_agent_operational_context();
ok76b($ctx['crm_contacts']===11&&$ctx['commerce_summary']['customer_orders']===2,
    'Existing CRM Brain context enhanced with commerce rather than replaced');
ok76b(sf_admin_agent_plan('Show pending refunds')['type']==='commerce_report','Admin Chat routes pending refunds to commerce report');
ok76b(sf_admin_agent_plan('Which orders need attention?')['type']==='commerce_report','Admin Chat routes plural orders to commerce report');
ok76b(sf_admin_agent_plan('Create a follow-up for order SF-REAL-111: Contact warehouse')['type']==='prepare_commerce_followup',
    'Follow-up intent goes to governed commerce proposal, not automation');
$draft=sf_agent_commerce_parse_followup('Create follow-up for order SF-REAL-111: Contact warehouse');
ok76b($draft['order_id']==='SF-REAL-111'&&$draft['note']==='Contact warehouse',
    'Exact Chat follow-up note is deterministically parsed');
ok76b(sf_agent_commerce_parse_followup('Refund customer SF-REAL-111')===null,'Refund request cannot be mistaken for note creation');
ok76b(sf_agent_orchestration_contract('admin','commerce_report')['may_read_or_navigate'],
    'Registered read-only commerce skill discoverable to Admin');
ok76b(sf_agent_orchestration_contract('admin','prepare_commerce_followup')['may_prepare_reviewed_proposal'],
    'Follow-up skill uses existing governed proposal infrastructure');
ok76b(!sf_agent_tool_allowed('commerce_followup_add','fan',true)
    &&!sf_agent_tool_allowed('commerce_aggregate','fan',true),'Fan role cannot use Admin commerce tools');
ok76b(!sf_agent_tool_executable('commerce_followup_add','admin',false),'Tool cannot execute without approval');
ok76b(!sf_agent_tool_executable('customer_order_status','admin',true)
    &&!sf_agent_tool_executable('commerce_aggregate','admin',true),'Read-only tools cannot execute commerce writes');
ok76b(bad76b(fn()=>sf_agent_action_normalize_commerce_followup(['order_id'=>'SF-CERT-X','note'=>'Forbidden'])),
    'Fake Stripe probe cannot be selected as follow-up order');
ok76b(bad76b(fn()=>sf_agent_action_normalize_commerce_followup(['order_id'=>'SF-OTHER-999','note'=>'Invalid'])),
    'Unknown order cannot receive Agent follow-up');
ok76b(bad76b(fn()=>sf_agent_action_prepare(7,'commerce_request_12345','commerce_followup_add',
    ['order_id'=>'SF-REAL-111','note'=>''])), 'Blank note rejected before proposal');
$key='commerce_request_12345678901';
$proposal=sf_agent_action_prepare(7,$key,'commerce_followup_add',$draft);
ok76b($proposal['status']==='proposed'&&$proposal['tool_id']==='commerce_followup_add',
    'Valid follow-up is prepared but has no side effect');
ok76b(sf_commerce_ops_followups('SF-REAL-111')===[],'No file or record written before approval');
$retry=sf_agent_action_prepare(7,$key,'commerce_followup_add',$draft);
ok76b($retry['id']===$proposal['id'],'Proposal retry returns same immutable request');
$changed=$draft;$changed['note']='Changed without approval';
ok76b(bad76b(fn()=>sf_agent_action_prepare(7,$key,'commerce_followup_add',$changed)),
    'Reused request key with changed terms rejected');
ok76b(bad76b(fn()=>sf_agent_action_approve(7,$proposal['id'],str_repeat('f',64),'APPROVE')),
    'Wrong proposal hash cannot be approved');
ok76b(bad76b(fn()=>sf_agent_action_approve(8,$proposal['id'],$proposal['payload_hash'],'APPROVE')),
    'Other Admin cannot approve someone else proposal');
ok76b(bad76b(fn()=>sf_agent_action_execute(7,$proposal['id'])),
    'Unapproved proposal cannot execute');
$approved=sf_agent_action_approve(7,$proposal['id'],$proposal['payload_hash'],'APPROVE');
ok76b($approved['status']==='approved','Exact typed approval marks proposal approved');
ok76b(sf_commerce_ops_followups('SF-REAL-111')===[],'Approval alone never writes customer record');
$done=sf_agent_action_execute(7,$proposal['id']);
ok76b($done['status']==='completed'&&str_starts_with($done['result']['followup_id'],'fu_'),
    'Separately authorized execution writes one follow-up and receipt');
ok76b(count(sf_commerce_ops_followups('SF-REAL-111'))===1,'Exactly one customer follow-up saved');
$again=sf_agent_action_execute(7,$proposal['id']);
ok76b($again['result']['followup_id']===$done['result']['followup_id']
   &&count(sf_commerce_ops_followups('SF-REAL-111'))===1,'Completed approval replay never duplicates note');
ok76b($GLOBALS['orders']['SF-REAL-111']['payment']['status']==='paid'
   &&$GLOBALS['orders']['SF-REAL-111']['fulfillment']['status']==='preparing',
   'Agent follow-up cannot mutate Stripe payments or fulfillment');
$ui=file_get_contents($root.'/admin/assets/agent-canvas.js');
$mod=file_get_contents($root.'/admin/assets/agent-commerce-review.js');
$actions=file_get_contents($root.'/admin/api/agent-actions.php');
$index=file_get_contents($root.'/admin/index.php');
$customerBrain=file_get_contents($root.'/api/agent-customer-brain.php');
$runtime=file_get_contents($root.'/api/agent-runtime.php');
ok76b(str_contains($ui,'data-agent-commerce-review')&&str_contains($ui,"c.openView('commerce-ops')"),
    'Agent Chat canvas links commerce review and existing Operations page');
ok76b(str_contains($mod,"typed_confirmation:'APPROVE'")&&str_contains($mod,"action:'execute'"),
    'Canvas requires separate exact approval and save interactions');
ok76b(str_contains($actions,"'commerce_followup_add'")&&str_contains($actions,"'agent_commerce'"),
    'Unified action endpoint writes commerce Agent Brain audit events');
ok76b(str_contains($index,'agent-commerce-review.js'),
    'Admin module is loaded on the production canvas');
ok76b(str_contains($customerBrain,'sf_agent_commerce_customer_snapshot($userId)')
    &&str_contains($runtime,'sf_agent_commerce_customer_context($userId)'),
    'Customer Brain and customer Chat share the owner-bound commerce bridge');
foreach(glob($tmp.'/storage/commerce-ops/*')?:[] as $f)@unlink($f);
@rmdir($tmp.'/storage/commerce-ops');@rmdir($tmp.'/storage');@rmdir($tmp);
echo 'Section 76B dual-agent commerce: '.$pass.'/'.($pass+$fail).' passed'.PHP_EOL;
exit($fail?1:0);
