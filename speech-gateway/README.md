# Stonefellow Speech Engine gateway — isolated, account-bound pilot (91B9B)

This is a **separate, opt-in runtime**. It is not started by HostGator PHP.
The existing ElevenLabs API key stays encrypted in Admin > AI Providers. The
same key must be provisioned separately as an environment variable on the
Node gateway host for ElevenLabs' signed WebSocket request verification.
No browser credential field is added.

Requirements: Node 22+, HTTPS reverse proxy exposing `/ws` as secure WebSocket,
and an ElevenLabs Speech Engine resource pointed at that public `wss://` URL.
The PHP site and the gateway share a random bridge secret (at least 32 bytes).
Only the PHP site holds the account database and saved Agent Brain. The gateway
signs each account lookup request, including a unique nonce and short-lived
timestamp; the PHP endpoint checks the owner of the specific conversation.

```text
ELEVENLABS_API_KEY=[same-server-side-key]
SF_SPEECH_ENGINE_ID=seng_[registered-engine-id]
SF_SPEECH_GATEWAY_PORT=3001
SF_SPEECH_BRIDGE_URL=https://your-stonefellow-site.example/api/speech-engine-bridge.php
SF_SPEECH_BRIDGE_SECRET=[same-unique-bridge-secret-as-PHP]
```

Also set `SF_SPEECH_BRIDGE_SECRET` on the PHP server to the same random value.
Never commit this value, expose it in a public config response, or set it in
browser JavaScript. The API key saved in Admin > AI Providers remains the
PHP site's source for short-lived provider conversation tokens; the private
Node process needs the key independently for ElevenLabs JWT verification.

On a **separate** gateway host, run `npm ci --omit=dev`, `npm test`, then `npm start`.
The server listens on loopback; configure TLS at the reverse proxy.
`GET /health` contains no credentials or customer data.

The PHP site ships a prebuilt browser client in `assets/js/` so HostGator does
not need Node. To rebuild this asset from source use `npm ci` and
`npm run build:client` in this folder. Keep the gateway service outside the
public PHP document root; it is deliberately excluded from the site ZIP.

**Safety boundary:** the SDK verifies ElevenLabs JWTs; `disableAuth` must never
be enabled. Each voice session requires a signed-in owner claim, and an unbound
or expired session gets no private Agent Brain access. The existing original
voice engine stays selectable and is the default. The voice pilot uses the
normal authenticated agent response policy and stores transcript turns under
its own conversation ID. Player/navigation actions remain deferred in the pilot.
