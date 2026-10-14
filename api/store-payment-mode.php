<?php
declare(strict_types=1);
/** Stable provider environment for a previously created Stripe order.
 * Never infer an existing payment's test/live identity from changed Admin settings.
 */
function sf_store_order_expected_livemode(array $order): bool {
    $payment=(array)($order['payment']??[]);
    $sessionId=(string)($payment['checkout_session_id']??'');
    if(str_starts_with($sessionId,'cs_test_'))return false;
    if(str_starts_with($sessionId,'cs_live_'))return true;
    if(array_key_exists('livemode',$payment))return (bool)$payment['livemode'];
    // Compatibility for pre-75 orders with no persisted payment environment.
    // Such orders still need an exact Checkout Session/Intent match.
    return (string)(sf_store_config()['mode']??'test')==='production';
}
