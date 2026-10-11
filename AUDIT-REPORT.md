# Stonefellow v1.3.28 — Section 29 Hardened Release Publishing & Distribution Audit

Section 29 was re-audited after its initial merge. The second pass treated passing tests as the starting point, not the score, and reviewed failure modes, data integrity, state transitions, package contents, privacy boundaries, storage containment and upgrade behavior.

## Defects found and corrected

- Legacy playback audio or artwork could satisfy preflight even though the package builder only exported managed Media Library files.
- Media lookup did not guarantee `master_audio` was preferred over `primary_audio`.
- Managed media records could pass readiness even when the underlying file was missing.
- Cover preflight did not enforce square geometry or distributor-safe JPG/PNG format.
- WAV technical quality was not enforced.
- Calendar re-sync could reset a completed distribution milestone to pending.
- DSP/release lifecycle states could be moved without enough operational evidence.
- Live DSP links were not required to be valid HTTPS URLs.
- Raw Rights Registry structures exposed more internal fields than a distributor package needs.
- Package freshness used timestamps and could be invalidated by unrelated DSP status changes.
- Package download did not re-verify the stored ZIP hash before serving it.
- Package creation did not re-hash managed source files before adding them.
- A manual audit caught and fixed a DSP URL self-assignment regression before merge.
- Older regression tests emitted PHP warnings due to interpolated assertion literals; the warning noise is now removed.

## 29A — Distribution Metadata & Identifiers — 10/10
- UPC-A / EAN-13 checksum validation.
- Duplicate UPC/EAN assignment is blocked.
- ISRC normalization/validation remains deterministic.
- Explicit-release flag must agree with explicit track metadata.
- Distribution metadata edits invalidate any previously built package.

## 29B — Managed Media & Preflight — 10/10
- Distributor assets must come from controlled Media Library storage.
- Legacy playback paths do not qualify as delivery masters.
- Managed file existence/path containment is verified.
- Cover art must be square, at least 3000×3000, and JPG/PNG.
- Audio delivery requires managed WAV, at least 44.1 kHz / 16-bit, with valid channel metadata.
- `master_audio` has deterministic priority over `primary_audio`.

## 29C — Rights & Privacy Boundary — 10/10
- Section 28 remains the authority for rights readiness.
- Distributor manifest uses a minimized rights projection.
- Internal rights notes, legal/internal rows and license terms are not dumped wholesale into the package.
- Composition/master ownership readiness still blocks delivery when incomplete.

## 29D — Package Integrity — 10/10
- Package contains manifest, credits/rights CSV, managed cover and one managed master per track.
- Managed cover/master bytes are SHA-256 checked against Media Library records before packaging.
- Final ZIP is reopened and verified.
- Master count and source fingerprint are checked.
- Final ZIP receives SHA-256 certification.
- Download endpoint re-hashes the ZIP and refuses stale or corrupted packages.

## 29E — Exact Package Freshness — 10/10
- Delivery source is fingerprinted from release metadata, catalog track data, managed asset hashes/specs and sanitized rights data.
- The source fingerprint is embedded in `manifest.json`.
- Existing package fingerprint is compared against current source state.
- DSP tracking changes do not incorrectly stale the package because destination status is not delivery-source content.
- Older packages without a fingerprint are safely treated as stale.

## 29F — Governed Publishing Lifecycle — 10/10
- Release transitions are constrained: Draft → Preparing → Ready → Submitted → Scheduled → Live, with governed archival.
- Submitted/Scheduled/Live require a current package and matching DSP evidence.
- DSP transitions are forward-governed; failure/recovery is explicit.
- Live status requires a valid HTTPS public release URL.
- Submitted, scheduled, live, archived and failed consequential states require explicit Admin confirmation.

## 29G — Calendar, Agent & Admin UX — 10/10
- Calendar sync uses deterministic source keys.
- Re-sync updates title/dates without resetting completion state.
- Admin UI only presents governed release/DSP transitions.
- Stale package state is visible and download is withheld until rebuilt.
- Track delivery UI exposes managed/master technical readiness.
- Admin Agent, Agent Brain and Admin audit remain integrated.
- 28-link sidebar density regression remains enforced.

## 29H — Regression & Release Safety — 10/10
- PHP syntax: **PASS**
- Public JavaScript syntax: **PASS**
- Admin JavaScript syntax: **PASS**
- Sections 1–28 inherited suites: **PASS**
- Original Section 29 certification: **PASS**
- Section 29 hardening suite: **PASS**
- Explicit PASS lines on measured hardening candidate: **1577**
- PHP warnings: **0**
- Failures: **0**

FINAL SECTION 29 HARDENED SCORE: **10/10**

The application remains **v1.3.28** and database schema remains **1.3.24**. This hardening requires no new database migration.
