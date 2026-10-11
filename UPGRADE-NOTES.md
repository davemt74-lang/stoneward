# Stonefellow v1.3.28 Upgrade Notes

1. Upload/extract the v1.3.28 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, site-specific configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.23** to **1.3.24**.
5. Migration **2026-10-10-025** creates:
   - `distribution_releases`
   - `distribution_destinations`
   - `distribution_events`
6. Migration 025 creates a distribution record for each existing release and initializes the default DSP destination set.
7. Existing catalog, releases, rights records, media assets and Operating Calendar data remain authoritative; Section 29 references them rather than copying them into a shadow catalog.
8. Open Admin → **Distribution + Publishing** and complete the distributor metadata for each release.
9. A release cannot move to Ready/Submitted/Scheduled/Live while preflight has blockers.
10. Submission/scheduled/live state changes require explicit Admin confirmation.
11. Use **Sync calendar** to create/update the release's distributor deadline, presave, campaign start and live-check milestones. Repeated syncs update deterministic milestones instead of duplicating them.
12. Use **Build package** only after preflight is green. The generated ZIP is stored under private `storage/distribution/` and downloaded through the authenticated Admin endpoint.

Application: **1.3.28**  
Database schema: **1.3.24**

