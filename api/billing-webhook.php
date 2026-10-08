<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_billing_ensure_schema();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$payload=(string)file_get_contents('php://input');$signature=(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??'');$secret=sf_stripe_webhook_secret();
if(!sf_stripe_verify_signature($payload,$signature,$secret)) sf_json_response(['ok'=>false,'error'=>'invalid_signature'],400);
$event=json_decode($payload,true);if(!is_array($event))sf_json_response(['ok'=>false,'error'=>'invalid_json'],400);
try{sf_billing_process_stripe_event($event,hash('sha256',$payload));sf_json_response(['ok'=>true]);}
catch(Throwable $e){if(function_exists('sf_log_site_event'))sf_log_site_event('error','billing_webhook',$e->getMessage());sf_json_response(['ok'=>false,'error'=>'webhook_processing_failed','message'=>$e->getMessage()],500);}
