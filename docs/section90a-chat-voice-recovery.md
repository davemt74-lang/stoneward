# Section 90A — Fan Agent Chat and listening recovery

The public Stonefellow composer and the conversation popup use the same Agent Chat
endpoint. Voice recognition no longer writes interim results into the typed chat
input: a separate accessible, live-updating transcript banner shows listening,
recognized words, response playback, and microphone failures. Final recognition
submits through the canonical `command()` path with a synchronous busy gate that
prevents duplicate final events.

The conversation popup remains closed until explicitly opened. With the persistent
music player visible, it stays attached to the top of the chat composer rather
than jumping an extra player-height above it. Send during an active voice reply
preserves the typed draft and displays a useful waiting status.

The backend remains responsible for authentication, CSRF, entitlements, response
routing, and voice synthesis; this section does not expand tool permissions.

## Verification

```
node --check assets/js/app.js
node tests/section89a-floating-agent-voice.cjs
node tests/section89a-runtime-gate.cjs
node tests/section90a-voice-transcription.cjs
```

Browser acceptance on a deployed installation should check desktop and mobile
composer layout, conversation popup open/close with music playing, interim
transcripts without typed-draft loss, single final submission, and microphone
permission and voice-playback errors. Local headless browser acceptance is not
claimed by the source tests.
