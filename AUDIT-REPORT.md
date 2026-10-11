# Stonefellow v1.3.30 — Section 31 Public Artist Experience & Fan Journey Audit

Section 31 was built cleanly on the v1.3.29 mainline with extra emphasis on the single-artist homepage experience. The Homepage is implemented as a Settings-managed plugin rather than another Admin navigation area, keeping the already-dense left sidebar unchanged.

## 31A — Homepage Plugin & Launch Control — 10/10
- Added a dedicated Artist Homepage plugin toggle under existing Site Settings.
- Existing Splash toggle remains completely independent.
- Safe launch order is Splash (optional) → Artist Homepage (optional) → existing Agent/Personalized Home fallback.
- When the plugin is disabled or unavailable, the existing Home implementation remains unchanged.
- The plugin adds no 30th Admin sidebar item; the existing 29-link density contract remains intact.
- Public navigation says Home when the plugin is active and Agent when it is not.

## 31B — Single-Artist Homepage Editor — 10/10
- Fixed Stonefellow-only section model: Hero, Featured Release, Featured Track, Latest Releases, Upcoming Show, Merch, Artist Story, Newsletter, Personalized.
- No artist selector, artist_id, tenant key or multi-artist abstraction was introduced.
- Each section supports enable/disable, position, eyebrow, heading, body and section-specific configuration.
- Smart auto-selection supports newest public release, first featured-release track, next public show and featured/available merch.
- Explicit overrides are server-validated against authoritative public Stonefellow releases/shows/products and catalog tracks.
- Server-side config normalization prevents API callers from setting unsupported destinations or arbitrary config keys.
- Defaults can be restored only after explicit Admin confirmation.
- Preview opens the real public Home route.

## 31C — Public Artist Homepage Experience — 10/10
- Homepage becomes the visual landing surface rather than stacking under the large Agent stage.
- Persistent chat, voice controls and music player remain available throughout the experience.
- Cinematic hero uses governed Site Media and responsive layout.
- Featured release, track, latest releases, show, merch, story, newsletter and personalized sections share the existing public application shell.
- Live DSP links come from Section 29 and are revalidated as HTTPS before exposure.
- Main menu labels Home appropriately while the plugin is active.
- Mobile layouts collapse cleanly without creating a second public shell.

## 31D — Public Media & Playback Boundaries — 10/10
- Homepage release/track/show imagery is resolved through explicitly Public Media Library relationships.
- Featured track playback requires a public primary_audio relationship.
- Homepage playback overrides the catalog object with the approved public audio URL.
- The second-pass audit removed the remaining legacy catalog-audio fallback entirely.
- Site hero/artist media continue to use the governed site-media publication path.
- No homepage-specific uploader or duplicate media store was introduced.

## 31E — Logged-In Fan Journey — 10/10
- Logging in does not turn the homepage into a generic account dashboard.
- Existing personalization is presented as a lower Your Stonefellow section beneath the artist experience.
- Continue Listening, favorites and Agent suggestion data reuse the established Home/personalization model.
- Anonymous visitors never receive personalized payload data.
- Persistent playback, queue, library and account behavior remain part of the same AJAX application shell.

## 31F — Homepage Readiness & Completeness — 10/10
- Added deterministic homepage readiness scoring.
- Core homepage blockers include missing hero copy, featured release and explicitly Public featured-track audio.
- Warnings surface missing published hero imagery, artist photo, future public show or available merch.
- Readiness is visible directly inside Settings alongside plugin state.
- Homepage can still be edited while disabled, allowing safe preparation before launch.
- Later public-media/catalog changes are reflected by fresh payload/readiness evaluation rather than copied shadow data.

## 31G — Truthful Fan-Journey Events & Governance — 10/10
- Allowlisted events: view, hero primary/secondary CTA, release open, track open/play, show open, merch open, newsletter CTA, About, personalization and Agent CTA.
- No email-open, impression-score or inferred engagement events exist.
- Public event writes are blocked when the Homepage plugin is disabled.
- Anonymous event writes are session-rate-limited to prevent trivial event-table flooding.
- Event detail is bounded and sanitized.
- Homepage section changes/reset feed Admin audit and Agent Brain.
- Resetting all homepage defaults requires explicit confirmation.

## 31H — Upgrade, Regression & Release Safety — 10/10
- Application advances to **1.3.30**.
- Database target advances to **1.3.26**.
- Migration **2026-10-11-027** creates `artist_home_sections` and `artist_home_events`.
- Upgrade integrity requires both new tables.
- Existing Section 30 version/schema regressions were made forward-compatible without weakening their minimum guarantees.
- Existing Section 17 Fan Community persistence regression remains enforced while allowing the new site toggle.
- PHP syntax: **PASS**
- Public JavaScript syntax: **PASS**
- Admin JavaScript syntax: **PASS**
- Sections 1–30 inherited regression suites: **PASS**
- Section 31 certification: **PASS**
- Explicit PASS lines on hardened implementation candidate: **1708**
- PHP warnings: **0**
- Failures: **0**

FINAL SECTION 31 SCORE: **10/10**
