# 91B21B1 — Unified login protection

Release: v1.3.237. Base: fa404655 / v1.3.236. First security subsection after 91B21A.

## Scope
Customer, Admin and database-upgrade password entry points share one atomic admission and credential verifier. Existing thresholds are retained: eight unsuccessful or in-flight checks per normalized email, and thirty per source IP, within fifteen minutes. A transaction claims capacity before password hashing, so independent PHP workers and switching entry points cannot bypass the cap. Hash verification occurs outside the locking transaction.

All three surfaces preserve CSRF and required account status/role checks. Invalid, unknown, disabled and wrong-role credentials return a generic failure. Throttled requests return HTTP 429, Retry-After and the actual remaining seconds. A blocked request neither appends a failure nor extends its expiry. No permanent account-status change, CAPTCHA or extra approval dialog is introduced. Successful verification is recorded distinctly but cannot clear another account/source's failures.

The existing auth_attempts history is retained. A standalone auth_login_locks table and IP lookup index initialize repeat-safely without invoking operational, membership or lifecycle migrations. Upgrade authentication reads only original account columns. It now issues a persistent token, checks token revocation even with cached PHP identity, and performs real logout rather than leaving a persistent login active.

Only REMOTE_ADDR is trusted. IP representations are canonicalized, including mapped IPv4. Forwarded headers cannot self-assert a new address. Sites behind a reverse proxy must configure trusted proxy address restoration at the web-server layer; otherwise shared proxy IPs share the thirty-attempt limit. Hashes are pseudonymous security data, not anonymous data.

Storage/lock failures deny authentication with a generic 503. Pending attempts expire using the same bounded window. Successful/failed records are pruned after thirty days in bounded batches; stale locks after two days. No raw emails, IP addresses or passwords are written by this limiter.

## Acceptance
- Route contracts, normalization and role boundaries.
- Production PHP with real SQLite and MySQL: legacy schema preservation, cooldown and retry timing, mixed-surface failures, source spraying, independent concurrent processes, persistence fault injection, rollback and cleanup.
- Actual upgrade authentication/session functions on original-only user schema; cached identity must not outlive token revocation.
- Existing subscription/session and historical regression suites remain required. No paid/provider calls or production data involved.

## Deployment and limits
Single upload/extract deployment ZIP; preserve configuration/database/media. No registry migration or separate upgrade.php run is added by this section, but the application DB user must be allowed to initialize the security table/index. Existing login thresholds remain adjustable only in reviewed server code, not client input.

This is not MFA, bot detection, distributed edge protection or complete resistance to targeted temporary lockout. Password-reset requests and already authenticated sessions are not blocked by login throttling. Real HostGator/proxy and physical-device acceptance remain separate. B2 (media caching), B3 (trusted URLs), B4 (privacy lifecycle), full Stripe reconciliation and universal AI accounting remain unfinished.

Reference: https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html
