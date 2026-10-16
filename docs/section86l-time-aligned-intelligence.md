# Stonefellow v1.3.91 — Section 86L: Time-Aligned Mix Intelligence

86L extends the EXISTING Admin Stem Studio and Agent Brain. It does not add a separate mixer, HomeServer service, third-party audio processor or public Agent access.

## Why this is more than a visual overlay

- The browser actually decodes up to 16 **linked private audio sources** from existing authenticated media URLs, at 30 MiB per source maximum.
- A real 2048-frame Hann FFT analyzes each channel from the source offset corresponding to **the same REAPER project-clock time** across tracks, accounting for the imported item's position and source offset. Tracks that have no playable clip at that exact moment are not compared. Overlapping ambiguous takes on a single track are deliberately skipped.
- The full-song pass scans up to 120 distributed time points across up to eight minutes; a 12-second deep-focus pass samples every 0.5 seconds. The UI shows explicit sampled intervals and number of stems, not a pretend continuous waveform analysis.
- Stores RMS and five **absolute frequency-band levels**, not only band percentages. Only simultaneous, active raw-source energy above conservative band/RMS gates and within 8 dB is flagged as potential competition. Silent, offset or mutually exclusive tracks do not create masking alerts.
- Independent strong events are distinguished from **repeated consecutive time-aligned passages**. Show music time ranges and clickable timeline event markers, plus jump-to-audition and focus-this-passage actions.
- Sidecar history keeps the latest three scans with mix revision, signature, media fingerprints, shared timing grid and provenance. Evidence saving doesn't change the mix revision, and any change to media assignments or REAPER mix invalidates old findings.
- Admin Agent Brain receives only sanitized stem numbers/allowed roles, the measured times, band levels and review caveats. No original REAPER files, VST binary, track GUID, private music URLs, audio PCM or fan data go to the LLM.
- 86J production assistant reads real passage findings and explains what to audition. It **does not automatically cut EQ**, guess instrument identity, manipulate the mix, publish masters, or grant vinyl licenses; any future exact automation actions still use the existing typed hash-approval workflow.

## Use

Open Admin → Catalog → Song → Stem Studio. Link stems as usual. Open **86L Time-Aligned Mix Intelligence** and select **Analyze full-song timeline**. For each potential overlap, click a marker to seek the current mixer or **Inspect this passage in detail** for the 12-second / 0.5-second scan. Listen at that point, consider the mix context, and ask the adjacent **86J Agent Brain Production Assistant** for a grounded explanation. Refine the project or use its existing governed change proposals; rescan after edits.

## Important accuracy boundaries

This measures short samples of decoded *raw source clips*, before track FX, routing, panning and mastering. It does not implement native REAPER processing or import item playback-rate/fades not already handled by the existing importer. It cannot prove psychoacoustic masking, assess true-peak compliance or replace ears and native project A/B. The client is untrusted: the server validates timestamps, media assignments, source fingerprints and metric bounds, but marks browser measurements **not independently verified**. Sparse scans may miss quick transients between grid points. Do not publish or alter entitlements based on these estimates.

## Certification

Focused PHP deterministic same-time matching, onset offsets, silence suppression, passage grouping, revision and media checks; Node real 80 Hz/4 kHz PCM window tests, mono/ambiguous overlap, focus/overview timing and UI constraints. All older 86K–86F, Media Library publishing, MySQL and full-site regressions must pass on the exact PR revision and merged main before releasing the complete ZIP and checksum.
