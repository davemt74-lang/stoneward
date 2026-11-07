<?php
declare(strict_types=1);
/** Execute the production configuration reader in isolated fresh processes. */
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/sf-store-config-'.bin2hex(random_bytes(6));
mkdir($tmp,0700,true);
$runner= <<<'PHP'
$source=(string)file_get_contents($argv[2]);
$start=strpos($source,'function sf_store_config(): array {');
$end=strpos($source,'function sf_catalog(',$start);
if($start===false||$end===false)throw new RuntimeException('Production configuration reader missing.');
define('SF_ROOT',$argv[1]);
eval(substr($source,$start,$end-$start));
$first=sf_store_config();
file_put_contents(SF_ROOT.'/config/store.php',"<?php return ['fixture'=>'changed-during-request'];");
echo json_encode(['first'=>$first,'cached'=>sf_store_config()],JSON_THROW_ON_ERROR);
PHP;
function verifyStoreReader(string $folder,string $runner,string $source):array {
    $process=proc_open([PHP_BINARY,'-r',$runner,$folder,$source],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start configuration reader.');
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
    if($status!==0)throw new RuntimeException('Configuration reader failed: '.$error);
    return json_decode($output,true,512,JSON_THROW_ON_ERROR);
}
try{
    foreach(['fresh','configured'] as $mode){
        $folder=$tmp.'/'.$mode;mkdir($folder.'/config',0700,true);
        copy($root.'/config/store.default.php',$folder.'/config/store.default.php');
        if($mode==='configured')file_put_contents($folder.'/config/store.php',"<?php return ['mode'=>'production','fixture'=>'existing-host'];");
        $result=verifyStoreReader($folder,$runner,$root.'/api/bootstrap.php');
        if($result['first']!==$result['cached'])throw new RuntimeException('Configuration changed inside one request.');
        if($mode==='fresh'&&($result['first']['products']['custom_vinyl']['price_cents']??null)!==4900)
            throw new RuntimeException('Fresh install did not load shipped store defaults.');
        if($mode==='configured'&&$result['first']!==['mode'=>'production','fixture'=>'existing-host'])
            throw new RuntimeException('Shipped defaults replaced an existing host configuration.');
    }
    echo "Store defaults, existing host precedence, and per-request cache passed\n";
}finally{
    foreach(['fresh','configured'] as $mode){
        foreach(['store.php','store.default.php'] as $file)if(is_file($tmp.'/'.$mode.'/config/'.$file))unlink($tmp.'/'.$mode.'/config/'.$file);
        if(is_dir($tmp.'/'.$mode.'/config'))rmdir($tmp.'/'.$mode.'/config');
        if(is_dir($tmp.'/'.$mode))rmdir($tmp.'/'.$mode);
    }
    rmdir($tmp);
}
