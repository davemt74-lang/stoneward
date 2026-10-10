# Stonefellow v1.3.28 — Section 29: Press Kit, EPK & Media Relations

Stonefellow remains a **single-artist direct-to-fan platform**. Section 29 adds a structured electronic press-kit and media-relations workflow around the existing releases, Media Library, email delivery and Admin Agent Brain.

## Electronic press kits

Admin now includes **EPK + Press**.

An EPK can contain:

- title and share slug
- release association or general artist-kit mode
- headline and short summary
- long artist/project bio
- press contact name/email
- website link
- press-ready Media Library attachments
- release artwork, date, genre, description, liner notes and credits
- playable release tracks

EPK media reuses the central Media Library rather than creating another uploader.

## Public and private sharing

EPKs support:

- draft
- published
- private
- archived

Published EPKs use:

`/press/{slug}`

Private EPKs require a bearer token.

Stonefellow stores only the SHA-256 token hash. Rotating a private link invalidates the old token and reveals the new raw link once to the current Admin session.

Draft and archived EPKs are not publicly available.

## Press contacts

Press contacts are separate from the Fan CRM because journalists/media contacts are not fans and should not inherit newsletter consent semantics.

Each press contact can store:

- name
- outlet
- email
- role
- location
- tags
- notes
- active / do-not-contact / archived status

Do-not-contact and archived contacts cannot receive press outreach.

## Governed outreach

Stonefellow can prepare and send one press outreach message at a time.

Every send:

- requires explicit Admin confirmation
- uses the existing configured Stonefellow email delivery
- writes to the existing transactional email outbox
- records the actual delivery state returned by that system: queued, sent or failed
- records the action in Admin audit and Agent Brain

Stonefellow does **not** claim an email was opened/read. There is no tracking pixel or invented email-open event.

For private EPKs, the Admin must have the current raw private token in the current session before outreach can include that private link.

## Coverage

Admin can record confirmed:

- articles
- reviews
- interviews
- radio
- podcasts
- video
- playlists
- other coverage

Coverage is a separate fact ledger from outreach.

## EPK analytics

Public EPK pages record only real in-site interactions:

- page view
- media click
- contact link click
- release-track play
- download click

These events are not interpreted as email opens.

## Media Library

`press_kit` is now a reusable Media Library relationship type.

EPKs can attach:

- hero artwork
- press photos
- approved alternate audio
- video
- PDFs/documents
- archive media

Only assets marked Public are returned to the public EPK.

## Agent Brain

Admin Agent Brain records:

- EPK saves/publishing
- private-link rotation
- press contact changes
- confirmed outreach sends
- recorded coverage

The Admin Agent routes EPK, journalist, reviewer, media-contact, outreach and coverage requests to EPK + Press.

“Record pressing / vinyl manufacturing” continues to route to POD Handoffs rather than Media Relations.

Application: **1.3.28**  
Database schema target: **1.3.24**
