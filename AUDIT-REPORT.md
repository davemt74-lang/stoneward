# Stonefellow v1.3.19 — Section 20 Universal Media Integration & Publishing Audit

## 20A — Release Media — 10/10 design
- Central Media Library embedded in Release Builder.
- Front/back cover, labels, social art, photos, video, PDFs/documents and archive roles.
- Manual artwork/archive path entry removed from live Release Builder.
- Published primary artwork synchronizes canonical release data.

## 20B — Shows & Live Archive — 10/10 design
- Poster, photo, alternate/live audio, video, document and archive media.
- Manual poster and archive-media path entry removed.
- Published poster synchronizes canonical Show data.
- Public Show page can render explicitly public Media Library assets.

## 20C — Campaign Builder Media — 10/10 design
- Dedicated Media tab.
- Hero, background, offer-artwork, video, document and archive roles.
- Manual campaign artwork path field removed.
- Public campaign payload includes public Media Library relationships.

## 20D — Store Media — 10/10 design
- Per-product Media Library manager.
- Primary and gallery imagery.
- Storefront API and cards consume managed public media.

## 20E — Site / Artist Media — 10/10 design
- Logo, hero, artist photo, social-share art and app icon.
- Canonical site metadata updated by explicit publish.
- Public shell/About page uses published assets.

## 20F — Usage Intelligence — 10/10 design
- Asset usage list resolves entity names and roles.
- Public/download state shown.
- Linked assets cannot be destructively deleted.
- Managed-storage and unused-asset totals available.

## 20G — Agent Media Operations — 10/10 design
- Attach/detach/publish actions feed Admin Agent Brain.
- Missing/broken/unused media queries route to Media Library.
- Consequential publishing remains explicit.

## 20H — Completeness Dashboard — 10/10 design
- Missing song audio/artwork.
- Missing release covers.
- Missing show posters.
- Missing campaign heroes.
- Missing store imagery.
- Missing site roles.
- Broken files.
- Public/private relationship mismatch.
- Storage and unused-media totals.

## Migration & backward compatibility — 10/10 design
- Migration 017 only adds universal relationship backfill.
- Existing Section 19 media tables remain canonical.
- Existing public paths are preserved.
- Legacy local media is copied/registered safely.

## Regression gate — pending measured GitHub validation
- PHP syntax required.
- Public JS syntax required.
- Admin JS syntax required.
- Campaign Builder JS syntax required.
- Media Library JS syntax required.
- Sections 1–19 regression suites required.
- Section 20 suite required.

PROVISIONAL SECTION 20 SCORE: **10/10 design / pending green release gate**
