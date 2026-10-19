# Stonefellow 88D3G4B — Supervised Video & Audio Crossfades

**Release v1.3.124 · Schema 1.3.61 · Migration 052**

G4B adds actual synchronized video and audio overlaps to the existing private, supervised FFmpeg pipeline. Editors select an explicitly authorized revision-scoped **dissolve** or **wipe-left** visual transition at 250 or 500 ms. Audio uses the matching triangular acrossfade. No transition is applied by default: existing hard cuts remain valid and backward compatible.

This controlled pilot supports 2–6 verified clips and 30 seconds of original source duration. Clips must be long enough for safe overlaps. Actual final movie time is shortened by one overlap for every adjacent pair. When a G4A licensed music bed is also selected, its audio duration and dialogue-duck offsets are recalculated for the shortened timeline.

Settings are private, subject to existing production editor authority, audited transactionally, and tied to an exact saved assembly revision. Settings cannot be changed once any render is queued for that revision. Existing source fingerprint, independent publication clearance, two music-use license checks and private output SHA-256 validation remain in effect.

The render still runs only via `php scripts/artist-world-private-render.php --editor-id=EDITOR_ID`, and output is reviewed through the existing authenticated G3 private player. No public download, publishing, social distribution or automated processing is introduced.

**Automated certification**: a real three-segment FFmpeg H.264/AAC render with two simultaneous visual/audio overlaps, duration verification, timed speech ducking with a licensed Stonefellow bed, secure source restrictions, activity-audit rollback, stale-revision/queued-job protections, MySQL 8 and legacy workflows.

Live HostGator FFmpeg availability, varied personal camera formats, auditory quality, and music licensing documentation remain field certification requirements. Future sections may add keyed transitions, per-cut editor parameters and export/publication, each separately governed.
