# Section 89A — Floating Music Agent and Conversation Popup

## User experience

- A CSS-animated companion floats above the sticky chat bar on desktop and mobile. Its listening, processing, and speaking animations follow the real runtime state; it remains visible on the artist homepage and builder.
- The new Chat button in the bar opens and closes a scrollable conversation popup immediately above the composer. The panel is closed by default, supports Escape/Close, shows recent owner-scoped history, and offers New chat.
- Typing and voice conversation submit through the existing authenticated `agent-chat.php` path. Text replies and errors appear both in the floating caption and in the popup transcript; closing the popup does not stop voice mode.
- Speech recognition processes one final transcript at a time. It does not restart during the Agent request or ElevenLabs playback and resumes only after completion. The voice indicator becomes Listening only after microphone recognition actually starts.
- Voice synthesis remains server-side through the existing ElevenLabs endpoint. This change deliberately does not add browser/system text-to-speech fallback or another AI routing path.

## Acceptance

Run:

```sh
node --check assets/js/app.js
node tests/section89a-floating-agent-voice.cjs
node tests/section89a-runtime-gate.cjs
```

On a deployed installation with a configured AI provider and ElevenLabs, manually verify: grant microphone access, say a question, observe a single user turn and Agent reply, hear audio, then confirm that Listening resumes; verify stop during speech and failure messages. Open and close the popup at phone and desktop widths, with and without the music player. No new database migration is required.
