# Stonefellow 86G — Private Mix Rendering & Mastering Lab

Version: 1.3.86. Scope: the existing Stonefellow Stem Studio and Admin Agent Brain only. No HomeServer changes.

## Use
1. In Admin Catalog open a track, then its existing REAPER Stem Studio.
2. Upload the private REAPER project/stem bundle and link any missing media.
3. Set track/bus/master controls, effects, routing and automation. **Save the mix first** to preserve a reproducible project state.
4. Choose **Render current mix · WAV**. The browser makes a 48,000 Hz stereo, 16-bit PCM WAV from the supported Web Audio graph, up to eight minutes.
5. Inspect the estimated BS.1770-style K-weighted/gated LUFS, sample peak, RMS, crest, stereo balance, clipped-sample count and approximate low/high-band proportions.
6. Upload a private WAV or MP3 reference (up to 75 MiB, subject to HostGator upload_max_filesize and post_max_size). Reference bytes are stored under the private `storage/stem-projects/references` hierarchy, not the public catalog. Use A/B to switch sources at matching positions and review comparisons.
7. Save measured evidence for the Admin Agent Brain. Evidence saves at an exact project revision with `measurement_provenance=browser_measured_not_server_verified`. The save advances the revision; further project edits make the previous measurement stale.
8. Ask Admin Agent Brain to review the private song ID. It receives aggregate metadata only, with no reference WAV, raw audio, private media URL, or native plugin binary.
9. For an over-high sample peak, the lab can prefill the existing governed Automation Workbench with a conservative master-gain suggestion. The owner must inspect the complete proposal, type APPROVE against its exact hash, then separately execute. No autonomous mix edits or public uploads occur.

## Important technical scope
- Rendering uses actual decoded linked private stems and browser equivalents of supported Web Audio FX with post-fader sends, folder parent routing, automation and stereo panning. It is **not** native REAPER playback and does not interpret opaque VST state.
- Enabled unknown FX, unsupported REAPER receives and missing stems are disclosed. Missing referenced media prevents a misleading complete export. Reverb impulse-length automation and unsupported plugin parameters are not silently guessed.
- LUFS uses K weighting, 400 ms measurement blocks, 100 ms hop, -70 LUFS absolute gating and a relative -10 LU gate. It is an estimate, not independent EBU R128 or true-peak certification. Sample peaks are **not** dBTP; crest factor is not loudness range.
- Broad tonal low/high percentages are approximate frequency-band energy estimates, not EQ prescriptions. The reference A/B system does not automatically loudness-match.
- Browser evidence can be tampered with by the client and is marked accordingly. Stonefellow enforces numeric bounds and project-revision association, but the server does not resample or certify full PCM measurements.
- Rendered WAVs stay in the browser until explicitly downloaded; **they are not published, licensed or attached to the fan-facing media library**.
- If HostGator's PHP upload limits are lower than the reference-file cap, raise them through the hosting control panel or use a smaller compressed reference.
- Large render buffers may exceed memory limits on older devices. No background/unattended processing is claimed.

## Certification
- PHP report validation, stale revision protection, private path/auth invariants, redacted Agent insights, original source integrity.
- JS Web Audio graph schedule, PCM WAV format, 48 kHz loudness/peak/clipping and mono handling, browser reference/Agent controls.
- Existing 86A–86F stem suite, full Stonefellow PR release suite, MySQL acceptance, exact revision merge, merged-main release checks and deploy ZIP SHA-256.
