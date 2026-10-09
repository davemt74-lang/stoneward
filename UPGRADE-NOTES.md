# Upgrade to Stonefellow v1.3.11 Section 12

Upload/extract the update over the existing Stonefellow installation, then run:

```
/upgrade.php
```

Section 12 advances the database schema from **1.3.10** to **1.3.11** with:

- `2026-10-08-012` — catalog search telemetry, discovery analytics, and selected-result attribution.

New table:

- `catalog_search_events`

The table supports both anonymous discovery telemetry and signed-in search history. Anonymous session identity is hashed and rate bounded; signed-in users can clear their own search history from Search & Discover.

Existing catalog, releases, favorites, playlists, listening history, recommendations, Agent sessions, queues, saved builds, orders, notifications, and prior analytics are preserved.
