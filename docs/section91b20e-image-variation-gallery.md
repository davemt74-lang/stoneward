# Stonefellow 91B20E — Variation gallery, transparent output, targeted edits and billing audit

Version: **v1.3.231**. This builds on v1.3.230 (91B20D) and keeps the existing explicit paid-request, asset-review and publication boundaries.

## Gallery

- Admin → AI Image Studio now shows a searchable **Image Variation Gallery**. Original and derivative jobs are grouped into artwork families using persistent `parent_job_id` lineage. Historical images remain in the Media Library.
- Filter by approval status or variations. Choose an image to open a responsive comparison dialog with keyboard-accessible before/after reveal control. An original without a reference uses a single-image preview.
- Approved outputs can be selected as a new reference; unapproved outputs cannot. A new selection always resets source-image consent and edit-mask consent. No compare action initiates an API request.
- Media Library source images can be searched by filename or title and selected from the first 18 matching thumbnails per search without an internal scrolling panel. A full select list remains available for keyboard navigation. Current reference inventory covers up to 1,000 recent eligible Media Library images.

## Image pipeline

- Drafts can use `background=auto|opaque|transparent`; the provider request uses `webp` for transparency support. The option is snapshotted in an immutable `image_generation_options` row.
- For edit-region work, upload a PNG mask into Media Library and select it alongside a source image. The mask must be a distinct, locally managed image with a PNG alpha channel, matching image dimensions, and a separately acknowledged use permission. Its SHA-256 is saved with the draft.
- Source image and mask bytes are checked again before the paid daily slot is reserved, and before sending an approved request. PNG masks are converted to a private base64 data URL in the server-only Image Edits request. Neither browser nor GET response receives the image bytes.
- Mask original files cannot be deleted while referenced by a saved job. Existing output-generation, review, and private-placement gates are unchanged. Rejected or uncertain paid calls never retry automatically.
- The API contract follows OpenAI's Images edits endpoint with optional `background` and `mask` objects; targeted mask results remain model-dependent and require live-provider verification before claiming full accuracy.

## Billing audit

- **Admin-entered invoice reconciliation** is available on completed or uncertain paid jobs. A human enters the USD value and an identifying invoice reference after verifying OpenAI developer billing. This does not fetch provider invoices, settle payments, deduct tokens, or estimate dollar cost from usage tokens.
- `image_generation_cost_reviews` records cents, currency, verifying Admin, UTC timestamp and invoice reference. Corrections replace the previous value for that job with the change separately recorded in the existing Admin audit system.
- Dashboard shows manually reconciled count and sum, unreconciled count and provider-reported token usage as distinct measures.
- CSV export covers up to the 150 jobs loaded in the Admin view and contains no private image bytes or provider keys. Potential spreadsheet formula-leading fields are safely prefixed.

## Source changes

- `api/image-generation-core.php`: new options and cost review tables; immutable draft options; PNG alpha + dimensional validation; hash-verified mask; Image Edits request mask/background routing; manual invoice recording; family metadata in job list.
- `api/media-core.php`: mask-source deletion protection.
- `admin/api/image-generation.php`: invoice action and audit; background/mask consent audit on draft.
- `api/image-studio-presets.php`: manual billing dashboard metrics.
- `admin/assets/image-generation.js` and `.css`: search, filters, gallery, compare dialog, transparent/mask composer, invoice entry, recent CSV download and mobile layout.
- `tests/section91b20e-image-gallery-masks.test.cjs`: 15 new static/API-schema and SQLite contract tests.

## Local scoring and certification

| Area | Score | Reason |
| --- | ---: | --- |
| Gallery and revision comparison | 9/10 | Source-level navigation/keyboard/mobile rules tested; visual browser QA pending |
| Immutable background and mask pipeline | 10/10 locally | Contract, SQLite, consent, hash and preflight tests passed; actual edits untested |
| Paid request and review boundaries | 10/10 locally | Existing gates and daily budget remain; no automatic retry or publication |
| Invoice recording and CSV safety | 10/10 locally | Input validation, ledger separation, manual verification, SQLite uniqueness and formula escaping |
| Production certification | Pending | PHP CLI, MySQL staging, actual OpenAI image call, real invoice source and desktop/mobile browser access unavailable locally |

**92/92 Node tests passing across seven test files**, including all 77 prior checks and 15 new 91B20E checks. JS syntax checks must pass before packaging. These tests are not proof of successful live PHP/MySQL/provider execution; a production **10/10 cannot yet be claimed**.

## Deployment/testing checklist

1. Back up MySQL and PHP source. Deploy the source-only ZIP, preserving live `storage/media`, secrets, uploads and host-specific settings.
2. On Admin → Image Studio first visit, confirm lazy schema creation for `image_generation_options` and `image_generation_cost_reviews`. Verify MySQL grants allow table creation (or add these DDL statements through the normal DBA deployment workflow).
3. Generate a transparent original, review the output file's actual alpha channel, and confirm it is private until approved.
4. Upload a known transparent PNG mask with dimensions exactly matching a source image. Confirm source switch resets consent, mask mismatch is rejected without an API charge, and one approved edit succeeds. Check provider costs.
5. Compare reference/result at desktop and mobile widths using keyboard navigation. Select an approved variation as a new reference, then verify parent lineage and source-original protection.
6. Check OpenAI's actual invoice and reconcile one job; export CSV and validate numbers. Ensure admin-entered values are never represented as automated provider billing.
7. Stage a private artwork placement; verify the public site, current artwork and music publishing workflow remain untouched.

## Good next build: 91B20F

- In-browser masking/brush canvas that generates a correctly sized transparent PNG for review and upload, with undo and crop controls.
- Paginated gallery/inventory API so accounts with more than 1,000 images or 150 jobs can search beyond the current local result window.
- Optional provider billing reports via authenticated organization read-only credentials, with authoritative per-model/per-request attribution only where the provider supplies it.
- Actual browser QA and PHP/MySQL/OpenAI staging integration to close the outstanding production-certification gaps.
