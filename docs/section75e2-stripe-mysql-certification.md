# Stonefellow Section 75E2 — Stripe and MySQL certification

## Release state

**Draft PR only. No production merge or payment certification.**

The mandatory pull-request release gates now exercise actual **MySQL 8.0** SQL behavior, including two independent PHP processes competing for the final stock unit. This is a real database engine test, not a source assertion. The new tests cover an inherited finite-stock product variant (reserving parent inventory), exact replay, repeated stock release, cross-product variant-ID rejection, and last-unit over-sale prevention.

The one-time payment contract has a separate executable 10-check fixture requiring server-quoted Stripe Checkout metadata, positive amounts, HTTPS return URLs, and strict test/live key separation.

## Run the local database tests

In CI the MySQL service is isolated, disposable, and has no live store or customer data. The test creates its own `stonefellow_test` database schema. It is destructive **within that test database** and must not be pointed at a production schema.

Run `php tests/section75e2-provider-contract.php` with PHP 8.3. The MySQL integration and concurrency test runs automatically in the `mysql-certification` GitHub job.

## Run the optional, actual Stripe test API probe

Only on an installed **staging Stonefellow** application, configure billing.provider=`stripe`, billing.mode=`test`, a genuine `sk_test_` secret, a `whsec_` webhook secret, a publicly resolvable HTTPS base URL, and environment `STONEFELLOW_STORE_MODE=stripe_test`. Then run:

```
SF_RUN_STRIPE_SANDBOX=I_UNDERSTAND_THIS_CREATES_A_TEST_SESSION php tests/section75e2-stripe-sandbox.php
```

This calls real Stripe *test* API to create, GET, and expire one harmless Checkout Session. It cannot charge a card and does not create or mutate local orders. No secret key or event payload is printed. Refuses production mode and `sk_live_` credentials.

## Still outstanding (release blockers)

- Real customer-like Stripe test-mode payment success, decline, cancellation, and webhook delivery to the user's actual HTTPS staging endpoint.
- Test-mode refund, partial refund and signed event-order verification, including interruption/replay.
- Legacy MySQL migration/upgrade using **production-like backed-up data** (not the isolated fresh test database).
- Staging fulfillment and entitlement reconciliation, including owner-reviewed refunds and disputes.
- Approvals of production Stripe configuration, appropriate staff access, and deployment rollback plan.

A passing CI run is **not** evidence that these live Stripe tests occurred. Keep PR #141 draft until all release blockers are resolved.