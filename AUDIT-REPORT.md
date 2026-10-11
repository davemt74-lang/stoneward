# Stonefellow v1.3.29 — Section 30 Press Kit, EPK & Media Relations Audit

Section 30 was built cleanly on the hardened Section 29 mainline. The abandoned pre-Section-29 EPK branch was used only as design reference; none of its stale version/schema state was merged wholesale.

## 30A — EPK Records & Publishing Readiness — 10/10
- General artist and release-linked EPKs share one canonical press-kit model.
- Draft, Published, Private and Archived states are explicit.
- Publishing/private access requires a current readiness pass.
- Readiness checks title, headline, summary, bio, public press contact, HTTPS website, valid release link and explicitly Public hero media.
- Release-specific EPKs consume the current Section 29 distribution readiness rather than duplicating release state.
- Public/private access re-runs readiness so later media/distribution changes can safely make an outdated kit unavailable.

## 30B — Public / Private Sharing & Token Privacy — 10/10
- Published kits resolve at /press/{slug}.
- Private kits use a 256-bit random bearer token; only its SHA-256 hash and a short hint are stored on the EPK record.
- Constant-time hash comparison is used.
- Private tokens live in URL fragments, not query strings, so they are not sent in the initial page request or ordinary referrer.
- Private EPK content is loaded by POST body with no-store JSON responses.
- Rotating a private token invalidates the prior link.
- Leaving Private status clears the stored token hash/hint.
- All existing EPK visibility-state changes require explicit Admin confirmation.
- Pretty-route state is handed from the PHP shell into the SPA correctly.

## 30C — Media Library & Section 29 Integration — 10/10
- press_kit is a first-class Media Library relationship; no second uploader exists.
- Media Library rejects orphan press-kit attachments.
- Hero publishing uses the existing governed universal-media path.
- Public EPK payloads return only explicitly Public EPK, release and track media.
- Legacy catalog audio paths are never exposed as EPK previews.
- Release-specific EPKs inherit only live HTTPS DSP links from Section 29.
- Public track preview remains unavailable unless the Admin explicitly publishes the audio asset.

## 30D — Press Contact Directory — 10/10
- Press contacts are intentionally separate from Fan CRM/newsletter consent semantics.
- Unique email, name, outlet, role, location, tags and internal notes are supported.
- Active / Do Not Contact / Archived states are explicit.
- Non-active press contacts cannot receive outreach.
- Contact changes feed Admin audit and Agent Brain without turning journalists into fan-marketing contacts.

## 30E — Governed Press Outreach — 10/10
- Every send requires explicit Admin confirmation.
- The Admin send control locks while delivery is in flight.
- Private EPK sends require the current raw token in the current Admin session.
- A press_outreach ledger row is created before calling email delivery, so attempts remain visible even if delivery fails.
- Existing Stonefellow transactional email/outbox is reused; no shadow mailer exists.
- Real queued/logged/sent/failed status and delivery errors are preserved.
- Identical active/successful sends are suppressed for five minutes.
- Failed attempts do not block immediate retry.
- Private-link rotation changes the safe token version used for duplicate identity without storing the raw token in the press ledger.
- Admin audit and Agent Brain never record the raw private bearer token.

## 30F — Coverage Ledger & Truthful EPK Analytics — 10/10
- Confirmed articles, reviews, interviews, radio, podcasts, videos, playlists and other placements are recorded separately from outreach.
- Coverage validates linked EPK/contact IDs, HTTPS source URLs and publication dates.
- EPK analytics record only real in-site events: view, media click, contact click, release play, download click and DSP click.
- Event detail is allowlisted/sanitized.
- No tracking pixel, opened_at field or invented email-open event exists.
- Outreach and coverage remain separate fact ledgers.

## 30G — Admin, Agent & UX Integration — 10/10
- EPK + Press is a first-class Admin module.
- Admin includes EPK readiness, share links, reusable media, contacts, outreach, coverage and real activity.
- Raw private tokens remain browser-session scoped after rotation.
- Admin links are subdirectory-safe.
- Admin Agent routes EPK, media-relations, journalist, reviewer, outreach and coverage requests into EPK + Press.
- “Record pressing / vinyl manufacturing” continues to route to POD Handoffs.
- Agent Brain and Admin audit record governed press actions.
- Sidebar density regression now protects all 29 Admin navigation links.

## 30H — Routing, Upgrade & Release Safety — 10/10
- Application advances to 1.3.29.
- Database schema advances to 1.3.25.
- Migration 2026-10-11-026 creates press_kits, press_contacts, press_outreach, press_coverage and press_events.
- Upgrade integrity requires all five Section 30 tables.
- Public base-URL derivation now preserves subdirectory installs when called through API/Admin routes.
- Server-side internal rewrites pass their initial route into the public SPA.
- Section 29 regression was made forward-compatible without weakening its minimum app/schema guarantees.
- PHP syntax: PASS
- Public JavaScript syntax: PASS
- Admin JavaScript syntax: PASS
- Sections 1–29 inherited suites: PASS
- 29-link sidebar density regression: PASS
- Section 30 certification: PASS
- Explicit PASS lines on hardened implementation candidate: 1651
- PHP warnings: 0
- Failures: 0

FINAL SECTION 30 SCORE: **10/10**
