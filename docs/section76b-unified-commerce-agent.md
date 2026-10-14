# Section 76B — Unified Commerce Agent Integration

## Overview

The preexisting Admin Agent Brain/Chat and customer Agent Brain/Chat now reuse the same merged Section 76 customer commerce records, without creating a competing Agent runtime.

**Admin Agent Brain** includes the current operations summary, queue count and first eight at-risk order IDs/status/flags, with no customer emails, names, tracking IDs or payment keys. The existing Admin Brain drawer displays commerce metrics and a link to the real Commerce Operations canvas.

**Admin Agent Chat** recognizes operational questions such as “Which orders need attention?” or “Show pending refunds?” and answers deterministically from the authorized Commerce Operations snapshot, with a direct action to inspect the full order queue. The LLM remains explanation-only, not a direct executor.

**Governed commerce follow-ups** are added to the existing static Agent tool and skill registries as `commerce_followup_add`, admin-only, explicit confirmation, executed solely by the existing Section 44 proposal ledger. A message such as:

    Create follow-up for order SF-REAL-123: Contact the warehouse for tracking

creates a **review card only**, not the note. The Admin clicks Prepare, inspects the exact normalized proposal and SHA-256, types APPROVE, clicks Approve, and separately clicks Save. The backend revalidates the real order immediately before execution and uses a stable key derived from the proposal ID to prevent duplicated notes after interrupted execution. The ledger records exact approvals and results; uncertain operations stay review_required. No charge, refund, customer email, payout or shipping action is available to the Agent.

**Customer Brain/Chat** reads only the authenticated fan's own orders and status/fulfillment/tracking, never another customer's ID. Data comes from existing fulfillment-order discovery with a fresh owner check against the full order record. Signed-out fans get no order context; internal Stripe certification orders are omitted. Customer Agent writes to commerce remain prohibited.

## Regression gates

New Section 76B suite covers admin-versus-customer tools, order ownership isolation, mocked customer A/B tracking separation, Stripe test-order omission, Admin query routing, exact-proposal hash, unauthorized Admin approval rejection, no writes before APPROVE, distinct approval and execute, idempotent replay, and no Stripe/fulfillment effects. The existing payment, MySQL, legacy migration and Agent suite remains mandatory.

## Deliberate boundaries

- Agent responses cannot initiate Stripe charges, refunds, subscription upgrades, POD fulfillment or shipment changes.
- The Agent does not proactively send customer follow-up messages or schedule repeat reminders.
- Approval and conversation history remain in the existing per-Admin ledger; the customer Brain never sees Admin conversations or internal follow-up notes.
- Real Stripe acceptance remains a separate blocker to live payment activation despite merged Section 75 source code.

## Release process

Merge Section 76B only after complete GitHub release gate and real MySQL integration job both pass on the exact PR head. Build the final deployment ZIP from the tested GitHub artifact and record SHA-256.
