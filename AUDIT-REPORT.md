# Stonefellow v1.3.35 — Section 36 Visual Media & Video Experience Audit

## 36A — Video Publication Model — 10/10
- One canonical Video Hub model for music videos, live sessions, visualizers, behind-the-scenes, interviews and short films.
- Video records store publication metadata only; bytes remain in Media Library.
- Draft, Published and Archived states are explicit.
- Publishing requires current readiness and explicit Admin confirmation.
- Featured published video state is unique.

## 36B — Managed Media & Playback Safety — 10/10
- Public video requires exactly one managed public playback asset and one public poster.
- Public playback is restricted to browser-safe MP4/WebM; MOV may remain private/source media.
- Public playback requires known managed duration.
- Public poster must be at least 640×360 in either orientation.
- Media delivery keeps path containment, public visibility and byte-range playback.

## 36C — Captions & Chapters — 10/10
- Caption attachments are managed WebVTT assets.
- Publication validates file extension/MIME and actual WEBVTT header.
- Current single-language model permits at most one published caption track.
- Language codes are normalized and validated.
- Chapter positions are floats, sorted, de-duplicated and constrained inside duration.

## 36D — Governed Media Publication — 10/10
- Video Hub media starts private by default.
- Public/private and downloadable visibility changes require explicit Admin confirmation.
- Poster publication requires server-side confirmation.
- Visibility changes are written to Admin audit and Agent Brain.
- No shadow uploader or duplicate media store exists.

## 36E — Public Video Hub Experience — 10/10
- Public /videos hub and /video/{slug} detail routes.
- Native controls, preload=metadata and playsinline; no autoplay.
- Managed captions, chapters, linked song/release/show entities and gallery media.
- Canonical sharing reuses the completed-share workflow.
- Homepage supports optional Featured Video without another Admin sidebar item.

## 36F — Truthful Events & Analytics — 10/10
- Allowlisted events only: view, play, pause, 25/50/75 progress, complete, caption enable, chapter seek and entity open.
- Progress milestones emit once per playback through a guarded loop.
- No invented impressions or estimated watch-time events.
- Public writes are rate-limited and require a currently public-ready video.

## 36G — SEO, Homepage & Agent Integration — 10/10
- VideoObject structured data includes poster, content URL, duration, publication date and linked entities.
- Sitemap uses readiness-filtered public Video Hub data.
- Canonical /videos and /video/{slug} routing works in server and SPA layers.
- Homepage auto-selects featured published video or explicitly selects a ready public video.
- Agent video context is sourced only from published-ready records.

## 36H — Upgrade & Release Safety — 10/10
- Application: 1.3.35
- Database target: 1.3.30
- Migration 2026-10-11-031 creates video_features and video_events and refreshes Homepage defaults.
- PHP syntax: PASS
- Public JavaScript syntax: PASS
- Admin JavaScript syntax: PASS
- Sections 1–35 inherited suites: PASS
- Section 36 certification: PASS
- Explicit PASS lines: 2031
- PHP warnings: 0
- Failures: 0

FINAL SECTION 36 SCORE: 10/10
