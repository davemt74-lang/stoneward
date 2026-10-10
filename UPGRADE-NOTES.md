# Stonefellow v1.3.19 Upgrade Notes

1. Upload/extract the v1.3.19 deploy overlay over the current Stonefellow installation.
2. Preserve production `data/`, `storage/`, site-specific config, and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.15** to **1.3.16**.
5. Migration **2026-10-09-017** performs universal media backfill:
   - release artwork roles
   - local release archive media
   - show posters
   - local show archive media
   - campaign artwork
6. Existing public paths are preserved during migration.
7. Open Admin → Media Library and review the Media Completeness dashboard.
8. Open representative Release, Show, Campaign, Store Product and Settings records and verify upload/reuse/publish behavior.
9. Publishing a primary media role is explicit and audited.

Application: **1.3.19**  
Database schema: **1.3.16**
