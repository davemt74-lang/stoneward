# Stonefellow v1.3.13 Upgrade Notes

1. Upload and extract the v1.3.13 deploy package over the existing installation.
2. Preserve production `data/`, `storage/`, and site-specific configuration.
3. No new database migration is required for Section 15. The database schema target remains **1.3.12**.
4. Existing catalog and release JSON records remain valid. Archive fields are additive and optional.
5. Release records saved through Admin use `stonefellow.release.v2`, adding liner notes, credits and archive context.
6. Visit the public **Music Archive** and verify any archive metadata entered in Admin appears as expected.

Running `/upgrade.php` is safe but should report the database as already current when v1.3.12 migrations were previously applied.
