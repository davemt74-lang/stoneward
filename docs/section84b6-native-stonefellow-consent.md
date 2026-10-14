# Section 84B6 — Native VP3 Stonefellow: mandatory Cloud account login and consent

## Essential rule
**Having a VP3 HomeServer member account does NOT grant any Stonefellow music.** Every fan must have a Stonefellow account, sign in to that account on the real Stonefellow HTTPS site and explicitly select **Authorize HomeServer** for their own purchased-music scope. A separate HomeServer owner/admin presence requires an actual *Stonefellow administrator account* to log in and grant a separate read-only scope. An admin presence must never unlock a fan's purchases or the right to spend, manufacture or publish.

## Pairing flow (no password sharing)
1. HomeServer owner installs the embedded `vp3.stonefellow` native app and explicitly grants `network.external`. The owner configures the canonical public Stonefellow HTTPS origin through the HomeServer Apps admin API.
2. HomeServer owner enables the app for the desired HomeServer member. The member signs in locally and selects **Connect my Stonefellow account**.
3. HomeServer creates a 256-bit pairing verifier, retains it solely in the VP3 app-secret vault under that member's hashed identity, and requests a time-limited pairing ID from Stonefellow. HomeServer shows only the Stonefellow authorization URL, never the private verifier.
4. The user opens `/homeserver-connect.php?request_id=...` **on the actual Stonefellow site**. Without an active signed-in Stonefellow session, it cannot approve anything. The page shows account email, HomeServer device label and exact requested scope; approval requires an explicit button press and Stonefellow session CSRF protection.
5. Only after approval does the original HomeServer verifier redeem one account-scoped 30-day token. Stonefellow persists only SHA-256 of that token; the private token lives only inside the HomeServer app-secret vault (Windows DPAPI). A wrong verifier, denied/expired request, second redemption or scope mismatch fails closed.
6. HomeServer's native app presents read-only owned-song metadata for that linked account. It performs no payments, vendor actions or manufacturing. Stream/download links will require a subsequent separately tested entitlement-scoped media gateway; music is **not** automatically copied to every VP3 user or installed local Media Server folder.
7. The user can disconnect in HomeServer or open `/homeserver-connections.php` after signing into Stonefellow to revoke any individual HomeServer grant immediately. Cloud user role/disabled status is rechecked each request, so revocation cannot be bypassed with stale HomeServer secrets. Local disconnect alone does not revoke the Cloud token; users should revoke on Stonefellow too.

## Two distinct integration modes
- **Personal fan:** `fan.library.read`; verifies the exact Stonefellow account’s paid orders and library grants; no admin status, no other customer's music.
- **Owner/Admin:** `admin.presence.read`; Stonefellow logged-in administrator approves the HomeServer control connection. It exposes only catalog count, aggregate Kunaki queue status and non-executable capability flags. No fan identity or payment/marketing data exposed. Doesn't grant automatic Kunaki browser publication.

HomeServer native integration uses existing VP3 HomeServer Apps, member account sessions, member app-access permissions, owner-approved outbound network capability and the private app-secret vault. The Stonefellow-side account authorization is a separate scoped device-approval grant; it intentionally does **not** treat the previous media worker HMAC key as a fan or admin login.

## Required security and deployment configuration
- The Stonefellow site must be reachable through public HTTPS. The owner configures only the HTTPS origin (no paths/URLs/IPs or local-network targets). HomeServer checks DNS is globally routable, disables redirect following, uses verified TLS and ignores proxy environment settings.
- The app is an optional native VP3 HomeServer install; member access defaults off until owner assigns it.
- Pair requests expire after ten minutes. Cloud rights expire after 30 days. Approved tokens can be revoked immediately by the Stonefellow user; administrator role downgrade and disabled user status invalidate access.
- No automatic account creation or automatic login as the Stonefellow artist or another HomeServer member.
- Secrets never appear in Agent Chat prompts, browser UI, local storage, logged request bodies or diagnostic responses.
- This release does not connect VP3 local music streaming directly to Stonefellow audio bytes. A secure playback/download proxy will be a separate 10/10-gated follow-on.

## Release gates
Cloud: tests/section84b6-homeserver-user-consent.php and the existing full PHP/JS + MySQL release gate.
HomeServer: tests/stonefellow_native_84b6.py on Linux/Windows plus the existing full repository checks and packaged Windows upgrade/recovery certification. Each PR must certify at its exact head; no merge until those and the previous section’s merged-main gates are complete.
