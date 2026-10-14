<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=stonefellow_test;charset=utf8mb4',
getenv('SF_E2_DB_USER')?:'stonefellow',getenv('SF_E2_DB_PASS')?:'stonefellow',
[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function sf_db():PDO{global $pdo;return $pdo;}
function sf_db_config():array{return ['driver'=>'mysql'];}
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
function sf_media_public_links(string $type,string $id):array{return [];}
require $root.'/api/commerce-core.php';
$orderId=(string)($argv[1]??'');
$cart=json_decode(base64_decode((string)($argv[2]??''),true)?:'',true,512,JSON_THROW_ON_ERROR);
try{sf_commerce_reserve_order($orderId,$cart,null,'buyer@example.test');echo "RESERVED\n";exit(0);}
catch(Throwable $e){echo "REJECTED\n";exit(2);}
