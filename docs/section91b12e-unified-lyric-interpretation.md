# Section 91B12E — unified lyric listening and interpretation

Stonefellow build 1.3.194.

## Listener workflow

1. Play a published track in the existing player; the floating particle orb is the only agent surface.
2. Choose **Listen & Interpret** on the orb. This authorizes timed player-audio clips to be sent to the configured OpenAI transcription provider. The sidebar displays provisional, unverified lyric text while listening. Playback is not stopped by listening.
3. Choose **Stop listening**. The final captured clip is transcribed once, replacing provisional text. With this explicitly chosen workflow the agent automatically requests one interpretive report using published Stonefellow story and writer notes, credits, songbook annotations, public-agent-approved knowledge, and optional consented user listening insights. This is not a claim to have verified lyrics.
4. Ask follow-up questions, make a manual correction, or explicitly save a private note. Future agent-brain ingestion remains an independent user choice.
5. **Record Clip** remains separate and local: it does not enable network live captions or automatically trigger analysis. The user may decide to transcribe its saved browser clip afterward.

The exact published track ID controls the song-specific context; unpublished catalog tracks, story drafts, and knowledge files not marked `public_agent` are excluded. Reports identify the sources actually supplied and distinguish documented writer intent from the model's interpretation. The original clip is in browser memory, never automatically published, saved, or added to Agent Brain.

If OpenAI audio transcription is disabled in Admin → AI Providers, the UI retains the captured clip and displays the actionable provider failure. A configured conversational AI provider and sufficient Active Tokens are also required for the interpretation pass.

## Canvas UI

- `+` opens a single horizontal row of small links along the top-left mesh canvas, not a boxed menu. Existing destinations, keyboard focus, outside click and Escape remain.
- Each link has a tiny pulsing LED and meter-style underline, respecting reduced-motion settings. The row is horizontally swipeable on smaller screens.
- The orb keeps its minimal, auto-hiding music player below and the lyric session panel to the side on desktop. Listen & Interpret, Record Clip and Stop live beside the orb, not in the main music player.
- Public and admin agent styling is unchanged outside this scoped feature; no new duplicated audio element or chat pop-up is introduced.

## Certification

Tests: `tests/section91b12e-lyric-interpretation.cjs` and `.php`, along with the existing 91B11 and 91B12 regressions. PHP syntax and integration suites run on the release-gate workflow. No production deploy or database migration is implicit in this PR.
