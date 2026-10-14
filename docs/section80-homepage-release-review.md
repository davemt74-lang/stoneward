# Section 80 — Homepage Designer Release Review

## Existing foundation reused
The existing artist homepage builder already supports block editing, responsive preview, inline content editing, drag/keyboard reordering, draft saves, explicit publish, public rendering, section templates, and version restore. Section 80 keeps that builder rather than creating another editor.

## New release controls
- Compare the server-saved private draft to the *current* live homepage on the server.
- Return an authenticated, read-only diff with section keys, changed field names, and block counts; do not include unpublished copy, images, catalog data or secrets in the diff summary.
- Show the changed section count and section keys in the current homepage toolbar.
- Disable publish while there are unsaved edits, stale live versions, no meaningful changes, or an ongoing save.
- On publish, explicitly confirm the number of reviewed sections and revalidate the current published hash, draft revision and whether any real changes exist; no-op publication is refused on the server.
- Refresh the review state after save, restore, reset or successful publish. The existing private draft stays separate from the published homepage.

## Ten release-acceptance gates
1. Original artist homepage builder and responsive iframe preview remain operational.
2. The canonical public renderer still reads only published records.
3. The owner can privately save and restore without publishing.
4. Diff is deterministic and identifies changed sections.
5. Custom blocks include counts, but draft content never enters diff metadata.
6. Stale draft hash blocks publication.
7. Empty/no-op draft blocks publication in API and UI.
8. Human must confirm the exact publish action.
9. Existing published history and conflict protection stay intact.
10. Full PHP/JS regression suites and real MySQL integration green on exact PR head and merged main.

## Boundaries
No automatic publishing, external provider side effects, or unverified browser UI claims. Production payment integrations remain gated by Section 77.
