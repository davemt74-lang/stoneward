# Stonefellow v1.3.18 — Section 19: Media Uploads & Asset Library

Stonefellow remains a **single-artist direct-to-fan platform**. Section 19 completes the song-media workflow with a central Media Library, real upload controls, audio metadata extraction, controlled publishing, and reusable asset relationships.

## Central Media Library

Admin now includes **Media Library** as a first-class workspace.

Supported media categories:

- Audio — MP3 and WAV
- Images — JPG, PNG, WebP and GIF
- Video — MP4, WebM and MOV
- Documents — PDF, TXT, Markdown and RTF
- Archive media — any supported media type attached in an archival role

Every asset records:

- stable media UUID
- original filename
- managed storage path
- SHA-256
- file size
- MIME type and extension
- category
- title, caption, alt text, credit and copyright
- extracted technical metadata
- image variants where available
- uploader and timestamps
- asset usage count / relationships

Files live under private `storage/media/`. Public playback and display go through the controlled `api/media.php` delivery endpoint.

## Song Media workspace

Every song editor now has a real **Media** section.

It supports:

- Upload audio
- Upload artwork
- Upload photos
- Upload video
- Upload documents
- Upload archive material
- Choose an existing Media Library asset

Audio roles include:

- Primary audio
- Master
- Preview
- Download
- Alternate audio
- Candidate / replacement

Uploading audio never silently replaces the live song. The Admin can inspect/play the uploaded asset first and then explicitly choose **Make Primary Audio**.

Replacing primary audio:

- updates the song's playable source
- refreshes duration from extracted audio metadata when available
- marks the selected media relationship public
- demotes the previous primary to a non-public candidate instead of deleting it
- records the action in Admin audit and Agent Brain

Artwork follows the same pattern with **Make Primary Artwork**.

Attached media supports:

- Public on/off
- Downloadable on/off
- Featured on/off
- role selection for audio
- detach without deleting the asset
- permanent deletion only when unused
- drag reordering within media roles

## MP3 / WAV metadata extraction

Stonefellow uses **FFprobe when available**.

For MP3/WAV, FFprobe can provide:

- duration
- codec / format
- bitrate
- sample rate
- channel count
- bit depth where available
- embedded title
- artist
- album
- year/date
- track number
- genre
- comments
- embedded-artwork presence
- stream-level metadata

Stonefellow also includes built-in fallbacks.

### WAV fallback

The RIFF parser reads:

- exact duration from data size and byte rate
- sample rate
- channels
- bits per sample
- audio format / PCM type
- byte rate
- block alignment
- INFO metadata such as title, artist, product/album, date, genre and comment

### MP3 fallback

The MP3 parser reads:

- MPEG version/layer
- first valid frame bitrate
- sample rate
- channel mode
- approximate constant-bitrate duration
- ID3v2 text fields
- ID3v1 title, artist, album, year and comment
- embedded-artwork presence from ID3v2 APIC when detected

The Admin Media Library reports whether FFprobe is installed and whether Stonefellow is using the richer FFprobe path or its built-in fallback.

## Image processing

When the PHP GD extension is available, uploaded images generate:

- Large — max 1600 px
- Medium — max 800 px
- Thumbnail — max 320 px

The original is retained untouched.

If GD is unavailable, the original asset still works and the Media Library reports that derivative generation is unavailable.

## Public song pages

Only media explicitly marked **Public** appears on public song pages.

Public song media can include:

- photos/artwork galleries
- video
- alternate audio
- downloadable audio when separately allowed
- documents and archive items

Public visibility and download permission are independent.

Private masters never enter the public song page or public Agent context.

## Existing catalog migration

Migration 016 backfills existing song media where possible.

It can:

- recover old folder-import master/preview audio using the previous SHA-256 media index
- register existing stored originals without duplicating bytes
- copy legacy local song audio/artwork into managed Media Library storage
- import local legacy archive media
- preserve the song's existing playback source during the migration itself

Future folder imports register master and preview files directly in Media Library.

## Agent integration

Admin Agent Brain records:

- media uploads
- primary-audio publication
- primary-artwork publication

The Admin Agent routes media-management requests to Media Library.

The public Stonefellow Agent can see a concise summary of **public** song media only. Private masters and private archive assets are excluded.

## Database

Migration **2026-10-09-016** adds:

- `media_assets`
- `media_links`

Application: **1.3.18**  
Database schema target: **1.3.15**
