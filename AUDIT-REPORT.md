# Stonefellow v1.3.40 — Section 41 Fan Account, Profile & Privacy Center Audit

Section 41 extends the existing My Stonefellow account rather than creating a second account system. It adds a dedicated account-only Privacy Center while preserving the existing public legal Privacy page.

## 41A — Fan Profile & Privacy Schema — 10/10
- Added user_profiles for timezone, country, locale, listening-history and personalization preferences.
- Added user_privacy_events for user-visible privacy action history.
- SQLite and MySQL are both supported.
- Timezone, two-letter country code and locale format are server validated.
- Default behavior remains backward compatible: history and personalization are enabled until a fan changes them.

## 41B — Runtime Privacy Controls — 10/10
- “Save listening history” changes the actual listening pipeline.
- When disabled, future listening analytics are stored without the account user_id.
- Continue Listening/progress writes stop when history is disabled.
- “Use my activity for personalization” changes the real recommendation profile.
- When disabled, recommendations/Agent ranking receive a zero-signal profile rather than merely hiding personalized UI.

## 41C — Account Data Export — 10/10
- Confirmed JSON export covers account/profile, sessions, notification/fan preferences, favorites, listening/progress, playlists, library, saved builds, purchases, invoices, membership, activity, Agent conversations/Brain, support history, identifiable telemetry and privacy events.
- Password hashes, CSRF secrets and token hashes are not deliberately exported.
- The export includes a clear retention note for operational/accounting records.
- The browser creates the download locally; no public export file is left on disk.

## 41D — Selective Privacy Resets — 10/10
- Confirmed listening-history clear anonymizes historical listening and removes progress.
- Personalization reset clears listening/feedback signals while preserving intentional saved content such as favorites/playlists.
- Search/discovery cleanup removes account identity from search/discovery telemetry.
- Agent-history cleanup deletes conversations, messages and Agent Brain rows strictly; database failures surface instead of being silently ignored.
- Every destructive reset requires explicit confirmation.

## 41E — Governed Account Closure — 10/10
- Requires the current password plus exact typed DELETE plus final UI confirmation.
- Administrator accounts cannot self-close.
- Active managed paid subscriptions must be cancelled first.
- Physical fulfillment must be complete/resolved.
- Open support cases must be resolved first.
- Closure uses a database transaction; required purge/anonymization failures roll the closure back.
- Persistent sessions are deleted inside the transaction.
- Fan CRM identity is archived, newsletter/Agent marketing is disabled, and the linked email is replaced with a random closed-account alias.
- Transactional-email rows lose the original user/email identity.
- Behavioral event rows are anonymized so aggregate analytics remain possible.
- Account profile, personalization, library, playlists, saved builds, notifications, sessions, tokens/subscription entitlements and other account-owned rows are purged.
- The user row is disabled with a random password and status=closed.
- Historical orders, invoices, refunds and support records are retained only as stated operational/accounting history.

## 41F — Account UX & Route Safety — 10/10
- My Stonefellow links to Privacy & Data without adding a global navigation module.
- Privacy Center includes profile/privacy controls, export, selective resets, recent privacy activity and closure.
- Existing public legal Privacy remains at view=privacy.
- Account Privacy Center uses the separate account-privacy route, preventing the route collision found during the audit.
- Danger actions use explicit styling and responsive layouts.
- Artist Theme typography/colors are respected; no hard-coded legacy public font stack bypasses Section 34.

## 41G — Release/PWA Integration — 10/10
- Public/Admin/server build versions advance together to 1.3.40.
- Service-worker cache advances to stonefellow-static-v1.3.40 so installed PWAs do not retain stale account JavaScript.
- Section 40 accessibility/PWA certification remains forward-compatible and still enforces its 1.3.39 minimum.
- Public Privacy policy remains intact.

## 41H — Upgrade & Regression Safety — 10/10
- Application: 1.3.40
- Database schema: 1.3.33
- Migration 2026-10-12-034 creates user_profiles and user_privacy_events.
- Migration integrity requires both tables.
- Section 38/39/40 inherited schema/version tests were made forward-compatible without weakening their original minimum guarantees.
- PHP syntax: PASS
- Public JavaScript syntax: PASS
- Admin JavaScript syntax: PASS
- Sections 1–40 inherited suites: PASS
- Section 41 certification: PASS
- Explicit PASS lines: 2358
- PHP warnings: 0
- Failures: 0

FINAL SECTION 41 SCORE: **10/10**
