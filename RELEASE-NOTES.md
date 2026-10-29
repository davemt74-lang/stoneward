# Stonefellow v1.3.169 — Section 91B4: Speech-state echo protection and Admin music controls

Public and Admin Agent speech now has an explicit output lifecycle with a post-speech acoustic-tail cooldown before normal microphone recognition can resume. During real Agent speech the dedicated verified barge-in recognizer remains available, but normal dictation cannot consume the Agent's output; the silence probe allows additional echo decay before accepting independent voices. The public catalog player retains its existing Agent tools. Admin now has a compact same-origin catalog-preview music player controlled by voice/text commands for play, pause, resume, next/previous, status, mute and volume, with Agent-speech ducking and temporary silence during acoustic probes. Private audio masters and external audio URLs are not played. Browser autoplay restrictions can still require an initial manual Play. No database migration.

## Previous release: Stonefellow v1.3.168 — Section 91B3D: Reviewed private image attachments

Admin Agent Chat can attach an existing image asset by numeric ID to an exact song or release as a private artwork/cover candidate. The existing source JSON and media-link tables are read with revision fingerprints, and an Admin must prepare a canonical proposal, type `APPROVE`, and separately click Save. The save runs in one SQL transaction, refuses duplicate/stale relationships, and verifies the new link is private, unfeatured and not downloadable. Existing published artwork remains unchanged. No media file, publishing, billing, sending, deletion or visibility change. No database migration.

## Previous release: Stonefellow v1.3.167 — Section 91B3C: Reviewed image accessibility alt text

Admin Agent Chat now prepares one-image `alt_text` improvements by exact Media Library ID. Public use and total attachment counts are shown before an exact hash-bound proposal; a typed `APPROVE` and a separate Save step are both required. The transaction compares the current image and attachment revisions, changes only the image accessibility description, and verifies the saved value. Audio/video, files, captions, links, publication, customer actions, billing and deletion remain outside this scope. No database migration.

## Previous release: Stonefellow v1.3.166 — Section 91B3B: Exact-reviewed Media Library captions

Admin Agent Chat can now prepare a caption edit for one exact Media Library asset ID. The owner sees the old/new caption, asset name, total attachment count and number of public links before staging a server-normalized, hash-bound proposal. A separate typed `APPROVE` and save step uses the existing action ledger. The SQL destination update is guarded against concurrent asset changes and verifies the saved caption; attachment relationships and their visibility are never modified. A caption on an already public asset may change visible text on every public use, which the modal explicitly warns about. No upload, delete, publication, billing or outside services are authorized.

## Previous release: Stonefellow v1.3.165 — Section 91B3A: Exact-reviewed Knowledge Base notes

Admin Agent Chat can now propose a change to the private description/notes on one exact, uniquely identified Knowledge Base file. The existing owner-scoped proposal ledger provides an immutable snapshot, SHA-256 review, typed APPROVE, separate execution and no-retry recovery. The new editor review modal verifies the proposed previous/new value; stale Knowledge Base versions refuse the edit. Only `notes` may change: file contents, file storage, Agent visibility and sharing remain untouched. No model writes directly; this increment grants no media attachment writes, publishing, deletion, outbound messages or billing. No database migration.

## Previous release: Stonefellow v1.3.164 — Section 91B2C: Knowledge Base and Media Library reads

Admin Agent Chat can read bounded inventories of its existing Knowledge Base documents, Media Library assets, and media-to-record attachments. New Admin-only read contracts return document names/visibility, media categories/usage, and attachment roles/visibility. Document contents, notes, storage paths, hashes and private media internals do not enter responses. Navigation and exact-review permissions remain unchanged; no files are published, deleted or modified by the Agent. Added Section 91B2C regression tests to the release gate. No database migration, new external provider, VP3 or Gelato change.

## Previous release: Stonefellow v1.3.163 — Section 91B2B: Real Admin live-show reads

