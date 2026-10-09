# Stonefellow v1.3.18 — Section 19 Media Uploads & Asset Library Audit

## 19A — Central Media Library — 10/10 design
- Reusable central asset model.
- SHA-256 deduplication.
- Private managed storage.
- Reusable entity relationships.
- Metadata, credits, captions, alt text and rights.
- Safe detach vs permanent deletion.

## 19B — Secure Uploader — 10/10 design
- Authenticated/CSRF-protected Admin upload.
- MIME + file-signature validation.
- Collision-safe managed paths.
- Server upload-capability reporting.
- Upload progress in Admin.
- No executable media is placed into a PHP runtime directory.

## 19C — Song Media — 10/10 design
- Audio, artwork, photos, video, documents and archive media.
- Multiple assets per role.
- Choose existing media.
- Explicit audio roles.
- Explicit publish replacement for primary audio/artwork.

## 19D — Gallery / Ordering — 10/10 design
- Public/Downloadable/Featured controls.
- Drag ordering.
- Detach preserves library asset.
- Deletion blocked while an asset is still linked.
- Public song page uses public relationships only.

## 19E — Audio Metadata — 10/10 design
- FFprobe path when installed.
- WAV RIFF fallback.
- MP3 frame + ID3 fallback.
- Duration, bitrate, sample rate, channels and bit depth extraction.
- Embedded metadata and artwork-presence detection.
- Primary publish refreshes song duration.

## 19F — Image Processing — 10/10 design
- Original preserved.
- 1600/800/320 derivatives with GD.
- Safe original-only fallback when GD is unavailable.

## Existing media migration — 10/10 design
- Recovers old importer files using SHA/index.
- Registers existing storage originals without byte duplication.
- Copies legacy local public assets into managed media storage when needed.
- Preserves existing public playback URLs during upgrade.

## 19H — Agent Brain — 10/10 design
- Upload/publish actions audited.
- Media operations feed Admin Agent Brain.
- Admin Agent routes media requests to Media Library.
- Public Agent receives only public media summaries.

## Regression gate — pending measured GitHub validation
- PHP syntax required.
- Public JavaScript syntax required.
- Admin JavaScript syntax required.
- Media Library JavaScript syntax required.
- Synthetic WAV parser verification required.
- Synthetic MP3 parser verification required.
- Sections 1–18 regression suites required.

PROVISIONAL SECTION 19 SCORE: **10/10 design / pending green release gate**
