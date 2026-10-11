# Stonefellow v1.3.33 Upgrade Notes

1. Upload/extract the v1.3.33 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, database configuration, secrets, store configuration and generated catalog/config JavaScript.
3. Run `/upgrade.php` as usual.
4. The database target remains **1.3.28**. Section 34 adds no database migration because visual theme state is stored in the existing `app_meta` settings table.
5. Existing sites retain the Stonefellow **Desert** visual identity unless a published theme already exists.
6. Open **Admin → Settings → Artist Brand & Visual Theme**.
7. Choose a preset or customize the bounded controls:
   - Dark / Light / Follow System
   - display/body typography
   - content width
   - comfortable/compact density
   - square/soft/round corners
   - paired dark and light color palettes
8. Save the draft first. Unsaved browser edits are intentionally not eligible for live preview or publishing.
9. Use **Live saved-draft preview** to inspect the real public application. The preview is Admin-only, noindex and excluded from public acquisition/Homepage/Storytelling analytics.
10. Resolve any contrast blockers shown by readiness.
11. Publish only when ready; publishing requires explicit confirmation.
12. **Restore Desert default** also requires explicit confirmation because it immediately changes the public site.

Application: **1.3.33**  
Database schema target: **1.3.28**
