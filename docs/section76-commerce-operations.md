# Section 76 — Commerce Operations Workspace

## Delivery
- Orders → Commerce Operations launches an integrated Admin canvas without increasing the Admin sidebar navigation count.
- View payment exceptions, pending Stripe refunds, awaiting physical fulfillment, completed refunds and open customer-service follow-ups. The new API derives read-only status from the real Store order records.
- Internal Stripe certification orders are excluded from all customer operation counts and detail rows.
- Admin creates notes with a high/normal priority and optional due date, or resolves them. Persistence is in private file storage at `storage/commerce-ops`, separate from payment and fulfillment state.
- Every follow-up includes an authenticated Admin owner, creation timestamp, and idempotency hash. Exact retries return the existing record and changed-terms retries fail. Writes take a per-order file lock.
- An explicit **Check Stripe** button reuses the existing, provider-backed, non-charging order reconciliation and emits the existing Admin audit action. This workspace cannot create payments, silently refund customers, or mark physical shipments shipped.
- Operations workspace supports filters, queue counts, refresh and actions on desktop/mobile canvas.
- The normal Orders and Fulfillment views remain authoritative for actual refunds and shipping operations.

## Automated work / operations policy
The queue is generated from live order state on every read. It highlights aged payment-pending orders (15 minutes), pending refund requests, incomplete paid-order side effects, pending fulfillment, and unresolved customer notes. This is **read-only automatic triage**. There is no unapproved auto-refund or duplicate reminder scheduler. Owner-triggered reconciliation may update an order only from verified Stripe provider responses.

## Gates
The required GitHub release CI now checks Section 76 operations persistence, idempotency, authorization, navigation, test-order exclusion, Stripe read-only reconciliation, and that the new module cannot bypass payment/fulfillment permissions. Existing MySQL upgrade/inventory and Stripe safety gates remain.

## Stripe release disclaimer
Section 75 payment features are merged by explicit owner approval, but the active merchant must still pass real Stripe test account acceptance. Live-mode activation remains blocked; Section 76 does not change that gate.
