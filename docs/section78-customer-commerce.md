# Section 78 — Customer Commerce & Fulfillment hardening

## Scope and reuse
Audit: Stonefellow already ships customer Account Purchases, detailed order timeline, invoice access, shipments, digital purchase entitlements, vinyl production updates, support cases, and Admin-created refunds/returns. It uses the Section 75 signed Stripe refund/webhook code and Section 76/76B operations + dual Agent Brains. We did **not** create duplicate services, a second Agent, or new money movement.

## Completed integration
- Account Purchases, Customer Order Details, and Customer Care now share the same authenticated owner/guest-email matching rules and reject internal Stripe certification probes.
- A single allowlisted customer-commerce-view.php builds customer responses. It preserves order totals, items, order status, shipping address, tracking, delivery timeline, production stage, refund status, and support conversations. Internal Stripe Checkout/PaymentIntent IDs, encrypted credentials, internal POD/vendor paths, request keys, approval/staff fields, provider refund references, and internal notes are not serialized to customers.
- Only credential-free HTTPS tracking links are rendered. Non-HTTPS, credential-bearing and fragment URLs are stripped from read models and rejected on new shipment writes.
- Order-bound shipment/refund idempotency guards reject cross-order request key reuse or changed replay payloads. Benign replays return the original row *without repeating notifications, order timeline transitions, or fulfillment side effects*. Collision handlers recheck the same key to protect racing requests.
- The preexisting customer support, digital entitlements, fulfillment notification and Agent commerce features remain in place; the support read API no longer returns its raw internal order/snapshot record.

## 10-point acceptance criteria
1. Strict account ownership and explicit guest checkout fallback.
2. Reject all internal certification probes on customer routes.
3. No raw order/confirmation/Stripe IDs in customer responses.
4. No vendor file paths or staff fields in POD, shipment and refund responses.
5. HTTPS tracking only, no credentials/unsafe schemes.
6. Support cases isolated by both linked order and authenticated owner.
7. Existing purchase, production, shipment and refund UI contract preserved.
8. Idempotent shipment creation, with cross-order and changed-terms protection.
9. Idempotent refund request staging; no manual Stripe confirmation bypass.
10. Full existing regressions and real MySQL job green at the exact PR head, then again on merged main.

## Boundaries / not yet certified
A source regression pass is not a live shipping-provider integration, a live Stripe transaction, a warehouse/POD confirmation, or a customer mobile-device staging test. Production Stripe activation remains blocked until the Section 77 external acceptance criteria are independently met.

## Release
Run php tests/section78-customer-commerce.php plus the complete Stonefellow Release Gate and MySQL job. Do not claim 10/10 or merge before the exact PR head is green; reverify main after merge. The existing release-gate artifact remains the deployable package and its checksum.
