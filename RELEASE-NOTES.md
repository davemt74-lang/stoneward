# Stonefellow v1.3.33 — Section 34: Artist Brand & Visual Theme

- Added a governed **single-artist Artist Brand & Visual Theme system** under Admin → Settings.
- Added four curated presets: **Desert, Midnight, Paper and Monochrome**.
- Added **Dark, Light and Follow System** appearance modes.
- Added paired dark/light palettes with safe controls for background, surfaces, text, secondary text, accent, accent-button text and danger.
- Added allowlisted display/body typography stacks, content width, density and corner controls.
- Added deterministic server-side contrast/readiness scoring; inaccessible required color combinations block publishing.
- Added separate **Draft** and **Published** theme state using the existing app_meta settings store.
- Added a real-site **saved-draft preview** that is Administrator-only, noindex and analytics-clean.
- Added explicit confirmation for live theme publishing and restoring the original Desert default.
- Added Admin audit and Agent Brain visibility for theme lifecycle changes.
- Added site-wide theme integration across Splash, Homepage, Storytelling, music, releases, shows, store, account/library surfaces and the persistent Agent/player shell.
- Replaced structural hard-coded public colors/type stacks with theme tokens while preserving intentionally art-directed record/cassette visuals.
- Added system-aware browser theme-color metadata.
- Added no arbitrary CSS, script injection or remote-font capability.
- Application advances to **1.3.33**.
- Database schema remains **1.3.28**; no new migration is required.

# Stonefellow v1.3.32 — Section 33: Public Content & Storytelling

- Added a governed **single-artist Storytelling system** for Stonefellow.
- Added Artist About, Editorial Essay, Release Story, Track Story, Show Story and Timeline Milestone types.
- Storytelling is embedded in **Admin → Settings** and does not add another Admin sidebar item.
- Added explicit publish/archive/delete confirmation and readiness enforcement.
- Added canonical links from editorial stories to existing public tracks, releases and shows without creating shadow catalog data.
- Added public **About** storytelling, editorial essays and Stonefellow history timeline.
- Added canonical public **/story/{slug}** pages.
- Added supplemental Story sections to track, release and show detail pages.
- Added Storytelling Media Library integration with governed hero, inline and gallery media.
- Added story-specific SEO, Article structured data and sitemap integration.
- Published Artist About now automatically drives the Homepage Artist Story; Section 31 copy remains fallback only.
- Public Agent context now includes bounded, relevance-ranked, published Storytelling content.
- Added truthful story view, connected-content, media and share events.
- Native/copy share events are recorded only after the corresponding user action succeeds.
- Application advances to **1.3.32**.
- Database schema advances to **1.3.28** via migration **2026-10-11-029**.

# Stonefellow v1.3.31 — Section 32: SEO, Discovery & Social Sharing

- Added route-specific canonical metadata for Home, songs, releases, shows, products, campaigns, EPKs and major public sections.
- Added server-rendered Open Graph and social-card metadata.
- Added structured data for Stonefellow, albums, recordings, shows, products and public press kits.
- Added /sitemap.xml and /robots.txt with explicit private/admin/account/token exclusions.
- Added canonical pretty public routes for tracks, releases, shows, merch, campaigns and press kits.
- Added server-rendered route snapshots so critical public content exists before JavaScript executes.
- Added SPA metadata synchronization so title/canonical/social/JSON-LD stay correct after AJAX navigation.
- Added public Share controls for songs, releases, shows and focused merch pages.
- Added focused /store/{slug} product pages and exposed public product slugs through Storefront.
- Share analytics only record completed native-share or copy actions.
- Added privacy-bounded acquisition attribution for direct, search, social, referral and campaign landings.
- Full referrer URLs are not retained.
- Discovery attribution feeds the existing Performance Intelligence dashboard and Admin Agent brief.
- Application advances to **1.3.31**.
- Database schema advances to **1.3.27** via migration **2026-10-11-028**.

# Stonefellow v1.3.30 — Section 31: Public Artist Experience & Fan Journey

- Added a new **single-artist Artist Homepage plugin** managed under Admin → Settings.
- Added a separate **Artist Homepage on/off toggle** without removing or repurposing the existing Splash toggle.
- When the Homepage plugin is off, Stonefellow continues to use the existing Agent/Personalized Home.
- The Homepage plugin does **not** add another Admin sidebar item; the existing 29-link navigation remains unchanged.
- Added configurable homepage sections for Hero, Featured Release, Featured Track, Latest Releases, Upcoming Show, Featured Merch, Artist Story, Newsletter and logged-in personalization.
- Added per-section enable/disable, ordering, copy and source controls.
- Added smart auto-selection for newest release, featured-release track, next show and featured available merch.
- Added server-side validation/normalization for homepage configuration and explicit content overrides.
- Added a cinematic responsive public homepage that visually replaces the large Agent stage on Home while preserving the persistent Agent chat and music player.
- Added live DSP links from Section 29, public show/ticket context, direct merch entry points and the existing governed newsletter signup.
- Added logged-in **Your Stonefellow** personalization as a lower homepage layer rather than replacing the artist experience.
- Added homepage readiness/completeness scoring in Settings.
- Added truthful homepage journey events for real views, CTAs, release/track/show/merch actions, newsletter interaction, personalization and Agent entry.
- Homepage event writes are disabled when the plugin is off and rate-limited when enabled.
- Featured-track playback uses only explicitly Public Media Library audio and has no legacy catalog-audio fallback.
- Public navigation now labels the Home route **Home** when the plugin is active and **Agent** when the legacy Agent Home is active.
- Application advances to **1.3.30**.
- Database schema advances to **1.3.26** via migration **2026-10-11-027**.

