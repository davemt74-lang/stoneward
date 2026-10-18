# Artist World 88D3B — Supervised provider execution

Release **v1.3.115**, database schema **1.3.52**, additive migration **2026-10-18-043**.

## What works

- Server-side configured OpenAI provider: transcribes MP3/WAV/FLAC/OGG/WebM/MP4/M4A files (24 MB cap) with `gpt-4o-mini-transcribe`.
- Server-side configured OpenAI or Anthropic provider: describes still JPEG/PNG/WebP images (5 MB cap) as a **private machine draft**.
- All provider credentials come from existing encrypted Admin AI settings; requests use fixed HTTPS provider endpoints, no redirects, bounded response size and timeouts.
- Private capture must have human editorial approval, immutable media SHA256/size evidence, and separate `external_ai` rights clearance. The worker checks lease and clearance before and after outbound processing and rehashes local bytes.
- Per-day budget up to six bounded job attempts, one CLI job invocation, and a maximum three claims per job inherited from 88D3A.
- An interrupted or uncertain network/provider operation is **not** automatically replayed.
- Provider output is stored in the new private `story_inference_results` table as `pending_review`. An Admin editor can approve/reject it without publication. Raw data does not enter public content or general Agent Brain context.

## Set up and operate

1. Back up the installation and run the existing `upgrade.php` database upgrade.
2. Configure and enable the existing OpenAI (required for transcription) or OpenAI/Anthropic (for still-image description) provider with a server-side API key.
3. Review contributor rights in Artist World and separately approve `external_ai`.
4. Queue a specific private capture through `admin/api/story-inference.php`.
5. Explicitly opt into paid provider execution using authenticated Admin POST JSON `{"action":"configure_execution","enabled":true}`. Disable at any time using `false`.
6. Run **one supervised job** through the server's PHP CLI:
   `php scripts/artist-world-inference-worker.php --editor-id=YOUR_EDITOR_USER_ID`
7. Inspect private results with Admin GET `admin/api/story-inference.php?job_id=JOB_ID`, then Admin POST `{"action":"review_result","job_id":JOB_ID,"decision":"approved"}` or `"rejected"`.

The worker is intentionally **CLI-only** and not exposed as an HTTP handler. Admin POST controls use the existing authentication and CSRF protection.

## Important limits

Video transcription only works for supported short files below 24 MB. **Video scene/frame extraction is not yet implemented**; only still-image vision runs. Transcript timing is a clearly labeled whole-clip estimate, not real word timestamps. Returned descriptions and speech remain separate review records and are not automatically imported into the canonical storyboard/transcript, published, or added to a rendered film.

No live provider billing, HostGator environment, permission revocation mid-stream, or production load has been field-certified by the mocked CI tests. The fixed six-attempt daily cap and owner-controlled opt-in mitigate accidental cost but are not a monetary spending guarantee. A live, supervised pilot should verify provider access and hosting timeouts. Next: 88D3C editorial import, improved timestamp alignment, video frame extraction, and a native in-page review UI.
