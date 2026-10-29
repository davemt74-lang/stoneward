# Section 91B3C — Reviewed image accessibility alt text (v1.3.167)

Admin can prepare an accessibility description for one existing image asset:

> Set alt text for media asset 123 to Singer on stage beneath blue lights.

The owner must identify the **numeric Media Library asset ID**. Audio/video and
unknown IDs fail closed. The action can edit only `alt_text` (1–500 UTF-8 bytes).
It cannot alter the caption, image bytes, file paths, relationships, visibility,
featured state, public download permission, billing, messaging or publishing.

The one shared image asset may appear on multiple pages. Its accessibility text
may become visible to visitors, screen readers, and search engines if any
attachment is already public. The review shows total/public attachment counts.

1. Agent reads the existing image and its attachments; nothing changes.
2. Admin prepares an exact canonical proposal with asset revision, attachment
   fingerprint, old/new text, exposure counts and payload SHA-256.
3. Admin types `APPROVE`. This alone does **not** save.
4. Admin separately saves. The existing action ledger claims execution once.
5. A database-owned transaction locks/re-reads the asset and attachments,
   rejects stale values, and compare-and-sets only `alt_text` plus `updated_at`.
   Read-back verification creates the completion receipt.

Interrupted or ambiguous saves become `review_required`, with no automatic
retry. Replaying a completed proposal returns the prior receipt. No migration,
new permission service, or VP3/Gelato integration is introduced.

Certification: `tests/section91b3c-reviewed-image-alt-text.php` (isolated
SQLite + ledger), `tests/section91b3c-reviewed-image-alt-ui.cjs`, the preserved
91B3B regression and all required GitHub workflows. HostGator live acceptance
remains separate.
