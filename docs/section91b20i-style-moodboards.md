# 91B20I — Private Style Mood Boards · v1.3.235

Admin Image Studio now organizes up to eight existing approved Media Library images per visual style. Admins can add private captions, move references up/down, remove a reference without deleting its source, and select one source for the established image-edit workflow. Board edits preserve the creative prompt and never generate or publish images.

The implementation also fixes three single-element/list-selector errors in 91B20H, and clears the previous mask when selecting a different source. Source-image consent and mask consent are reset together.

## Persistence and permissions
The existing authenticated, CSRF-protected Image Studio API handles board_add, board_move and board_remove. All changes are scoped to an active style, use a parent-row lock (SQLite write-first / MySQL FOR UPDATE), enforce an eight-reference limit and per-style asset uniqueness, and write the official Admin audit in the same transaction. Audit failure rolls back the edit. Source deletion cascades its reference only. Revoked generated-image approval removes the board preview and disables source selection.

The table is created idempotently when Image Studio initializes. No existing music, public art, presets, users, usage records or paid-generation settings are reset. This section adds no upgrade.php migration; back up the installation before deployment as usual.

## Acceptance score: evidence required for each of ten checks
1. Existing Admin/CSRF gate remains before all new actions.
2. Duplicate, invalid and unapproved references are rejected.
3. Simultaneous final-slot requests cannot exceed eight references on SQLite/MySQL.
4. Reorder is scoped, bounded, stable and atomic.
5. Removal requires confirmation and leaves the source media intact.
6. Initialization is repeat-safe and source deletion cannot leave a dangling reference.
7. Audit and mutation commit/roll back together.
8. Archived styles and revoked image approvals fail closed.
9. Browser source handoff preserves prompt, resets consent and removes the old mask, without calling a provider.
10. Desktop/mobile-width browser tests, full release regression gates, and exact-source single-ZIP packaging pass.

Tests: seven Node contract checks, production-function integration tests on real SQLite/MySQL including competing processes, and 34 full Image Studio Chromium interactions at 1440/375 pixels using synthetic API responses. The new permanent workflow supplements the existing release gate; none of the historical checks are disabled.

Browser viewport checks are not physical iPhone Safari certification. No live HostGator credentials, customer records, paid image calls, invoices or external publication are used in these tests. Live provider fidelity remains a separate operator check.
