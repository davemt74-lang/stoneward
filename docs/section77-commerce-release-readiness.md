# Section 77 — Commerce Release Readiness

## Scope
Extends the merged Section 75/76/76B ecommerce stack without adding a second agent or payment provider. The Admin Payments & Stripe canvas now includes a read-only release gate panel. The Admin API returns an authenticated snapshot using existing direct merchant, Stripe sandbox, signed webhook, and Commerce Operations services.

The panel checks twelve conditions: direct merchant identity, stripe_test environment, charges capability, HTTPS webhook URL, stored signing configuration, signed sandbox verification, bounded webhook queue sample, availability of Commerce Operations, and four zero-backlog queue indicators (payment, refunds, fulfillment, follow-ups). It exposes separate booleans for test activation readiness, test checkout actually active, and operations clearance. Each missing dependency fails closed.

A green sample does **not** authorize live charging, prove zero failures across the full ledger, or override existing provider activation gates. The live-activation action remains intentionally unavailable. This is a release diagnostic, not an operational payment mutation.

## Connect webhook hardening
Optional Connect OAuth webhook discovery now rejects malformed or paginated provider endpoint responses before creating a webhook. A lost response or unknown preexisting endpoint must be investigated manually. No duplicate creation or recoverable signing secret is assumed.

## Acceptance
The deterministic Section 77 PHP suite is wired into the existing release-gate workflow, alongside Stripe refund/sandbox, MySQL inventory, and Admin Agent tests. It checks credential-bearing webhook URLs, environment switches, malformed account IDs, unresolved webhooks, missing Orders service, outstanding commerce queues, Admin auth integration, and the read-only UI.

## External provider certification still required
- Link a genuine Stripe TEST merchant in the existing Admin wizard; create a signed webhook.
- Use Stripe hosted checkout and verify paid, declined, expired, and refunded $1.00 test orders against signed webhooks.
- Test real host PHP/MySQL upgrade and real browser/mobile Admin/customer checkout and fulfillment paths.
- Independently review reconciliation, payouts/disputes, and any outstanding cases before considering live activation in a separate implementation.
- No Stripe credentials, merchant account, payments, or customer data are linked to ChatGPT.

A green GitHub Actions suite certifies source regression checks only. Live Stripe remains gated.
