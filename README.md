# Stonefellow v1.3.11 — Section 12: Search, Discovery & Catalog Intelligence

Section 12 replaces the basic client-side Music filter with a unified server-backed search and discovery system for Stonefellow tracks and releases.

## Included

- Unified track + release search from the Music workspace.
- Searchable catalog signals include:
  - title and release
  - moods and themes
  - story and recording notes
  - lyrics
  - credits
  - structured track metadata
  - release description, label, genre, catalog number, UPC/EAN, and track titles
- Deterministic relevance ranking with exact-title, prefix, release, tag, content, multi-term, and typo-tolerant matching.
- Signed-in personalization boosts using the existing favorites/listening/playlist recommendation profile.
- Popularity ranking from canonical listening starts and completions.
- Facets for type, mood, theme, release, year, and energy.
- Sort modes: Best match, Most played, Newest, Title A–Z.
- Shareable/restorable search/filter URL state.
- Mood/theme discovery chips.
- Recent searches for signed-in users with Clear history.
- Zero-result spelling/discovery suggestions.
- Search-result Play, Favorite, Queue, track detail, and release detail actions.
- Dedicated `catalog_search` listening telemetry source.
- Search/click telemetry with server-side hashing and bounded event volume.
- Agent natural-language catalog search with deterministic search execution and search-result opening.
- Admin search intelligence:
  - searches
  - result click-through rate
  - zero-result rate
  - top queries
  - catalog-gap queries
  - most selected results
  - filter usage
  - recent search activity
  - per-user search metrics

## Database

Section 12 advances the database schema to **1.3.11**.

Migration:

- `2026-10-08-012` — catalog search telemetry, discovery analytics, and selected-result attribution.

Run **`/upgrade.php`** after deploying v1.3.11.

Application: **1.3.11**  
Database schema target: **1.3.11**
