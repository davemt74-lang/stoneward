# Section 91B9C — Speech Engine operational readiness

The Admin AI Providers page now offers an explicit **Test provider and gateway**
button beside the existing pilot settings. This performs two server-side checks:

1. An ElevenLabs conversation-token request against the saved encrypted API key
   and current Speech Engine ID. The token is discarded; it never reaches the
   Admin page or any logs.
2. A bounded health check of the separate Node gateway. Admin configures the
   optional `SF_SPEECH_GATEWAY_HEALTH_URL` PHP-server environment variable;
   the request is not based on any browser-supplied URL. HTTPS is required,
   except local development loopback. Redirects and oversized responses are
   rejected. The gateway must report that the signed account bridge is enabled.

The UI shows provider and gateway results independently so deployment problems
are distinguishable from credential problems. The result is readiness for a
**manual** voice test, not proof of a successful live WebRTC conversation.

To certify, run `php tests/section91b9c-speech-engine-readiness.php` and
`node tests/section91b9c-speech-engine-readiness.cjs` along with the normal
release gate. No account must turn on Speech Engine to run the Admin test.
