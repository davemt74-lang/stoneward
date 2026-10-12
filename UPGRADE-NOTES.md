# Stonefellow v1.3.38 Upgrade Notes

1. Upload/extract the v1.3.38 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, database configuration, secrets, store configuration and generated catalog/config JavaScript.
3. Run `/upgrade.php`.
4. Database target remains **1.3.32**. Section 39 introduces no new migration.
5. Section 39 reuses the existing `catalog_search_events` table created by migration **2026-10-08-012**. New site-wide telemetry uses isolated event names `site_search` and `site_click`.
6. Public navigation gains **Search** and Apache must allow the existing rewritten `/search` route.
7. Search is intentionally **noindex,follow,noarchive** and is not added to the public sitemap.
8. Search results are generated only from currently public/readiness-approved records. Publishing or unpublishing content changes Search eligibility immediately.
9. Performance Intelligence gains a separate Site-wide Search panel.
10. Existing Catalog Search & Discovery remains available under Music; its history and analytics remain separate.
11. No Fan Community setting or CRM behavior changes in this release.

Application: **1.3.38**  
Database schema target: **1.3.32**
