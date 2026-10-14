<?php
declare(strict_types=1);
/** Actual MySQL 8 transactional grant smoke: catches implicit-commit DDL
 * and FOR UPDATE incompatibilities that SQLite-only consent tests miss.
 * Run only against the isolated CI stonefellow_test database.
 */
if(!extension_loaded('pdo_mysql'))throw new RuntimeException('MySQL extension is required.');
$user=(string)getenv('SF_E2_DB_USER');$pass=(string)getenv('SF_E2_DB_PASS');
if($user==='')throw new RuntimeException('Explicit CI MySQL user is required.');
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=stonefellow_test;charset=utf8mb4',$user,$pass,
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false]);
function sf_db():PDO{return $GLOBALS['mysql_pair_db'];}
function sf_db_config():array{return ['driver'=>'mysql'];}
$GLOBALS['mysql_pair_db']=$db;
require dirname(__DIR__).'/api/homeserver-pair-core.php';
$uid=random_int(450000,900000);
$verifier=bin2hex(random_bytes(32));
$now=time();
$pair=sf_hs_pair_start('fan.library.read',$verifier,'CI MySQL Device',
 'M'.str_repeat('d',32),$now,'192.0.2.200');
$id=$pair['request_id'];
try{
    $approval=sf_hs_pair_decide($id,$uid,'fan',true,$now+1);
    if($approval['status']!=='approved')throw new RuntimeException('MySQL approval was not atomic.');
    $result=sf_hs_pair_redeem($id,$verifier,$now+2);
    if($result['status']!=='connected'||!str_starts_with((string)$result['credential_once']['token'],'sfhs_'))
        throw new RuntimeException('MySQL token issuance failed.');
    if($db->inTransaction())throw new RuntimeException('Pairing left MySQL transaction open.');
    $q=$db->prepare('SELECT COUNT(*) FROM homeserver_links WHERE user_id=?');
    $q->execute([$uid]);if((int)$q->fetchColumn()!==1)
        throw new RuntimeException('MySQL did not persist the approved credential.');
    try{sf_hs_pair_redeem($id,$verifier,$now+3);
        throw new RuntimeException('MySQL one-time redemption replay succeeded.');
    }catch(RuntimeException $e){
        if(str_contains($e->getMessage(),'succeeded'))throw $e;
    }
    echo "PASS Section 84B6 real MySQL device consent, atomic grant and duplicate denial".PHP_EOL;
}finally{
    $db->prepare('DELETE FROM homeserver_pair_requests WHERE request_id=?')->execute([$id]);
    $db->prepare('DELETE FROM homeserver_links WHERE user_id=?')->execute([$uid]);
}
