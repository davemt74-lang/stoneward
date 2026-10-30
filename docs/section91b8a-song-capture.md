# Section 91B8A — Opt-In Song-Segment Capture (v1.3.175)

## Purpose
Stonefellow can listen to the specific portion of a **currently playing** published song when explicitly asked. This is not a playlist listening session, microphone dictation, lyric recognition, or automated research. Sections 91B8B–E will add lyric transcription, timed annotations, agent analysis, memory, and full acceptance.

## Voice and typed controls
- `Stonefellow, listen to this next verse` / `capture this chorus` / `transcribe this section`: start capturing the **player's audio stream** at its current offset.
- `Stop listening` / `Okay, that's enough`: stop capture, return song identity, source offset range and duration. Inform the user that the audio is ready for a later transcription step.
- `Are you listening to this verse?`: report capture state without starting another one.

The server explicitly chooses a `song_capture_*` action under the same authenticated, allowlisted agent policy. Capture actions are **independent** of `listening_session_start`, and album playback commands remain unchanged. Song capture is never started automatically by the microphone, page load, song playback, player errors, or a recommendation.

## Source, runtime limits, retention
The browser calls `HTMLMediaElement.captureStream()` (or supported prefixed equivalent) and `MediaRecorder`. The microphone is never opened. Recording is limited to **90 seconds / 20 MiB**, is bound to exactly one track, and is discarded on seek, track change, playback errors or closing the player. Pausing or reaching the end stops capture. Stop cleans up the capture stream without pausing the user's music.

The resulting Blob and metadata remain **in that browser tab's memory only**. No audio or transcripts are uploaded, persisted, or sent to an LLM in Section 91B8A. A page refresh discards it. The UI shows a visible **SONG CAPTURE** indicator during recording. Successful capture does not imply accurate lyrics; full transcription will require a separately configured speech-to-text provider in 91B8B and user review for uncertain words.

## Compatibility and safe failure
Media capture support is browser-dependent; devices that lack a player capture stream, whose media is tainted by cross-origin restrictions, or whose browser refuses MediaRecorder return an explicit unavailable result and leave playback unchanged. Actual browser playback and cross-origin media tests remain necessary after deployment; no untested browser is certified to support capture.

## Regression certification
`tests/section91b8a-song-capture.php` verifies spoken capture intent, separation from normal playback/listening sessions, client-state bounds, and LLM route registration. `tests/section91b8a-song-capture.cjs` runs the capture contract against a deterministic recorder, validates timecodes, in-memory retention, safe cancellation, unsupported browsers, and the existing player/router wiring. The Release Gate includes both tests plus PHP and JavaScript syntax checks.
