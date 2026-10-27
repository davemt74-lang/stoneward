# Section 90X — Echo-safe Agent speech continuity (v1.3.157)

## User behavior

- Public and Admin conversations continue speaking until their audio playback actually completes, the user clicks the manual stop control, or another speaker is acoustically confirmed.
- Speech Recognition alone is not allowed to truncate an Agent reply. Browser transcription often invents different words from an Agent voice played through speakers.
- A candidate interruption briefly pauses synthesized speech (and any locally played music in the public player). The shared microphone monitor verifies that playback **is actually paused**, then looks for sustained independent speech. If energy disappears, playback resumes at the paused position and any provisional transcript is discarded.
- If independent speech continues while output is paused, only then is the Agent interrupted. Recognition submits the participant's words, or returns to listening if the browser cannot transcribe them.
- If microphone acoustic monitoring is unavailable, the unsafe transcript-only interruption path stays disabled; the user can still stop playback explicitly.

## Verification

`section90l`, `section90n`, `section90o`, `section90p`, `section90q`, and `section90r` exercise full-response completion, public/Admin pause acknowledgment, echo-only transcripts (including a fake `stop`), cancelled pauses, confirmed speech, stale events, and microphone permission failure.
