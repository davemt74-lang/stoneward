# Section 84B1 — Stonefellow Cloud → HomeServer Kunaki Publishing Contract

**Scope:** Stonefellow Cloud PHP application only. This change does not modify, deploy or claim to run any HomeServer code. Another independent HomeServer task owns the worker implementation.

## Existing systems reused

- Section 84A Kunaki XML adapter and per-record approved-design fingerprints
- Section 81 vinyl production, revisions and Admin approvals
- Paid Stonefellow orders, personal `user_library` rows and authoritative catalog
- Section 44 Agent Tool Registry and Section 47B Skill Registry (descriptive only)
- Existing Admin Fulfillment / Vinyl Production canvas

## Purchase and source eligibility

The Cloud queues a publishing job only after Admin confirmation when: (1) a real customer purchased the custom vinyl physical line, (2) the same signed-in user previously purchased **every selected audio track** as a track line in real paid source order(s), (3) the personal library contains matching purchase grants, (4) the catalog marks every source master POD-eligible and records an audio asset, and (5) the order's protected artwork matches the Admin-approved fingerprint.

**Real-payment restriction:** Both the vinyl order and each source-track purchase must be paid by a real LIVE Stripe checkout confirmed through the signed webhook. PaymentIntent, `postpayment_done.library` and the vinyl-specific `postpayment_done.pod` checkpoint must exist. Stripe TEST, simulated cash, reserved orders, missing signed-payment evidence, canceled/refunded status, and paid-fee-only records are denied. Until Stripe LIVE is configured, Admin can inspect the design but cannot queue a real Kunaki publishing job; CI uses wholly synthetic fixtures and never charges money. A saved playlist, free catalog preview, refunded track purchase, or the purchase of the vinyl pressing fee alone is insufficient.

**Fixed albums:** Reusable album vinyl editions will need an independently certified album license/product mapping workflow. The current job contract is **custom editions only** and fails closed on missing individual track purchases. Do not silently grant the album right from a playlist/release view.

## Cloud API: worker lease and state report

- Endpoint: `POST /api/kunaki-worker.php` over HTTPS only. Reverse proxy and hosting must enforce HTTPS, TLS validation and no caching.
- Provision on the Stonefellow host as **environment variables** (not tracked code, public chat, git, JS or database):
  - `STONEFELLOW_KUNAKI_WORKER_SECRET`: a distinct random high-entropy value, minimum 32 bytes.
  - `STONEFELLOW_KUNAKI_WORKER_ID`: e.g. `HS-kunaki-primary`; the HomeServer must use this exact ID. Separate future VPS workers require separate authorization design, not a copied key.
- Request headers: `X-SF-Worker-Id`, `X-SF-Worker-Timestamp` (10-digit UTC Unix seconds), `X-SF-Worker-Nonce` (32 lowercase hex), `X-SF-Worker-Signature` (64 hex HMAC-SHA256).
- Signature input, joined by newline exactly: `timestamp`, `nonce`, `POST`, `/api/kunaki-worker.php`, `sha256(raw UTF-8 request body)`. HMAC key is `STONEFELLOW_KUNAKI_WORKER_SECRET`; compare constant-time on both ends.
- Replay: ±300 seconds clock window plus durable nonce create-only storage. Expired or reused nonces fail closed.
- Body for a claim: `{"operation":"claim"}`. The response includes at most one `stonefellow.kunaki.publish-job.v1` contract plus a secret one-time lease token (600 seconds), which must remain on HomeServer and never go to browser DOM, LLM or logs.
- Body for a safe report: `{"operation":"report","job_id":"KJP-...","lease_token":"...","state":"awaiting_media_grant"}`. Allowed states are `awaiting_media_grant`, `awaiting_browser_approval` and `needs_review` only.
- A lease expiry yields `needs_review`, not automatic requeue; the other worker cannot concurrently claim an active job. Ambiguous publishing or manufacturing outcomes must never be replayed automatically.

## HomeServer agent's separate job

The other agent should implement the companion contract as a separate PR in `davemt74-lang/otro`, reusing its existing isolated Chromium, mission browser approval, takeover, worker bridge and owner audit tools. It must **not** use generic model-issued arbitrary file uploads, POST submissions or page selectors to bypass the existing governed browser safety layer. Its browser interface needs a Kunaki-specific, least-privilege upload capability and distinct explicit publication approval.

The worker should:
1. Pair using the exact configured worker ID and HMAC secret; never accept a browser or LLM supplied signature key.
2. Claim one job, verify its hash and catalog metadata, and report `awaiting_media_grant` because the Cloud does not yet grant audio bytes.
3. Require a future, specifically authorized Cloud media transfer protocol using short-lived download grants and asset integrity hashes; never scrape arbitrary storage or customer libraries.
4. Render artwork and prepare audio from protected catalog originals *after* grants exist; check licensing, duration, WAV/JPEG specs and rights.
5. For Kunaki login, request human takeover when needed; keep cookies, 2FA and credentials within isolated HomeServer browser storage, not Cloud or Agent prompts.
6. Review the exact published design; require explicit owner approval before clicking publish; reconcile uncertain submission outcome without automatic retries.
7. Return product evidence with a separate signed status contract to be implemented/certified in a subsequent stage.
8. Never submit a Kunaki manufacturing order from this publishing job.

This document is a **handoff contract**, not evidence that the HomeServer agent has already integrated or that a real Kunaki product has been published.

## Cloud runtime boundary

- File queue: `storage/kunaki/publishing-jobs.json` under exclusive `flock` with atomic rename. Retain private storage across upgrades. Apache request denial is placed in `storage/kunaki/.htaccess`, but HostGator must also ensure the application storage directory cannot be served publicly, including on a future VPS/nginx deployment.
- Worker secret missing/short or ID missing: requests fail closed. No capability to upload audio, execute a browser action, call Kunaki, charge a payment, or mark an external product published.
- Admin jobs show only safe status fields; worker lease keys and library/source order IDs are redacted.
- Strict one-job-per-order-item-design fingerprint prevents duplicate staging; payment/owner/design changes cannot bind to an existing job.
- PHP/JS release checks and real MySQL certification are required on exact PR head and merged main.

## Section progression

- **84B1 Cloud:** authenticated purchase proof + queue + read-only Admin visibility + signed worker polling — this PR.
- **84B2 HomeServer:** separate agent’s approved upload browser/runtime, takeover and signed worker client — not in scope here.
- **84B3 Cloud:** catalog asset grant/streaming + actual locked album SKU/reusable edition entitlement + artwork rendition.
- **84C–E:** Kunaki quotes, separately approved real manufacturing orders, provider recovery, shipping/tracking and customer updates.

A test fixture has no effect on real vendor systems and is not proof of HostGator/HomeServer networking, Kunaki login or physical manufacturing.
