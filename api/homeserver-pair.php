<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/homeserver-pair-core.php';
header('Cache-Control: private, no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$raw=file_get_contents('php://input');
if(!is_string($raw)||strlen($raw)>4096)sf_json_response(['ok'=>false,'error'=>'bad_request'],400);
$data=json_decode($raw,true);
if(!is_array($data))sf_json_response(['ok'=>false,'error'=>'invalid_json'],400);
$action=(string)($data['operation']??'');
try{
    if($action==='start'){
        $r=sf_hs_pair_start((string)($data['scope']??''),(string)($data['verifier']??''),
            (string)($data['label']??''),(string)($data['member_binding']??''),0,(string)($_SERVER['REMOTE_ADDR']??''));
        sf_json_response(['ok'=>true]+$r);
    }
    if($action==='redeem'){
        $r=sf_hs_pair_redeem((string)($data['request_id']??''),
            (string)($data['verifier']??''));
        sf_json_response(['ok'=>true]+$r);
    }
    if($action==='approve'||$action==='deny'){
        // Authentication and CSRF are both mandatory; no GET approval,
        // cross-site form consent, or HomeServer-issued authorization.
        $u=sf_require_user(false,true);
        $r=sf_hs_pair_decide((string)($data['request_id']??''),(int)$u['id'],
            (string)($u['role']??''),$action==='approve');
        sf_json_response(['ok'=>true]+$r);
    }
    sf_json_response(['ok'=>false,'error'=>'unsupported_operation'],422);
}catch(Throwable $e){
    error_log('HomeServer pairing denied: '.get_class($e));
    sf_json_response(['ok'=>false,'error'=>'pairing_rejected_or_expired'],403);
}
