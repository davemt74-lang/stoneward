# Stonefellow v1.3.18 Upgrade Notes

1. Upload/extract the v1.3.18 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, site-specific configuration, and generated catalog/config JS during deployment.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.14** to **1.3.15**.
5. Migration **2026-10-09-016** creates:
   - `media_assets`
   - `media_links`
6. Migration 016 also attempts a safe backfill of existing catalog media:
   - old importer master/preview files are recovered through stored SHA/index information when available
   - local public song audio/artwork is copied into managed Media Library storage if required
   - local legacy archive files are attached
   - existing song playback/artwork URLs are not silently replaced by the backfill
7. New media uploads are stored under private `storage/media/`.
8. Confirm Media Library reports FFprobe/GD capability and the effective upload ceiling.
9. Open a song → Media and verify upload, preview, Choose from Library, visibility controls and Make Primary Audio.
10. Public media is delivered through `api/media.php`; HTTP Range support is enabled for audio/video seeking.

Application: **1.3.18**  
Database schema: **1.3.15**
