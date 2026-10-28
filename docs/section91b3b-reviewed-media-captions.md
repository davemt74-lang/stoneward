# Section 91B3B — Reviewed Media Library caption edits (v1.3.166)

The next narrow 91B3 operation uses the existing Media Library asset source of truth and the existing Admin Agent action proposal ledger. Admin can say:

> Set caption for media asset 123 to An updated concert photograph caption.

Use the numeric asset ID shown by an Admin Agent Media Library inventory or the Media Library editor. Filename guesses and ambiguous titles are not accepted. Only the **global `caption`** field on one existing asset can change. The proposal includes its previous and new caption, asset title, revision digest, attachment digest, total attachment count, and count of publicly visible attachments.

**Important:** A global caption can affect every place the same asset is used, including public galleries and SEO text. The review modal explicitly shows public exposure before any action.

1. The Agent identifies the asset and prepares the draft. No write occurs.
2. Admin opens the review modal, stages a ledger proposal, inspects canonical payload/SHA-256, and types `APPROVE`.
3. Admin separately clicks **Save approved caption**. The ledger first claims the one execution.
4. The database update runs inside a transaction and compares the approved asset revision and attachment digest. It updates only `caption` and `updated_at`, verifies the saved caption and records a receipt.
5. Any failed or ambiguous execution stays in `review_required`. No automatic retry. A completed proposal can be read again without repeating the edit.

No file content, paths, hashes, media links, featured status, visibility, download permission, billing, messaging, deletion or publication handlers can be changed by this action. Public/customer Agent skills cannot discover it. No schema migration or VP3/Gelato integration.

Tests: `tests/section91b3b-reviewed-media-caption.php` (real isolated SQLite + proposal ledger), `tests/section91b3b-reviewed-media-ui.cjs`, and the full GitHub Release Gate. HostGator live acceptance remains separate.
