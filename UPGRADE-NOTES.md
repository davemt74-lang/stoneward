# Stonefellow v1.3.47 — Admin Agent Chat UI Consolidation

- Admin Agent Chat now uses the **existing sticky ChatGPT-style footer composer only**. The duplicate middle-of-page form has been removed.
- The footer supports a multiline message (Enter to send, Shift+Enter for a newline) and preserves drafts on request failures.
- The conversation scrolls inside its own region, with the Agent Brain opening as a right-side drawer.
- The Admin sidebar can scroll independently on shorter screens. The original governed Admin Agent endpoint, conversation ID, and approvals remain unchanged.
- This is a UI-only upgrade: **no new database migration** and no password or account changes. The schema target remains **1.3.46**.
- Back up production first, preserve the existing data/storage/secrets and run the normal `upgrade.php` preflight. Refresh the browser/PWA to clear cached assets.

Application: **1.3.47**  
Database schema target: **1.3.46**

---

# Stonefellow v1.3.46 — Section 47 Release Notes

1. Make a verified backup of the current database and private data before uploading this version.
2. Upload/extract the **certified v1.3.46 ZIP** into the existing site without deleting production-owned `data/`, `storage/`, environment/secrets or generated catalog configuration.
3. Keep existing user records and password hashes unchanged. This release has **no password-reset or first-admin creation step**.
4. Open `/upgrade.php` as an authenticated administrator and run the pending migration after its backup/preflight.
5. Migration **2026-10-12-037** adds `agent_mission_plans` and `agent_response_feedback`. Both are additive with no users-table changes.
6. Confirm the public and Admin Agents load, that Customer memories/conversations remain private, and that reviewed actions still require separate exact approval.
7. Customer response ratings are stored privately, included in account privacy export and cleared with Agent history. Admin ratings remain operational and Admin-owned.
8. Clear the browser/PWA cached shell if an older version remains visible; the cache advances to **stonefellow-static-v1.3.46**.

Application: **1.3.46**  
Database schema target: **1.3.46**

---

# Stonefellow v1.3.40 Upgrade Notes

1. Upload/extract the v1.3.40 deploy over the existing installation.
2. Preserve production-owned data/, storage/, database/secrets/store configuration and generated catalog/config JS.
3. Run /upgrade.php.
4. Database target advances from **1.3.32** to **1.3.33**.
5. Migration **2026-10-12-034** creates:
   - user_profiles
   - user_privacy_events
6. Existing fan accounts default to listening-history and personalization enabled until the fan changes those settings.
7. My Stonefellow now includes **Privacy & data**. The existing public Privacy policy link remains unchanged.
8. The service-worker cache advances to **stonefellow-static-v1.3.40**; installed PWAs should refresh to the new shell automatically.
9. Before testing account closure, resolve active paid subscriptions, unfinished physical orders and open support cases. Administrator accounts cannot self-close.
10. Account closure intentionally retains disclosed historical order/invoice/refund/support records while removing or anonymizing fan-profile/behavior identity.

Application: **1.3.40**
Database schema target: **1.3.33**
