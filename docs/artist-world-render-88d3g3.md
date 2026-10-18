# Stonefellow 88D3G3 — Secured Private Output Playback & Human Review

**v1.3.122 · schema 1.3.59 · migration 2026-10-18-050**

## Review workflow

Go to **Admin → Agent & Knowledge → AI Production Review → AI Director Editorial Assembly → Private Render**. On a render marked `private_review`, the new embedded video player requests MP4 bytes from a **same-origin Admin API**. The API requires the editor's authenticated Admin session and production role **for every playback/range request**.

Before sending any bytes, it revalidates current assembly revision, independent contributor publication permission, independently reviewed music synchronization/master-use licenses, full source hashes/immutable evidence, private output filename containment and full output SHA-256. The response is cache-disabled, marked same-origin and nosniff. A single HTTP Range request can return at most 1 MiB. Multiple/suffix/out-of-bounds ranges are denied. No public direct-file path is disclosed and the file remains outside the web document root.

Editors record an independent video review basis of 25+ characters and choose **Approve private draft** or **Reject private draft**. A successful decision writes an audit-backed `story_render_reviews` entry and changes the job's status transactionally. A failed audit rolls back the decision. Source/content revocation stops playback or approval. Rejection remains available after rights revocation.

A private approval only means this editor reviewed the cut. It **does not authorize publishing, wider distribution, downloads, licensing, customer/fan access, social posting or export**. Because authorized browser playback delivers actual bytes, users with an authenticated browser can technically capture those bytes: disabling the download button is *not* a DRM guarantee. Treat access to Editor roles accordingly.

## Deployment

Back up, install the complete v1.3.122 package, and run `upgrade.php` migration 050. Keep the private render output directory outside the web root with minimal filesystem permissions. Retain FFmpeg availability for later render jobs; playback uses existing private render files and PHP's Range support.

Automated tests cover access control, output fingerprint tampering, revocation, partial ranges, duplicate review rejection, audit rollback, authorized private approval, and no-publication API boundaries; MySQL 8 and legacy CI remain required. Live HostGator video playback, browser codecs, full QA review of rendered scenes/music, and actual rights documentation still require field certification.

## Next independent section

88D3G4 should implement more advanced audio overlays and crossfade timing plus reviewer-visible source/cue synchronization validation, with another review gate. Real publishing must be a **separately authorized phase**, not a side effect of approval here.
