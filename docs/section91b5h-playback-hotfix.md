# Section 91B5H — Verified Named Playback Hotfix (v1.3.171)

## Live incident

- “Change songs two hands up”: the Agent knew the song but did not issue a real player command.
- “Play genius 7 humankind”: the Agent said “Playing Subtle Surrender” with no attempt to load the new requested audio.

## Correction

1. PHP detects explicit named-playback requests before the JEV/LLM route.
2. The policy resolves only actual published catalog matches. The active track can be used only when the user explicitly says “this song”, “play it”, or similar.
3. Unmatched or ambiguous titles never become a guessed play action; the existing playback remains unchanged.
4. The public browser independently validates the requested title against published track IDs, replacing any mismatched backend action before dispatch to its active audio player.
5. Playback success requires the requested track to be selected and the browser audio element to be loaded and unpaused after `play()` resolves.
6. The shared parser covers “change songs”, “change the song to”, “switch to” and ordinary play/listen phrases, retaining prior generic listening and session commands.

## Verification

- `node tests/section91b5h-playback-intent.cjs`
- `php tests/section91b5h-playback-intent.php`
- Existing Node/PHP regression suites and full GitHub Release Gate.
- After deployment, test **both exact live phrases** in public Agent Chat. Confirm the footer player switches to “Two Hands Up” if published; confirm an unmatched title never restarts “Subtle Surrender” or claims playback.
- Also test a title with no audio, a duplicate title, and browser autoplay rejection. Do not mark live device acceptance complete until these are observed.
