# Section 81 — Custom Vinyl Production Proof and Quality Gate

## Audit and integration
The existing Customer Vinyl Builder already freezes approved track selection and audio master paths, cover art choices, notes and player appearance in the purchased quote; the fulfillment core writes private POD handoffs and the Admin Fulfillment canvas already has explicit production stage, revision and audit controls. This section does not create a second vinyl production workflow or send files to an unconfigured vendor.

## Production-proof workflow
1. Read the paid, nonrefunded customer order and its original immutable quote; reject internal Stripe certification orders.
2. Verify each vinyl product's existing protected POD handoff matches purchased title, artwork, dedication and exact A/B sides/master files, and that player components remain visual-only.
3. Return an Admin-only SHA-256 fingerprint over the purchased production design and summarized track count. Do not expose recipients, audio master filenames or internal handoff payload in the summary.
4. Require the operator to review the artwork in the existing Admin Fulfillment UI and submit that exact proof hash when advancing to artwork_approved.
5. Bind future stage transitions to that same approved digest; reject drift and missing proof. Legacy stage records with no proof need a separate explicit authenticated, versioned re-verification action and audit event.
6. Require a second quality-control signoff and at least 10 characters of inspection notes before production_complete, without claiming third-party manufacturer acceptance.
7. Reuse existing admin authentication, CSRF, exclusive file lock, revision check, atomic write and audit trail.

## Ten acceptance gates
1. Persisted quote and real POD record agree.
2. Missing, malformed or tampered handoffs fail closed.
3. Audio master, side order and artwork drift are detected.
4. Stripe certification probes never trigger pressing.
5. Stripe paid/POD checkpoints are independently checked.
6. Refund/cancellation blocks new production stage changes.
7. Staff confirms exact proof digest in a revisioned action.
8. Subsequent milestones retain the digest; legacy proofs need reviewed migration.
9. Production completion requires recorded quality inspection.
10. Full release regression and real MySQL jobs pass on exact PR head and merged main.

## Deliberate boundaries
This is internal file-handoff and production management, not external manufacturing or shipping-provider fulfillment, proof delivery to a customer, a vendor callback, or automated payment/refund activation. Customer order and Agent privacy remain unchanged. Source certification does not replace physical quality inspection or vendor order acceptance.
