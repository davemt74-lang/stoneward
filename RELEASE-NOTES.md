# Stonefellow v1.3.11 — Section 12: Search, Discovery & Catalog Intelligence

- Replaced the basic Music filter with unified server-backed track/release Search & Discover.
- Added weighted search across titles, releases, moods, themes, stories, lyrics, credits, metadata, and release metadata.
- Added typo-tolerant matching and zero-result suggestions.
- Added facets for result type, mood, theme, release, year, and energy.
- Added relevance, popularity, newest, and title sorting.
- Added signed-in personalization boosts without creating a parallel recommendation model.
- Added popularity signals from canonical listening events.
- Added recent searches and user-controlled search-history clearing.
- Added shareable/restorable catalog search URLs.
- Added search-result selection telemetry and dedicated catalog-search playback telemetry.
- Added guest-safe telemetry throttling bound to server-observed identity and signed-in user/session governance.
- Added Agent natural-language catalog search and current-search context.
- Added Admin search/discovery analytics, zero-result catalog-gap reporting, filter usage, top selected results, and recent search activity.
- Added migration 012.
- Application and database schema target advance to 1.3.11.
- Updated the Section 11 regression gate to remain forward-compatible with later schema upgrades.
