# Stonefellow v1.3.33 — Section 34 Artist Brand & Visual Theme Audit

Section 34 adds a governed visual identity system for the one Stonefellow artist. It deliberately avoids a generic page builder, arbitrary custom CSS, remote font injection, or multi-artist theming.

## 34A — Safe Single-Artist Theme Model — 10/10
- Four curated presets: Desert, Midnight, Paper and Monochrome.
- Dark, Light and Follow System appearance modes.
- Safe allowlisted display/body typography stacks.
- Allowlisted corner styles, density and content width.
- Strict six-digit hex color validation.
- No artist_id, tenant_id, workspace_id or multi-artist abstraction.
- No custom CSS, @import, script or remote-font escape hatch.
- Draft and published state reuse the existing app_meta settings store; no new theme table.
- Modified preset values are server-reclassified as Custom so the UI never mislabels a changed preset.

## 34B — Contrast & Readiness Governance — 10/10
- Server-side WCAG-style relative luminance and contrast calculation.
- Required 4.5:1 checks for primary text/background, secondary text/background, primary text/surface, accent/background and accent-button text.
- Danger/background uses a 3.0:1 warning threshold.
- Follow System validates both dark and light palettes.
- All four built-in presets pass both palettes at 100%.
- A contrast-blocked draft may be saved safely but cannot be published.

## 34C — Draft, Preview, Publish & Restore — 10/10
- Editing happens in a draft separate from the public theme.
- Unsaved browser edits disable Publish and clearly require Save Draft first.
- Real public-site preview uses the saved draft.
- Publishing requires explicit Admin confirmation.
- Restoring the original Desert default requires explicit Admin confirmation and changes the live theme.
- Draft reset safely returns to the currently published theme.
- Draft saves, publishes and default restores are written to Admin audit.
- Publish/restore lifecycle is visible in Agent Brain.

## 34D — Secure Public Preview & Shell Application — 10/10
- Draft preview is available only when theme_preview=1 and the current account is an Administrator.
- Draft preview is forced noindex,nofollow,noarchive and has no canonical URL.
- SPA SEO synchronization preserves that noindex/no-canonical state after navigation.
- Theme preview persists through public SPA routes.
- Automated discovery, Homepage and Storytelling event writes are suppressed during theme preview.
- Server-side Storytelling view events are suppressed only for authenticated Admin preview.
- Theme CSS and body classes are rendered server-side before the SPA runs.
- Browser theme-color metadata supports fixed modes and system dark/light variants.

## 34E — Public Surface Integration — 10/10
- Core theme variables cover background, surfaces, text, secondary text, accent, accent text, danger, width, typography, corners and density.
- Top bar, menus, drawers, persistent player and Agent chat use theme surfaces.
- Forms, search, account, history, library, cards and commerce surfaces follow theme colors.
- Splash, Artist Homepage, Storytelling, music, releases, shows, store and public editorial pages share the same visual system.
- Legacy structural hard-coded colors were replaced with theme tokens; intentionally decorative vinyl/cassette artwork remains art-directed.
- Display and body typography now use configured stacks throughout the public site.
- Compact density and square/soft/round corner settings affect real components.
- Maintenance mode also uses the published theme.

## 34F — Settings, Preview UX & Admin Agent — 10/10
- Artist Brand & Visual Theme is embedded in Admin → Settings.
- The Admin sidebar remains exactly 29 links.
- Editor includes preset, appearance mode, display/body type, corners, density, content width and paired dark/light palettes.
- Side-by-side dark/light preview cards are shown inside Settings.
- Detailed contrast readiness appears before publishing.
- Live saved-draft preview opens the actual public application.
- Admin Agent routes theme, appearance, brand-color, typography and dark/light requests to Settings.

## 34G — Release & Schema Boundaries — 10/10
- Application advances to **1.3.33**.
- Database target remains **1.3.28**.
- No migration 030 is introduced because visual state correctly reuses app_meta.
- Public and Admin asset builds both advance to 1.3.33.
- Web-server build header advances to 1.3.33.
- Version capability metadata documents Artist Brand, contrast governance and site-wide theme scope.

## 34H — Regression & Release Safety — 10/10
- Theme Admin JavaScript is syntax-checked by the release gate.
- Section 34 certification is part of the full release gate.
- PHP syntax: **PASS**
- Public JavaScript syntax: **PASS**
- Admin JavaScript syntax: **PASS**
- Sections 1–33 inherited regression suites: **PASS**
- Section 34 certification: **PASS**
- Explicit PASS lines on the hardened implementation candidate: **1902**
- PHP warnings: **0**
- Failures: **0**

FINAL SECTION 34 SCORE: **10/10**
