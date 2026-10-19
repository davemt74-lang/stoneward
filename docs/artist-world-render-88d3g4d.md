# 88D3G4D — Authenticated Private Video QC Cues

**Stonefellow v1.3.126 · existing schema 1.3.61 (no migration)**

G4D extends the current G3 authenticated rendered-video player with an opt-in **verified QC cue sheet**. A production editor can load time-aligned scene, dialogue, licensed music, and transition markers next to a private MP4. Clicking a marker seeks the **same authenticated video element** to the intended output time; playback updates highlighted cues and its clock.

## Exact private-output gate

The endpoint `admin/api/story-render-review.php?action=cues&job_id=...` requires Admin authentication, production-editor authorization, an eligible rendered-job state, current assembly revision and certified render source, *and* a fresh full SHA-256 rehash of the finished private MP4. Only after these conditions pass does it calculate the canonical G4C EDL for the exact job revision. The returned data is restricted to short labels, cue types and millisecond positions: no private paths, source IDs, hashes, raw media or contributor identities. Responses use private no-store caching. Rejection, rights revocation, changed revision or output tampering blocks access.

## No automatic publication

The reviewer cannot bypass human editorial approval or distribute media. Rendering remains opt-in, server CLI-only, and separately rights-gated. The QC sheet cannot serve videos or public downloads.

## Acceptance

`php tests/section88d3g4d-review-cues.php` checks permission isolation, independently verified output hash and revision, licensed audio/video overlap alignment, sorted cues, rejected outputs and non-leakage. `php tests/section88d3g4d-release.php` checks the release feature, protected route, UI binding and CI wiring. Existing G1–G4C/legacy/MySQL/FFmpeg CI remain required.

HostGator/browser playback, varied camera formats and human listening QC still require field verification.
