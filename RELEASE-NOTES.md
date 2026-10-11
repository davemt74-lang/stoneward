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
