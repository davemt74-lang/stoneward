# Section 91B19C — Token Accounting Hardening (v1.3.226 candidate)

## Scope

- Per-user ledger changes now serialize on the user row (InnoDB) or an initial write lock (SQLite) before reading any balance. Balance and ledger row commit together.
- Trial, monthly, and administrator credits are idempotent by `(user_id, entry_type, source)`. Repeated matching credits return the balance unchanged; a conflicting administrator grant raises an error.
- The administrator Create User flow uses a per-account credit source. Manage User grants use a UUID request identifier retained across network retries. Notifications and audit entries are emitted only when a credit was actually applied.
- Complimentary package activation uses one transaction for the subscription change and monthly credit. Concurrent requests for the same already-active package skip duplicate allocations.
- AI use from unlimited administrators produces zero-charge usage entries with provider metadata, so paid API activity remains visible.
- Paid JEV response evaluation is skipped if the user lacks sufficient remaining Active Tokens after the initial chat response.
- If a normal chat answer would require a paid LLM when the account has insufficient tokens, the agent returns a local-only explanation and no paid action. Published catalog and local player controls remain available.
- Aggregate and per-user Admin views show usage by safe feature category: Agent Chat + Routing, Agent Brain + Evaluation, Voice Synthesis, Song Transcription, Lyric Analysis, and Other AI. Exact feature classifications are derived from ledger source allowlists; public/Agent aggregate views do not expose user identifiers.

## Test and scoring gates

| Section | Available local evidence | Status |
| --- | --- | --- |
| Token grant retry safety | Static branch checks, UUID request propagation, row locking and idempotency inspection | Green locally |
| Package/monthly allocation safety | Single-transaction structural assertions | Green locally |
| Aggregated feature reporting | SQLite execution of aggregate provider/feature SQL, UI syntax checks | Green locally |
| Zero-balance and admin metering | Local-route/JEV guard and metering checks | Green locally |
| Live concurrency and PHP syntax | Requires PHP CLI and staging MySQL/SQLite service | Not certified |
| Full application regression suite | Not present in recovered deployment snapshot; 21 focused tests are available | Not certified |
| Provider settlement with overlapping paid requests | Preflight plus serialized post-call debit; no reservation of balance before a provider call | Not certified |
| Admin-only background story-composition and story-inference provider costs | Outside the interactive user ledger; needs worker accounting follow-up | Not certified |

This package is an installable **candidate**, not a claimed production 10/10. Existing user data is preserved. Do not merge or deploy as certified without PHP/staging validation and user approval.

## Staging checks

1. Run PHP lint on `api/entitlements.php`, `api/agent-runtime.php`, `api/user-usage-report.php`, and `admin/api/users.php`.
2. Against the staging database, create one user and apply two simultaneous identical grant requests (same UUID). Confirm exactly one credit, one notification, one Admin audit event, and an unchanged second response with `applied=false`.
3. Submit the same UUID with a different amount and confirm `409` without any balance change.
4. Assign the same package from two browser sessions at the same time; verify exactly one current-period grant. Reassign in a later period and verify a fresh grant.
5. Exhaust a non-admin token balance; verify that Chat, voice, transcription, song analysis, JEV intent/evaluation, and local actions follow the defined free/paid boundary.
6. Exercise an Admin unlimited chat and voice request; verify a zero-charge usage ledger row with provider, model, and usage metadata.
7. Compare feature and provider totals in the 7/30/90-day reports to raw ledger rows; confirm no user-identifying details are sent to the Admin Agent's external model.
8. Validate the mobile User Management layout, the entire PHP/database lifecycle, and the complete 161+ legacy JS suite on the original repository snapshot.
