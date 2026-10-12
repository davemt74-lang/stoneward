# Stonefellow v1.3.36 — Section 37: Contact, Booking & Professional Inquiries

Stonefellow now has a dedicated professional contact workflow for a **single artist**.

Public visitors can use **/contact** for booking, press/media, licensing/sync, collaboration or another direct professional inquiry. These submissions are deliberately separate from Fan CRM and newsletter consent.

## Professional inbox

Admin → Fans + CRM includes a Professional Inquiries panel with status/type/search filters, conversation history, private notes and governed replies.

Replies use Stonefellow’s transactional email outbox. The inquiry is marked Replied only when delivery reports Sent. Duplicate active/successful replies are suppressed server-side, while failed sends can be retried.

## Privacy and governance

- no automatic Fan CRM contact creation
- no marketing opt-in
- explicit permission to reply about the inquiry only
- source page stores path only, not query strings/tokens
- public Agent can route someone to Contact but cannot read inbox records
- Admin audit/Agent Brain do not copy message bodies

Application: **1.3.36**  
Database schema target: **1.3.31**

---

# Stonefellow v1.3.35 — Section 36: Visual Media & Video Experience

Stonefellow now includes a first-class single-artist Video Hub built on the existing Media Library.

## Video Hub
Admin → Media Library contains the Public Video Hub publisher. Video records represent music videos, live sessions, visualizers, behind-the-scenes films, interviews, short films and related Stonefellow visual work.

Video records store editorial/publication metadata only. MP4/WebM/MOV files, posters, captions and gallery photos remain reusable Media Library assets.

## Public publishing
A published video must have:
- exactly one Public managed video;
- MP4 or WebM public playback;
- known managed duration;
- exactly one Public poster;
- poster dimensions at least 640×360 in either orientation;
- valid public song/release/show links;
- valid chapters;
- optional WebVTT caption file with a real WEBVTT header.

MOV can remain a source/private asset but is not a browser-safe public deliverable.

## Public experience
Published-ready video appears at /videos and /video/{slug}. The detail page uses native controls, never autoplay, supports managed captions and chapters, shows linked music/live context and public gallery photos.

## Events and Agent
Video analytics record only real user interactions: view, play, pause, 25/50/75 progress, completion, caption enable, chapter seek and linked-entity open. The public Agent can browse and discuss only published-ready Video Hub records.

Application: 1.3.35
Database schema target: 1.3.30

---

# Stonefellow v1.3.34 — Section 35: Lyrics, Songbook & Track Notes

Stonefellow now has a governed Songbook layer for publishing lyrics and lyric context without making the private Catalog lyric field automatically public.

## Privacy model

Canonical lyrics live on the private Catalog track record.

The generated public catalog JavaScript deliberately removes the `lyrics` field. A visitor can receive lyric text only through a Songbook that is:

- attached to a public Stonefellow track;
- explicitly Published;
- currently readiness-green.

This same boundary is used by public search and Agent grounding.

## Songbook publishing

Admin → Catalog → Track includes the Songbook editor. It manages:

- Draft / Published / Archived status
- language
- verse / chorus / bridge / other section ranges
- line annotations
- optional songwriting credits
- internal Songbook notes

Automatic section detection can recognize common structural markers such as Verse, Chorus, Bridge, Hook, Intro and Outro.

Publication requires complete meaningful-line coverage. Blank lines and recognized structural heading lines do not need to be assigned as lyric content.

## Public track pages

Published Songbooks appear within the existing track detail experience. Lyrics render in structured sections with optional line annotations. Raw Catalog lyrics are not rendered directly.

## Search and Agent

Public search includes lyric matches only for published Songbooks.

The Stonefellow Agent may receive bounded published Songbook text when it is relevant to the user’s question. Draft/private lyric text is excluded from public Agent ranking/context.

## Analytics

Songbook analytics record only real in-site interactions:

- Songbook view
- section open
- annotation open

No fabricated share, impression or email-style tracking is generated.

Application: **1.3.34**  
Database schema target: **1.3.29**

---

# Stonefellow v1.3.33 — Section 34: Artist Brand & Visual Theme

Stonefellow remains a **single-artist platform**. Section 34 adds controlled visual identity without becoming a generic theme marketplace or arbitrary site builder.

## Artist Brand

Admin → Settings now includes **Artist Brand & Visual Theme**. It does not add another sidebar item.

Built-in starting points:

- Desert
- Midnight
- Paper
- Monochrome

Each can run Dark, Light or Follow System.

## Safe customization

The theme system exposes only bounded design controls:

- background and surface colors
- primary and secondary text
- accent and accent-button text
- danger color
- display and body type stacks
- content width
- comfortable or compact density
- square, soft or round corners

