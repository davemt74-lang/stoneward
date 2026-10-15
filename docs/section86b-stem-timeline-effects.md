# Section 86B — Non-Destructive Timeline + REAPER FX

## Built inside the existing Stonefellow Stem Studio
Per-song song-editor lane, private Media Library stems, and source .rpp remain
unchanged. No parallel player, new login, Stripe or HomeServer integration.

**Clip controls:** Drag each timeline clip to move it, drag the trailing edge
to trim duration, or enter exact clip start, length and REAPER source offset in
the inspector. Turn beat snapping on/off; use the imported tempo. Each saved
edit has a revision check and cannot alter the clip's source filename, media
link, account rights or private master. **Undo/redo** tracks the most recent
20 clip/FX edits and preserves media attachments.

**Actual playback:** The existing Web Audio clock now drives a separate media
element per REAPER timeline clip for which an audio stem matching the source
filename is privately attached. It honors each clip's start, duration and
source offset, silences gaps/unmatched takes, and corrects drift above 80 ms.
It is not sample-accurate DAW rendering. Imported clips referencing stems that
were never uploaded remain visible but silent until their sources are mapped.

**FX:** Editable, bounded Web Audio approximations for ReaEQ
(frequency, gain, Q), ReaComp (threshold, ratio, attack, release), ReaDelay
(time, feedback, wet), ReaVerb (wet, decay). Native plugin names and unmodified
RPP source are retained. Unsupported VSTs are shown read-only and never run.
FX bypass honors each imported chain stage. These browser controls are not
reproduction of proprietary/native plugin presets or bit-perfect REAPER mixes.

**Workflow:** Admin → Catalog → Song → Stems & REAPER Studio. Upload the .rpp
and stems together with the existing ZIP importer or use separate uploads;
adjust clips and effects, review with real on-demand waveforms, save named mix.

**Safety:** Edits are song-scoped and CSRF/Admin guarded by existing endpoint;
all time/offset/FX values validated server-side; stale revisions rejected.
Undo/redo deliberately does not roll back prior media assets or licensing.
Media rights and public catalog playback are unchanged.

**Release gates:** Focused 86B PHP persistence and Node UI/FX acceptance;
all earlier 86A, 85B media and 85A rights regressions; full final PHP/JS and
real MySQL; merged-main verification; deploy ZIP + SHA-256.

**Field test:** HostGator with uploaded real audio still needs interactive
testing and audio quality review. Some earlier uploaded user files have expired
from this chat session, so source-specific tests may require reuploading the ZIP.
