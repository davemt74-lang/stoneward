# Stonefellow 88D3G4E — Per-Cut Transitions and Editable Production Timeline

**Release v1.3.127 · schema 1.3.62 · additive migration 053**

G4E extends the private G4B/G4C production timeline with individual transition controls for each boundary in an already approved, revision-locked assembly. An authorized editor can choose **dissolve** or **wipe-left** and an independent **250/500 ms** simultaneous video/audio overlap at each cut. The original global setting remains the default when no per-cut override is saved, preserving all existing projects.

The Studio displays per-cut selection controls alongside the synchronized metadata EDL. Saving validates the complete current source manifest, verifies editor privileges, checks that every boundary is set and both adjacent clips have safe overlap handles, and commits the complete preset with a transactional activity audit. No revision changes or reconfiguration are allowed after the first render is queued.

A successful per-cut edit remains private and does not trigger FFmpeg. Rendering still requires explicit owner enablement, independently certified source files with SHA-256 verification, current music synchronization and master-use rights, CLI-only one-shot execution, and separate human review. The final output is not published or downloadable.

The worker uses independent per-boundary offsets in its actual `xfade` and `acrossfade` commands. The same EDL provides exact final duration, transition positions and dialogue-duck windows to the licensed soundtrack mixer and private QC player. Re-saving a global preset atomically clears custom cuts. A new assembly revision invalidates prior customizations.

**CI:** `tests/section88d3g4e-per-cut.php` exercises real three-clip FFmpeg transitions of two distinct styles and overlap durations plus ACL, rollback, source-rights revocation, stale revision, queued-render lock, EDL and soundtrack timing. The release contract test verifies the schema, API, Studio and private worker integration, while the full previous MySQL, packaging and regression workflows still apply.

**Field qualification still required:** Visual/audio quality and FFmpeg availability on live HostGator deployment, actual diverse user footage and human licensing evidence.
