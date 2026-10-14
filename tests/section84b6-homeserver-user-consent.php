<?php
declare(strict_types=1);
/** 84B6 standalone DB fixture: explicit logged-in Stonefellow authorization,
 * one-time verifier redemption, admin/fan isolation and revocation.
 */
$tmp=sys_get_temp_dir().'/sf84b6-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);
define('SF_ROOT',$tmp);
$GLOBALS['hs_db']=new PDO('sqlite::memory:');
$GLOBALS['hs_db']->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$GLOBALS['hs_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$GLOBALS['hs_db']->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL,status TEXT NOT NULL)');
$GLOBALS['hs_db']->exec("INSERT INTO users(id,role,status) VALUES(11,'fan','active'),(22,'admin','active'),(33,'fan','active')");
function sf_db():PDO{return $GLOBALS['hs_db'];}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_order_paid_for_library(array $order):bool{return ($order['status']??'')==='paid';}
require dirname(__DIR__).'/api/homeserver-pair-core.php';
$ok=0;$failed=0;
function chk86(bool $condition,string $what):void {global $ok,$failed;$condition?$ok++:$failed++;echo ($condition?'PASS ':'FAIL ').$what.PHP_EOL;}
function stops86(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}}
$verifier=bin2hex(random_bytes(32));
$pair=sf_hs_pair_start('fan.library.read',$verifier,'Bedroom HomeServer','M'.str_repeat('a',32),100000);
$id=$pair['request_id'];
chk86(strlen($id)===48&&$pair['status']==='pending'
   &&!isset($pair['credential_once'])&&!str_contains(json_encode($pair),$verifier),
 'New request has no account credential and does not expose verifier');
chk86(sf_hs_pair_view($id,100001)['scope']==='fan.library.read',
 'Consent screen can show narrowly described requested scope');
$pending=sf_hs_pair_redeem($id,$verifier,100005);
chk86($pending['status']==='pending'&&!isset($pending['credential_once'])
   &&!sf_db()->inTransaction(),'Unapproved request cannot redeem and releases transaction');
chk86(stops86(fn()=>sf_hs_pair_redeem($id,str_repeat('f',64),100006)),
 'Other device without its verifier cannot redeem');
$approved=sf_hs_pair_decide($id,11,'fan',true,100009);
chk86($approved['status']==='approved'&&$approved['authorized_account_id']===11,
 'Explicit logged-in Stonefellow fan approves their own request');
$redeemed=sf_hs_pair_redeem($id,$verifier,100010);
$token=$redeemed['credential_once']['token']??'';
chk86($redeemed['status']==='connected'&&str_starts_with($token,'sfhs_')
 &&$redeemed['scope']==='fan.library.read',
 'Only approved HomeServer verifier receives one scoped fan credential');
chk86(stops86(fn()=>sf_hs_pair_redeem($id,$verifier,100011)),
 'A second redemption cannot mint another credential');
chk86(sf_homeserver_bearer('Bearer '.$token,'fan.library.read',100012)===11,
 'Approved fan token resolves only to the consenting account');
chk86(stops86(fn()=>sf_homeserver_bearer('Bearer '.$token,'admin.presence.read',100012)),
 'Fan token never authorizes administrator presence');
chk86(!str_contains(json_encode(sf_homeserver_link_owner(11,100012)),substr($token,5,14)),
 'Account settings list link metadata but never its reusable bearer credential');
$links=sf_homeserver_link_owner(11,100012);
chk86(count($links)===1&&$links[0]['state']==='active',
 'Only consent owner can list the authorized HomeServer connection');
chk86(sf_homeserver_link_owner(33,100012)===[],
 'Other Stonefellow account cannot see connected fan devices');
chk86(!sf_homeserver_link_revoke(33,$links[0]['id']),
 'Other fan cannot revoke a device owned by someone else');
chk86(sf_homeserver_link_revoke(11,$links[0]['id']),
 'Fan may revoke their own linked HomeServer');
chk86(stops86(fn()=>sf_homeserver_bearer('Bearer '.$token,'fan.library.read',100012)),
 'Revocation immediately invalidates HomeServer reading credentials');
$adminVerify=bin2hex(random_bytes(32));
$a=sf_hs_pair_start('admin.presence.read',$adminVerify,'Owner HomeServer','HOMESERVER_OWNER',100020);
chk86(stops86(fn()=>sf_hs_pair_decide($a['request_id'],33,'fan',true,100021)),
 'Fan cannot approve Administrator HomeServer integration');
