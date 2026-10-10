# Stonefellow v1.3.19 — Section 20: Universal Media Integration & Publishing

Stonefellow is a **single-artist direct-to-fan platform**. Section 20 extends the v1.3.18 Media Library from songs to every major publishing surface.

## Universal Media Library

The same reusable Media Library now powers:

- Songs
- Releases
- Shows / Live Archive
- Campaigns
- Store products
- Public site / artist media

Uploads are still stored privately under `storage/media/` and delivered through the controlled media endpoint. Public visibility and download permission remain separate.

## 20A — Release Media

Release editing now uses the Media Library instead of typed paths or a separate artwork uploader.

Supported release roles:

- Front cover
- Back cover
- Label — Side A
- Label — Side B
- Social square
- Social story
- Photos
- Video
- Documents / liner-note PDFs
- Archive media

Publishing an artwork role updates the canonical release record. Existing legacy archive links are preserved.

## 20B — Shows & Live Archive

Show editing now supports:

- Poster
- Photos
- Alternate / live audio
- Video
- Documents
- Archive / memorabilia

This replaces the old poster-path and archive-media text fields.

Publishing a Poster updates the canonical Show record. Public Show pages load additional public Media Library relationships.

## 20C — Campaign Builder Media

Campaign Builder now has a dedicated **Media** tab.

Supported campaign roles:

- Hero artwork
- Background
- Offer artwork
- Video
- Documents
- Archive/supporting media

Publishing Hero updates the campaign's canonical artwork field. Public campaign payloads include public campaign-media relationships.

## 20D — Store Media

The Media Library dashboard lists current store products and opens a product-specific media manager.

Store roles:

- Primary product image
- Product gallery

The storefront API resolves public product imagery from Media Library relationships. Store cards display managed product imagery automatically.

## 20E — Site & Artist Media

Admin Settings now embeds a Site & Artist Media panel for:

- Logo
- Hero/background
- Artist photos
- Social-share artwork
- App icon

Published site media becomes canonical site metadata.

The public experience uses it for:

- header/splash logo
- Agent-stage hero background
- Open Graph image
- favicon
- About-page artist photo

## 20F — Media Usage Intelligence

Each asset can show every place it is used:

- entity type
- entity name
- role
- public/private state
- downloadable state

Deletion remains blocked while an asset is referenced.

## 20G — Agent Media Operations

Media attach/detach/publish operations are recorded in Admin Agent Brain.

Admin Agent requests such as missing artwork, unused media, broken media, media completeness, or attaching/reusing artwork route to the Media Library.

Consequential publish/detach actions remain explicit Admin operations.

## 20H — Media Completeness Dashboard

Media Library now reports:

- total managed assets
- storage use
- unused assets
- broken stored files
- songs missing audio
- songs missing artwork
- releases missing front cover
- shows missing poster
- published/scheduled campaigns missing hero artwork
- store products missing media
- missing site-media roles
- public records pointing to Media Library assets that are no longer public

## Upgrade

Migration **2026-10-09-017** backfills existing release/show/campaign media into the universal relationship model where local files are available.

It does not replace canonical public paths during migration. Publishing a replacement remains an explicit Admin action.

Application: **1.3.19**  
Database schema target: **1.3.16**
