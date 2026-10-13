# Stonefellow v1.3.73 — Codebase Audit and Store Recovery

Baseline: main commit `8e17d1e3c00f62f985472ea88613b998687019ee` (after Section 73).
Review date: 2026-10-13. Scope: distributable PHP, JavaScript, routing, database schema, purchase flow, and deployment.

## Evidence and boundaries

- 190 packaged PHP files, all passing `php -l` locally.
- 41 packaged JavaScript files, all passing `node --check` locally.
- 117 `api/*.php` modules screened for public endpoint, database, and error-response patterns.
- Read the Store endpoint, client renderer, commerce schema/products/summary, price quote and order write paths, user draft schema, database migration runner, installer entry, Apache rules, and PWA cache behavior.
- Real live-site SQL schema, payment processor, credentials, and production HTTP response were **not available**. The report does not claim a live penetration test or a successful production deployment.
- New regression `tests/section74-storefront-runtime.php` exercises full output, disabled merchandise/media dependencies, hiding unlisted releases, safe diagnostics, and frontend messaging. All existing CI checks must pass before merging.

## Confirmed and addressed in this release

1. **P0 / Store fatal:** `api/storefront.php` called undefined `sf_releases()`. The app defines `sf_release_rows()`. PHP fatals created non-JSON responses, displayed as Store unavailable. Fix: use existing release source.
2. **P1 / Unnecessary availability coupling:** The whole storefront failed if public media, merchandising tables, or store summary SQL threw. Fix: independent data reads, honest partial-catalog warnings, and suppression of incomplete merchandise listings.
3. **P1 / Release response mismatch:** Store returned artwork as a string and only `price_cents`; the browser expected `artwork.cover` and `digital_price`. Fix: provide both compatible amount forms and structured artwork.
4. **P1 / Unhelpful diagnostics:** JSON parse failures all appeared as generic invalid responses. Fix: HTTP-status-aware browser message and server-side logged error references, without sending raw database errors.
5. **P2 / Stale client cache:** Change public/admin/app/service worker/cache/header version from `1.3.72` to `1.3.73` so clients receive the patched Store renderer.

## Additional codebase findings — not silently claimed fixed

| Priority | Area | Evidence | Risk and required follow-up |
|---|---|---|---|
| P1 | Checkout post-commit | `api/order.php` writes order JSON and completes the request key before some user-library/activity/notification calls, which can throw into its generic HTTP 500 catch | A recorded order might be reported as failed. Protect noncritical post-commit actions; certify single-order, single-charge, replay and recovery before any payment-path change |
| P1 | Schema on public GET | `sf_commerce_products()` invokes `sf_commerce_ensure_schema()`, which executes DDL/indices | Bad legacy schema or limited DB permissions can break a public read. Move schema maintenance into the additive upgrader, then migrate tested read paths |
| P1 | Fresh-install catalog availability | Server `sf_catalog()` expects `data/catalog.json`, while current deployment ZIP intentionally omits `data/` | Existing installs preserve data; new installs need explicit seeded catalog or a validated import step. Verify installer from an empty database and filesystem |
| P2 | Merchandise sales metrics | `sf_commerce_summary()` counts all order items not marked `released` as sold revenue | Pending/reserved payment may inflate gross sales. Distinguish paid, pending, canceled and refunded |
| P2 | Variant save accounting | `sf_commerce_save_product()` treats PDO `execute()` success as evidence a variant ID exists | Submitted incorrect variant IDs can be recorded as seen. Check fetched row identity and ownership explicitly, add cross-product variant fixtures |
| P2 | Diagnostics consistency | Multiple API endpoints return raw exception messages, while newer ones log protected references | Review public error surfaces to avoid sensitive SQL or file paths; unify structured errors by endpoint |

## Existing safeguards observed

- Apache explicitly denies HTTP access to `config/`, `data/`, and `storage/`.
- Account cookie uses HTTP-only, SameSite=Lax, and conditional secure flag.
- Checkout requires CSRF, quotes prices on the server, and has a persisted request key for retry deduplication.
- Section 73 preserved user ownership for saved drafts and kept unverified paid modules inactive.

These are static observations, not substitutes for a deployed security test.

## Acceptance and scores

| Dimension | Baseline | Patched target / confidence |
|---|---:|---|
| Syntax consistency | 10/10 | 10/10 (190 PHP + 41 JS checks) |
| Public Store end-to-end logic | 2/10 | 10/10 **only when new runtime and release-gate checks pass** |
| Store partial-outage handling | 1/10 | 9/10 when tested; live DB outage unverified |
| Database upgrades and legacy compatibility | 7/10 | 7/10; real MySQL legacy fixtures still needed |
| Checkout and payment recovery | 6/10 | 6/10; follow-up P1 risk remains |
| Authentication and privacy | 8/10 | 8/10; static inspection, no penetration test |
| Packaging and cache coherence | 7/10 | 9/10 if versioned ZIP and checksum pass |

**Overall project grade: 7/10 provisional.** This does not satisfy a whole-project 10/10 certification. Resolve P1 follow-ups and run live acceptance before beginning a new feature phase. A green Store PR certifies only its changed scope.

## Deployment requirements

1. Back up live files and DB. Preserve `storage/`, private configuration, and `data/`.
2. Install the **new, tested** v1.3.73 release, not previous 1.3.72 ZIPs.
3. Confirm `api/storefront.php` returns HTTP 200 JSON on normal operations, and `partial: true` with warnings during optional subsystem outages.
4. Confirm music, store, cart, quotes, guest checkout, signed-in order details, saved drafts, and PWA cache invalidation in the actual hosting environment.
5. Do not claim payment certification until test-mode checkout/idempotent-retry is proven against that environment.
