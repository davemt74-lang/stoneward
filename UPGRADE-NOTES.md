# Stonefellow v1.3.50 — Section 50 Admin UI Certification

- Audit all 29 Admin navigation views against shared headers, New/Add controls, responsive widths, form lifecycle, and existing action approval boundaries.
- Fix creation-button visibility checks for hidden ancestor sections; include both Knowledge Base New Folder and Upload Files header actions.
- Batch mutation refreshes and restore keyboard focus to the originating creation action after closing a legacy editor overlay. Preserve original save/delete/publish handlers and the unsaved-input confirmation policy.
- Contain desktop/table/form widths and small-screen overflow with responsive page gutters and mobile editor layouts.
- Add **Upload / choose hero image**, **Edit artist story**, and **Preview homepage** directly within the Artist Homepage editor. These navigate to the existing Site & Artist Media and Stories Settings tabs and preserve the one-artist published-media source of truth.
- Prevent duplicated Artist Homepage section saves and resets. On API failure, retain unsaved form content and allow a safe retry.
- Preserve all original site/private data, Administrator accounts/passwords, Agent, CRM, merch, campaign, and media permissions.
- **No database migration required**; schema target remains 1.3.46. Advance app/PWA/Admin build caches to 1.3.50.
- Certification comprises 50A–50F test suites, all historical regression suites, green PR/main release gates, and verified pinned main deploy ZIP with SHA-256. Automated CI does not replace a live-browser acceptance test.

Application: **1.3.50**
Database schema target: **1.3.46**

---

# Stonefellow v1.3.49 — Unified Admin Page Headers and Editor Overlays

- Extends the Settings/Story Editor visual language across **all 29 Admin navigation views** through a shared page-header and responsive spacing layer.
- Existing section-level New/Add controls are elevated into the page header when the underlying module already provides that action.
- Existing create/edit views for tracks, releases, shows, templates, products, packages, campaigns, member content, ticket offers, audience segments, automations, EPKs, and launch plans are presented as responsive overlay form sections instead of replacing the whole visible page layout.
- Specialized existing modals (stories, videos, galleries, contact dialogs, shipments, refunds, coverage, milestone edits) remain governed by their own original code.
- The existing form elements and bound event handlers are **moved, never cloned**: saves, publish confirmations, media upload permissions, approval paths and status validation remain authoritative in the original modules.
- Long editors scroll vertically without horizontal layout overflow. Keyboard Escape/Tab focus handling, close confirmation for unsaved inputs, and a mobile full-screen editor are provided.
- This is a UI-only release: no schema change, new authentication path, contact permission, database migration, or password reset. Database schema target remains **1.3.46**.

Application: **1.3.49**  
Database schema target: **1.3.46**

---

# Stonefellow v1.3.48 — Tabbed Settings and Modal Story Editor

- **Settings** is now a ten-tab workspace: Public Site, Visual Theme, Artist Homepage, Stories, Site & Artist Media, Folder Access, Upload Limits, Password, Database Upgrade, and Admin Session.
- **+ New Story** appears at the top of Settings regardless of the selected tab and opens a focused editor modal.
- Existing stories also open in the modal for editing. After saving a new draft the modal remains open so Hero, Inline, and Gallery media can be attached using the existing universal Media Library controls.
- Modal keyboard support includes Escape and focus trapping. Unsaved edits require confirmation before closing. Sections load when first selected and remain mounted between tab changes, preserving unsaved form fields.
- Responsive tabs scroll horizontally instead of overflowing the right column.
- No new database migration or administrator password changes. The schema target remains **1.3.46**.
- Back up production data before uploading; preserve existing configuration and private files. Refresh cached Admin assets after deployment.

Application: **1.3.48**
Database schema target: **1.3.46**

---

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
