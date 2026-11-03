# Stonefellow 91B20D — Image reference variations (v1.3.230)

## User workflow

1. Open **Admin → AI Image Studio**, or choose **Create variation** on an image in **Media Library**. Existing eligible PNG, JPEG, and WebP assets (200 B–10 MB) can serve as sources.
2. Choose the source image, preview it, edit the visual brief or prompt, and explicitly acknowledge permission to send the source to OpenAI for this specific variation. If a generated image is the source, it must first be approved in Image Studio.
3. **Save prompt draft** does not send a provider request or charge API credits. A source asset ID, SHA-256, consenting administrator, consent timestamp, and optional parent generation job ID are saved transactionally with the immutable prompt.
4. **Approve exact prompt** is a distinct Admin action. **Generate · paid API request** is a further action with an explicit confirmation and daily attempt limit. It uses the existing encrypted OpenAI key and the Image Edits API only when there is a reference. Text-only jobs retain Image Generations API routing.
5. View the reference and result side by side, review provider-reported usage, and separately **Approve image for Media Library use**. A newly generated image remains private and cannot be used as a new reference until approved.
6. **Preview placements** compares existing and proposed art in multiple desktop/mobile mockups. **Stage private attachment** requires separate confirmation and does not replace or publish any existing image. Publication still happens in the relevant content editor.

## Safety, budgets, and history

- Reference images can only originate from managed local Media Library files; no arbitrary path or URL is accepted from the browser. Existing generated-media review gates apply. The source file is rehashed before reserving the paid daily slot and before uploading to OpenAI.
- The original source is protected from Media Library deletion while any variation records reference it. The history tracks the source and the immediate parent job; every generation is a new job, preserving source/output separation.
- The Admin API returns only asset UUIDs, IDs and job metadata, never raw provider image bytes, encrypted keys, local source paths, or reference-image base64. Reference bytes are sent only by the server during a separately approved paid call over TLS.
- The daily cap and no-retry-on-uncertain-outcome policy from 91B20A remain. Generated images and prompt refinement are tracked in separate usage categories; reported tokens are not presented as an invented dollar amount. The OpenAI developer organization's actual billing is external.
- There is no automatic approval, public publication, retry, image replacement, or free customer-token grant.

## Source changes

- `api/image-generation-core.php`: transactional reference table, eligible image inventory, source validation and hashing, idempotent draft linkage, paid-slot preflight, Image Edits endpoint routing, lineage in history.
- `admin/api/image-generation.php`: Admin reference inventory and consent audit fields.
- `api/image-studio-presets.php`: reference-variation counts and 30-day attempt metric.
- `api/media-core.php`: protect referenced source originals from deletion.
- `admin/assets/image-generation.js`, `admin/assets/image-generation.css`: source picker/preview, explicit rights checkbox, comparison history, variation CTA, responsive design.
- `admin/assets/media.js`: one-click Media Library handoff with an editable creative brief.

## Verification and scoring

| Section | Local score | Evidence / residual risk |
| --- | ---: | --- |
| Reference integrity and consent | 10/10 | Local path, allowed MIME/size, SHA-256, review gate, stored consent, immutable draft retry |
| Cost/approval controls | 10/10 | Distinct draft, prompt approval, paid confirmation, UTC cap, no automatic retry |
| History and deletion protection | 10/10 | SQLite unique reference schema exercised, parent job linkage and protected original |
| Admin/Media Library UX | 9/10 | JS syntax, static contract tests and responsive CSS passed; visual mobile browser inspection pending |
| Provider/PHP production integration | Pending | PHP CLI and live MySQL/OpenAI provider credentials unavailable in the local verification environment; no billed test call executed |

**Automated local suite: 77/77 passing** across six Node test files, including 12 dedicated 91B20D checks. Static source assertions plus SQLite table exercise do not replace a live PHP integration run. The overall section is a **release candidate**, not a certified production 10/10.

## Suggested 91B20E work

1. Image variation gallery with a searchable reference selector, accessible comparison slider and selected result as a new reference (only after approval).
2. Optional transparent backgrounds and masking/edit-region controls with separate cost approvals.
3. Actual provider billing reconciliation per request ID, errors, output size and 30-day usage dashboards. Do not estimate exact dollars solely from tokens.
4. Live MySQL upgrade, PHP extension and OpenAI test in staging; desktop/mobile browser QA and privacy review before production publication.

## Deploy checklist

- Back up code and MySQL database. Preserve `storage/media` and credentials; do not overwrite either with this source ZIP.
- Deploy the full PHP/JS source tree. Version 1.3.230; MySQL reference table is created lazily on first Image Studio request by `sf_image_gen_schema`.
- Confirm PHP 8+, PDO MySQL, PHP cURL, configured OpenAI provider and writable Media Library paths.
- Verify a non-generated Media Library image → consented draft → saved reference → approve → one test paid edit → private preview → asset approval → private placement. Confirm original remains intact, repeated generation does not occur, and public site imagery remains unchanged.
- Validate the new Media Library deletion guard, UTC daily cap, mobile layout, and server/provider error handling.
- Review the provider response and actual billing before enabling wider staff or customer access.
