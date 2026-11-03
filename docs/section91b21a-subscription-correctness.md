# 91B21A — Subscription correctness and session-revocation hotfix

Release: v1.3.236. Base: v1.3.235 / d881aa4814d641fb894b44cef159144dcd3e3c95.

## Changes
- Billing and membership use the same date-aware access policy. Expired/missing trial deadlines, ended cancellations, expired paid periods and elapsed grace fail closed.
- Resume reverses a valid scheduled cancellation; it never turns unpaid/canceled/expired subscriptions into active accounts and never grants tokens.
- Existing Stripe-linked plans cannot be overwritten through local Admin assignment or free-package checkout. The latter opens managed billing. Complimentary token grants remain separate. Re-enrollment is initial-only.
- Both invoice.paid and invoice.payment_succeeded use one atomic invoice/period-credit handler. Credit sources deduplicate per subscription and billed interval. Existing invoice-key credits are respected.
- Only current subscription-create/cycle invoices with an unambiguous configured Stripe price and non-proration period qualify for a full allocation. Unknown prices fail with an explicit reconciliation error. Proration and historical invoices do not receive an automatic full-period grant.
- Late invoice payments cannot reactivate canceled subscriptions. Delayed creation/checkout bindings cannot replace an already recorded Stripe identity or reset its cancellation.
- Legacy and item-level subscription period fields are supported. Stripe trial start/end dates are retained.
- Invoice receipt, allocation, period update and billing audit commit together. Schema DDL is excluded from transactional token lookups.
- Every authenticated request checks its persistent server-side token even when PHP holds a cached identity. Reset/change-password and session revocation are atomic. Revocation failures are not reported as successful sign-outs.
- Package-save audit logging now receives the authenticated administrator ID.

## Deployment
Upload and extract the single deployment ZIP. No new schema migration or upgrade.php run is introduced by this section. Preserve existing configuration, database and media. A legacy browser with only a PHP session cookie and no valid persistent login token must sign in again.

## Acceptance scope
Pure policy checks; production PHP on isolated SQLite and MySQL; concurrent payment aliases; invoice/audit rollback and replay; file-backed independent PHP-session revocation; atomic password-reset fault injection; all existing release workflows and direct-ZIP integrity.
No real customer data, Stripe settings, live provider requests or actual charges are used by these fixtures. CRM delivery is stubbed; email delivery uses the existing log-only outbox. Real HostGator and Stripe-provider acceptance remain separate.

## Remaining audit phases
91B21B: broader login throttling, media cache policy, trusted URLs and privacy data lifecycle.
91B21C: full provider plan-change mapping, subscription checkout attempts, lifecycle event ordering/reconciliation, annual/proration policy, controlled live readiness. This release deliberately blocks ambiguous mappings rather than guessing or silently granting benefits. It does not provide a local override of a Stripe-managed package, nor automatically repair already lost billing links or replay historical events.
91B21D: universal AI reservations and consistent usage reporting.
91B21E/F: worker deployment, recovery, enforced aggregate release gates, performance and maintainability.

## References
https://docs.stripe.com/api/events/types
https://docs.stripe.com/webhooks
https://docs.stripe.com/changelog/basil/2025-03-31/deprecate-subscription-current-period-start-and-end