Admin Agent Chat now reads actual saved Shows + Live records for upcoming scheduled performances, postponed shows, and cancellations. Responses include bounded venue/city/date/status fields only; private archive notes, media and ticket URLs never enter the reply or external model. Added Admin-only `admin_show_inventory` tool/skill and pure-source regression cases. Existing catalog/release reads, navigation and all permission controls remain intact. No schema change or VP3/Gelato work.

## Previous release: Stonefellow v1.3.162 — Section 91B2A: Authorized catalog and release status reads

The Admin Agent now resolves unpublished/private tracks, missing ISRC/writer information, pending releases and scheduled releases against Stonefellow's existing source data. Responses are bounded and read-only, omit private media/lyrics/notes, and never forward the private inventory to an external LLM. Both tools are Admin-scoped in the existing skill/registry system; normal navigation, approval gates and the 91B1 coverage audit are preserved. Section 91B2A adds deterministic fixture/role tests to the full release gate. No database migration or VP3/Gelato change.

## Previous release: Stonefellow v1.3.161 — Section 91B1: Agent integration coverage audit

Section 91B1 adds an authenticated, read-only Agent capability coverage diagnostic (`admin/api/agent-tools.php?audit=1`). It inventories live tool and skill contracts, distinguishes the 23 Admin navigation-only intents from genuine data operations, and enumerates the five existing exact-reviewed proposal paths. The coverage audit never grants execution, changes permissions, invokes a model or modifies data. Its regression tests are part of the full release gate. The full operational-control integrations remain scoped to 91B2–91B5.

## Previous release: Stonefellow v1.3.160 — Section 91A: Conversational voice reliability

- Public and Admin speech monitoring now requires both independently transcribed participant words and continuing microphone speech after the Agent output is silenced. The Agent's own voice level alone cannot repeatedly pause or stop a reply.
- Browser speech playback retries a transient canceled/interrupted chunk once, and does not skip to the next chunk if playback ends during a temporary echo probe. Normal listening resumes only after all spoken chunks end or playback is explicitly stopped.
- ElevenLabs segments no longer treat a delayed audio pause event as the end of a reply. Music continues with the existing ducking and echo-check rules.
- Added an accessible Stop speaking button in the Admin chat bar while an answer is playing; the public Stop speaking button remains available.
- If speech recognition becomes unavailable, automatic interruption fails safely instead of mistaking the Agent for a person. The visible/manual stop controls remain available. No new database migration or external voice disclosure.
- Acceptance and repeatable regression checks: `tests/section91a-voice-completion.cjs`.

---

# Stonefellow v1.3.142 — Section 90J: Agent page awareness and exact-record navigation

- The public Agent understands “what page am I on?” and “what can I do here?”, with actionable suggestions tied to the current installed view.
- Exact named song and release requests open the published item in the existing SPA without restarting music playback or resetting chat.
- “Open my latest release” uses verified published release dates, requiring disambiguation for same-day releases.
- Relative requests such as “open this song” resolve against the viewed track or currently playing public track; “open this release” resolves against the validated current release.
- Ambiguous titles, unpublished items, forged route IDs, arbitrary URLs and unsupported page names never become a deep link.
- Admin Agent Chat accepts bounded page context and answers what the current installed Admin module supports without executing an edit or revealing private record data.
- “Open the song editor for Exact Song Title” opens the matching Admin Catalog editor without automatically saving or publishing.
- No schema migration. Added Section 90J PHP and JavaScript regression tests to the release gate; Section 90I and prior approval contracts remain unchanged.

---

# Stonefellow v1.3.141 — Section 90I: Agent page navigation

- Natural-language public Agent commands can switch between installed Stonefellow pages, including Music, Releases, Shows, My Library, Agent Chat and Agent Brain.
- The Admin Agent can switch directly to an authorized Admin workspace (for example, Dashboard, Releases, CRM, Analytics or Settings) when the owner explicitly asks to open it. The persistent composer and saved chat history remain available.
- Destinations are resolved from fixed route aliases; arbitrary URLs, private file paths, and cross-account navigation are not accepted.
- No upload, publish, payment, campaign or account mutation is triggered by a navigation command. Existing edit proposals and approvals are unchanged.
- No database migration. PHP intent tests and browser wiring checks run in the full release gate.

