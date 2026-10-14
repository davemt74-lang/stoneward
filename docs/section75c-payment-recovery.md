# Section 75C/D — Stripe Payment Recovery and Admin Review

**Status: review candidate. Do not deploy to live payments or merge until real-Stripe certification.**

## Recovery architecture

`api/store-reconciliation.php` checks an existing Stripe Checkout Session by authenticated server-side GET. A completed/paid session is validated against the stored order ID, session ID, amount, currency and live/test mode, then processed through the same order-locked transition as signed Stripe webhooks. An expired session releases reserved inventory **only after Stripe verifies expiration**. Open and asynchronous-unpaid sessions stay pending. Unknown states require human review, never guessed cancellation or payment.

Existing authorized refunds with a known Stripe `re_...` ID are reconciled by authenticated Stripe GET and the same verified identity and balance rules as the signed refund events. Uncertain refund requests without a provider ID are flagged. Refund retries reuse the exact idempotency key, but after 23 hours (before Stripe might forget it), automatic POST retries stop and require manual provider reconciliation. Reconciliation never submits a charge, creates a new Checkout Session, or creates a new refund.

`cron-store-reconciliation.php` is PHP-CLI-only. Example: schedule a server cron entry to run `php /path/to/stonefellow/cron-store-reconciliation.php 20` every 10–15 minutes after validating the Stripe webhook in test mode. The job handles at most 50 candidates, defaults to 20, and skips very recent orders. Review nonzero exit codes and `PAYREC-*` references in the server error log. Do **not** expose the CLI script as an HTTP endpoint.

## Checkout crash recovery

Every Stripe checkout request has a stable, secret-keyed internal order ID. Retrying a stale request-key claim reloads the previously written order, checks the same customer and immutable server-quoted cart, and reuses the same Stripe idempotency key and session. Existing stock ledger rows are accepted only when every line matches the exact product, variant, quantity, unit price, subtotal and inventory source and is still **reserved**. Already released, sold, or mismatched reservations require manual review instead of charging without inventory.

Checkout Session creation holds the same per-order lock as Stripe event processing; a webhook cannot observe a half-committed session ID and payment state. The multi-process regression holds this lock during a fake Stripe network request, then replays a paid event after the concurrent worker writes its session.

## Admin

Orders shows `Payment review` for stale pending payments, pending refunds or unfinished postpayment checkpoints. Manage Order provides *Check Stripe status* and a reviewed provider-backed refund form. It preserves retry identity through ambiguous errors, requires explicit confirmation, validates amount and refundable balance, and does not call a local `refunded` transition. Stock restock and digital-license revocation remain manual policy decisions, not automatic consequences of a partial refund.

## Acceptance evidence and limits

- Section 75A checkout state-machine suite passes, including new lost-request-key replay test.
- Section 75B refund suite: 31/31, signed webhook HMAC suite: 6/6.
- Section 75C recovery: 22/22, including a two-process session/webhook lock test.
- Section 75D inventory/Admin: 19/19, including changed cart, variant, total, sold/released stock and reviewed Admin controls.
- All PHP and JS syntax files checked; no live Stripe test credentials or live production database used.

**Release blockers:** Actual Stripe test-mode Checkout and refund events, real MySQL schema/backups/migrations, end-to-end CI and live upgrade validation, payment dispute/reversal policy, reconciliation observability/alerting, and fresh install/checkout integration. No release merge permitted until all gates are satisfied.
