# Stonefellow 88D3G2 — Supervised Private Video Rendering

**v1.3.121**, schema **1.3.58**, migration **2026-10-18-049**.

This section executes an actual, short, privately stored MP4 draft using FFmpeg. It is not an unattended render farm, streaming player, automatic audio mix engine, editorial approval, or publishing workflow.

**First production-pilot limits:** 1–12 certified timeline clips, up to 60 seconds total and 20 seconds per clip, output normalized to 1280×720, 24fps, H.264 + stereo AAC. Hard cuts only. A video scene includes its recorded audio if an audio stream exists, otherwise silence; still images use the editorial frame duration and silence; audio/transcript and Stonefellow music cues use a black background with their audio. Audio music overlays, crossfades and full multi-track mixing are deferred to a later section; the pipeline does not falsely describe these hard cuts as blended audio.

## Governance and execution

The user must individually approve contributor publication rights, separately approve music synchronization/master-use licenses, and independently bind a canonical Stonefellow song to a SHA-256 verified private audio master at the current assembly revision. 88D3G1 re-verifies source data and file hashes when the render is queued, before each segment, and after concatenation.

The Admin Storyboard now exposes a song master picker, **Enable private renderer** (off by default), **Queue private render**, and sanitized job status. These controls are authenticated and CSRF guarded. Clicking Queue does not call FFmpeg; it creates at most one job per exact assembly revision, with a maximum of three render jobs per UTC day. No automatic retries occur for interrupted jobs.

A privileged operator executes **one** queued render using:

```bash
php scripts/artist-world-private-render.php --editor-id=EDITOR_ID
```

All FFmpeg/ffprobe invocations use structured argument vectors, fixed known executables, no shell expansion, bounded subprocess runtime and output limits. Temporary files use a private workspace. Final MP4 drafts and SHA-256 identifiers are recorded in `story_render_jobs`. The default private directory is `dirname(SF_ROOT)/stonefellow-private-renders`, which must be outside the publicly served application tree. A configurable `SF_RENDER_PRIVATE_DIR` constant may point to another secure absolute directory. The process fails closed if it cannot establish a private output root.

**No public or Admin HTTP route currently serves private video bytes or absolute filesystem locations.** Completed outputs are marked `private_review`; they must pass a separately built editorial playback/review gate before download or distribution is even considered.

## Certification

Automated acceptance includes a genuine FFmpeg-generated MP4 and WAV source and one normalized MP4 output with playable video/audio, output SHA-256 verification, file containment outside the web application, duplicate prevention, consent cancellation, tamper detection, no retries, privileged opt-in, MySQL schema validation and full legacy regression gates. HostGator CLI/FFmpeg hosting limits, actual contributor media codecs and a human playback quality review still require field certification.

**Next section 88D3G3:** secured private output playback under existing Admin authorization, editorial approval/rejection, stale-source revocation, and no-publication guarantee. Then separate formal publishing controls.
