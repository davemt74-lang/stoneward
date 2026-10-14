# Section 83 — End-to-End Production Pilot Acceptance

## What was audited and reused
The existing Stonefellow single-artist script already contains merchant TEST onboarding, signed Stripe events and refunds, authenticated customer orders, POD/vinyl handoffs, Agent Brain commerce triage and governed follow-up actions, customer notifications, opt-in CRM and marketing, and admin upgrade/backup tools. Sections 72–82 contain targeted regressions. Section 83 does not duplicate any of these services.

## New production pilot panel
Payments & Stripe now includes a read-only ten-gate production pilot report, based on the existing Section 77 runtime evidence and the existing Admin commerce Agent read model. Gates distinguish actual runtime state from simple installed component presence:
1. Real signed Stripe TEST paid/declined/expired/refund certification
2. Signed webhook and bounded recent reconciliation window
3. Clear Commerce Operations exceptions
4. Customer order and entitlement routes installed
5. Fulfillment/return/refund controls installed
6. Exact VINYL/POD proof controls installed
7. Read-only Admin Agent commerce triage available
8. Consent-aware campaign and CRM modules installed
9. Customer notification infrastructure installed
10. Authenticated upgrade, migrations and operational tooling installed

A passed *technical presence* gate does not certify live delivery. Even 10/10 technical/runtime gates cannot turn on Stripe LIVE mode. Pilot acceptance additionally requires operator evidence for six still-pending checks: real browser checkout and customer A/B privacy, hosted backup+restore/upgrade, external POD vendor and final delivery, mail-provider delivery/unsubscribe, Agent/Admin approval rehearsal, and independently verified Stripe TEST events.

## Non-destructive CI pilot
New tests stage an in-memory paid-order model and a local temporary POD handoff, then verify:
- Customer order privacy, identity isolation and exclusion of certification probes
- Matched exact manufacturing fingerprint, human approval and QC guard
- Tampered artwork rejection
- Prioritized, read-only Agent commerce review
- Opt-in and late unsubscribe before campaign dispatch
- Runtime evidence fail-closed behavior, missing external dependencies and impossibility of enabling LIVE charging
- Presence of all prior cross-system tests in release CI

No live money, customer email, real user records, vendor API, or shipping action is generated. The temporary handoff is removed when the test ends.

## Acceptance gates / scoring
Code/test acceptance: ten technical criteria, counted only when the exact PR-head release suite, PHP/JS syntax and real MySQL integration pass, then separately certify merged main. This is an engineering score and **not a claim of external production signoff**. External pilot signoff remains pending and is deliberately unautomatable from a source-only CI run.

## Deployment and recovery
Use the existing GitHub Actions certified full deploy artifact for the exact merged revision, perform private-data-preserving backup before upgrade, validate restored user data on a clone, then rehearse in Stripe TEST mode. Do not enable any live transaction from this release.
