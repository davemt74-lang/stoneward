# Stonefellow v1.3.39 — Section 40 Accessibility, PWA & Mobile Resilience Audit

## 40A — Installability — 10/10
- Dynamic manifest at /manifest.webmanifest.
- Subdirectory-safe id/start_url/scope.
- Standalone display, Stonefellow identity, music shortcuts.
- App-owned maskable SVG fallback icon.
- Progressive install control appears only when browser install criteria are met.

## 40B — Offline/privacy boundary — 10/10
- Versioned service-worker cache.
- Precache limited to offline shell, site CSS, app/library JS and app icon.
- No API/Admin/account/private HTML caching path.
- Navigations remain network-first.
- No-store responses are never persisted.
- Offline shell explicitly states private/account/music data is not cached.

## 40C — Keyboard navigation — 10/10
- Skip-to-content link and focusable main target.
- Escape closes active drawers/menu/quick actions.
- Modal Tab focus is trapped.
- Focus returns to the opening control when drawers close.
- SPA navigation keeps aria-current synchronized.

## 40D — Dialog semantics — 10/10
- Activity and Up Next drawers are named aria-modal dialogs.
- Dedicated polite live status region for network/install announcements.
- Existing Agent/player controls retain their accessible names/states.

## 40E — Motion, contrast & platform accessibility — 10/10
- Existing governed Artist Theme contrast readiness remains authoritative.
- prefers-reduced-motion removes decorative motion and near-eliminates transitions.
- forced-colors mode preserves interactive boundaries.
- focus-visible treatment is explicit and theme-aware.

## 40F — Touch/mobile resilience — 10/10
- Coarse-pointer interactive controls receive >=44px touch targets.
- Manifest/mobile capability metadata is present.
- Installed shell preserves the same responsive Stonefellow UI instead of creating a second mobile app.

## 40G — Update safety — 10/10
- service-worker.js is served no-cache/no-store.
- Cache name is pinned to v1.3.39 and old Stonefellow static caches are removed on activate.
- Version-query static assets can fall back to path-matched precache entries.
- Service worker remains constrained to its default app-root scope.

## 40H — Regression & release safety — 10/10
- App version: 1.3.39.
- DB schema remains 1.3.32; no unnecessary migration.
- Admin build synchronized to 1.3.39.
- Admin sidebar remains 29 items.
- PHP syntax: PASS.
- Public JS syntax: PASS.
- Admin JS syntax: PASS.
- Sections 1–39 inherited suites: PASS.
- Section 40 certification: PASS.
- Explicit PASS lines: 2286.
- PHP warnings: 0.
- Failures: 0.

FINAL SECTION 40 SCORE: **10/10**
