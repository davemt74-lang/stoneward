# Section 90D — Always-visible companion and music-aware voice conversation

The public Stonefellow agent floats independently of the dynamically replaced page
canvas and remains above the footer/player/chat popup. Its vertical position tracks
the actual footer and popup dimensions, including the live transcript. Artist
homepage configurations no longer hide the primary Agent Chat composer.

The configured server-side ElevenLabs voice remains the preferred spoken reply.
If that provider is unavailable, the device's built-in speech synthesis is used
only when it reports an installed **local-service** voice. This fallback sends no
information to another remote voice provider and does not charge ElevenLabs
Active Tokens. If no local-service voice is installed, text remains available
in the conversation instead of sending private replies to an unapproved service.
The Agent's talking animation and a
separate **Stop speaking · listen** button follow real playback state.

Listening mode remains selected across route changes and successive turns. The
microphone pauses while the Agent is thinking/speaking to avoid self-transcription,
and resumes automatically after completion or interruption. It never starts
without the user's microphone-button gesture. Microphone-permission and capture
failures stop the mode with an actionable error; transient recognition outages
back off before retrying.

Music continues to play while the Agent listens and speaks at quieter volume;
the user's original music volume is restored when conversation is stopped. To
avoid turning song lyrics into accidental queries, **say “Stonefellow” before
the first question while music is playing**. After the Agent responds, natural
follow-up questions work for an 18-second window without repeating the name.
When music is paused, no wake phrase is required. Browser speech recognition
does not guarantee perfect voice isolation; the wake rule is a protective gate.

## Acceptance

```sh
node --check assets/js/app.js
node tests/section89a-floating-agent-voice.cjs
node tests/section89a-runtime-gate.cjs
node tests/section90a-voice-transcription.cjs
node tests/section90d-ambient-agent-voice.cjs
```

Release-gate CI, the five required GitHub checks, and merged-source deployment
verification must pass before installation. On the hosted site, manually test
Chrome desktop and mobile microphone permission, music playing with wake and
follow-up questions, interruption, ElevenLabs and device-voice fallback, popup
open/close, and recovery after a recognition network interruption.

No database migration. The Agent's action permissions and approved data classes
are unchanged; this section does not grant any new LLM context or write tools.
