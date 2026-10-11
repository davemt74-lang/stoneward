# Stonefellow v1.3.33 Upgrade Notes

1. Upload/extract the v1.3.33 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, database configuration, secrets, store configuration and generated runtime files.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.28** to **1.3.29**.
5. Migration **2026-10-11-030** creates:
   - `songbooks`
   - `songbook_events`
6. Migration 030 also regenerates `assets/js/catalog.js` from the private Catalog **without the `lyrics` field**.
7. Existing track lyrics remain in the authoritative Catalog and are not copied into SQL.
8. Open Admin → Catalog → a track to configure its Songbook.
9. Save any canonical lyric edits with **Save track** before editing/publishing Songbook line ranges.
10. Songbook defaults to Draft. Publishing requires a public track, canonical lyrics, valid language and valid non-overlapping section ranges.
11. Draft lyrics no longer appear in the public track page, public generated catalog, public search, or public Agent context.
12. Published Songbook lyrics remain searchable and available to the public Agent through the governed Songbook layer.
13. The new web-server rules deny direct HTTP access to `/data/` and `/config/`; server-side application access is unchanged.

Application: **1.3.33**  
Database schema target: **1.3.29**
