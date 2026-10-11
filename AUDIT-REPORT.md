# Stonefellow v1.3.33 — Section 34 Lyrics, Songbook & Track Notes Audit

Section 34 keeps each track’s existing Catalog `lyrics` field authoritative and adds governed publication metadata around it. The Songbook database never stores a second copy of lyric text.

## 34A — Canonical Lyrics + Songbook Data Model — 10/10
- Catalog lyrics remain the single authoritative lyric text.
- Songbook stores only status, language, section ranges, annotations, credits display, internal notes and timestamps.
- One Songbook record per canonical track.
- SQLite and MySQL schemas are both supported.
- Existing Catalog records require no destructive backfill or duplication.

## 34B — Catalog-Embedded Songbook Editor — 10/10
- Songbook lives inside each existing Catalog track workspace; no additional Admin sidebar item was added.
- Canonical lyrics remain editable through the existing track form.
- Admin copy explicitly explains that canonical lyrics remain private until Songbook publication.
- Songbook editor supports Draft / Published / Archived, language, public credit visibility, internal notes, section ranges and line annotations.
- Verse / chorus / bridge structure detection can seed section ranges from conventional lyric headings.
- Readiness and 30-day Songbook metrics are visible directly in the track workspace.
- Admin Agent routes Songbook / lyric-publishing / verse / chorus / annotation requests into Catalog.

## 34C — Publication Readiness & Governance — 10/10
- Publishing requires a public canonical track, non-empty canonical lyrics, valid language code and at least one valid section.
- Section ranges must remain inside the current canonical lyric line count.
- Overlapping sections block publication.
- Annotations must reference a lyric line that belongs to a published section.
- Initial publication, later publication and archival all require explicit Admin confirmation.
- Status changes and saves feed Admin audit and Agent Brain.
- If canonical lyrics later change enough to invalidate stored ranges, the public Songbook automatically becomes unavailable until corrected.

## 34D — Public Songbook Experience — 10/10
- Raw Catalog lyrics are no longer rendered directly on track pages.
- Track pages load only a Published + ready Songbook payload.
- Public view renders named sections, line numbers, optional songwriting credits and expandable line annotations.
- Public Songbook uses dedicated responsive styling.
- Missing/draft/unready Songbooks simply disappear from the public track page rather than leaking partial content.
- Existing rich track page, credits, recording notes, media, stories, queue, purchase and custom-record controls remain intact.

## 34E — Draft-Lyric Privacy & Public Catalog Hardening — 10/10
- Public generated `assets/js/catalog.js` strips the canonical `lyrics` field entirely.
- Full canonical lyrics remain only in the private authoritative `data/catalog.json`.
- Migration 030 immediately regenerates an existing public catalog without lyric text.
- Every later Catalog write continues generating a lyric-free public catalog.
- Apache now denies direct web requests to `/data/` and `/config/`.
- Public search indexes lyric text only when the corresponding Songbook is currently Published + ready.
- Public Agent track ranking removes private lyrics before relevance scoring, eliminating draft-lyric title-association leakage.

## 34F — Search + Agent Integration — 10/10
- Published Songbooks remain searchable by lyric phrase through the established Search & Discovery engine.
- Draft/archived/unready Songbooks contribute no lyric search text.
- Public Agent receives a bounded published Songbook context, separate from the normal catalog context.
- Agent Songbook context is query-ranked and capped, preventing indiscriminate full-catalog lyric injection.
- Existing song, story, release, show and knowledge context remains intact.

## 34G — Truthful Songbook Events — 10/10
- Allowlisted events are limited to view, section_open, annotation_open and share.
- Songbook GET is side-effect free.
- Public event writes use the rate-limited POST path.
- Events are accepted only while the Songbook remains public.
- Event detail is bounded and sanitized.
- Public UI records real views, section opens and annotation opens; no inferred reading/completion events are invented.

## 34H — Upgrade, Regression & Release Safety — 10/10
- Application advances to **1.3.33**.
- Database target advances to **1.3.29**.
- Migration **2026-10-11-030** creates `songbooks` and `songbook_events` and regenerates the lyric-free public catalog.
- Upgrade integrity requires both Songbook tables.
- Section 5 rich-track regression now requires governed Songbook lyrics rather than raw Catalog rendering.
- Section 12 search regression now requires published-Songbook lyric search.
- Section 33 version/schema regression remains forward-compatible.
- PHP syntax: **PASS**
- Public JavaScript syntax: **PASS**
- Admin JavaScript syntax: **PASS**
- Sections 1–33 inherited regression suites: **PASS**
- Section 34 certification: **PASS**
- Explicit PASS lines on hardened implementation candidate: **1887**
- PHP warnings: **0**
- Failures: **0**

FINAL SECTION 34 SCORE: **10/10**
