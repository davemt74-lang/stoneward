# Section 91B9A — Isolated ElevenLabs Speech Engine pilot foundation

## Scope

- Adds opt-in per-account engine preference (`legacy` default) to Account Settings.
- Reuses encrypted **Admin > AI Providers > ElevenLabs** configuration. It does not
  introduce an alternative key storage or leak provider secrets to the browser.
- Adds a separate Admin pilot configuration with a registered `seng_…` engine ID.
- Introduces migration `2026-10-30-062` for the account preferences table.
- Adds a standalone Node WebSocket gateway scaffold, with provider JWT validation
  and **no private Agent data access** until the next section implements the
  account-bound handshake.

## Invariants

1. Original voice on every account and Admin remains the production default.
2. Account opt-in is CSRF-protected and owner-bound; Admin pilot enablement
   requires the already saved ElevenLabs provider credentials.
3. The selected option is not applied to the live microphone until 91B9B.
4. The gateway refuses to route private transcripts, tool actions, or Agent Brain
   context without a verified account bridge.
5. No API keys, raw transcripts, or user identity appear in health responses.
6. The gateway lives outside shared PHP hosting and runs on a separately managed
   Node host behind a TLS reverse proxy.

## Next: 91B9B

Implement authenticated per-account WebRTC token creation, conversation binding,
gateway-to-Agent request signing, explicit tool-result handling, connection
recovery, and a functional browser session while preserving legacy fallback.
The pilot must not be publicly enabled until that flow is tested end-to-end.
