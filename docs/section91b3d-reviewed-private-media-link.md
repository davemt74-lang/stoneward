# Section 91B3D — Reviewed private image attachments (v1.3.168)

Admin Agent Chat now supports exact-ID instructions:

> Attach media asset 123 as artwork to song SONG-ID.

> Attach media asset 123 as cover to release RELEASE-ID.

Only existing **image** assets and uniquely identified real song/release records
are eligible. The supported pairings are `track/artwork` and `release/cover`.
The Agent reads the real Media Library, target catalog/release JSON and existing
attachment relationships. Titles are display-only; guesses and missing IDs are
rejected. No external LLM receives any of the private records.

The review modal displays the image, target, role, existing public usages and
target attachment count, plus the canonical SHA-256 payload. The owner must
prepare, type `APPROVE`, then separately select Save. An existing attachment
cannot be duplicated. Changed image metadata, relationships, catalog/release
source or target attachments invalidate the approval.

Execution is claimed **once** in the existing durable Admin Agent ledger.
It transactionally adds exactly one new relationship in `media_links` with
`public_visible=0`, `download_allowed=0`, `featured=0`, verifies the saved
link, and emits a receipt. A previously public cover/artwork is left as-is;
the new private candidate is not published or made primary. On an ambiguous
failure, the proposal becomes `review_required`; no automatic retry or
duplicate insertion. A completed replay returns the original receipt.

No new file bytes, public visibility changes, publication, downloads,
replacement, detachment, deletion, billing, messaging, database migration,
VP3 or Gelato work. Public/customer Agent contexts cannot discover this tool.

Certification: isolated SQLite and target-source tests in
`tests/section91b3d-reviewed-private-media-link.php`, browser contract suite
`tests/section91b3d-reviewed-private-media-ui.cjs`, previous Section 91B3
regressions and complete GitHub Release Gate. HostGator acceptance is separate.
