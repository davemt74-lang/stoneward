# Stonefellow v1.3.27 Upgrade Notes

1. Upload/extract the v1.3.27 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, site-specific configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.22** to **1.3.23**.
5. Migration **2026-10-10-024** creates:
   - `rights_parties`
   - `rights_works`
   - `rights_splits`
   - `rights_licenses`
6. Migration 024 initializes one rights-work record per current catalog track and copies existing descriptive rights metadata where available.
7. Migration 024 intentionally does **not** infer ownership percentages or parties from free-text credits.
8. Open Admin → Rights + Licensing and enter/verify composition and master ownership.
9. A work becomes rights-ready only when both split groups total exactly 100% and recorded clearances have no blockers.

Application: **1.3.27**  
Database schema: **1.3.23**
