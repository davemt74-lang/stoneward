# Core canvas implementation readiness audit

Status: source inspection completed for initial sequencing; implementation remains paused. Image/vision/production expansion is on hold. Existing functionality must remain compatible. This review does not certify live hosting, database migrations, browser behavior, physical devices or provider availability.

## Findings and reusable interfaces

| Area | Source evidence | Readiness and next work |
| --- | --- | --- |
| Tool discovery and host datasets | `api/agent-tool-registry.php`, `api/agent-canvas-data.php` | Existing registry exposes scope, fields, filters, actions and row limits. Extend this registry with versioned capability cards; do not create a competing tool list. Existing delivery switches must remain compatible. |
| Music and canonical audio | `docs/canvas-music-data.md`, `assets/js/canvas-audio-sdk.js`, `api/canvas-audio-core.php` | Existing selection and independent audio-node interfaces support authored interactions. Preserve IDs, selected order, source checks, main-player synchronization and raw authorized audio access. |
| Merchandise and tickets | `api/canvas-resources.php`, `api/agent-canvas-public-commerce.php`, `docs/canvas-resources.md` | Existing authenticated resource selection and published datasets. Reuse picker behavior; public data status does not mean this selection endpoint currently permits guests. |
| Current-user commerce and listening | `api/agent-canvas-private-commerce.php`, `api/agent-canvas-data.php` | Existing account-scoped rows for purchases, owned tickets and listening statistics. `/me` still needs category discovery, explicit per-category capability scope and consent integration; do not build duplicate commerce storage. |
| Playlists | `api/playlists.php` | Existing owner list/detail and public-ID lookup, plus authenticated writes. `/playlists` needs picker/contract integration and published artist discovery rules. Public-ID lookup alone is not a complete approved artist inventory. Keep writes separate from selection. |
| Stories | `api/story-core.php` | Existing published/readiness-filtered list/detail and track/release/show relationships. Add safe picker projections and contracts using these filters; do not expose unrestricted story rows. |
| Lyrics | `api/agent-canvas-lyrics.php`, `api/song-lyrics-catalog.php` | Existing published lyric resolution and songbook/timeline context. Add independent `/lyrics` discovery and selection without requiring active playback or starting transcription. |
| Knowledge | `api/knowledge-search.php` | Existing public-agent flagged search returns bounded snippets (eight hits, 600-character snippets). Useful starting point, not a complete versioned resource inventory. Add stable identity/provenance and authorized retrieval before claiming full knowledge access. |
| Canvas references and gallery | `docs/canvas-resources.md`, `api/canvas-gallery-core.php`, `assets/js/canvas-gallery.js` | Owned selection and separate approved public gallery exist. Unified `/canvas` picker needs explicit owned/public modes, attribution, and independent remix behavior. Public discovery cannot expose private source scenes. |
| Project planning and approval | `docs/canvas-projects.md`, `api/canvas-projects.php` | Existing exact-project approval, account preferences and idempotent usage flows. Extend the contract for new selections/scopes; no parallel approval or charging engine. Preserve existing legacy caller behavior during migration. |
| Testing and repair | `docs/canvas-build-workflow.md`, `assets/js/canvas-scene-tester.js`, `assets/js/canvas-preview-guard.js` | Existing coordinated workflow and guarded previews are foundations. Trusted security verification, broader visual evidence and snapshot gates need extension; browser reports alone are not authoritative security certification. |
| Admin permissions and remembered consent | `api/agent-tool-registry.php`, `docs/canvas-capability-card-format.md` | Role/scope checks exist; the planned general admin toggle matrix and revision-aware viewer grants are additional work. Publication consent in the gallery is a different permission and must not be reused as blanket data consent. |
| Libraries and public data | `docs/canvas-capability-card-format.md`, main outline | Reviewed exact-version library locks, public-source adapters, `/data`, and their policy enforcement remain planned. Do not treat listed libraries/providers as installed or enabled. |
| Discovery menu, inspector and relationships | Existing picker modules, registry and story/catalog relationships above | Shared UI and typed relationship contract need implementation. Existing domain relationships are reusable but do not imply a universal authorized graph already exists. |
| Audit learning | Main outline | Proposed versioned learning and review process; not an existing self-updating security engine. Live enforcement changes require administrator authority. |

## First implementation section when resumed

**Capability contracts and permission foundation.** Extend the existing registry with stable capability/permission IDs, safe card projections and a versioned admin policy. Inventory current callers and preserve compatibility before enforcing any new mandatory declaration. Keep enabled capability availability separate from viewer consent, browser permission and transaction approval.

Deliver the Admin Permissions UI, server enforcement, policy history, What this canvas uses, owner-bound remembered grants and revocation as a coordinated foundation. Bind grants to trusted canvas lineage and scope; independent remixes receive independent permission contexts. A disabled permission cannot be bypassed through a stale card, direct API call or existing grant. Core account isolation, authentication, CSRF and sandbox protections remain mandatory.

Required evidence: guest/two-account separation; admin role and CSRF checks; equal/narrower scope reuse; expanded-scope consent; revocation and disablement during pending work; stale policy/results; account switching; independent remixes; and compatibility with current music, commerce, gallery, approval and token-accounting flows. Tests must demonstrate the normal user path as well as direct API behavior.

## Follow-on sequence

1. Connect domain inventories and selection contracts: playlists, stories, lyrics, knowledge, `/me`, unified `/canvas`; then the shared command menu, inspector and authorized relationships.
2. Add `/data` and incremental reviewed providers/libraries after their exact deployment and policy contracts are resolved. No default library or generated widget.
3. Extend exact-candidate security/static/runtime verification, visual review and repair, with a trusted runner design that fits shared hosting.
4. Connect reviewed learning, quarantine, dependency impact and release recovery; complete scoped acceptance before PR, merge and merged-source deploy ZIP.

## Unresolved implementation decisions

- Permission policy storage, safe defaults and migration strategy for existing callers and canvases.
- Trusted verification runner availability; HostGator must not be assumed to provide headless Chromium or Node.
- Exact library versions, licenses, local bundles, worker/WASM constraints and mobile support.
- Published artist playlist inventory rules and versioned/live membership behavior.
- Stable knowledge resource IDs, full-content retrieval and explicit completeness/pagination metadata.
- Required versus advisory findings and administrator authority for incomplete evidence.

Image/vision/production expansion remains deferred. No new provider requests, installed libraries, application code, PRs, merges or deployments were performed by this audit.
