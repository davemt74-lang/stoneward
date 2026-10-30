# Stonefellow Section 91B9B — Account-bound Speech Engine

## Scope

This remains opt-in and isolated from the original conversation engine. The
existing encrypted ElevenLabs Admin credential and the existing Account voice
selector are reused. Selecting Speech Engine causes the public microphone to
request a short-lived signed provider conversation token from the PHP server.

The browser gives the conversation ID and a 120-second one-use account claim
to the same-origin PHP endpoint. Independently, the Node gateway must register
the actual provider conversation ID through an HMAC-authenticated server bridge.
The claim succeeds only when the signed-in account, unassigned provider session,
and one-use token match. A session remains owner-bound for at most 40 minutes.

When bound, speech transcription is passed to `sf_agent_reply()` as that user,
using a separate voice conversation ID and the existing entitlement, catalog,
history, Agent Brain, and configured LLM path. Playback and navigation actions
are deliberately not sent back to the new browser transport yet. Captured
speech is not silently analyzed before the user begins the voice conversation.

## Release boundaries

- Runtime: Node 22+ gateway behind a TLS WebSocket proxy. `speech-gateway/`
  stays outside the HostGator deployment archive.
- Provider: existing encrypted Admin ElevenLabs credential; no browser key.
- Account: signed-in, CSRF-protected `start` and `bind`; legacy choice remains.
- Transport: provider JWT verified by SDK, signed server bridge with replay
  protection, short-lived browser claim, and disconnect revocation.
- Privacy: unbound sessions return no private response. Admin disabling the
  provider or account selection revokes subsequent private lookup.
- Installation: site `upgrade.php` applies migration 063, and gateway deployment
  needs `SF_SPEECH_BRIDGE_URL`, `SF_SPEECH_BRIDGE_SECRET`, and TLS proxy.

## Certification

Run `node tests/section91b9b-speech-engine-client.cjs`,
`node --test speech-gateway/tests/gateway.test.mjs`, and
`php tests/section91b9b-speech-engine-sessions.php`.
The release gate runs these with PHP 8.3/SQLite and additionally checks the
existing v1.3, voice, privacy, Account, and deployment suites. Live conversation
audio cannot be certified without a configured account/provider and gateway.
