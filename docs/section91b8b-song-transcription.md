# Section 91B8B — Explicit Song-Lyric Transcription (v1.3.176)

## User flow

1. Play a published song. Say **“listen to this verse”** to capture the current section from player audio, not the microphone.
2. Say **“stop listening”** to finish a maximum 90-second capture. The song ID/title and exact start/end positions remain attached.
3. Say **“transcribe what you heard”** or **“write down those lyrics.”** This **separate, explicit request** authorizes sending only the held clip to the configured OpenAI audio transcription provider.
4. Stonefellow displays an **unverified lyric draft** linked to the song and playback range. User corrections and LLM analysis follow in the next sections; this stage never asserts accurate word-level timestamps or verified copyright ownership.

## Isolation, policy and availability

- The binary clip stays in the browser tab until the user requests transcription. Capture start/stop alone does **not** upload it. The server requires a signed-in account and CSRF token, validates that the claimed song ID is uniquely published and playable, checks clip bounds and actual MIME, and routes transcription to the existing admin-configured OpenAI provider. The conversational LLM stays provider-independent.
- Stonefellow does not save a copy of the uploaded audio on its server; PHP uses the request's temporary upload file. OpenAI receives the chosen clip for transcription under that provider's applicable processing terms. No storage write, catalog publication, or training claim is made here.
- Draft text and playback metadata exist in the current page's memory and chat view only. On reload, unsaved captures and drafts are gone. A new capture clears the previous raw clip. The user must review uncertain lyric words before any factual analysis is considered verified.
- Unavailable providers, unsupported formats, expired CSRF, missing capture, no tokens, and capture/device changes fail with a useful message; a failed transcription retains the browser clip for retry. This does **not** imply live-browser capture is certified on all devices.

## 10/10 section criteria

- [x] Server allowlisted route and explicit second-step consent.
- [x] Login + CSRF + published song identity + bounds + MIME checks.
- [x] Existing OpenAI audio endpoint, secret stays server-side, metered on success.
- [x] No Stonefellow audio persistence; draft marked unverified, whole-clip estimated timing.
- [x] Browser typed and spoken agent action share one binary-upload implementation.
- [x] New deterministic PHP and JS regressions registered in the full Release Gate.
- [ ] End-to-end live-device provider transcription still requires deployed acceptance testing.
