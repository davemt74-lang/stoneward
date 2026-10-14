# Section 75E3 — Integrated Payment Event Ledger Certification

**Status:** automated CI review only. **Not approved for live Stripe payments.**

Section 75E3 adds coverage through the actual \`sf_billing_process_stripe_event()\` dispatcher using an isolated SQLite webhook ledger and real order-state file writes. Stripe API network calls and charges are never made by this suite.

## Product changes

- A newly created Stripe Checkout Session must match its persisted order's test/live environment, including the Session ID prefix. When Stripe returns amount, currency or environment fields, each must match the original order before storing the URL.
- A paid Store event must include a valid Stripe PaymentIntent. If a completed session's status or order reference is supplied, it must be consistent with the paid order.
- Failed validation never grants library items, ships a product, or marks the order paid.

## Integration acceptance

The new executable signed-event + billing-ledger test exercises:
1. Stripe signature validation and forged payload rejection.
2. An unpaid Checkout Session remaining unpaid and unfulfilled.
3. Exact amount, environment, PaymentIntent and order-reference validation.
4. Signed paid event transitioning the order once.
5. Billing webhook event-ID replay plus separate duplicate paid events.
6. Paid order immunity to stale expiry events.
7. A deliberately failed library grant, durable paid state and retry of only unfinished checkpoints.
8. Signed expiration and at-most-once inventory release.
9. Refusal to reactivate an already canceled order.
10. Signed partial refund, duplicate refund, forged amount, wrong Stripe environment and no automatic restocking.

An additional checkout regression confirms a wrong-environment Stripe Session is not persisted, and a subsequent valid retry recovers the original reserved order.

## Certification boundaries

- CI uses isolated in-memory SQLite for billing event handling. The separate Section 75E2 CI job checks MySQL 8.0 inventory concurrency.
- Stripe card payments, disputes and real refunds have **not** been executed against a connected Stripe test account.
- Legacy MySQL installations, all install/upgrade paths and real multi-process webhook/checkout workloads still require full certification.
- The test fixture's mock side effects count calls; live CRM/library/email providers are not invoked. Therefore the results are not evidence that downstream integrations succeeded in production.
- PR #141 remains in draft, unmerged, and NOT a production release until all remaining gates are green.

## Deployment acceptance

A staging operator should configure \`stripe_test\`, matching test billing mode and \`sk_test_\` secret and \`whsec_\` webhook secret entirely within Stonefellow Admin. Run the guarded Stripe test-session probe, a real sandbox checkout and a test-mode refund with verified webhook deliveries. Then validate idempotent retries, customer Library, inventory, order history, fulfillment and Admin reconciliation. Do not enable \`production\` until certified.
