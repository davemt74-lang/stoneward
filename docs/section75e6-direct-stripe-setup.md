# Section 75E6 — Stripe Direct Merchant Setup and Verification

**Release state: CI candidate; not approved for live Stripe.**

## Design decision

Stonefellow is a single-artist ecommerce site. **Direct Stripe merchant processing is the default.** Stripe Connect is for platforms/marketplaces: linking an already-established merchant to a self-hosted app via a popup requires an authorized registered Connect platform. Stripe does **not** expose a credential-free direct merchant connection popup that a self-hosted app can invoke. Stripe hosts owner account creation and all customer Checkout pages.

The previously built Connect OAuth feature is retained in a collapsed optional section for an existing registered Stripe platform, not promoted as the standard single-artist path.

## New Admin wizard

Open Stonefellow Admin → Orders → Payments & Stripe:
1. Stripe-hosted account signup and Dashboard links. This step works without an account or credentials.
2. Test key verification: owner supplies a Stripe **test secret key only** via authenticated CSRF-protected Admin POST. The server calls GET /v1/account with that key, strictly validates account ID and test environment, and stores the credential encrypted in Stonefellow private metadata. No key is reflected into HTML/API responses or client storage. It does not enable payments.
3. Signed webhook: the server reads existing Stripe webhook endpoints and **refuses** duplicate URLs or uncertain pagination before creating an endpoint. A new webhook with the Store and subscription event allowlist is registered, its one-time signing secret encrypted, and the endpoint ID retained for idempotency. Requires an HTTPS Public Base URL and verified account ownership.
4. Readiness and certification: account charge capability, test mode, signed webhook and **actual** sandbox payment/refund acceptance are independent gates. A missing certification marker prohibits activation. Key rotation and new webhook registration clear that marker.
5. Test checkout enablement and pause action, without any live-mode activation path.

## Safety controls and scope

- No production key accepted in this wizard. The server must have STONEFELLOW_STORE_MODE=stripe_test, and a Connect account cannot be silently replaced with a direct merchant.
- A stored merchant account ID cannot be replaced with a different Stripe ID via a new key.
- The merchant setup module restricts its provider API paths to GET account, GET list webhooks and POST create webhook. It cannot issue charges or refunds.
- In the billing core, an existing direct merchant ID with no independently recorded sandbox certification cannot enable Stripe via the legacy settings save function or via the normal runtime configuration check.
- Direct connection uses the same existing checkout, subscription, refund and signed-event routing; no new payment provider or customer card storage.
- Webhook creation is not a claim of successful delivery: the next real test must verify signed Stripe events.

## Not yet verified

A real Stripe account has not been connected. Stripe test payment, declined payment, signed delivery, refund, and dispute acceptance and browser-based Admin/customer staging must be performed with an actual Stripe test account. Never mark them green from fixture results.

This section adds isolated mocked payment-configuration tests to the full release gate, while retaining the real MySQL 8 inventory and saved-draft upgrade/restore suite.

**Hold PR #141 draft and unmerged until final financial acceptance gates pass.**

## Official Stripe references

- https://docs.stripe.com/payments/checkout/quickstarts
- https://docs.stripe.com/api/webhook_endpoints
- https://stripe.com/connect