# Stonefellow v1.3.29 — Section 30: Press Kit, EPK & Media Relations

- Added first-class Admin **EPK + Press** workspace.
- Added general artist EPKs and release-specific EPKs with Draft, Published, Private and Archived lifecycle states.
- Added EPK publishing-readiness checks for press copy, public contact information, hero media, linked release validity and Section 29 distribution readiness.
- Added pretty public URLs at `/press/{slug}`.
- Added private EPK links using 256-bit bearer tokens stored only as SHA-256 hashes on the EPK record.
- Private bearer tokens use URL fragments and private EPK content loads through POST, keeping the token out of the page request query string.
- Leaving Private status invalidates the previous token; rotating a token invalidates the previous private link.
- Added reusable Media Library relationships for EPK hero art, photos, approved audio/video, documents and archive media.
- Public EPKs expose only explicitly Public Media Library assets; legacy/private catalog audio paths are not surfaced.
- Release EPKs consume live DSP links from Section 29 Distribution + Publishing.
- Added separate press-contact directory with Active, Do Not Contact and Archived governance; press contacts are not Fan CRM/newsletter contacts.
- Added governed press outreach through Stonefellow’s existing transactional email outbox.
- Every press send requires explicit Admin confirmation and uses duplicate suppression; failed sends remain visible and may be retried.
- Added confirmed coverage ledger for articles, reviews, interviews, radio, podcasts, video, playlists and other placements.
- Added truthful EPK engagement events for page views, media/contact/DSP clicks, release plays and downloads; no email-open tracking is invented.
- Added Admin audit + Agent Brain visibility for EPK publishing, token rotation, press contacts, outreach and coverage.
- Admin Agent distinguishes media relations from vinyl/record pressing.
- Hardened public base-URL and internal-route handling for subdirectory installations.
- Admin sidebar now supports 29 navigation links under the short-viewport density regression.
- Application advances to **1.3.29**.
- Database schema advances to **1.3.25** via migration **2026-10-11-026**.

# Stonefellow v1.3.28 — Section 29: Release Publishing & Distribution Operations

## Hardening audit

A second production-focused audit tightened the v1.3.28 Distribution + Publishing implementation before final acceptance.

- Distributor preflight now requires managed Media Library assets rather than legacy playback paths.
- Cover delivery requires managed square JPG/PNG artwork at 3000×3000 or greater.
- Track delivery requires managed WAV masters at 44.1 kHz / 16-bit or better.
- Media source hashes are verified during package construction.
- Distributor ZIPs now carry an exact source fingerprint and stale packages are blocked.
- Downloads re-verify the stored ZIP SHA-256.
- Rights data in the package is minimized to distributor-relevant fields.
- Release and DSP state transitions are governed by package/DSP evidence.
- Calendar re-sync preserves completed milestone state.
- The Admin UI exposes only valid next transitions and clearly identifies stale packages.
- Full regression gate is warning-free.
- App remains **v1.3.28**; DB schema remains **1.3.24**; no new migration is required.

## Original Section 29 delivery

- Added first-class Admin **Distribution + Publishing** workspace.
- Added release preflight across metadata, release date, track list, ISRCs, audio masters, cover artwork and Section 28 rights clearance.
- Added checksum validation for UPC-A and EAN-13 identifiers.
- Added managed-cover certification at 3000×3000 or greater; legacy cover paths remain visible with an uncertified-resolution warning.
- Added distributor metadata for artist, edition/version, label, genre/subgenre, language, territory, catalog number, © and ℗ lines.
- Added publishing lifecycle: Draft → Preparing → Ready → Submitted → Scheduled → Live → Archived.
- Added DSP status tracking for Spotify, Apple Music, YouTube Music, Amazon Music and Bandcamp.
- Added external IDs and live release URLs without pretending Stonefellow has direct DSP submission APIs.
- Added distributor-ready ZIP builder containing `manifest.json`, `credits-rights.csv`, managed masters and managed cover art.
- Added SHA-256 certification for every generated release package.
- Added authenticated, path-contained Admin package download.
- Added native Operating Calendar synchronization for distributor deadline, presave, campaign start and release-day milestones with deterministic dedupe keys.
- Added durable distribution event history plus Admin audit and Agent Brain logging.
- Added Admin Agent routing for distribution, DSP, UPC/EAN, preflight and release-readiness questions.
- Expanded the compact Admin sidebar safely from 27 to 28 links.
- Application advances to **1.3.28**.
- Database schema advances to **1.3.24** via migration **2026-10-10-025**.

> Stonefellow prepares and tracks release delivery. It does not claim direct submission to Spotify, Apple Music or other DSPs until a real distributor integration is configured.

# Stonefellow v1.3.27 — Section 28: Rights, Credits & Licensing Registry

- Added first-class Admin **Rights + Licensing** workspace.
- Added reusable rights parties for writers, composers, publishers, artists, labels, master owners and licensors.
- Added authoritative rights work record for each catalog track.
- Added ISWC, PRO work ID, copyright, publisher, territory and registration metadata.
- Added composition ownership and master ownership splits.
- Stored ownership as integer basis points to avoid floating-point split errors.
- Added exact 100% composition/master readiness validation.
- Added track and release license/clearance records.
- Added pending, restricted, expired and expiring license intelligence.
- Added release-level rights readiness rollup.
- Added migration backfill from existing catalog rights metadata without inventing ownership percentages.
- Added explicit-confirmation boundary for deleting ownership splits and licenses.
- Added rights changes to Admin audit and Agent Brain.
- Added Admin Agent routing for rights/ownership/licensing questions.
- Application advances to **1.3.27**.
- Database schema advances to **1.3.23**.
