# Stonefellow v1.3.21 Upgrade Notes

1. Upload/extract the v1.3.21 deployment over the existing installation.
2. Preserve production `data/`, `storage/`, site-specific configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.17** to **1.3.18**.
5. Migration **2026-10-10-019** creates lifecycle automation/run/event and segment-membership storage.
6. Existing Campaign Builder saved segments remain intact and become reusable lifecycle audiences.
7. Configure the server scheduler to run:
   `php cron-automations.php`
   at an appropriate cadence for wait/scheduled automation processing.
8. Verify Admin → Segments + Automations:
   - segment preview
   - save/refresh membership
   - draft journey
   - publish/pause
   - manual run
   - run timeline

Application: **1.3.21**  
Database schema: **1.3.18**
