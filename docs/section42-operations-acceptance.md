# Stonefellow Section 42 — Production readiness and operational hardening

Scope: **single-artist** Stonefellow application. No additional admin sidebar workspace, database migration or public health endpoint.

## A. Admin operational health
- Only active administrators can view `admin/operations-health.php`.
- Browser output contains status and generic remedies, not SQL errors, credentials or private data.
- Checks PHP, PDO, storage, backup directory, connection, checksum drift, pending migrations, and latest upgrade outcome.
- Read-only: no repair, directory creation, migrations or backup creation.

## B. Backup and migration safety
- Existing `upgrade.php` retains admin + CSRF + exclusive lock + migration drift and preflight guards.
- After backing up, it now verifies the artifact **before** any migration runs.
- Restricts path to the private backup directory, checks size, hashes with SHA-256.
- SQLite backups undergo `PRAGMA quick_check`. SQL dumps undergo header/trailer completeness checks.
- Failed checks abort before the first migration. Checks do not restore or transmit backup bytes.
- SQL dump completeness markers are **not** a substitute for a test restore; periodically restore backups on an isolated system.

## C. Recovery visibility and privacy
- Health page flags latest failed/interrupted upgrade state without disclosing stored error text.
- Browser-facing upgrader exceptions are generalized. The site event feed records the exception *class* rather than the raw underlying message.
- Existing upgrade history and admin log remain authoritative.

## D. Continuous verification and packaging
- The existing complete Stonefellow release gate runs all Section 42 regressions.
- Dedicated workflows cover health security boundaries, backup corruption, and upgrade privacy.
- Following a green `main` release gate, a separate packaging workflow archives tracked source and produces a deployment ZIP + SHA-256 manifest.
- Build excludes developer tests/workflows/scripts; includes the web application and upgrade entrypoint.
- `upgrade.php` must be used for pending schema migrations; do not restore production data as part of a normal deployment.

## Operational acceptance
1. Anonymous and non-admin requests receive HTTP 403.
2. Admin health cannot mutate migration/backup state.
3. Both supported database types can pass connection checks; database or storage failures produce generic statuses.
4. SQLite backup corruption and truncated SQL export are rejected before migration.
5. Existing migration lock prevents concurrent runs.
6. Missing/current/drifted migrations appear without secret information.
7. Interrupted/failed latest upgrade is flagged; successful latest upgrade clears that status.
8. CSRF, administrator permission and upgrade confirmation remain enforced.
9. Full release gate + all three Section 42 suites pass on the same commit.
10. Only that green commit may produce a verified deploy ZIP; merge follows all checks.

## Important limitations
The SQL file sentinel validation is a completeness guard, not a database restore certification. A true restore rehearsal on a disposable database and production-specific backup/permissions checks remain recommended before a live upgrade.
