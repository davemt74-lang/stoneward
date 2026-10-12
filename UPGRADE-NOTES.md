# Stonefellow v1.3.37 Upgrade Notes

1. Upload/extract the v1.3.37 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, database configuration, secrets, store configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances to **1.3.32**.
5. Migration **2026-10-11-033** creates:
   - `photo_galleries`
   - `photo_gallery_events`
6. Photo Galleries live inside Admin → Media Library; no additional sidebar item is added.
7. Create a gallery as Draft first, then attach managed Media Library images using cover/photo roles.
8. Before publication, make exactly one cover and at least three photos Public; all public images require alt text and minimum usable dimensions.
9. Photographer/creator credit is recommended and surfaced as a readiness warning when absent.
10. Gallery links may reference only currently public releases, shows and editorial stories.
11. Publishing/archive and public/download visibility changes are explicitly confirmation-governed.
12. Existing Video Hub and Contact routing/governance remain unchanged.

Application: **1.3.37**  
Database schema target: **1.3.32**
