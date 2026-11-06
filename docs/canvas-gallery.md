# Canvas Gallery

Canvas Gallery publishes an exact saved custom canvas version after the owner explicitly consents and an administrator reviews its immutable snapshot. Later private edits do not change a pending or approved submission. Returned or declined designs require a new submission. Submission, moderation, Open and Remix use no model or Active Tokens.

## User flow

Open **Canvas Catalog** from the public user menu to browse approved versions, copy an entry link, open an experience, or create a private remix. A shared entry link loads its catalog details; running code still requires Open. Signed-out visitors can open published experiences and play public music. Remix requires an account and saves an independent private version; subsequent `/edit` requests follow the normal project approval and token rules.

Use **Submit** beside a custom version in Canvas History, or **Submit a canvas** in the catalog. The source selector uses the complete owned canvas inventory. Choose the exact version, public details and optional bound-image preview, then consent to sharing its code and images. **My submissions** shows review status, feedback and withdrawal. An administrator reviews submissions in **Admin → Canvas Catalog**, where source inspection and the explicit guarded visual preview are separate from publication decisions. The visual preview has no personal account or playback bridge.

The first submission creates the additive gallery tables and protected image storage using the existing database and writable `storage/` directory. No API key, model setting, paid provider call or new service is needed. Deploy the complete versioned ZIP so the server endpoints, browser modules and static version markers match.

The publication includes submitted code, public title/description/creator label, selected public artist resource scopes, and independent copies of bound images. The host omits private scene IDs, prompts, chat, workflow logs, original image titles, private paths and resolved personal datasets. Authored code is still literal content: the consent and administrator review cover information the author placed in it; the host cannot automatically anonymize arbitrary programs.

New workflow-required canvases must be the exact current ready/applied candidate or a host-verified derivative before submission. Known failed or superseded workflows cannot borrow older verification. Legacy scenes retain their established eligibility.

## Public and owner API

`api/canvas-gallery.php` returns JSON with private/no-store caching. Public IDs and idempotency request IDs are lowercase 32-character hex strings. Every POST requires the signed-in account's CSRF token.

| Request | Inputs | Result |
| --- | --- | --- |
| GET `action=list` | optional `page`, `limit`, `q` | Approved public cards and exact paging totals. |
| GET `action=detail` | `id` | Approved public card, without code. |
| GET `action=open` | `id`, optional `current_track_id` | Approved public card and frozen scene. No private save or inference. |
| GET `action=mine` | optional `id`, `status`, paging/search | Own submission states and review feedback. |
| POST `action=submit` | `scene_id`, `request_id`, `title`, `description`, `creator_label`, `publication_consent:true`, optional `preview_asset_id` | A pending immutable submission. |
| POST `action=withdraw` | `id` | Own submission becomes withdrawn; public access is revoked. |
| POST `action=remix` | `id`, `request_id` | Independent private history copy with copied images and public ancestry. |
| GET/HEAD `action=asset` | `id`, `asset_id` | Frozen authorized image bytes. |

List results are `{ok:true,items,page,limit,total,has_more}`. Detail and mutations return `{ok:true,item}`; Open/Remix add `scene`. Public cards contain only `id`, `title`, `description`, `creator_label`, `preview_url`, timestamps and optional public attribution. Owner responses additionally contain state, feedback, exact snapshot hash and the owner's original saved scene ID. Public Open never includes those owner fields, even when its viewer is the creator.

Limits are UTF-8 bytes: title240, description2000, creator label120, review feedback2000 and search200. Page size is1–60, default24. Public pagination scans bounded metadata batches and current artist-reference availability; it does not load all submitted programs or silently cap the catalog.

The public scene has `gallery_id` and no private history `id`/`previous_id`. It includes validated code, safe scopes and copied `image_assets`. Public artist datasets are resolved freshly. Signed-in personal tools resolve the current viewer; anonymous viewers receive no personal rows. These transient datasets are never stored in the publication snapshot. Each refresh rechecks publication access. Administrator preview resolves public data only.

## Administrator API

`admin/api/canvas-gallery.php` requires a current administrator session. GET lists pending entries by default; `status=all` or a specific state, paging and search are supported. GET `id` returns `{ok:true,item,scene}` for exact snapshot preview. Withdrawn entries retain status/feedback but have no executable preview.

POST accepts `{action,id,snapshot_hash,feedback?}` with administrator CSRF. Actions are `approve`, `return`, `decline`, and `unpublish`. Approval/return/decline require a pending snapshot. Unpublish moves an approved snapshot to returned. Return requires feedback. The64-character snapshot hash binds code, scopes, asset manifest, chosen preview and submitted public metadata; a changed hash or stale state returns409. Administrator preview and approval cannot modify the snapshot.

## Asset isolation, ancestry and privacy

Published images receive new public identities and are served through the gallery endpoint from protected storage. Public snapshots never refer to the publisher's private image route. Every image delivery checks current entry state and bytes. Revoked artist-reference permissions hide affected public entries and block their pixels. Inactive creators are also hidden. Own pending/returned/declined image previews are authorized separately; their canvas code is available only in the authenticated administrator preview.

Remix copies approved code and pixels into the receiver's private stores. It omits the publisher's private IDs, brief and workflow state. Public creator ancestry is host-owned history metadata and survives later edits; model output cannot supply or overwrite it. Existing independent copies remain owned by their listeners after the original publication is withdrawn. Replaying a completed Remix request returns the same private copy, including after source withdrawal; its optional source `item` may then be null.

Submission captures the account's privacy epoch before copying assets and rechecks it under the account lock before inserting. Remix captures the receiver epoch before acquiring its account/entry locks and saves privately in the same transaction. Review and withdrawal serialize with owner privacy clearing. Clearing Agent history or closing an account purges that owner's publications and remix receipts; file cleanup occurs only after commit. Already imported copies owned by other listeners remain independent. Export/reset helpers create no optional gallery tables. No late submission or remix may restore cleared private history or publication data.

Tests: `tests/canvas-gallery.php`, `tests/canvas-gallery-privacy.php`, `tests/canvas-gallery-attribution-history.php`, `tests/canvas-gallery-assets.php`, and `tests/canvas-gallery-http.py` exercise the server contracts without paid providers. Browser suites cover the public/admin interfaces and integration separately.

The focused native browser suites are `tests/canvas-gallery-browser.py`, `tests/admin-canvas-gallery-browser.py`, and `tests/canvas-gallery-integration-browser.py`. They use the existing Playwright/Chromium test environment (`CHROMIUM_BIN` can select the installed browser); the integration fixture also uses the existing FFmpeg-generated MP3s and PHP/SQLite host. They exercise production UI, renderer, app adapters and real HTTP endpoints. Local verification does not constitute a live HostGator deployment or physical iPhone Safari test.
