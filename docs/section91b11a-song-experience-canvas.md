# Section 91B11A — Persistent Song-Section Conversation (v1.3.188)

The previous UI appended a text notice for the lyric draft and returned the
analysis as a transient Agent reply. After Stop Listening, the capture controls
disappeared, and the full My Agent Chat route had no access to the in-memory
transcript or report.

## New flow

1. **Listen to this verse:** a song-session card opens in the existing Agent
   popup, identifying the playing track and timestamp. Stop Listening remains
   available while recording. The normal music player remains unchanged.
2. **Stop listening:** the same card changes to *Captured* and offers an explicit
   **Transcribe excerpt** action. Stop never triggers transcription by itself.
3. **Transcribe:** the card retains the automated words as an **unverified
   lyric draft**. Failures display a retry opportunity while the capture remains
   available. This action alone never calls the conversational LLM.
4. **Analyze excerpt / Ask Agent:** only a deliberate user action sends the
   draft and question to the existing authenticated interpretation endpoint.
   The report, its source qualification, and subsequent user-requested analyses
   appear in the card in order; the standard Agent response stays in chat.
5. **Open My Agent Chat:** a full-width copy of the same live card appears on the
   main chat canvas above the existing private Agent conversation, including
   transcript, full reports and next-question form. Navigation between views
   uses one in-browser state object, without duplicate backend orchestration.
6. **Save private note:** the existing authenticated private annotation flow
   is the only storage path; it does not automatically share with Agent Brain.

## Privacy / lifecycle

- Transcription is explicit; interpretation is separately explicit. Reports
  are grounded in the unverified draft and approved Stonefellow context; the
  app does not claim to perform live external research.
- Transcript and reports remain in current-page browser memory, including
  when navigating between popup chat, main chat canvas and player. Reloading
  does **not** persist them without the user selecting Save Private Note.
- Track changes and seek invalidate the active draft for new analysis, but a
  previously displayed report remains legible until a new capture begins or
  the page is closed.
- All lyric and analysis text is rendered as DOM text, not injected HTML.

## Certification

`node tests/section91b11a-song-experience.cjs` verifies each transition and
control, multiple report history, safe text rendering, main-canvas integration
and no automatic persistence/provider requests. Legacy capture, transcription,
analysis, private-annotation, chat and voice regressions remain in Release Gate.
