# Section 91B13B — Listen & Interpret without recording

Release: Stonefellow 1.3.196

## Behavior

- **Listen & Interpret** starts a temporary browser speech-recognition session against the current published song player `MediaStreamTrack` (`SpeechRecognition.start(audioTrack)`).
- Only desktop Chromium browsers with documented player-track recognition support are permitted: Chrome/Edge 135+ or Opera 120+. Older browsers and mobile/Safari/Firefox show a clear unsupported-browser message. **Never silently fall back to microphone recognition**, because those browsers may ignore the supplied audioTrack.
- The browser's speech service may process audio on a remote service. Stonefellow does not make or retain an audio Blob, send a clip to `agent-song-transcribe.php`, or upload a clip through its own backend for this mode.
- Interim words update the orb lyric sidecar. Stop (or song end/pause or the 90-second limit) closes the recognition session, creates an *unverified text-only draft*, and (for the Listen & Interpret button) sends that draft to the configured chat LLM for songbook- and knowledge-grounded interpretation. Claude is supported for this stage via existing `sf_ai_llm_candidates('chat')`.
- Playback seek, source switch or player error discards the recognition session so lyrics from different positions/songs cannot combine.
- **Record Clip** remains a distinct `MediaRecorder` feature. Its retained browser-local clip can still be transcribed *only after an explicit separate request* through the existing OpenAI audio endpoint; this provider requirement does not apply to Listen & Interpret.
- Create Record remains full-page `?view=builder`, never embedded in the left Agent Canvas page rail.

## Privacy and limitations

The browser's online speech recognition provider is independent of the site-configured Claude chat provider. Audio processing, quality, permissions and availability follow the browser's speech service policies. Song vocals are substantially harder to recognize than spoken dialogue; all results are clearly marked unverified. This feature cannot promise a transcript for every song. No live clip is retained by Stonefellow.

## Verification

The release-gate invokes `tests/section91b13b-player-track-listening.cjs` plus updated 91B11B/91B12E regressions, checking player-track input, no microphone fallback, text-only drafts, separate Record Clip, independent interpretation and builder routing. Live smoke testing should use Chrome/Edge desktop, a published playing track, and a configured Claude chat provider; then verify no request to `agent-song-transcribe.php` appears for Listen & Interpret.
