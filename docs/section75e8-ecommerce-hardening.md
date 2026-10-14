# Section 75E8 — Ecommerce hardening, merge criteria and staging requirements

Current scope:
- Hide isolated Stripe test probe orders from ordinary customer Admin Orders list, detail view, fulfillment and local changes; block ordinary refund/reconciliation actions on probes. Stripe certification can still manage these tests in its own authenticated workflow.
- Never allow a direct TEST merchant to be enabled when Store mode is production or simulated test, even if prior sandbox evidence was certified.
- Retain signing/webhook, refund, checkout, MySQL and concurrency acceptance gates.

**User-approved release distinction:** Section 75 source code may merge without an external Stripe account, provided real payment processing remains disabled until the separate live Stripe acceptance gate is satisfied. Green source CI certifies code regressions, NOT actual provider card charging or financial correctness. No automatic live enablement is part of the merge.

Outstanding post-merge staging acceptance:
- Real Stripe sandbox payment, decline, expiry, signed webhook retries, full/partial refund.
- Browser/mobile flows with real staging checkout and Admin.
- Upgrade rehearsal on an anonymized copy of the actual host database; exact host PHP, MySQL/MariaDB versions.
- Platform connection authorization if optional Connect is selected later.
- Live-mode activation in a separate reviewed section only after actual test evidence is completed.
