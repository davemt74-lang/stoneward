# Canvas permission foundation validation

This covers the permission-policy foundation and remembered viewer consent. It does not by itself certify the broader canvas roadmap or live hosting.

## Real HTTP authentication and policy API

Run `SF_TEST_PHP=/path/to/php python tests/canvas-permissions-http.py` with PHP PDO SQLite and ffmpeg available. The test creates a disposable copy of the application and starts its real PHP endpoints. It uses actual account login sessions and CSRF tokens, not a mocked admin API. Provider transport is blocked inside the disposable copy and the test checks that it was never reached.

The 20 checks cover guest/non-admin denial; authenticated administrator policy reads; no-store responses; missing, forged and cross-account CSRF rejection; unsupported methods; invalid request shapes; persistent save/audit; stale-save conflict; idempotent no-op; administrator role revocation during an existing session; and unchanged policy after rejected calls.

## Native MariaDB transaction and concurrency checks

Run `SF_TEST_PHP=/path/to/php SF_MARIADB_ROOT=/path/to/extracted/mariadb/root python tests/canvas-permissions-mysql.py`. The runtime root must contain `usr/sbin/mariadbd`, `usr/bin/mariadb-install-db`, `usr/share/mysql` and required shared libraries under `usr/lib/x86_64-linux-gnu`. These are test-environment dependencies, not deployment dependencies. The test binds a temporary server only to loopback on a random port, creates a temporary database and terminates/removes it afterward. It never connects to the deployment database.

Verified on MariaDB 10.11.14 with PHP PDO MySQL. Fifteen checks cover default non-writing reads, native schema and cards, transactional InnoDB, policy/audit commit, rejection before DDL inside another transaction, rollback of the caller's pending row, two independent concurrent PHP workers using one revision, exact winning actor/change history, and atomic rollback when an injected database trigger rejects audit insertion. The real application bootstrap is then used on a separate temporary database to verify account-isolated grants, explicit InnoDB consent tables, concurrent grant/revoke serialization, stale Allow replay rejection and atomic rollback when consent revision insertion fails.

This is native MariaDB coverage of the MySQL SQL path, not a claim of testing every MySQL version, HostGator configuration or live hosting. Browser tests and physical-device coverage remain separately identified.

## Real HTTP viewer consent and data delivery

Run `SF_TEST_PHP=/path/to/php python tests/canvas-access-http.py`. Its 27 checks use real login/CSRF sessions, saved scene lineage, an approved public gallery entry and distinct synthetic listening rows for two accounts. Actual scene/gallery endpoints withhold private data before consent, return only the authorized current viewer's data after consent and withhold it again after revocation. The suite also verifies private-context ownership, declared-only grants, public/creator context separation, equal-scope revision reuse, expanded-scope consent, independent copies, account grant management, stale Allow replay and guest public summaries.

This suite exposed and verified the fix for fresh-request consent mutation attempting scene-history initialization inside an active transaction. It runs the production endpoint without patching that behavior.
