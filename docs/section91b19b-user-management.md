# Section 91B19B-A — Admin User Management (v1.3.224 candidate)

Baseline: Stonefellow v1.3.223 deployment package, retrieved from the saved release (672 files). This is an additive release candidate; it does not merge remote branches.

## Implemented

- Admin → Administration → Manage Users and Create User.
- Searchable user list with package, role, status and Active Token balance.
- Secure admin-created user accounts with random unusable initial password hashes.
- Optional one-time password setup invitation using the existing expiring password-reset token mechanism. Invitation email delivery follows the existing transactional email configuration (log versus PHP mail); no raw password or token is returned in the API.
- Role creation is restricted to User or explicitly confirmed Administrator.
- Optional complimentary package assignment and separately audited initial free token grant.
- User detail screen: editable display name, role, status; resend invitation/verification; session revocation; complimentary package changes; free token top-ups with mandatory reasons.
- Ledger-backed 30-day totals, model/provider breakdown, and paginated detailed transactions.
- Disabled accounts have all active sessions revoked. Last active administrator protection remains in place.
- Existing Stripe-managed active subscriptions cannot be replaced by the local package-assignment action.
- Existing Song Moments and Stories files are unchanged.

## Backend API

`GET /admin/api/users.php`: existing list, compatible response.

`GET /admin/api/users.php?id=123&offset=0`: user record, entitlement, 30-day usage totals, per-provider breakdown and up to 100 token ledger rows.

`POST /admin/api/users.php` with action:
- `create`: display_name, email, role, confirm_admin (for admin), package_id, free_tokens, grant_reason, send_invite.
- `invite`: id; sends a one-time secure setup link for an active account.
- `grant_tokens`: id, amount (1–100,000,000), note (required).
- `update`: id, display_name, role, status.
- Existing assign_package, send_verification and revoke_sessions remain available.

All writes use existing Admin auth/CSRF checks and administration audit events.

## Acceptance tests still required before 10/10 certification

1. Configure PHP + SQLite/MySQL test runtime; lint and run API integration tests for duplicate emails, invalid roles, CSRF, account creation, invitation expiry, and retry.
2. Verify created users can set their passwords and cannot access Admin by default.
3. Verify malformed free-token grants are rejected; valid grants do not alter monthly package allowances; ledger reflects actual amounts and reasons.
4. Verify token account balances and provider/model usage breakdowns against live provider events, including zero-token fallback behavior.
5. Verify complimentary package assignment and protection of active Stripe subscriptions.
6. Test last-admin protection, disabled-user session revocation, and invitation throttling.
7. Test responsive create/manage screens at desktop and mobile widths (no vertical nested scrollbars).
8. Run the full prior 161 JavaScript checks and live PHP/database behavior on a staging copy.

Only JavaScript syntax and archive integrity have been verified in this environment. The PHP CLI and prior 161-check test suite are unavailable in the retrieved deployment ZIP. Do not represent this build as fully certified or merge into main until those checks pass.
