# Section 79 — Unified Agent Commerce Triage & Unsent Customer Replies

## Existing architecture reused
Stonefellow already has two separated Agent Brains, Admin Agent Chat, customer Agent Chat, Commerce Operations queue, and the Section 44 exact-review action ledger. Section 79 extends the Admin Brain view; it does not introduce a second agent, email sender, customer write API or payment tool.

## Features
- Evidence-backed commerce review cards rank post-payment recovery, pending refunds, aged payment exceptions and unfulfilled physical merchandise. The source is the existing authorized Commerce Operations snapshot, not a model guess. Only bounded order IDs, flags, severity and next-view metadata appear in Agent Brain. Missing data is shown as unavailable, not as zero outstanding orders.
- Admin Chat recognizes messages such as “Draft reply for order SF-REAL-123 about shipping”. It verifies the real customer order and chooses a fixed, safe template for shipping, refund, payment, digital access or general support. No customer name, email, card data, tracking reference or raw order JSON is interpolated into drafts.
- The reply is presented as UNSENT and can be copied for manual review. The existing Commerce Operations navigation remains available. No customer email, support reply, refund, fulfillment, payment or payout is initiated.
- Existing exact-approved follow-up notes remain the only commerce-related Agent write permitted; their ledger, proposal digest, explicit approval, and idempotency controls are unchanged.

## Ten acceptance criteria
1. Missing commerce data fails closed.
2. Certification/test orders cannot be triaged or drafted.
3. Agent triage uses only safe order IDs and authorized flags.
4. Critical paid-order repair signals appear before non-critical delivery reviews.
5. Pending refunds do not imply a completed refund.
6. Suggested steps are navigation/review only.
7. Support draft requests require an exact existing customer-order ID.
8. Draft templates remain unsent and contain no private customer or provider data.
9. Admin Chat/Brain canvas integrates with existing routing, without a new agent or payment executor.
10. Full PHP/JS regressions and real MySQL certification pass on exact PR head and merged main.

## External / operational boundaries
No customer message delivery, Stripe charging, refunds, automatic inventory updates, provider verification, or shipping transitions are performed. Any future outbound message action requires separate customer consent and the governed approval ledger. No live Stripe merchant certification is claimed by this release.
