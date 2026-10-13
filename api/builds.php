<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$u=sf_require_user(false,$write); $uid=(int)$u['id'];
if(!$write) sf_json_response(['ok'=>true,'builds'=>sf_account_saved_builds($uid),'csrf'=>sf_user_csrf()]);
$body=sf_request_json(); $action=(string)($body['action']??'save');
/** Keep protected diagnostics without telling customers to repeat an already-completed upgrade. */
function sf_builds_fail(Throwable $e, string $operation): never {
    $reference='DRAFT-'.strtoupper(bin2hex(random_bytes(4)));
    $sqlstate=$e instanceof PDOException?(string)$e->getCode():'none';
    error_log('Stonefellow '.$reference.' '.$operation.' '.get_class($e).' SQLSTATE='.$sqlstate.' detail='.$e->getMessage());
    $message='Draft could not be stored (reference '.$reference.'). Please try again; if it continues, send this reference to the administrator.';
    $status=500;
    if($e instanceof InvalidArgumentException){$message=$e->getMessage();$status=422;}
    elseif($e instanceof RuntimeException&&str_contains($e->getMessage(),'not found')){$message='This draft is no longer available in your account. Reload your saved drafts.';$status=404;}
    sf_json_response(['ok'=>false,'message'=>$message,'error_ref'=>$reference],$status);
}
if($action==='save'){
    try{
        $row=sf_account_save_build($uid,is_array($body['build']??null)?$body['build']:[],(int)($body['id']??0));
        sf_json_response(['ok'=>true,'build'=>$row,'builds'=>sf_account_saved_builds($uid),'csrf'=>sf_user_csrf()]);
    }catch(Throwable $e){sf_builds_fail($e,'save');}
}
if($action==='suggest'){
    try{$result=sf_account_build_fill_suggestions($uid,is_array($body['build']??null)?$body['build']:[],(string)($body['side']??'both'));sf_json_response(['ok'=>true,...$result,'csrf'=>sf_user_csrf()]);}
    catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
}
if($action==='rename'){
    try{
        $id=(int)($body['id']??0);
        $row=sf_account_rename_build($uid,$id,(string)($body['name']??''));
        sf_json_response(['ok'=>true,'build'=>$row,'builds'=>sf_account_saved_builds($uid),'csrf'=>sf_user_csrf()]);
    }catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
     catch(RuntimeException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],404);}
}
if($action==='delete'){
    $id=(int)($body['id']??0); if($id<1) sf_json_response(['ok'=>false,'message'=>'Invalid saved build.'],422);
    sf_account_delete_build($uid,$id); sf_json_response(['ok'=>true,'builds'=>sf_account_saved_builds($uid),'csrf'=>sf_user_csrf()]);
}
sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
