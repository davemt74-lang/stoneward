# Stonefellow v1.3.28 Upgrade Notes

1. Upload/extract the v1.3.28 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, site-specific configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.23** to **1.3.24**.
5. Migration **2026-10-10-025** creates:
   - `press_kits`
   - `press_contacts`
   - `press_outreach`
   - `press_coverage`
   - `press_events`
6. Migration integrity now also explicitly checks the Section 28 rights tables.
7. Press-kit media continues to live in the existing central Media Library.
8. Verify Admin → EPK + Press, create a draft kit, attach media, then explicitly publish or make it private.
9. If using a private EPK, rotate the private link and store/share that URL securely. Rotating again invalidates the old token.
10. Press outreach uses the existing Admin-configured email delivery settings.

Application: **1.3.28**  
Database schema: **1.3.24**
