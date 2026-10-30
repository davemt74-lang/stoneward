# Section 91B7 — Album Playback + Listening-Time Intelligence (v1.3.174)

## Real-world regressions addressed
- “Play something from Happier Than Hey” must recognize the published release **Happier Than Hay**, select a playable published song from that release, and request actual browser playback.
- “Choose any song from the Happier Than Hey album and play it” must call the player rather than navigate to Releases.
- “Play another song from that album” resolves the previous album mention before relying on a possibly unrelated current track.
- Named-song playback such as “Play Genius of Unhumankind” and full album listening sessions retain their existing tools.

## Execution and authority
The language model proposes music intent. An explicit album-selection request overrides an erroneous browse/named-song classification. A bounded server resolver matches only published, playable release tracks with unambiguous titles. The browser independently verifies the exact song ID, title, release and audio URL from its own catalog before calling its existing audio player. Browser `audio.play()` must succeed before a 'start' event is logged; browser autoplay blocks remain possible and are reported without false success.

## Historical listening intelligence
Existing `listening_events` already record each successful start, pause, resume, skip, completion and periodic progress with an authoritative UTC `created_at`. No duplicate event store or database migration is needed. The chat client now sends its IANA time zone (e.g. America/Phoenix); the server converts the most recent 180 days of consented **start** events into local morning (05:00–11:59), afternoon (12:00–16:59), evening (17:00–21:59) and night (22:00–04:59) patterns. A bounded summary of real tracks, counts and recent timestamps is available in music-related LLM conversations. Listening recommendations gain a small time-of-day affinity boost. Album selections avoid the current track where alternatives exist and favor tracks not repeatedly started in that daypart.

The time zone is interpreted at request time, so historical playback from a different geographic zone may be classified using the listener's current zone. Event timestamps remain authoritative UTC; this section does not infer a user's location. Future travel-aware history can store the playback-time zone per event with a separate consent-reviewed migration.

## Privacy
Only the authenticated user's events can influence their agent. Both listening-history and personalization permissions must be enabled. Clearing listening history removes the user's association, so the agent cannot retrieve it. Missing legacy databases, a disabled preference, or invalid time zone produce an empty insight instead of breaking the player. Only published song titles and bounded historical counts—not private masters or another account's history—enter LLM context.

## Certification
`tests/section91b7-album-time-insights.php` exercises voice-album matching, follow-ups, authorization, local dayparts, and context. `tests/section91b7-album-time-insights.cjs` verifies the browser release receipt, playback-event timing, and timezone wiring. The Release Gate runs both tests plus PHP syntax. The merged source deployment remains one full root-level ZIP. Post-deploy browser audio and configured-provider behavior require a live acceptance test.