chk86(sf_hs_pair_decide($a['request_id'],22,'admin',true,100022)['status']==='approved',
 'Stonefellow administrator may explicitly approve separate admin connection');
$admin=sf_hs_pair_redeem($a['request_id'],$adminVerify,100023);
$adminToken=$admin['credential_once']['token'];
chk86(sf_homeserver_bearer('Bearer '.$adminToken,'admin.presence.read',100024)===22,
 'Approved administrator presence uses distinct scoped credential');
chk86(stops86(fn()=>sf_homeserver_bearer('Bearer '.$adminToken,'fan.library.read',100024)),
 'Administrator presence cannot see another user’s purchased music');
sf_db()->exec("UPDATE users SET role='fan' WHERE id=22");
chk86(stops86(fn()=>sf_homeserver_bearer('Bearer '.$adminToken,'admin.presence.read',100025)),
 'Loss of admin role invalidates HomeServer admin access');
$pending2=sf_hs_pair_start('fan.library.read',bin2hex(random_bytes(32)),'Guest device',
 'M'.str_repeat('b',32),100030);
chk86(sf_hs_pair_decide($pending2['request_id'],33,'fan',false,100031)['status']==='denied',
 'Stonefellow user can explicitly refuse a pairing request');
chk86(stops86(fn()=>sf_hs_pair_decide($pending2['request_id'],33,'fan',true,100032)),
 'Denied request cannot be later approved without a new login/consent');
$expired=sf_hs_pair_start('fan.library.read',bin2hex(random_bytes(32)),'Expired device',
 'M'.str_repeat('c',32),100040);
chk86(sf_hs_pair_view($expired['request_id'],100641)['status']==='expired',
 'Consent requests expire after ten minutes');
chk86(stops86(fn()=>sf_hs_pair_decide($expired['request_id'],11,'fan',true,100641)),
 'Expired consent cannot be approved');
$anon=sf_hs_pair_start('fan.library.read',bin2hex(random_bytes(32)),'No account',
 'M'.str_repeat('d',32),100050);
chk86(stops86(fn()=>sf_hs_pair_decide($anon['request_id'],0,'fan',true,100051)),
 'Without an authenticated Stonefellow user no pairing is possible');
chk86(stops86(fn()=>sf_hs_pair_start('payments.write',bin2hex(random_bytes(32)),
 'Untrusted permission','M'.str_repeat('e',32),100060)),
 'Neither fan nor HomeServer may request financial/checkout access');
$owned=sf_homeserver_owned_tracks(11,[
 ['item_type'=>'track','item_key'=>'alpha','source_order_id'=>'SF-ONE'],
 ['item_type'=>'track','item_key'=>'beta','source_order_id'=>'SF-OTHER'],
],[
 'SF-ONE'=>['user_id'=>11,'status'=>'paid',
   'quote'=>['items'=>[['type'=>'track','track_id'=>'alpha']]]],
 'SF-OTHER'=>['user_id'=>33,'status'=>'paid',
   'quote'=>['items'=>[['type'=>'track','track_id'=>'beta']]]]
],[
 'alpha'=>['audio'=>'private.wav','title'=>'First','duration'=>182],
 'beta'=>['audio'=>'private.wav','title'=>'Private','duration'=>195]
]);
chk86(count($owned)===1&&$owned[0]['id']==='alpha'
 &&!isset($owned[0]['audio']),'Fan library excludes other-account songs and private audio paths');
$endpoint=file_get_contents(dirname(__DIR__).'/api/homeserver-pair.php');
$view=file_get_contents(dirname(__DIR__).'/homeserver-connect.php');
$link=file_get_contents(dirname(__DIR__).'/api/homeserver-link.php');
chk86(str_contains($endpoint,'sf_require_user(false,true)')
 &&str_contains($endpoint,'sf_hs_pair_decide')
 &&!str_contains($link,"\$action==='issue'"),
 'Only CSRF-verified logged-in Stonefellow consent can issue a connection');
chk86(str_contains($view,'sf_current_user()')&&str_contains($view,'Authorize HomeServer')
 &&!str_contains($view,'sf_hs_pair_redeem('),
 'User-facing approval happens at Stonefellow login, not via device token');
echo 'Section 84B6 Stonefellow logged-in pairing: '.$ok.'/'.($ok+$failed).' passed'.PHP_EOL;
exit($failed?1:0);
