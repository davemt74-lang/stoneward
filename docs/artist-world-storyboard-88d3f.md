# Stonefellow Artist World — 88D3F Storyboard & Production Preview

**Release:** v1.3.119 · **Database:** 1.3.56 · **Migration:** `2026-10-18-047`

## What editors see

**Admin → Agent & Knowledge → AI Production Review → AI Director Editorial Assembly**

Below the existing verified editorial timeline, the **Storyboard** shows ordered illustrated source cards for verified scene descriptions, dialogue segments and Stonefellow catalog music cues. Each card shows source identity, a virtual start/end time, cue category, transition and source-rights state. Applied AI Composer proposals from the current revision may supply suggested transitions; otherwise cuts are used. No source files or private URLs are returned to the browser.

The virtual playhead supports Play/Pause, Reset and manual scrubbing. It is a **metadata simulation**, not audio/video playback or a rendered preview. The UI makes this limitation explicit.

## Rights and evidence readiness

The server generates a list of individual blockers from:
- Source scene/transcript still verified and parent capture approved; canonical music ID still exists
- Independent **publication** rights for all contributor visual/dialogue sources
- Independent **synchronization** and **master-recording** rights for every catalog track in each assembly
- The private media file for capture-based sources existing under the controlled Media Library root and matching the stored byte size
- Assembly length and source intervals remaining valid

Music license decisions are recorded by a privileged editor with a substantive written basis, independent purpose (`sync` or `master`), explicit clearance/restriction, and the current assembly revision. They are audit-backed and transactional. Revocation immediately creates blockers. Any new timeline revision makes prior track decisions stale until re-reviewed.

Even when blockers are cleared, the preview explicitly states `render_engine_available=false`, `render_allowed=false` and `publication_allowed=false`. This readiness check is **not** a rendering certification: it does not rehash every full source file, inspect the presence/rights for a local track playback master, clear external copyrighted music, validate output codecs or execute FFmpeg. A future render worker must perform these checks again at the time of execution. A rights status record documents an editor's decision; it is not a legal guarantee.

## How to deploy and operate

Back up the installation, upload the verified full deployment ZIP and run `upgrade.php` to apply migration 047. Open an existing verified story assembly. Review its storyboard cards and blockers. For each music cue, separately record reviewed synchronization and master-use licenses using the scoped rights controls. The review UI requires normal Admin authentication and CSRF protections.

If source files are missing, restore the originals in the private Media Library and confirm their sizes. If a source's review or publication permission is revoked, correct that through the existing Production Review / Team Workspace workflow. No contributor media is sent to a provider by this preview.

Automated gates include SQLite rights ACL/rollback/revocation, source files, stale revisions and transitions; real MySQL 8 table certification; PHP and JS syntax; and deployment checksum checks. Production HostGator/browser operation, music contract documents, and actual video rendering remain separately certifiable.

## Proposed next phase

**88D3G — Governed Render Pipeline**: add explicit per-project permission to render, actual source SHA-256 and music master provenance checks, a sanitized bounded FFmpeg render plan, private output storage and human output review, preserving publication as an independent action. No unattended media distribution.
