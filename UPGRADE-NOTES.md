# Stonefellow v1.3.40 Upgrade Notes

1. Upload/extract the v1.3.40 deploy over the existing installation.
2. Preserve production-owned data, storage, secrets and generated catalog/config files.
3. No new database migration is required; schema target remains **1.3.32**.
4. Run /upgrade.php as usual to verify migration integrity.
5. Existing Media Library image variants are reused automatically for responsive delivery.
6. Public media now uses ETags and revalidation; visibility changes remain revocable.
7. Core Web Vitals begin accumulating only after real public browser traffic reaches the upgraded site.

Application: **1.3.40**
Database schema target: **1.3.32**
