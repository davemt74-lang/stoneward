# Stonefellow Section 86A — Native Stem Studio and REAPER Import

This module is a **new implementation in the current Stonefellow website**, with
feature expectations recovered from the older Stonefellow editor. It does not
import or embed legacy PHP code. All authentication, uploads, private media,
song identities, versioning and audit controls reuse the existing website.

## Existing Admin workflow

1. Open **Admin → Catalog → Song**; under Media, use the new
   **Stems & REAPER Studio** section and open the full-canvas editor.
2. Upload the song's `.rpp` session (limit 8 MB). The importer reads track
   names, GUIDs, tempo, meter, sample rate, clip offsets and lengths,
   stem gain/pan, and recognized plugin identities. The original `.rpp`
   is retained privately in `storage/stem-projects`.
3. Upload the actual **consolidated MP3/WAV stems**. These go through the
   **existing Media Library uploader** (validation, SHA-256, dedupe,
   private storage); matching filenames automatically assign stems to the
   corresponding REAPER lanes. Unmatched assets can be attached by media ID.
4. Open the editor to listen to tracks using a shared Web Audio clock,
   media playback drift correction, per-track gain/pan/mute/solo,
   transport, seeking, loop, clip-offset presentation, track name/role edits,
   and persistent named mix snapshots.
5. Existing `api/media.php` restricts stems to authenticated Admin.
   Stem media links may not be made public or silently re-roled. No
   fan-facing download, vinyl production, Stripe, or publishing workflow is
   changed by this module.

## What is approximate or pending

- REAPER plugins such as ReaEQ, ReaComp, ReaDelay and ReaVerb are **detected
  and described**, with limited Web Audio EQ/compression approximations.
  The browser does NOT execute native REAPER VST/JS binaries or restore their
  exact proprietary/native parameter blobs.
- HTMLMediaElement multitrack playback uses drift detection and correction,
  not a guaranteed sample-accurate offline DAW render. Future sections should
  implement buffered clip scheduling, quantized transport, better waveforms,
  automation lanes, full effect controls and routing.
- The RPP text only contains media file references; audio must be separately
  uploaded. No source `.rpp` supplied by a user is checked into GitHub.
- This module is Admin-only; a public remix store or fan-access control is
  NOT part of Section 86A.

## Acceptance gates

The dedicated 86A CI checks importer structure and hard limits, safe file path
projections, private original persistence, named saved mixes, editing, stale
revisions, and integration with existing Catalog, Admin CSRF and Media Library.
Before merging also require final full PHP/JS regression tests, real MySQL
certification, merged-main verification and a ZIP with SHA-256.
