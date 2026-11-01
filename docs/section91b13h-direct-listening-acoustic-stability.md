# Section 91B13H — Direct listening and acoustic stability

Version: 1.3.200

## Behavior

- Typed and original voice-recognition commands such as "listen and interpret", "listen and interpret the lyrics", "start live transcription", and "stop listening" (during a song session) dispatch through the existing browser-owned listening skill. They do not depend on the general Agent chat endpoint or any conversational LLM route just to begin listening.
- Exact analysis requests after a reviewable lyric draft use the dedicated song-analysis endpoint. Only the returned interpretation is offered to Agent speech. Credits, time offsets, draft provenance, source labels, and other metadata remain in the side panel.
- Starting listening does not silently start Record Clip or upload a recording. Only browsers with documented player-track recognition support can start the live song listener. Unsupported mobile Safari receives a specific message instead of a generic Agent-unavailable error.
- When music is playing, microphone speech onset alone does not duck the player. The original conversation recognizer first needs a recognized "Stonefellow" wake phrase; unrecognized song vocals and Agent speaker echo leave music volume alone. During music playback, the wake phrase also gates turn submission, including the immediate follow-up window after Agent speech.
- Automatic acoustic barge-in is suspended while the public Agent speaks. Even a transcript-gated interruption detector can misinterpret its own speaker output and repeatedly pause TTS/mute the player during verification. The existing **Stop speaking** control remains available for immediate interruption; the normal mic recognition resumes after speech ends.
- Speech synthesis and the optional Speech Engine hold the current duck level for 220 ms between adjacent speech chunks, avoiding unnecessary volume pumping. Completed or cancelled output immediately restores the listener's chosen level.
- Listen & Interpret shows the blue listening state, keeping Record Clip's distinct recording state.

## Acceptance

1. Play Earth Girl and send "listen and interpret" while general Agent chat is unavailable: player-track recognition starts locally when supported; no 503 from agent-chat.php is needed.
2. Stop listening: an unverified draft appears with the pencil editor. No analysis runs until explicitly requested after review.
3. Request "interpret lyrics": the dedicated LLM analysis runs; the Agent speaks only the interpretation.
4. Leave music playing with the mic armed: song vocals alone do not change playback volume.
5. Have the Agent speak a multi-chunk answer: volume stays ducked through short chunk gaps, then restores after output ends.
6. Confirm the microphone interruption monitor does not run during Agent speech and that the manual Stop speaking control still works. After the reply finishes, ordinary voice recognition resumes.
