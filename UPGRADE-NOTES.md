# Stonefellow v1.3.21 Upgrade Notes

1. Upload/extract the v1.3.21 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, site-specific configuration and generated catalog/config JavaScript.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.17** to **1.3.18**.
5. Migration **2026-10-10-019** creates:
   - `fan_segments`
   - `lifecycle_automations`
   - `lifecycle_enrollments`
   - `lifecycle_action_log`
   - `lifecycle_runs`
6. Existing CRM contacts, Campaigns, merch purchase history, listening data and notification preferences remain authoritative inputs.
7. Configure a scheduler/cron to run:
   - `php cron-lifecycle.php`
   at an appropriate interval, such as every 5–15 minutes.
8. New lifecycle automations start in Draft.
9. Activation requires explicit Admin confirmation.
10. Marketing email actions only deliver to newsletter-opted-in contacts.
11. Agent-message actions only deliver to linked accounts that allow proactive Agent engagement.
12. Fans can control Lifecycle Messages under their existing notification preferences.
13. Test a journey with a narrow segment before activating it broadly.

Application: **1.3.21**  
Database schema: **1.3.18**
