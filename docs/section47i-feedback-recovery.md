# Section 47I — Explicit feedback, diagnostics and safe recovery

Customer and Admin Agent Chat now expose optional Helpful / Not helpful controls on real assistant replies. Feedback is stored as owner- and Brain-scope-isolated metadata (message ID, rating, timestamps). No user prompt, Agent response text, private customer memory, API key, or provider payload is duplicated in the feedback ledger.

## Scope and validation
- Server authentication chooses Customer vs Admin authority; the browser cannot select its own scope or owner.
- The feedback service verifies that the response ID belongs to the authenticated owner and is an **assistant** message.
- One rating per reply; changing a rating updates the existing record. Invalid values and cross-identity IDs fail closed.
- An explicitly not-helpful rating can provide bounded quality guidance in future authorized model replies, where personalization consent allows it. Feedback does not retrain provider models or override permissions.

## Continuity
Each Brain shows current feedback totals and a read-only recovery summary. Pending mission steps stay pending; Admin ledger states `executing` and `review_required` are surfaced for manual review. This module never invokes action execution, releases an uncertain operation, or silently retries any write.

## Privacy lifecycle
Customer privacy export includes private feedback metadata, and Agent-history clear/account closure deletes it using the existing transaction. Deletion does not create tables or implicitly commit MySQL transactions. Admin ratings never appear in customer exports or vice versa.

## Acceptance
`tests/section47i-feedback-recovery.php` exercises a real SQLite database for response ownership, role isolation, idempotent updates, no raw text copying, cross-Brain separation, review-required recovery, privacy clear, and the authenticated UI/API integration. The full release gate includes the test.

Database schema initialization is included in the Section 47J formal installer/upgrade migration and certified deployment.
