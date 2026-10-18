# Stonefellow 88D3G1 — Governed Render Source Certification

**Release v1.3.120 / Schema 1.3.57 / Migration 048**.

This is the first gated section of 88D3G. It certifies immutable source bytes and rights for a private video render request. **It does not execute FFmpeg, render a video, serve private media publicly, or publish a film.**

### Certified media and rights

- Every previously verified capture scene or transcript is revalidated against its present editorial review, independent **publication** clearance, SHA-256 and byte size in both the Media Library and immutable capture evidence. The file must be contained in the private `storage/media` root with a format consistent with its category. The current storyboard's independent music synchronization/master-use clearances must both be valid.
- The editor must independently bind a canonical Stonefellow track to an existing private Media Library **audio** master for each assembly revision; the binding records the master hash and is audit-backed. This is separate from both music license purposes. A song entry without an actual music master is blocked.
- A revised timeline expires the music master binding, synchronization license, and master-use license. Rights revocation and any file or digest change invalidate certification on the next check.
- Internal `sf_render_source_manifest(editor,assemblyId,revision)` holds server filesystem paths for the future CLI render worker. Only `sf_render_source_admin_status`, which has **no paths, hashes or credentials**, may be emitted to the Admin browser.
- All account actions require existing production editor authority. Master binding uses a database transaction and activity audit. There is no HTTP endpoint capable of rendering or returning the internal manifest.

### Admin

The existing Admin `story-assembly.php` API adds authenticated POST `{"action":"bind_render_master","assembly_id":A,"revision":R,"track_id":"catalog-track-id","asset_id":M}` and returns a path-free `render_certification` object in the private GET response. Only private Media Library audio asset IDs may be bound.

### Certification scope

Automated SQLite tests cover role isolation, audit rollback, independently documented music and contributor rights, immutability of capture evidence, full SHA-256 of source bytes, private storage containment, and stale revisions. A real MySQL 8 schema test covers the additive binding table. Live HostGator media path permissions, actual FFmpeg availability/quality, output codecs, video editing, and any publication step require a later phase.

**Next section 88D3G2:** implement a supervised, private output FFmpeg worker with strict budgets and certification immediately before execution. Do not add public output routes or a background daemon without separate gate approval.
