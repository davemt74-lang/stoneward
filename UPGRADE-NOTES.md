# Stonefellow v1.3.34 Upgrade Notes

1. Upload/extract the v1.3.34 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, database configuration, secrets, store configuration and generated runtime state.
3. Run `/upgrade.php`.
4. Database target advances to **1.3.29**.
5. Migration **2026-10-11-030** creates:
   - `songbooks`
   - `songbook_events`
6. Migration 030 also regenerates the browser-delivered catalog asset without canonical lyric text.
7. Existing canonical lyrics remain in the private Catalog data source.
8. Open Admin → Catalog → a track to configure its Songbook.
9. Save the Catalog track first after changing canonical lyrics, then update the Songbook section/annotation line mappings.
10. A Songbook cannot publish until its readiness checks are green.
11. Publishing, archiving and unpublishing require explicit Admin confirmation.
12. Public search and the public Agent will not use lyric text unless that track has a currently published Songbook.

Application: **1.3.34**  
Database schema target: **1.3.29**
