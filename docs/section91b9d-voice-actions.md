# Section 91B9D — Speech Engine browser action parity

The signed Node gateway already invokes Stonefellow's authenticated agent
planner. This section closes the existing gap between spoken intent and the
browser-owned music/navigation actions available to typed chat.

## Flow

1. A registered, one-time-claimed Speech Engine session sends a spoken turn to
   the same server-side agent reply policy used for text.
2. The PHP bridge filters the structured result to an explicit allowlist:
   public navigation and local player actions only. Anything requiring a
   confirmation, an account edit, builder mutation or purchase is deferred.
3. A short-lived (75-second) pending action is stored with session and owner.
   No action payload is sent to ElevenLabs or the Node gateway.
4. On the ElevenLabs agent response, the signed-in browser posts its conversation
   ID with CSRF protection and claims the next action once. It runs the same
   published-catalog validation and player/navigation dispatcher as typed chat.
5. Actions expire unused and are deleted on conversation close. Disabling the
   pilot or disconnecting prevents further owner-bound retrieval.

The gateway can speak the planned response, but actual browser playback still
depends on normal browser audio permissions and the published audio file. The
browser displays the concrete player result; the provider is not told an
unverified playback operation succeeded.

## Certification

- PHP test: cross-account isolation, one-use consumption, revocation,
  confirmation and sensitive-action exclusion.
- JavaScript test: shared action-dispatch path, session lifecycle and CSRF.
- Full v1.3 PHP release gate before merge. Physical microphone, provider
  and external gateway require deployment smoke testing.
