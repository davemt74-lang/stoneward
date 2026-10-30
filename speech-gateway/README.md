# Stonefellow Speech Engine gateway — isolated pilot (91B9A)

This is a **separate, opt-in runtime**. It is not started by HostGator PHP.
The existing ElevenLabs API key stays encrypted in Admin > AI Providers. The
same key must be provisioned separately as an environment variable on the
Node gateway host for ElevenLabs' signed WebSocket request verification.
No browser credential field is added.

Requirements: Node 22+, HTTPS reverse proxy exposing `/ws` as secure WebSocket,
and an ElevenLabs Speech Engine resource pointed at that public `wss://` URL.

```text
ELEVENLABS_API_KEY=[same-server-side-key]
SF_SPEECH_ENGINE_ID=seng_[registered-engine-id]
SF_SPEECH_GATEWAY_PORT=3001
```

On a **separate** gateway host, run `npm install`, `npm test`, then `npm start`.
The server listens on loopback; configure TLS at the reverse proxy.
`GET /health` contains no credentials or customer data.

**Safety boundary:** 91B9A does not invoke private Agent tools, send transcripts
to the LLM, or store conversation history. The SDK validates ElevenLabs JWTs;
`disableAuth` must never be enabled. Account-bound WebRTC tokens and the
authenticated agent handoff belong to 91B9B. Account Settings stores the
preference, but the production microphone remains on the original engine.