## Previous release

### Stonefellow v1.3.140 — Section 90H: Reviewed song catalog publication

- Prepared, rights-reviewed private song drafts now have a separate Admin-controlled publication workflow.
- Public listening requires an independently uploaded MP3 export; the original WAV/MP3 source is never published as preview audio.
- A confirmation-gated publishing action creates one real public catalog track, with safe deduplication, stable track identity, owner-only receipt and resumable partial-write recovery.
- The original master remains a private media attachment. Album/release creation remains a separate workflow.
- Admin Agent Chat can open publishing review, handle preview uploads and display a public track link after successful publication.
- Includes Section 90H acceptance checks for scope, rights, duplicate protection, public data boundaries and UI actions.

### Earlier: Stonefellow v1.3.139 — Section 90G: Private song catalog preflight

Admin Agent Chat now extends the guided recording upload with a private artwork picker, cover preview, artist/lyrics/composition credits, composition and master ownership, optional ISRC and release date, explicit-content metadata, and an administrator rights attestation. The server calculates readiness against the saved recording and cover asset (including ownership and content hashes) and checks duplicate ISRC values.

An explicit **Prepare for catalog** approval moves a revisioned private song draft to `catalog_ready` exactly once. The operation does **not** write the public catalog, publish audio, create a release, or contact fans. Owner-scoped drafts remain stored privately; repeat approval is idempotent, stale revisions cannot be prepared, and Admin audit logs record the transition. The next phase can build catalog staging/publication from these reviewed records with a separate approval.

No new database migration. Full PHP and browser-contract tests run in the release gate.

---

# Stonefellow v1.3.45 — Section 46: Dual Agent Brain & Unified Canvas

Stonefellow now uses a **private customer Agent Brain per authenticated account**, a **separate operational Admin Agent Brain per administrator**, and one shared server-governed tool registry. Customers can manage explicitly saved preferences and private conversations inside My Agent Brain and My Agent Chat; Admin receives an operational chat workspace with an Agent Brain sidebar, missions, evidence and exact-review approvals. Customer chat content and individual memory are never included in Admin operational reporting.

The integration retains the existing music player, Campaign/Automation Builders, permission checks, Section 44 approval ledger and Section 45 no-replay recovery. **Schema 1.3.35** adds migration 036 for the new durable Brain tables. Existing data is preserved by the standard upgrade workflow.

Full implementation, privacy boundaries and deployment checklist: [Section 46 acceptance notes](docs/section46-dual-agent-brain-acceptance.md).

---

# Stonefellow v1.3.44 — Section 45: Fan Journey Intelligence

Stonefellow now provides a privacy-safe view of fan engagement across CRM, linked listening, opt-in, commerce, campaigns and existing lifecycle automations. Admin Journey Intelligence includes measured performance, evidence-backed review suggestions, governed Agent-created **unpublished** journey drafts, and safe no-replay recovery of interrupted work.

The new read-only reports do not infer a sequential causal funnel from unrelated aggregate counts. Email and proactive-Agent actions are still subject to the fan's most recent consent and privacy choices. **The Agent cannot publish journeys or send marketing messages autonomously.**

Database target remains **1.3.34**; no new migration beyond the existing v1.3.43 target. See [Section 45 acceptance and deployment notes](docs/section45-journey-intelligence-acceptance.md).

---

# Stonefellow v1.3.43 — Section 44: Governed Agent Campaign Actions

Section 44 makes the existing single-artist Stonefellow Admin Agent useful for **reviewed, permission-controlled campaign DRAFT creation**. It adds an owner-visible tool inventory; durable plan/approval/save stages; aggregate consent-aware CRM audience research; published-content draft suggestions; Agent Brain action history; exact-hash approval, execution caps and safe recovery; and mobile-friendly Admin review.

**No automatic newsletter sends, campaign publication, purchases or CRM edits.** These remain in the existing owner-controlled Admin interfaces. This release upgrades the schema target to **1.3.34** (Migration **035**) using the existing `upgrade.php` workflow.

See [Section 44 acceptance, security audit and deployment steps](docs/section44-agent-execution-acceptance.md).

