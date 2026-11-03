# 91B16 — Fullscreen Agent Pages and Chat Availability

Stonefellow version 1.3.205.

## Public Agent Canvas

- Every regular public route uses the original native application renderer in the Agent Canvas page workspace. There is no iframe or duplicate site session.
- The workspace expands from the current orb coordinates with the same 750 ms easing used by the expanded Agent Canvas. Its final bounds are the entire visual viewport, including the site's fixed header and footer.
- A fixed 44 px close control, Escape key and browser Home navigation return to the current Agent Canvas without restarting the existing audio player, transcript or conversation.
- The full viewport is a modal dialog with keyboard focus containment, internal content scrolling, safe-area padding and a 16 px minimum form input size to avoid iPhone's automatic focus zoom.
- Native route content uses a responsive, atmosphere-colored surface. Desktop content centers within a 1,280 px maximum layout; compact devices collapse multi-column content.
- `?view=builder` remains its own immersive standalone page and does not render in this modal.
- Version-exact assets and service-worker cache are bumped from 1.3.204 to 1.3.205.

## Chat Error Visibility

- The server now distinguishes missing AI provider, unreadable encrypted credentials, provider connectivity/auth/quota/model errors, and database errors, while keeping raw upstream messages and secrets out of client replies.
- Failures display a short, random reference code; server error log and Admin Operations events record the same reference with only a bounded category.
- A failure in optional customer mission planning or tool-contract metadata cannot discard an otherwise successful agent chat reply.
- Admin Operations > Test Anthropic now uses the effective **production chat** Claude model instead of the stored manual model setting; the response budget was increased so a health check can complete on adaptive-thinking models.

## Deployment verification

1. Preserve the existing `config/` and `storage/` trees, particularly `config/app-secret.php`; encrypted AI provider credentials cannot be recovered if that secret is lost.
2. Confirm the installed app reports **1.3.205** and refresh the PWA once to activate version-exact assets.
3. Open Music, Releases, Store, Account and Membership from the agent. Each should expand over the mobile header and desktop page, then close to the original agent scene.
4. Confirm Create Record opens the standalone builder.
5. Sign in and ask the agent a plain-language question. If a chat 503 remains, note its reference and inspect Admin > Operations > Application events. Run **Test Anthropic** under Provider health, and check Admin > AI Providers > Preferred LLM and Anthropic enabled/key state.

## Local verification

- 85/85 Node browser-source suites passed, including section 91B16 checks.
- PHP CLI and live HostGator state were not available in the coding environment. The PHP diagnostic tests and live provider/phone checks require CI and deployment validation.
