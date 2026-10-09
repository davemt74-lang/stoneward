<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
sf_user_session();$b=sf_request_json();$action=(string)($b['action']??'signup');
try{
    if($action==='signup'){
        if(trim((string)($b['website']??''))!=='')sf_json_response(['ok'=>true,'subscribed'=>true]);
        $now=time();$attempts=array_values(array_filter((array)($_SESSION['sf_newsletter_attempts']??[]),fn($t)=>(int)$t>$now-600));
        if(count($attempts)>=6)sf_json_response(['ok'=>false,'message'=>'Too many newsletter signup attempts. Try again later.'],429);
        $attempts[]=$now;$_SESSION['sf_newsletter_attempts']=$attempts;
        $email=sf_email((string)($b['email']??''));$name=sf_clean_text($b['name']??'',120);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))sf_json_response(['ok'=>false,'message'=>'Enter a valid email address.'],422);
        $r=sf_crm_newsletter_signup($email,$name);sf_json_response(['ok'=>true,'message'=>$r['already_subscribed']?'You’re already on the Stonefellow list.':'You’re on the list. Welcome to Stonefellow.']+$r);
    }
    if($action==='unsubscribe'){
        $ok=sf_crm_newsletter_unsubscribe((string)($b['token']??''));if(!$ok)sf_json_response(['ok'=>false,'message'=>'That unsubscribe link is invalid or has already been used.'],422);
        sf_json_response(['ok'=>true,'message'=>'You have been unsubscribed from Stonefellow newsletter email.']);
    }
    sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
