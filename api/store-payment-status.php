<?php
declare(strict_types=1);
/** Guest-safe order status; the capability token is never included in a Stripe URL. */
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/store-payments.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$id=(string)($_GET['id']??'');$token=(string)($_GET['token']??'');
if(!preg_match('/^SF-[A-Z0-9-]{6,60}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$token))
    sf_json_response(['ok'=>false,'error'=>'not_found'],404);
$expected=sf_store_receipt_token($id);
if(!hash_equals($expected,$token))sf_json_response(['ok'=>false,'error'=>'not_found'],404);
$order=sf_read_order($id);
if(!$order||($order['payment']['provider']??'')!=='stripe')sf_json_response(['ok'=>false,'error'=>'not_found'],404);
sf_json_response(['ok'=>true,'order_id'=>$id,'status'=>(string)($order['status']??'payment_pending'),
    'payment_status'=>(string)($order['payment']['status']??'pending'),
    'amount_cents'=>(int)($order['quote']['total_cents']??0),
    'currency'=>(string)($order['quote']['currency']??'USD')]);
