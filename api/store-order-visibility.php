<?php
declare(strict_types=1);
/** Internal Stripe test probes must never enter customer commerce workflows. */
function sf_store_customer_order_visible(array $order):bool {
    $id=(string)($order['id']??'');
    return $id!==''&&!str_starts_with($id,'SF-CERT-')&&empty($order['certification_probe']);
}
function sf_store_customer_order_required(?array $order):array {
    if(!is_array($order)||!sf_store_customer_order_visible($order))
        throw new RuntimeException('Customer order not found.');
    return $order;
}