Arbitrary CSS, JavaScript, remote fonts and per-page themes are intentionally unsupported.

## Draft before publish

Theme changes are saved as a draft first. The live public site continues using the published theme.

The **Live saved-draft preview** opens the real Stonefellow public application with the draft theme. It is available only to an Administrator, is noindex, has no canonical URL and does not write normal discovery/Homepage/Storytelling analytics.

Publishing or restoring the default theme requires explicit confirmation.

## Accessibility

Stonefellow calculates contrast server-side and blocks publication when required color pairs do not meet the configured WCAG-style thresholds. Follow System validates both dark and light palettes.

## Site-wide visual identity

Published theme tokens apply to the persistent shell, Agent/chat/player, Homepage, Storytelling, catalog, releases, shows, store, account/library, Splash and maintenance state.

Application: **1.3.33**  
Database schema target: **1.3.28**

---

# Stonefellow v1.3.32 — Section 33: Public Content & Storytelling

Stonefellow remains a **single-artist platform**. Section 33 adds governed long-form storytelling around the artist’s real catalog without turning the product into a generic CMS.

## One editorial system, one artist

Storytelling lives in Admin → Settings and supports:

- Artist About
- Editorial Essay
- Release Story
- Track Story
- Show Story
- Timeline Milestone

There is no artist selector, tenant ID or second catalog.

## Canonical ownership

Storytelling adds context around the existing systems rather than duplicating them:

- Song metadata and short song story remain owned by Catalog.
- Release metadata, descriptions and liner notes remain owned by Releases.
- Show facts remain owned by Shows & Live.
- Long-form editorial can link to those public records and appears alongside them.

## Artist About as the narrative source

Once an Artist About is published, it becomes the governed narrative source for:

- the public About page,
- the Homepage Artist Story section,
- About SEO metadata,
- and grounded public Agent context.

The earlier Homepage/About copy remains a fallback until a published Artist About exists.

## Story media

Stories reuse Media Library assets. Hero, inline and gallery media can be attached; public presentation only receives explicitly public media relationships.

## Public editorial

Published, readiness-approved stories receive clean `/story/{slug}` URLs, route-specific SEO and Article structured data. Public track, release and show pages can surface linked editorial without replacing their canonical data.

## Truthful analytics

Storytelling records only observable public actions: view, connected-content open, media open and completed share.

Application: **1.3.32**  
Database schema target: **1.3.28**

---

# Stonefellow v1.3.31 — Section 32: SEO, Discovery & Social Sharing

Stonefellow’s public pages now have a canonical discovery layer built around the existing single-artist catalog.

## Canonical public routes

- /track/{id}
- /release/{id}
- /show/{id}
- /store/{slug}
- /campaign/{slug}
- /press/{slug}
- /music, /releases, /shows, /store, /about, /archive

The AJAX application still provides uninterrupted playback, but browser/history URLs and the server response now agree on the public canonical route.

## Search + social metadata

Initial HTML and later SPA navigation share the same server-side metadata resolver. Public pages can emit title, description, canonical URL, robots policy, Open Graph, social-card tags and JSON-LD.

Structured data includes MusicGroup, MusicAlbum, MusicRecording, MusicEvent, Product and ProfilePage.

## Sitemap + privacy

/sitemap.xml contains only genuinely public content. Draft/private/hidden records and private EPKs are filtered before sitemap generation.

/robots.txt blocks Admin/API/storage/data surfaces and common account/auth/token URLs.

## Social sharing

Songs, releases, shows and focused merch pages expose Share controls. Stonefellow uses native Web Share where available and clipboard-copy fallback otherwise. A share event is recorded only after the action actually succeeds.

## Discovery attribution

Stonefellow records bounded acquisition facts into its existing database:
- direct
- organic search
- social
- external referral
- campaign / UTM source

Full referrer URLs are not retained. Landing attribution is recorded once per page load rather than every internal AJAX navigation.

The resulting source/route data appears in Admin → Performance Intelligence and in the grounded Admin Agent business brief.

Application: **1.3.31**
Database schema target: **1.3.27**

---

# Stonefellow v1.3.30 — Section 31: Public Artist Experience & Fan Journey

Stonefellow remains a **single-artist platform**. Section 31 gives that artist a true public homepage without turning the project into a multi-artist CMS.

## Artist Homepage plugin

Admin → Settings now contains an **Artist Homepage** module and an independent on/off toggle.

The existing Splash remains separate. Public launch behavior is:

**Splash (optional) → Artist Homepage (optional) → existing Agent/Personalized Home fallback**

This makes the new homepage safe to configure before launch. Turning it off immediately returns the site to the existing Home experience.

