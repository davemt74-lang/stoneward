# Stonefellow v1.3.31 Upgrade Notes

1. Upload/extract the v1.3.31 deploy over the existing Stonefellow installation.
2. Preserve production-owned data/, storage/, database configuration, secrets, store configuration and generated catalog/config JS.
3. Run /upgrade.php.
4. Database target advances from **1.3.26** to **1.3.27**.
5. Migration **2026-10-11-028** creates discovery_events.
6. Apache rewrite support is required for canonical routes such as /track/{id}, /release/{id}, /show/{id}, /store/{slug}, /campaign/{slug} and /press/{slug}.
7. Existing query-string routes remain functionally compatible, but canonical metadata points search engines/social sharing to the pretty public route.
8. /sitemap.xml and /robots.txt become available automatically after deployment.
9. Review Admin → Settings SEO title/description and Public Site Media social-share/hero imagery before launch.
10. Discovery attribution begins recording bounded landing/share source data after deployment; full referrer URLs are not stored.
11. Performance Intelligence will begin showing Discovery sources/routes as real landing data accumulates.
12. Private EPKs remain excluded from sitemap/indexing; private bearer tokens continue to live in URL fragments.

Application: **1.3.31**
Database schema target: **1.3.27**
