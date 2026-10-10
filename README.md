# Stonefellow v1.3.24 — Section 25: Orders, Fulfillment & Fan Customer Care

Stonefellow remains a **single-artist direct-to-fan platform**. Section 25 completes the post-purchase layer behind the merch, membership, campaign and ticketing stack.

## Fulfillment + Care

Admin now has a first-class **Fulfillment + Care** workspace.

It combines:

- physical-order fulfillment queue
- shipment/tracking records
- delivery/exception/return states
- refund requests and confirmations
- explicit returned-inventory restocking
- fan support cases
- support conversations
- case priority/status management

## Shipment lifecycle

Shipment records support:

- Label created
- In transit
- Out for delivery
- Delivered
- Exception
- Returned
- Canceled

Each shipment has its own request key, carrier, service, tracking number/URL, ETA, note and timestamps.

Shipment actions synchronize the customer-visible order summary and existing merchandise inventory state. Shipped/delivered orders finalize reserved inventory as sold.

## Refunds and returns

Refunds use their own ledger.

A refund starts as **requested**. It does not claim that money moved.

Admin must explicitly confirm a refund after the payment-provider/manual process is complete. Confirmed refunds record the provider reference and update the order as partially or fully refunded.

Restocking is separate from refund confirmation.

- Unshipped reserved inventory can be released safely.
- Shipped/delivered merchandise is not silently restocked.
- A physical return must be recorded before sold inventory is explicitly restocked.

This prevents a refund button from accidentally increasing stock for merchandise the customer still has.

## Fan customer care

Signed-in fans can open a case directly from their own Order Details page.

Issue types include:

- order status
- shipping
- damaged item
- wrong item
- refund
- download
- billing
- other

Fans can reply to their own cases and close them. Admin can reply, set priority and move cases through:

- Open
- Waiting on fan
- Waiting on Admin
- Resolved
- Closed

Support messages use idempotent request keys.

## CRM + lifecycle

Stonefellow records CRM events including:

- order shipped/delivered/returned
- refund requested/confirmed
- support case opened

Because CRM events already feed the lifecycle engine, order and customer-care activity can become segment/automation triggers without a second workflow system.

## Fan experience

Order Details now includes:

- shipment history
- carrier/tracking links
- ETA
- refund/return status
- support-case history
- fan replies
- Get help with this order

Only the authenticated order owner can access these records.

## Agent integration

The public Agent receives the signed-in fan’s own recent order, fulfillment, refund and support-case status.

It may explain status and route the fan to their account. It may **not**:

- ship an order
- confirm or reject a refund
- restock a return
- close a support case
- reserve inventory
- charge money

The Admin Agent routes shipment, tracking, refund, return and support-case requests to Fulfillment + Care.

## Database

Migration **2026-10-10-022** adds:

- `order_shipments`
- `order_refunds`
- `support_cases`
- `support_messages`
- `order_ops_events`

Application: **1.3.24**  
Database schema target: **1.3.21**
