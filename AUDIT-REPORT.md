# Stonefellow v1.3.31 — Section 32 SEO, Discovery & Social Sharing Audit

Section 32 was built on the fully merged Section 31 mainline and audited against crawler privacy, canonical routing, structured data, SPA metadata drift, truthful sharing, discovery attribution, and regression compatibility.

## 32A — Dynamic Route Metadata — 10/10
- One canonical server-side resolver owns public metadata for Home, tracks, releases, shows, merch, campaigns, EPKs and top-level public sections.
- Initial HTML emits route-specific title, description, canonical URL, robots policy, Open Graph and social-card metadata.
- Unknown/private/non-public routes receive noindex,nofollow,noarchive and no canonical/JSON-LD.
- SPA navigation re-resolves metadata from the same server authority so title/canonical/social tags do not drift after AJAX navigation.
- Existing server JSON-LD is explicitly replaceable during SPA navigation.

## 32B — Music / Event / Product Structured Data — 10/10
- MusicGroup schema represents Stonefellow.
- MusicAlbum schema includes public tracks.
- MusicRecording includes public duration and ISRC where available.
- MusicEvent uses canonical public show data and only validated HTTPS ticket URLs.
- Product schema uses active public merch records and configured store currency.
- ProfilePage covers published/ready public EPKs.
- Structured-data images resolve through Public Media Library relationships where available.

## 32C — Sitemap & Robots Privacy — 10/10
- Standard XML sitemap endpoint is available at /sitemap.xml.
- Sitemap includes canonical Home/top-level pages, published public releases, public standalone/release tracks, public shows, active merch, live campaigns and published-ready EPKs.
- Private/draft/hidden records are rejected before sitemap inclusion.
- robots.txt blocks Admin, API, storage/data, account/auth query routes and token-bearing query URLs.
- Sitemap URL validation accepts only ordinary HTTP/HTTPS URLs.

## 32D — Canonical Public URLs — 10/10
- Pretty routes exist for /track/{id}, /release/{id}, /show/{id}, /store/{slug}, /campaign/{slug} and /press/{slug}.
- Main public sections use canonical /music, /releases, /shows, /store, /about and /archive routes.
- Server rewrite handoff preserves route id/slug for the SPA.
- Browser navigation generates the same route shapes instead of returning to duplicate ?view=... URLs.
- Private EPK bearer tokens remain in URL fragments and are never moved into query parameters.
- Public navigation and wordmark now prefer canonical URLs.

## 32E — Social Sharing — 10/10
- Track, release, show and focused merch pages expose Share controls.
- Native Web Share is used when available; clipboard-copy fallback is supported.
- Share attribution is recorded only after native share resolves or clipboard copy succeeds.
- Aborted native shares are not counted.
- Store cards can open focused /store/{slug} product pages with a product-specific social/SEO surface.

## 32F — Server-Rendered Discovery Content — 10/10
- Initial public HTML contains a crawlable route-specific content snapshot before JavaScript runs.
- The SPA replaces that snapshot with the interactive experience without creating a second shell.
- Direct pretty-route loading works through the existing public application.
- Critical titles/descriptions are server rendered rather than relying exclusively on JavaScript.
- Focused merch and SEO snapshot views have responsive presentation.

## 32G — Discovery Attribution & Existing Analytics — 10/10
- Discovery events are stored in the main Stonefellow database; no parallel analytics service was introduced.
- Only bounded landing/share event types are accepted.
- Referrers normalize into direct/internal/search/social/referral or bounded UTM source classes.
- Full referrer URLs are never persisted.
- Landing attribution is recorded once per document load, not on every internal SPA click.
- Public event writes are rate-limited.
- Raw discovery summaries are Admin-only.
- Discovery sources/routes feed the existing Growth Analytics payload, Performance Intelligence UI, and grounded Admin Agent brief.

## 32H — Release Safety & Regression — 10/10
- Application advances to **1.3.31**.
- Database target advances to **1.3.27**.
- Migration **2026-10-11-028** creates discovery_events and upgrade integrity requires it.
- Section 31 version/schema regression was made forward-compatible without weakening its minimum guarantees.
- Section 17 storefront regression was updated to allow the route-aware renderer while still requiring server-backed storefront data.
- Existing list-selector safety regression caught and prevented two collection binding defects.
- Manual audit caught and fixed SEO excerpt whitespace normalization and missing one-shot landing attribution before acceptance.
- PHP syntax: **PASS**
- Public JavaScript syntax: **PASS**
- Admin JavaScript syntax: **PASS**
- Sections 1–31 inherited regression suites: **PASS**
- Section 32 certification: **PASS**
- Explicit PASS lines on hardened implementation candidate: **1784**
- PHP warnings: **0**
- Failures: **0**

FINAL SECTION 32 SCORE: **10/10**
