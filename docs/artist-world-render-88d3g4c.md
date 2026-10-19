# Stonefellow 88D3G4C — Synchronized Production Timeline

**v1.3.125 · Schema unchanged (1.3.61)**

G4C adds a metadata-only edit decision list (EDL) shared by the private Studio and the supervised FFmpeg render pipeline. It maps every saved source clip to final-output millisecond offsets, including real overlaps and per-cut fade/wipe metadata. The private Studio shows the resulting clip, cut, and dialogue windows next to the existing storyboard.

**Audio alignment:** Dialogue ducking now follows the audible start and end of overlapping clips instead of beginning only after the incoming video transition completes. Adjacent overlapping dialogue windows merge to prevent music attenuation being multiplied. Existing hard-cut behavior remains the default; music level, soundtrack rights and source integrity policies stay unchanged.

**Safety:** The timeline exposes only editorial metadata: no source filesystem paths, raw media bytes, URLs, downloads or public publication. This addition cannot enqueue/render media by itself. Rights certification, SHA-256 rechecks, opt-in owner policy, revision-locked mix/transition settings and CLI-only supervised execution remain necessary. Invalid editorial drafts show no EDL without failing the whole Studio page.

**Verification:** `php tests/section88d3g4c-synchronized-edl.php`; release workflow also syntax-checks the implementation and retains all previous G1–G4B gates.

**Limits:** This is an accurate synchronized *metadata* timeline, not a multitrack visual drag-and-drop editor or automated media export. Live HostGator codecs, human assessment of audio transitions, and music licensing evidence still need field validation.