## Homepage sections

The homepage is intentionally opinionated around one artist:

- Hero
- Featured Release
- Featured Track
- Latest Releases
- Upcoming Show
- Featured Merch
- Artist Story
- Newsletter
- Your Stonefellow personalization for logged-in fans

Each section can be enabled/disabled and reordered. The Admin can edit copy and select explicit featured content, or leave release/track/show/merch selectors on **Auto** so Stonefellow chooses from current authoritative public data.

There is no artist selector, tenant ID or second catalog.

## Public experience

When enabled, the homepage becomes the visual landing surface. The large Agent orb/stage is hidden on Home so the site reads as an artist website first, while the persistent Agent chat bar and music player remain available.

The homepage pulls from existing systems instead of duplicating content:

- Media Library for public imagery and featured-track audio
- Releases + Distribution for releases and live DSP links
- Shows for the next public live date
- Merch for active available products
- Newsletter/CRM for fan signup
- Personalization for logged-in Continue Listening/favorites/Agent suggestions

## Publishing boundaries

Homepage track playback requires an explicitly Public Media Library `primary_audio` relationship. Legacy/private catalog audio is never used as a homepage fallback.

Explicit homepage release/show/product choices are server-validated against public Stonefellow records. DSP links are defensively revalidated as HTTPS before being exposed.

## Readiness

Settings displays a homepage readiness score and current blockers/warnings, including public hero imagery, featured release/cover, featured-track audio, artist photo, upcoming show and merch availability.

## Fan journey analytics

Homepage analytics record real Stonefellow actions only:

- page view
- hero CTA
- release open
- track open/play
- show open
- merch open
- newsletter interaction
- About
- personalization
- Agent entry

No invented email opens, impressions or inferred engagement are generated.

Application: **1.3.30**  
Database schema target: **1.3.26**

---

# Stonefellow v1.3.29 — Section 30: Press Kit, EPK & Media Relations

Stonefellow remains a **single-artist direct-to-fan platform**. Section 30 adds a governed media-relations layer around the existing catalog, Media Library, releases, Distribution + Publishing, transactional email and Agent Brain.

## Electronic press kits

Admin → **EPK + Press** can create a general artist EPK or link a kit to a specific release.

An EPK includes press-ready headline/summary/bio, public press contact information, reusable Media Library assets, and optional release context. Release-linked EPKs consume the same authoritative release and Distribution + Publishing data already maintained elsewhere in Stonefellow.

Published and Private kits must pass current readiness. A later change that makes required media or release readiness invalid will make the EPK unavailable until it is corrected.

## Public and private sharing

Published EPKs use:

`/press/{slug}`

Private EPKs use the same route plus a bearer token in the URL **fragment**. The raw token is never stored on the EPK record; Stonefellow stores its SHA-256 hash and a short display hint. Private EPK content is fetched by POST so the bearer value is not sent in the initial page request query string.

Rotating a token invalidates the previous private link. Leaving Private status clears the old token.

## Media and listening

EPK media reuses the central Media Library. There is no separate EPK uploader.

Public EPK pages can include explicitly Public hero art, press photos, approved audio/video, documents and archive media. Release and track media are also filtered through their Public Media Library relationships. Legacy/private catalog audio is not automatically exposed.

Release-linked EPKs can show live Spotify, Apple Music, YouTube Music, Amazon Music and Bandcamp links recorded by Section 29.

## Press contacts and outreach

Press Contacts are separate from Fan CRM because journalists, reviewers, radio programmers, podcasters and other media contacts should not inherit fan-newsletter consent semantics.

Contacts support Active, Do Not Contact and Archived status. Only Active contacts may receive press outreach.

Every outreach send:
- requires explicit Admin confirmation;
- creates a press-outreach ledger entry before delivery;
- uses Stonefellow’s existing transactional email/outbox;
- preserves real queued/logged/sent/failed state and delivery errors;
- suppresses duplicate active/successful sends for five minutes;
- allows failed attempts to retry;
- never invents an email-open event.

## Coverage and EPK analytics

Coverage is a separate confirmed-fact ledger for articles, reviews, interviews, radio, podcasts, video, playlists and other placements.

EPK analytics record only real Stonefellow interactions: page views, media clicks, press-contact clicks, release-track plays, downloads and DSP clicks. These events are not interpreted as email opens.

## Agent Brain

Admin Agent can route press-kit, journalist/reviewer, media-contact, outreach and coverage work into EPK + Press. Vinyl/record pressing remains a POD Handoff intent.

EPK publishing, token rotation, press-contact changes, confirmed sends and coverage changes are recorded in Admin audit and Agent Brain without logging the raw private bearer token.

