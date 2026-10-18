# Stonefellow 88D3C — AI Production Review Studio

**Release v1.3.116 / database target 1.3.53**, migration `2026-10-18-044`.

## Editor workflow

The Admin navigation now contains **Agent & Knowledge → AI Production Review**. Editors can review private source captures, read separate rights statuses, enter independently verified evidence for `external_ai` or `publication`, enable/disable supervised AI execution, enqueue a private capture, monitor results, manually recover/retry interrupted work, approve or reject a provider response, correct text and timecodes, and import reviewed results as separate private canonical drafts.

An approved AI result is **not** verification of its factual accuracy or people/likeness rights. Imported canonical transcript or scene records have `origin=machine_draft` and `review_status=draft`. A further independent editorial verification is needed before any graph can cite them as verified provenance. **No automatic publication or video rendering.**

### Precise timecodes

Audio transcription from 88D3B is still a whole-clip approximation and does **not** magically provide word-level timestamps. Editors now must explicitly supply reviewed start/end timecodes per transcript segment, constrained by measured media duration; no guessed word timings are generated.

For short, eligible MP4 videos, if `/usr/bin/ffmpeg` or `/usr/local/bin/ffmpeg` exists, the supervised CLI worker extracts a private JPEG frame near the first third of the video (up to 3 seconds). The returned scene draft records the exact sample millisecond, a narrow bounded sampled interval, and `timing=single_sampled_frame`. This is **one observed frame, not continuous scene interpretation**. The temporary JPEG is deleted whether the provider request succeeds or fails. The worker checks the original video's SHA-256 and existing independent `external_ai` clearance both before and after the provider request. If FFmpeg is not installed or fails, the operation remains interrupted, requiring manual review; it must not silently invent a scene.

### Canonical draft import and protection

- Import requires production-editor authority, approved private AI result, completed inference job, immutable source evidence match and current independent external AI clearance.
- Transcripts require 1–300 validated segments, chronological nonnegative timecodes, and a known media duration. Scene descriptions and actual still/video timecodes are validated as well.
- One `story_inference_imports` mapping per job guarantees idempotent import. A canonical draft, mapping and activity audit are committed transactionally; failures roll back.
- The reviewed source draft remains private; it is not silently verified or published.
- Permissions are purpose-scoped; external AI clearance is not publication clearance.

### Installation

Backup and use the existing `upgrade.php` procedure. Migration 044 creates the private import ledger. The AI worker still runs explicitly via `php scripts/artist-world-inference-worker.php --editor-id=EDITOR_ID`, with provider execution disabled by default until an editor opts in.

### Certification limits

Automated regressions exercise import authorization, idempotency, rights revocation, timestamp boundaries, rollback and the Admin wiring. Actual paid API usage, FFmpeg on the target HostGator server, precise speech alignment, multi-frame video understanding, video synthesis, and end-to-end live browser compatibility **require field verification or a later section**. PHP/cURL/FFmpeg compatibility differs across shared hosting plans.
