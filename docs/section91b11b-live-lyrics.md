# Section 91B11B — Explicit live lyric drafts and final transcript handoff

## What a listener experiences

1. Play a **published** song and say **“listen to this verse”**. The player records a bounded excerpt locally. No audio is uploaded.
2. While recording, choose **Start live transcription** on the shared song card, or say **“start live transcription”** / **“transcribe live.”** The card explains that short player-audio clips are uploaded to the configured transcription provider and metered.
3. Every ~10 seconds, a fresh browser `MediaRecorder` constructs a complete audio container from the *player's audio stream*, not from the microphone. The provider returns an unverified provisional lyric block. Blocks appear in the floating conversation and the **My Agent Chat** canvas as available. This is **near-live, chunk-by-chunk**, not word-by-word synchronized captions.
4. **Stop listening**, seek, song-switch, and player pause/end stop the capture. A **normal stop** after live opt-in initiates a second full-clip transcription request to reconcile and replace all provisional captions with one unverified draft. No analysis occurs automatically.
5. The listener can correct, request analysis, ask follow-ups and explicitly save a private note through the existing 91B11A unified song card. The report remains visible in that card across popup ↔ full chat navigation.

## Consent and limits

- Default capture is local-only. Only an explicit live user action enables incremental uploads. Server policy refuses model-inferred live activation without explicit command wording; normal after-stop transcription still requires a separate request.
- Clips belong to the current authenticated user and published track; each has bounded duration/size and passes the existing `agent-song-transcribe.php` CSRF, publish, MIME, provider and metering checks. No new provider key or gateway was introduced.
- A separate short recorder shares the original media stream. Its independent WebM/MP4 segments each contain their own container headers; **partial timeslice blobs are not sent as standalone files**.
- One request in flight, at most two queued segments; older previews are dropped when the provider lags. The final full-clip pass is authoritative. Stale responses are discarded after stop/seek/track change and cannot replace the final draft.
- Interim lyrics and the recorded Blob stay in browser memory, not Agent Brain or listening history. There is no live automatic analysis, saving, research or private-credential access. The existing full-draft consent flow remains in effect.
- A browser unable to run a second `MediaRecorder` can still perform the original local capture and later transcribe the full clip. If a preview fails, the final recording remains usable.
- The client has to remain open for the full-clip reconciliation. If the provider returns an error, the local full clip and provisional text remain for a manual retry. Transcriptions are estimated, especially over music; no word-level timestamps or verified lyric claims.

## Regression checks

- `node tests/section91b11b-live-lyrics.cjs`: opt-in, independent segments, timing, stop/discard, fallback, queue bounds, CSRF transport, stale responses, card safety.
- `php tests/section91b11b-live-lyrics.php`: explicit routes, model policy consent, duplicate/no-playback blocks, normalized client flags.
- Existing 91B8A–91B11A gates and full repository checks remain required before merge and deployment.
