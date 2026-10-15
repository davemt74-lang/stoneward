# 86C — REAPER-matched first-party plugin rack

## Purpose
Recreate the prior Stonefellow browser plugin *architecture* inside the
current site — without a second uploader, standalone login, media library or
HomeServer integration.

## RPP metadata: exact vs approximated
The source REAPER project is stored **byte for byte** as a private .rpp.
Imported master/track chains preserve **original chain order**, FX GUID,
format (VST/JS), module, vendor, plugin identity, enabled/bypass state and
`PRESETNAME`; serialized VST state blocks receive SHA-256 provenance
checksums, rather than guessed numeric parameters. JSFX slider tokens are
copied exactly as serialized text. Native opaque binary state is **not**
claimed to be decoded to ReaEQ/ReaComp/ReaVerb/ReaDelay controls.

## Current first-party plugin types
- Single-peak EQ, 5-band parametric EQ, compressor, reverb and delay: bounded
  browser processing controls and bypass.
- Master limiter: compressor/high-ratio approximation and output ceiling
  trim, NOT true-peak or oversampled REAPER limiter processing.
- Original native plugin instances remain in the rack and can be bypassed,
  reordered for browser listening or given separate bounded browser
  parameter overrides. The imported REAPER .rpp source remains unchanged.
- New Stonefellow-native plugin instances can be inserted, moved, configured
  and removed. Native REAPER plugins cannot be deleted from the rack.
- Master bus is now actually processed by an independent Web Audio FX
  chain before sound reaches the destination (prior 86B code merely
  displayed `master_fx` without processing it).

## User reference fixture
The user-uploaded archive
`05-Luna - Organic Beat 04 (Synth)-consolidated.zip` contains
`earthgirl-consolidated.rpp` and 13 consolidated MP3 files. The RPP has
13 tracks, BPM 122, 4/4, 44.1 kHz, and **38** plugin instances:
14 ReaEQ, 12 ReaComp, 8 ReaVerb, 3 ReaDelay, and one JS
`loser/masterLimiter`. The master chain contains the limiter,
ReaEQ and ReaComp; named presets include
`stock - Master Bus Glue`, `stock - Modern Vocal`,
`stock - steady rock kick`, `stock - Spanky Bass`, and
`stock - BackGroundVocals`.
User audio and RPP data are NOT added to GitHub or the deployment ZIP.
The repository has small synthetic fixtures with matching token structure.

## Safety and acceptance
Project edits are Admin/CSRF authorized through the existing stem-studio
endpoint and revision controlled. No changes to public song playback,
licensed masters, private media ACLs or manufacturing. Browser DSP should
be auditioned with the user's real audio, not described as sound-exact.
Full PHP/Node focused and final release gates, MySQL and merged-main ZIP
must pass before delivering 1.3.80.

## Next recommended build
86D: optional verified ReaPlugs native-state **parameter decoders**,
using documented/reverse-verified format versions, plus REAPER round-trip
tests. Do not claim exact numeric settings from opaque binary state until
schema-specific decoding is proven by known-source fixture comparisons.
