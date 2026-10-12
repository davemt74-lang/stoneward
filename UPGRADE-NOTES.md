# Stonefellow v1.3.39 Upgrade Notes

1. Upload/extract the v1.3.39 deploy over the existing installation.
2. Preserve production-owned data, storage, secrets and generated catalog/config files.
3. No new database migration is required; schema target remains **1.3.32**.
4. Run /upgrade.php as usual to verify migration integrity.
5. Browsers that support install prompts will expose **Install Stonefellow** in the public menu when eligible.
6. The service worker caches only public static shell assets and the offline fallback. It does not cache API, account, Admin, purchases or private content.
7. Existing Artist Theme contrast governance remains active; Section 40 adds keyboard, motion, forced-colors and touch-target hardening.

Application: **1.3.39**
Database schema target: **1.3.32**
