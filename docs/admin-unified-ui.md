# Stonefellow Admin Unified UI Audit — v1.3.49

**Scope:** all 29 authenticated Admin navigation views. Existing Settings and Story modal components are preserved.

## UX changes
- Unified page headers on all Admin views, with page-level Create/Add/Upload buttons promoted from nested panels when they already exist. Primary control handlers are never cloned, so existing application state and authorization paths remain authoritative.
- Existing standalone create/edit forms for **Templates, Catalog Tracks, Releases, Shows, Packages, Campaigns, Products, Member Content, Ticket Offers, Audience Segments, Automations, Press Kits, and Launch Plans** open in a focused dialog. The original fully-bound form DOM and canonical Back action are preserved.
- Legacy panels and tables gain consistent width constraints, page spacing and responsive overflow. On mobile, editor dialogs fill the viewport, with a separate vertical scroll region, keyboard focus containment, and explicit Close and Escape controls.
- Existing modern modals (Story Editor, Video, Galleries, Media, Shipments, Rights parties, Press contacts) are not intercepted. Admin pages without a safe create action do not invent one.
- Unsaved edits trigger confirmation on Close, Escape, backdrop click, and original Back buttons. Save failures do not clear the unsaved flag. Approval, upload, campaign sends and publishing use their original endpoints.

## Certification boundaries
This is a generic presentation-layer migration, not a rewrite of 29 independent backend modules. Source inspection and full CI are required for all known form identifiers and return actions. A live browser acceptance check of representative complex builders (campaign nodes, metadata templates, EPK, release uploads), mobile scroll, and keyboard focus is recommended after deployment. No new schema or account password mutation is introduced.

Release app and cache version **1.3.49**. Schema target remains **1.3.46**.
