# Section 91B13E — Lyric review and interpretation-only Agent output

Stonefellow 1.3.199 changes the existing Listen & Interpret flow without changing Record Clip.

- **Listening:** Desktop browser player-track recognition yields an unverified lyric draft; it does not record an audio clip.
- **Review:** After Stop, the sidecar displays the actual words and a small pencil icon. The listener can edit the words and save the browser-local correction. An opted-in Listen & Interpret session now pauses at **Review & interpret lyrics** so the draft is not sent to the model before review. Ordinary **Interpret lyrics** and follow-up questions remain available.
- **Language model:** The selected lyric draft is the center of the provider's *user* prompt, not buried in metadata. The language model is asked to interpret the phrases, imagery, symbols and emotions using approved song-specific context when relevant. Conversational AI provider fallback (including Anthropic/Claude when configured) remains supported.
- **Speech:** Only successful returned `analysis.report` content goes to Agent speech/chat for actions originating in the sidecar. Status messages, song identity, time range, credits, sources, model name, and verification details remain in the sidecar; source detail is collapsed under **Sources & interpretation metadata**. The server separates the optional `<analysis>…</analysis>` result from structured provenance.
- **Privacy:** Corrections stay browser-local unless the listener explicitly requests an interpretation or private save; they are not automatically written to Agent Brain. Report provenance remains structured for the session. Stale analysis responses are discarded if the selected draft changes during the request.
- **Record Clip:** A separate opt-in browser recording and transcription route is unchanged; its lyric draft also pauses for review when a follow-up interpretation has been requested.

## Verification

- `node tests/section91b12e-lyric-interpretation.cjs`
- `node tests/section91b13e-lyric-review-gate.cjs`
- `php tests/section91b13e-lyric-review-gate.php`
- Existing player-track, agent-canvas, and PHP source-boundary checks continue in the release gate.

## Section 91B13F — Conversation-aware volume

Starting an armed microphone no longer lowers music. The player ducks to 40% maximum only when browser speech detection/interim transcription is active, or 16% maximum while actual Agent speech is audible. Silence, completed transcription, network errors, and microphone stop restore the listener's original slider level. ElevenLabs Speech Engine sessions duck while the SDK reports Agent speaking, not merely while the conversation is connected. A volume change made by the listener while ducked is preserved when the duck ends. The new volume regression test runs as part of the release gate.
