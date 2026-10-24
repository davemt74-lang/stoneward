# Section 90E — Admin Chat canvas scroll + governed catalog metadata

Release: Stonefellow v1.3.137. Follows merged Section 90D. No DB migration.

## Chat canvas

- The Admin Agent conversation uses document/workspace scrolling. The transcript no longer creates a nested scroll region.
- The existing chat bar remains fixed to the bottom and has enough document padding to avoid covering the last response.
- On opening or receiving a reply the viewport moves to the newest messages.
- The chat textarea grows to 160 px and hides its tiny internal scroll gutter while still supporting multiline text.
- A separate animated Admin companion is now permanently mounted outside the replaceable chat content, including when the Agent Chat view hides its old compact status strip. Click it from another Admin page to open Agent Chat; click again in Chat to activate the supported mic control. It indicates ready, listening and thinking, respects reduced motion and never activates the microphone on page load.

## Spoken replies and floating companions

- Public Stonefellow replies now speak after typed messages as well as hands-free microphone turns; voice output no longer depends on the listening toggle. The chat popup has a saved **Voice on/off** control separate from microphone listening. It starts only after the user submits a prompt or activates the microphone.
- Public speech uses the configured ElevenLabs service for signed-in users with the existing on-device `localService` browser fallback. If a provider or local voice is unavailable, the text remains visible and the chat displays a speech status. Music is temporarily ducked while speech plays and restores its previous volume afterward. The user can interrupt speech without ending hands-free conversation.
- The Admin companion has ready, listening, thinking and talking states. Its **Voice on/off** control is independent of the public setting. Admin responses use *only locally installed device voices* so CRM, customer, unreleased music and operational content is not sent to another speech provider. Without an installed local voice, Admin remains text-only with a status explanation. Clicking the Admin floating companion while talking stops speech before opening the microphone.

## First real music-catalog write from Admin Agent Chat

For an existing song, say for example:

> Set genre for song "Road to Nowhere" to Americana

Exact title or song ID is required. Supported fields in this first action contract: **title, genre, story, year**. Other catalog fields, media upload, publication, rights, distribution, payment, sending messages, deletion and arbitrary writes are not exposed by this tool. Upload still uses a user-selected file in Media Library.

1. The server deterministically resolves the song, reads the real catalog and proposes an exact before/after patch in the Admin Chat canvas; no model decides IDs or permissions.
2. The signed-in Admin clicks **Review and save catalog change** and **Prepare proposal**. This stores one idempotent, owner-bound proposal with exact payload SHA-256.
3. After viewing the full proposal and hash, the Admin types `APPROVE` and separately clicks **Save approved metadata**. Approving alone never writes to the catalog.
4. The executor confirms the approved proposal, unchanged track revision and unchanged source-file hash under the same catalog writer lock used by regular Admin changes. On success it writes canonical `data/catalog.json` and published catalog index through the existing writer and records a receipt in the Agent action ledger and Admin audit.
5. Any uncertain save remains review-required; never blindly replay it. Only a confirmed result can be described as saved.

Role policy: customer/fan/guest cannot discover or execute the Admin catalog write capability. Public music and artist data can enter an LLM only through the separately approved 90C allowlist; private masters, unreleased material, private fan data and credentials are never forwarded for this deterministic action.

## Acceptance

- `php tests/section90e-agent-catalog-actions.php` (normalization, permissions, precondition, preserving untargeted fields)
- `node tests/section90e-admin-scroll.cjs` (single-scroll canvas, anchored bar, review UI lifecycle)
- `node tests/section90e-agent-voice.cjs` (typed prompt speaking, independent mute setting, Admin on-device-only voice, speaking animation)
- Existing releases, voice, Fan chat, capability audit and migration checks in Release Gate
- Manual desktop/mobile: long Admin conversation scrolls as one page, footer remains fixed, latest message clears footer; change genre of a real song after exact approval; independently modify a song during proposal review and verify the stale operation refuses to overwrite it; verify fan cannot access Admin actions.
- Manual desktop/mobile: persistent floating Admin Agent remains visible during Admin navigation and in Admin Chat, opens chat on tap, and shows mic/thinking states. Verify the separate public-site companion on a freshly deployed 90D+ build.
- Manual desktop/mobile: type an Agent question and hear the response with Voice on; mute and verify typing/listening continue silently; resume voice; converse while music plays and confirm duck/restoration. In Admin verify spoken responses, the floating mouth animation, interruption, absence of remote Admin TTS requests, and text-only explanation where a local voice is unavailable.

Never mark the manual live-browser acceptance as complete based solely on static tests.
