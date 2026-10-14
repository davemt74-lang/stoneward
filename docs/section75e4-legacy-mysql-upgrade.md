# Section 75E4 — Legacy MySQL Upgrade Safety

**Status:** Review branch only. No production credentials, public Stripe API calls or live payments are used.

## New MySQL acceptance

This runs against the disposable stonefellow_test database inside GitHub Actions and refuses to run unless both GITHUB_ACTIONS=true and SF_E4_EPHEMERAL=yes are supplied. All tables in that disposable schema are removed at the end, before the existing MySQL inventory concurrency test runs.

The fixture simulates an installed legacy schema with an existing user account, two library records, and a saved custom vinyl draft created with former mandatory title and data_json fields. It seeds the exact migration IDs and checksums for all previous migrations while leaving the historical draft repair pending.

Acceptance verifies:
- The migration registry identifies precisely one pending additive repair with no historical checksum drift.
- Installed account preflight passes.
- A MySQL SQL backup is created and checked before the migration; the backup includes the draft and library.
- Only migration 039 executes, adding name, format, build_json, and timestamps without deleting records.
- Draft ID, owner, legacy title, JSON payload and notes, plus the original Library rows, remain intact.
- New draft inserts can satisfy the old mandatory fields.
- A second migration run applies nothing and leaves the original verified backup unchanged.
- Restores the generated SQL backup into the disposable CI database, verifies original customer content/schema/history, and successfully reapplies the pending migration after restoration.
- Deliberately tampered historical migration checksums block any subsequent upgrade.

## Certification limits

This is a purpose-built legacy fixture, **not** a sanitized production database. It does not certify every historic layout, MariaDB variant, backup restoration or full installer. Signed webhook and event tests remain simulated; real Stripe test-mode checkout/decline/refund acceptance and staging Admin/customer browser acceptance remain incomplete.

PR #141 remains draft and unmerged until all Section 75 gates are green.
