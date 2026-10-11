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
