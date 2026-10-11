# Stonefellow v1.3.34 — Section 35 Lyrics, Songbook & Track Notes Audit

Section 35 adds a governed public Songbook layer without duplicating canonical lyric text. Canonical lyrics remain in the private Catalog record; only an explicitly published, readiness-green Songbook can expose structured lyric content publicly.

## 35A — Canonical Lyrics Privacy — 10/10
- Canonical lyric text remains on the private Catalog track record.
- Songbook tables store publication metadata, structure, annotations and visibility only; lyrics are not duplicated into Songbook storage.
- Generated public catalog JavaScript strips the lyrics field.
- Migration 030 immediately regenerates the lyric-free public catalog asset.
- All subsequent Admin catalog writes preserve the lyric-free public asset boundary.
- Direct access to private data/config directories remains blocked at the web server.

## 35B — Songbook Structure & Readiness — 10/10
- Explicit Draft / Published / Archived lifecycle.
- Publication requires a real public Stonefellow track and canonical lyrics.
- Language codes are normalized and validated.
- Section ranges must remain inside canonical lyric line bounds.
- Sections may not overlap.
- Every meaningful non-empty lyric line must be covered by exactly one Songbook section; structural heading lines are recognized and exempted.
- Annotations may reference only lines covered by a Songbook section.
- Readiness is deterministic and blocks publication when any rule fails.

## 35C — Governed Publishing Lifecycle — 10/10
- Initial publication/archival requires explicit Admin confirmation.
- Every later visibility-state transition, including Published → Draft unpublishing, requires explicit confirmation.
- Consequential status changes are visible in Agent Brain and Admin audit.
- Admin UI reflects Draft / Published / Archived state and readiness before save.

## 35D — Admin Songbook Editor — 10/10
- Songbook editor is embedded directly in each Catalog track workspace; no extra Admin sidebar item.
- Canonical lyrics remain edited in the existing Catalog field.
- Songbook controls sections, annotations, language, public songwriting credits and internal notes.
- Automatic verse/chorus/bridge structure detection is available as an editing aid.
- Readiness and 30-day public interaction metrics are displayed in the editor.
- Consequential status changes require an explicit confirmation prompt.

## 35E — Public Songbook Experience — 10/10
- Track pages no longer render raw Catalog lyrics directly.
- Public Songbook content loads only through the governed Songbook API.
- Structured verse/chorus/bridge sections render responsively.
- Line annotations are user-expandable.
- Optional public songwriting credits come from canonical track metadata.
- Collection bindings use the correct multi-element selector helper.
- Section 34 visual theme tokens are respected.

## 35F — Search & Agent Grounding — 10/10
- Public search indexes lyric text only when that track has a currently published Songbook.
- Draft/private Songbook lyrics do not influence public search ranking.
- Public Agent context receives only bounded text from published Songbooks.
- Draft lyric text is explicitly removed from public Agent track-ranking context.
- Agent Songbook context remains query-relevant and bounded rather than dumping the entire lyric catalog.

## 35G — Truthful Songbook Events — 10/10
- Public Songbook event writes are rate-limited.
- GET is side-effect free.
- Allowed event types match the public UI exactly: view, section_open and annotation_open.
- Events require a currently public Songbook.
- No fabricated share/open/impression event exists.
- Event detail is bounded and sanitized.

## 35H — Upgrade, Regression & Release Safety — 10/10
- Application advances to **1.3.34**.
- Database target advances to **1.3.29**.
- Migration **2026-10-11-030** creates `songbooks` and `songbook_events` and sanitizes the generated public catalog asset.
- Upgrade integrity requires both Songbook tables.
- Existing Section 1–34 regression suites remain green.
- PHP syntax: **PASS**
- Public JavaScript syntax: **PASS**
- Admin JavaScript syntax: **PASS**
- Section 35 certification: **PASS**
- Explicit PASS lines on hardened implementation candidate: **1956**
- PHP warnings: **0**
- Failures: **0**

FINAL SECTION 35 SCORE: **10/10**