---

# Stonefellow v1.3.42 — Section 43: Agent Intelligence & Fan Engagement

Stonefellow's existing single-artist Agent now has stricter public music discovery, consent-aware recommendations and proactive engagement, fan-requested favorites actions, role-gated aggregate administrator campaign briefings, validated ElevenLabs voice delivery, protected Agent Brain diagnostics, and bounded client routing state. Existing OpenAI/Claude/JEV routing, voice UX, persistent player and Agent Brain are retained.

No new database tables or migration. Database schema target: **1.3.33**. See [Section 43 acceptance and tool inventory](docs/section43-agent-acceptance.md).

---

# Stonefellow v1.3.41 — Section 42: Production Readiness & Operational Hardening

Stonefellow adds administrator-only read-only operational health diagnostics, verified backups before database migrations, upgrade failure/recovery visibility, safe browser error reporting, and release-gated deployment ZIPs with SHA-256 checksums. All Section 42 tests are included in the full release gate. Database target remains **1.3.33**; no schema migration is required. See `docs/section42-operations-acceptance.md`.

---

# Stonefellow v1.3.40 — Section 41: Fan Account, Profile & Privacy Center

- Added an account-only **Privacy & Data** center inside My Stonefellow.
- Added fan profile fields for timezone, country code and locale.
- Added real privacy controls for saving listening history and using account activity for personalization.
- When listening history is off, new listening events remain anonymous and Continue Listening stops updating.
- When personalization is off, recommendations and Agent music ranking use no account activity signals.
- Added confirmed JSON account-data export covering profile, library, listening, playlists, purchases, invoices, Agent conversations, support and identifiable telemetry.
- Added confirmed controls to clear listening history, reset personalization signals, anonymize search/discovery activity and delete Agent conversation history.
- Added governed account closure requiring current password, typed DELETE and final confirmation.
- Account closure is blocked for Administrators, active managed paid subscriptions, unresolved physical fulfillment and open support cases.
- Closure is transactional: required purge/anonymization failures leave the account open.
- Closure archives CRM identity, disables marketing, anonymizes retained email-delivery identity, revokes sessions, purges account-owned behavioral data and disables login.
- Historical orders, invoices, refunds and support records may remain as disclosed operational/accounting records.
- Preserved the existing public legal Privacy page; the account data center uses the separate account-privacy route.
- Public/Admin/server build and PWA cache advance together to **1.3.40**.
- Database schema advances to **1.3.33** via migration **2026-10-12-034**.

# Stonefellow v1.3.39 — Section 40: Accessibility, PWA & Mobile Resilience

- Added installable Stonefellow PWA manifest with standalone launch behavior.
- Added privacy-safe service worker caching only the static public shell.
- Added accessible offline fallback; API/account/Admin/private pages are never cached.
- Added progressive Install Stonefellow control.
- Added skip navigation and focusable main content.
- Added modal semantics, focus trapping/restoration and Escape handling for drawers.
- Added SPA aria-current synchronization.
- Added reduced-motion, forced-colors, focus-visible and 44px coarse-pointer support.
- Added secure-context service-worker registration and online/offline announcements.
- App advances to **1.3.39**.
- DB target remains **1.3.32**; no migration is introduced.

# Stonefellow v1.3.37 — Section 38: Photo Gallery & Visual Archive

- Added the single-artist **Photo Gallery & Visual Archive** system.
- Added Draft, Published and Archived gallery lifecycle with explicit Admin confirmation for visibility changes.
- Added reusable gallery relationships through the central Media Library; no duplicate gallery uploader/storage system was introduced.
- Gallery media accepts image assets only in governed cover/photo roles.
- Publishing requires one public cover, at least three public photos, minimum public image dimensions and complete alt text.
- Missing photographer/creator credit is surfaced as a warning.
- Galleries can connect to currently public releases, shows and editorial stories.
- Added embedded Photo Galleries management inside Media Library without increasing the 29-item Admin sidebar.
- Added public Photos hub and canonical /photos/{slug} detail pages.
- Added keyboard-aware photo lightbox with alt text, credits and copyright display.
- Added truthful gallery view, photo-open and entity-open events with public-readiness validation and rate limiting.
- Added published-only Agent Photo Gallery context and deterministic Photos browse intent.
- Added ImageGallery/ImageObject SEO and public-only sitemap integration.
- Preserved certified Video Hub confirmation/audit behavior while extending shared Media Library governance.
- Preserved Video Hub and Contact canonical route contracts.
- Hardened shared public media-links so draft/unready Video Hub and Photo Gallery records cannot expose media.
- Application advances to **1.3.37**.
- Database schema advances to **1.3.32** via migration **2026-10-11-033**.

