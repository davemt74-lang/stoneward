# Section 75E5 — Stripe merchant connection UX

Status: Code review candidate. No Stripe account is connected and no live payment is enabled.

## What the Admin now provides

A dedicated Commerce / Payments & Stripe Admin page, with:
- official Stripe-hosted signup link for an owner who has not created a Stripe account;
- an official Connect OAuth authorization popup when an existing Stripe Connect platform is registered;
- a same-origin verified callback and anti-CSRF OAuth state (bound to Admin user and a 10-minute validity window);
- an account-status card showing verification, charges capability and signed webhooks;
- manual status refresh and explicit pause;
- a test activation button that stays unavailable until real Stripe sandbox payment/refund certification has been recorded.

Important terminology: **Connect is optional and primarily intended for platforms connecting multiple merchants.** This is a single-artist Store. There is no legitimate client-side Stripe login popup that grants its own server API access without first registering a Stripe Connect application or otherwise provisioning merchant server credentials. Stripe recommends Connect Onboarding rather than OAuth for new Connect platforms. The legacy OAuth route was implemented as an opt-in compatibility path, not as an assertion that Stripe recommends it for this standalone site.

## One-time platform prerequisites (not ChatGPT)

A Stripe account/platform administrator must configure these SERVER-ONLY environment variables:

- STONEFELLOW_STRIPE_CONNECT_CLIENT_ID = the registered Connect application's ca_ client ID
- STONEFELLOW_STRIPE_CONNECT_PLATFORM_SECRET = matching sk_test_ or sk_live_ platform key
- STONEFELLOW_STRIPE_CONNECT_MODE = test or production

Register the exact HTTPS callback URL (derived from Stonefellow's public site URL) in Stripe Connect OAuth onboarding settings:
https://YOUR-STONEFELLOW-DOMAIN/admin/stripe-connect-callback.php

The OAuth button stays inactive until this setup is complete. Credentials never appear in public HTML, Admin JSON, or the OAuth browser URL. The connected merchant's account ID is recorded in the existing encrypted metadata system. Stripe-Account routing allows the existing Checkout/subscription/refund code to use the authorized merchant context. The signed webhook endpoint is created for that merchant when authorized and its whsec_ signing secret encrypted.

OAuth token exchange is single-use. A failed webhook registration leaves payments disabled. A duplicate webhook URL blocks automatic blind re-creation to avoid losing track of signing secrets. Authorization alone never switches the Store from test to live. A live-mode connection and a test-mode connection are not interchangeable.

## Outstanding acceptance

- Real Stripe platform registration, hosted authorization and callback against the user's domain.
- Stripe sandbox card payment, decline, cancellation, partial/full refunds, signed webhook retries and account ownership confirmation.
- Provider verification that the OAuth-linked account can register the webhook and accept direct charges with Stripe-Account.
- Full production-like MySQL upgrade rehearsal and staged checkout/Admin browser tests.
- A separate operator approval gate must record actual sandbox certification before any test payment enablement.

Do not merge PR #141 or enable live payments until all independent acceptance checks pass.
