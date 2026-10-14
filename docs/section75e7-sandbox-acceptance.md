# Stonefellow Section 75E7 — Evidence-backed Stripe staging acceptance

Review PR #141 only — **NOT LIVE-PAYMENT CERTIFIED** until the merchant has a real Stripe test account.

## Work completed

- Adds a dedicated sandbox certification core and Admin workflow under Orders → Payments & Stripe.
- A new certification run is bound to the exact **direct merchant account ID**, webhook ID, encrypted key fingerprints and Store mode. Changing credentials, endpoint or mode invalidates the certificate. A certificate expires after 30 days and a restart revokes it.
- The Admin can create isolated $1 Stripe-hosted Checkout Sessions in `stripe_test` mode without enabling public checkout. This executes a real provider TEST Checkout Session creation, never a live charge. Each session uses the existing Store webhook state machine, but test-probe orders are separated from Library, CRM, campaign, POD, and notifications. The refund button calls the existing idempotent Stripe refund path only for a paid $1 test probe after the successful signed event has been verified.
- The Admin supplies the local Store order ID and actual Stripe test webhook event ID for 4 independent scenarios: successful payment, declined card, confirmed expiration, and completed refund.
- Each receipt is checked against the authenticated **GET /v1/events/{id}**, **GET /v1/checkout/sessions/{id}**, plus **GET /v1/refunds/{id}** for refunds; checks the Store order, exact test mode/amount/currency/PaymentIntent and a **processed, signed-webhook ledger record created after the run started**. All evidence must refer to the run’s test orders; arbitrary existing orders are not eligible.
- Authorization alone, a successful Checkout browser return, or an Admin checkbox is *never* payment certification. Finalization runs read-only Stripe verification again on all 4 receipts, validates merchant charge capability, and only then records the certification flag.
- Signed webhook configuration includes payment_intent.payment_failed to support a real declined-card case. The Admin can see the last 7 days of ledger health (only type/status/timestamp), without exposing provider secrets or private customer data.
- Direct Stripe activation paths check an unexpired certificate bound to the same merchant, key and webhook. Cert flags alone are no longer sufficient.

## Set up later — no Stripe account is needed to merge code into a review branch

1. Register a regular Stripe merchant account through official Stripe Dashboard. No Connect platform is required for this single-artist store.
2. On a private HTTPS staging installation, set the Store mode to `stripe_test`, verify the Stripe `sk_test_` secret in Admin, and register the signed webhook.
3. Begin a test certification run. Use the Admin button to create a $1 **TEST** checkout. Complete a successful test-card payment and record the real `checkout.session.completed` event ID.
4. Create a separate test checkout and use a declined test card. Find the `payment_intent.payment_failed` Stripe event ID. Closing a tab alone is not evidence of decline.
5. Create another test checkout and arrange for Stripe to expire that Checkout Session, recording the real `checkout.session.expired` event. A browser cancel is not the same as a provider expiration.
6. For the previously verified paid order, initiate its $1 test refund from Admin and wait for a processed `refund.updated` signed event. Verify its ID.
7. Once all four receipts verify, explicitly finalize certification. Only then can Stripe test processing be enabled; live mode is never switched on by this wizard.

## Certification limitations

CI mocks Stripe provider responses, but uses the same validation code as staging. **NO REAL STRIPE ACCOUNTS, CARD PAYMENTS OR REFUNDS WERE USED IN CI.** Legacy upgrade/inventory checks run in the separate real MySQL job. End-to-end browser and live-account testing remain unverified. Do not turn on production Stripe with the review ZIP.

## Critical safety

- A certification probe can only be created from an authenticated, CSRF-protected Admin action while Store mode is `stripe_test` and the current run/merchant/webhook are valid. The per-request certification flag is not stored globally.
- Probe orders have a private run ID, fixed 100-cent USD total, no user or fulfillment. Signed Store webhooks may settle their **test** payment, but cannot grant customer entitlements or create fulfillment/campaign effects.
- Test refunds require deliberate Admin confirmation and cannot target normal customer orders.
- A new run or a credential change clears/revokes readiness. Stored event IDs are not reusable between runs; provider evidence must be fresh.
- Stripe API reads are bound to the same configured merchant identity. All callback/refund/webhook flows retain existing server-side locking and idempotency.

## Remaining Section 75 release gates

- Actual test-mode payment, declined card, refund and provider expiration with Stripe’s service; successful signed webhook deliveries (not simulated).
- Production-like staging upgrade, Customer/Admin browser acceptance and final Security/Operations review.
- When these are green, merge PR #141 and produce the production deployment ZIP and checksums.