# Stonefellow v1.3.36 — Section 37: Contact, Booking & Professional Inquiries

- Added public **Contact Stonefellow** experience for booking, press/media, licensing/sync, collaboration and general professional inquiries.
- Professional inquiries remain separate from Fan CRM/newsletter marketing consent.
- Added explicit inquiry-only contact permission, honeypot, rate limiting, duplicate submission protection and safe multiline validation.
- Booking and licensing forms support context-specific fields and authoritative public track/release references.
- Source attribution stores only the public page path and strips query strings/tokens.
- Added a professional inbox inside **Fans + CRM** without adding another Admin sidebar item.
- Added governed status lifecycle, internal notes, conversation history and durable inquiry events.
- Replies use the existing transactional email/outbox and preserve real sent/queued/logged/failed delivery state.
- Replied status cannot be manually claimed; it is set only when delivery reports Sent.
- Added server-side five-minute duplicate suppression for identical in-flight/successful replies while allowing failed retries.
- Status changes and reply sends require explicit Admin confirmation.
- Admin audit and Agent Brain receive governed summaries without copying private message bodies.
- Public Agent routes professional-contact intent to Contact but cannot read the professional inbox.
- Added canonical /contact route, ContactPage structured data and sitemap inclusion.
- Application advances to **1.3.36**.
- Database schema advances to **1.3.31** via migration **2026-10-11-032**.

# Stonefellow v1.3.35 — Section 36: Visual Media & Video Experience

- Added governed single-artist Video Hub.
- Video bytes remain in central Media Library.
- Added Draft / Published / Archived lifecycle with confirmation.
- Public readiness requires one managed public playback asset and poster.
- Public playback is MP4/WebM with known duration.
- Poster dimension readiness and actual WebVTT-header validation added.
- Added chapters, public /videos and /video/{slug}, linked entity context and gallery media.
- Added truthful video events and VideoObject SEO/sitemap integration.
- Added optional Artist Homepage Featured Video.
- Added public-only Agent video context.
- Added server-confirmed media visibility/poster publication with Admin audit + Agent Brain.
- Application 1.3.35; DB schema 1.3.30; migration 2026-10-11-031.

# Stonefellow v1.3.34 — Section 35: Lyrics, Songbook & Track Notes

- Added governed Songbook publishing for Stonefellow tracks.
- Canonical lyrics remain private Catalog data until an Administrator explicitly publishes a Songbook.
- Generated `assets/js/catalog.js` no longer contains canonical lyric text.
- Added Draft, Published and Archived Songbook lifecycle.
- Every Songbook visibility change requires explicit Admin confirmation.
- Added verse/chorus/bridge section ranges, language metadata, optional songwriting credits and line annotations.
- Publication readiness requires a public track, canonical lyrics, valid language, non-overlapping in-range sections, full meaningful-line coverage and valid annotation references.
- Added automatic section detection in the Catalog Songbook editor.
- Public track pages load governed Songbook content instead of raw Catalog lyrics.
- Public Songbook interaction metrics record only real views, section opens and annotation opens.
- Public search indexes lyrics only through a currently published Songbook.
- Public Agent grounding receives only bounded published Songbook lyric context.
- Migration 030 regenerates the public catalog asset immediately so existing private lyric fields are removed from browser-delivered catalog JS.
- Application advances to **1.3.34**.
- Database schema advances to **1.3.29** via migration **2026-10-11-030**.

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
