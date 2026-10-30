# Section 91B8C — LLM-Driven Song-Section Interpretation (v1.3.177)

## User-triggered experience

1. Play a published song, say `listen to this verse`, then `stop listening`.
2. Say `transcribe what you heard`; Stonefellow returns an **unverified** lyric draft tied to the song and playback offsets.
3. Say `analyze this verse`, `what does that line mean?`, `research the references in those lyrics`, or `give me a detailed report on that section`.
4. The customer agent chooses an allowlisted `song_capture_analyze` action. A separate authenticated request then passes the draft, the selected user question, verified published-song context, and a user-consented listening summary to Stonefellow's configured conversational LLM.
5. The report is displayed and optionally spoken by the same agent, with a source note identifying what was inferred from an unverified draft and what was present in approved records. No source audio is uploaded for analysis. The transcript and report remain in browser memory; annotation persistence is planned for Section 91B8D.

## Privacy and safety

- External LLM data transfer happens **only** after a specific analysis request, using the user's approved data scope. Transcription alone cannot automatically start interpretation or research.
- Server validates signed-in session, CSRF, unique published song identity, and 0.5–90-second user-claimed playback interval. The browser never sends another listener's history; the server retrieves only that user's opted-in listening summary.
- Transcript text is treated as **untrusted, unverified input**. No arbitrary transcript instructions gain system authority, and no provider may directly execute player, catalog, purchase, ticket, or vinyl actions.
- Existing Stonefellow approved knowledge may ground artist/release information; **live web search is not connected** in this section. The model must not invent external sources or claim lyric/artist facts it cannot verify.
- The report does not imply the user has reviewed lyrics or that any lyric annotations have been saved. Browser clip and report are invalidated on track change, seek or a new capture.

## Certification

New PHP tests cover bounded requests, public-catalog identity, rejection paths, prompt injection boundaries, provenance, and server routing. JS tests simulate authenticated report transport, no automatic egress, missing draft, stale clip, safe retry, and skill bypass. Both suites plus syntax checks run in the Release Gate, in addition to the existing playback and transcription regressions. End-to-end provider quality and playback-source behavior remain deployed acceptance tests.
