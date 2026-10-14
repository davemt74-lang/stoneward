# Section 84B5 — Stonefellow → HomeServer Secure Catalog Media Transfer

## Purpose / what is built
This section builds a **media staging capability**, not a Kunaki publisher. Every media job starts from an **already purchased, live Stripe-paid** custom or locked-album vinyl order, one reserved vinyl-print license per track, an Admin-reviewed design, and the exact existing Section 81 immutable POD proof. An eligible HomeServer worker claims the job, receives a short-lived manifest of original catalog assets, downloads files over signed HTTPS requests, verifies byte length and SHA-256, and records a private staging certificate before asking for browser approval. Streaming and downloading rights remain unchanged.

Source files:
- Cloud: `api/kunaki-media-transfer.php` and the existing `POST /api/kunaki-worker.php`.
- HomeServer: `app/services/stonefellow_kunaki_media.py`; separate isolated Python CLI with `--once`, using the existing installed `httpx`.
- Tests: `tests/section84b5-media-transfer.php` and `tests/stonefellow_kunaki_media_84b5.py`.

## Zero-trust rules
1. Cloud accepts only a catalog reference `api/media.php?id=M<20-uppercase-hex>` with optional `variant=large`. It **resolves the original** in the database-backed Media Library and private `storage/media`; it neither requests URLs nor reads untrusted client paths. All remote URLs, filesystem traversal, unrecognized media types, unsigned grants and missing originals fail closed.
2. Each media asset is SHA-256 checked against its immutable stored metadata; the media manifest includes the exact original size, format, side, track ID and order. Album art comes from the purchased front/back artwork reference. Palette/theme names are **not** production JPEGs.
3. A signed worker must have the exact configured `STONEFELLOW_KUNAKI_WORKER_ID`, high-entropy `STONEFELLOW_KUNAKI_WORKER_SECRET`, a still-active job lease, matching order owner, reserved print licenses, unchanged payment state and the exact immutable approved POD handoff. Short-lived 5-minute per-asset grants are bound to that worker, lease and proof SHA. A different user/worker or expired/replayed lease cannot download.
4. Maximum original asset size is 256 MiB; each manifest is limited to 1 GiB total and at most 40 tracks plus 2 artwork originals. Download streaming is bounded to declared size and verified by the recipient. It uses a stable SHA and private directory permissions (0700 directory, 0600 files).
5. HomeServer writes a `stonefellow.kunaki.staged.v1` certificate, **without credentials or grants**, and reports `awaiting_browser_approval`. No browser, vendor authentication, Kunaki product creation, order submission, billing or notification is authorized here. Failures are review-only, not blind retries.

## Configuration
Cloud HostGator / VPS environment:
- `STONEFELLOW_KUNAKI_WORKER_ID`: e.g. `HS-kunaki-primary`
- `STONEFELLOW_KUNAKI_WORKER_SECRET`: 32+ bytes generated with your secret manager
- HTTPS enforced at the web server/reverse proxy. `/storage` must not be externally served, regardless of Apache, LiteSpeed or nginx routing.

HomeServer environment:
- `STONEFELLOW_KUNAKI_CLOUD_URL=https://<your-stonefellow-hostname>` (origin only, no URL path)
- Same worker ID and secret as Cloud, provisioned out of band
- `STONEFELLOW_KUNAKI_STAGING_DIR`: a private, writable directory outside the web root

Run one job:
```bash
python -m app.services.stonefellow_kunaki_media --once
```
For recurring pickup, schedule the command with the already governed HomeServer scheduler; it processes **one claim only** per invocation, does not spawn a browser, and never exports secrets to Agent Chat/LLM messages.

The HomeServer module is a standalone worker. Do not run multiple copies under one worker ID; the Cloud exclusive lease prevents duplicate claims but staged output still requires owner review.

## HTTP contract
Every operation uses HMAC-SHA256 as documented by `docs/section84b1-stonefellow-homeserver-contract.md` against **POST /api/kunaki-worker.php**. Anti-replay nonce and timestamp apply to every operation.

- `{"operation":"claim"}` → `job` including private lease token (never log, display or persist it).
- `{"operation":"manifest","job_id":"KJP-...","lease_token":"..."}` → `manifest`, containing versioned media details and per-asset short-lived opaque grants. This returns only after proof, live payment, license, worker and lease validation.
- `{"operation":"download","job_id":"KJP-...","lease_token":"...","grant":"..."}` → `application/octet-stream`, exact original bytes, `X-SF-Media-SHA256`, `X-SF-Media-Bytes`, and bounded `Content-Length`. No URL redirect or arbitrary file path is supported.
- `{"operation":"report","job_id":"KJP-...","lease_token":"...","state":"awaiting_browser_approval"}` → closes the staging lease.

If a job or file is ambiguous, do **not** retry production; report `needs_review`. An expired lease is moved to review, not re-queued.

## Operational limits and pending acceptance
The live Media Library assets are runtime data absent from the public repo. Production cannot proceed unless each purchased audio master and both actual high-resolution artwork originals exist and match their source hashes. Standard album fronts and backs should be published from the Admin Media Library as source originals; existing custom cover *template names* require a separate reviewed artwork rendering step, not silent fabricated output. The helper validates source images at least 1600 pixels on both sides; Kunaki's actual final print template/dielines, color profile and final export specs still require real-provider checking before publishing. This section does not assert print readiness solely from artwork dimensions.

HomeServer staging does not yet grant the separately governed browser the ability to upload to Kunaki; that is Section 84B6. The worker can be installed and executed on HomeServer now (Cloud still remains usable on shared HostGator); no HostGator Chromium service is required.

Acceptance requires PHP/JS complete release CI, MySQL checkout certification, isolated HomeServer transport/integrity tests, exact PR-head green, merge, then merged-main checks on both repos. Live external catalog availability, network connectivity, HostGator upload constraints and real Kunaki publishing must be validated separately.
