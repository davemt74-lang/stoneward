# Stonefellow 86K — Per-Stem Audio Intelligence

**Version 1.3.90.** Extends Stonefellow's existing Admin Stem Studio and Agent Brain; no HomeServer changes.

## Workflow
1. Open a catalog song in Admin → existing Stem Studio; link its private source WAV/MP3 assets to the REAPER tracks.
2. In the **86K Per-Stem Audio Intelligence** panel, select **Measure private stems**. The browser retrieves up to 16 private media files sequentially using existing authenticated media URLs. Each file is capped at 30 MiB, decoder duration at 10 minutes, and the scan is abortable on Studio close.
3. For each linked track with a playable source clip, take up to eight 2048-sample Hann-windowed FFT frames distributed over the first 45 seconds of one clip (typically only ~0.34s of measured PCM at 48 kHz). Measure sampled source peak, RMS, crest factor, left/right RMS difference, active-window proportion and five spectral energy bands: 20–120 Hz, 120–500 Hz, 500–2000 Hz, 2000–8000 Hz and 8000 Hz–Nyquist.
4. Review track-level measurements on the same canvas. Click **Save bounded evidence to Agent Brain** to store *only measurements*, with no audio bytes.
5. The private server stores a revision- and source-signature-bound sidecar. Evidence saves do not increment the mix revision or invalidate 86G mastering analyses. Changing the project, media assignment or source fingerprint marks it stale.
6. Admin Agent Brain can now compare **measured** per-stem energy patterns with actual REAPER timeline clip intersections and flag *possible* competing bands when both tracks have at least 35% energy in the same band, active windows ≥15%, and at least 25% normalized time overlap. The model receives only numbered stems, allowlisted roles and bounded numeric data—never source file paths, track GUIDs, song audio, native plugin binary, music fan conversations or API keys.
7. The existing 86J Admin assistant can explain results using configured Claude/other Admin LLM, but **no automatic EQ, mix edit, DSP processing, mastering certification, public upload or vinyl license allocation** occurs. Any exact supported automation proposal still goes through the existing Admin hash approval and separate execution.

## Limits & interpretation
- Source-level FFT is **pre-fader, pre-pan, pre-FX** and not native REAPER processing. It cannot confirm audible masking, vocal resonances, clipping *after* plugin processing, or identify an instrument from sound.
- Eight short frames are sparse samples, not whole-song integrated spectral or loudness analysis. Silent portions and changing arrangements can evade sampling. The system explicitly shows observed sample count and source-limited coverage.
- User-provided instrument roles are mapped to a small allowed label set; no user-supplied text, asset UUID or untrusted instructions enter model context.
- Measurement origin is **browser_decoded_private_source_not_server_verified**. Browser reports can be altered and are not independent audio certificates. Testing on real audio, reference A/B listening and native REAPER comparison remain required.
- HostGator 30 MiB source cap applies to **each source scan**, not the separate 86I 100 MiB master upload limit. Large songs can be scanned by linking shorter consolidated stems.
- The sidecar stores a maximum of 24 verified tracks, but the browser currently scans at most 16 to bound device memory and processing.
- Server validation rejects unknown media assignments, duplicate GUIDs, stale revisions, nonfinite metrics, inconsistent bands and unsupported provenance. Sidecars are stored under the same private non-web-readable project directory as REAPER originals.

## Verification
86K PHP provenance, revision, sound-privacy and masking assertions; browser Node synthetic 80 Hz/4000 Hz/silence/mono FFT tests; 86J–86F Agent and mix release tests; 85B protected catalog media; exact-head MySQL and full release gate; green merged-main and exact-commit ZIP certification. Software acceptance is not live audio field certification.
