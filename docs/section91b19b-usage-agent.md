# Section 91B19B-B — Aggregate AI Usage and Agent Navigation (v1.3.225 candidate)

This builds on the saved Stonefellow v1.3.224 User Management candidate. It is additive and does not merge any GitHub branches.

## New behavior

- Admin → Manage Users displays 7-, 30-, or 90-day aggregate totals from the existing token ledger: consumed Active Tokens, free promotional grants, monthly package grants, active/disabled accounts, low-balance accounts, and model/provider usage.
- The aggregate backend is `api/user-usage-report.php`. Its permitted periods are 7/30/90 days. It returns no individual user identifiers, emails, names, conversations or credentials.
- `GET /admin/api/users.php?summary=1&days=30` exposes the aggregate through the existing Admin authorization gate. Invalid periods return 422.
- Admin Agent can answer aggregate token-usage questions through an authenticated local read tool. It does not forward those private ledger facts to the configured external LLM and never writes account state.
- Voice/chat requests to create a user open the existing Admin Create User form; requests to manage individual accounts open Manage Users. No account is created, credited, or modified merely by a voice/chat command.
- A failed transactional invitation email is explicitly surfaced as a warning during account creation.

## Verification evidence

- JavaScript syntax checks: Admin main, Admin Agent canvas, and public application script passed.
- 13/13 focused Node regression checks passed, including one SQLite execution of the aggregate report's SQL against synthetic account and token data.
- Previously verified archive baseline retained.
- No new schema migration: existing users, token ledger, package and account-action-token tables are reused.

## Required before a 10/10 production certification

1. PHP CLI lint for changed PHP files.
2. Staging PHP + MySQL API integration tests for create/invite/assign/grant, CSRF/role boundaries, link expiry, duplicate creation, balance races, Stripe subscription preservation and mixed token activity.
3. Real browser test for Admin mobile layouts and invite delivery mode.
4. Re-run the full prior 161-check JavaScript suite. That suite is not present in the deployment source ZIP.
5. Review provider-token metering in all paid model paths for accurate charge attribution and atomic ledger settlement.

## Operational rules

- Do not merge before CI and staging green.
- Admin user actions remain protected by authenticated API calls, not model-generated action claims.
- Users at zero tokens retain local/Stonefellow knowledge behavior and cannot make new paid provider requests, subject to existing runtime enforcement.
- Avoid displaying raw invitation tokens in browser responses.