Application: **1.3.29**  
Database schema target: **1.3.25**

---

# Stonefellow v1.3.28 — Section 29: Release Publishing & Distribution Operations

Stonefellow remains a **single-artist direct-to-fan platform**. Section 29 turns the existing release, Media Library, Rights Registry and Operating Calendar data into a governed distribution-preparation workflow.

## Distribution + Publishing

Admin now has a first-class workspace that answers the operational question: **is this release actually ready to deliver?**

Preflight covers:

- release title and date
- primary artist, label, genre, language and territory
- UPC-A / EAN-13
- per-track ISRCs
- managed WAV delivery masters at 44.1 kHz / 16-bit or better
- managed square JPG/PNG cover artwork at 3000×3000 or greater
- © / ℗ lines
- Section 28 composition/master ownership and license blockers

Stonefellow produces a deterministic readiness score, blockers and warnings before allowing Ready, Submitted, Scheduled or Live states.

## Distributor packages

A green release can generate an authenticated distributor package containing:

- `manifest.json`
- `credits-rights.csv`
- managed audio masters
- managed front cover
- exact delivery-source fingerprint
- SHA-256 package digest verified again at download

Stonefellow rechecks managed source hashes while building the ZIP, minimizes the Rights Registry data included in the manifest, and treats packages as stale whenever delivery-source content changes. Stonefellow does **not** claim to submit directly to Spotify, Apple Music or other DSPs without a real distributor API. It prepares the delivery package and records external submission/live status.

## DSP status and launch calendar

The workspace tracks Spotify, Apple Music, YouTube Music, Amazon Music and Bandcamp status, external IDs and HTTPS live URLs. Publishing and DSP states follow governed forward transitions, and Submitted/Scheduled/Live require a current package plus matching DSP evidence. It can also synchronize distributor deadline, presave, campaign start and release-day checks into the native Operating Calendar using deterministic milestone keys.

---

# Stonefellow v1.3.27 — Section 28: Rights, Credits & Licensing Registry

Stonefellow remains a **single-artist direct-to-fan platform**. Section 28 adds an authoritative Admin rights layer for the catalog and releases without pretending to be a royalty-accounting service.

## Rights Registry

Admin now has a first-class **Rights + Licensing** workspace connected to the existing Stonefellow catalog.

It manages:

- Rights parties: writers, composers, publishers, artists, labels, master owners and licensors
- One rights work per Stonefellow catalog track
- ISWC and PRO work identifiers
- Composition copyright
- Master copyright
- Publisher and territory metadata
- Composition ownership splits
- Master ownership splits
- Track/release license and clearance records
- Work and release rights-readiness status

## Ownership math

Stonefellow stores ownership as integer **basis points**.

- 10,000 basis points = 100%
- Composition ownership must total exactly 100%
- Master ownership must total exactly 100%

A work is rights-ready only when both ownership groups equal exactly 100% and no recorded license is pending, restricted or expired.

Stonefellow does **not** infer ownership percentages from existing writer/composer credit text.

## Registration vs readiness

ISWC, PRO work ID and registration status are tracked, but registration is not falsely treated as mandatory for every unreleased work.

Supported registration states:

- draft
- submitted
- registered
- not applicable

Rights readiness and registration state remain separate concepts.

## Licensing

Track and release rights can record:

- sync
- mechanical
- master use
- sample
- cover
- remix
- name / likeness
- other

Each record may include licensor, status, territory, start/end dates, reference/contract ID, terms and notes.

Pending/restricted/expired clearances block readiness. Cleared licenses expiring within 60 days generate a warning.

## Release clearance

Release readiness rolls up:

- every track’s composition ownership
- every track’s master ownership
- track-level license blockers
- release-level licenses

This gives Admin a single pre-release rights checkpoint without changing the existing Release Builder’s catalog/release authority.

## Catalog migration

Migration **2026-10-10-024** creates rights work records for the current catalog and safely copies existing descriptive rights metadata where present:

- title
- copyright year
- composition copyright
- master copyright
- publisher
- ISWC / PRO work ID if those fields already exist

The migration does **not** create parties or ownership splits from free-text credits.

## Governance + Agent Brain

Rights metadata edits are audited.

Ownership split changes are treated as consequential. Split/license deletion requires explicit confirmation.

Admin Agent Brain records:

- work metadata updates
- rights party updates
- ownership split changes
- license/clearance changes
- catalog synchronization

The Admin Agent routes questions about rights, ownership, splits, ISWC, PRO registration, licensing and clearance to this workspace.

Private ownership data is not added to public Agent context.

Application: **1.3.27**  
Database schema target: **1.3.23**
