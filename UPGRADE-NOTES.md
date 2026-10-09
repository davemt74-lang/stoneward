# Stonefellow v1.3.17 Upgrade Notes

1. Upload/extract the v1.3.17 application deploy over the existing installation.
2. Preserve production `data/`, `storage/`, generated catalog/config JS and site-specific configuration.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.13** to **1.3.14**.
5. Migration **2026-10-09-015** creates:
   - `campaigns`
   - `campaign_segments`
   - `campaign_participants`
   - `campaign_events`
   - `campaign_entitlements`
   - `campaign_message_runs`
6. Existing CRM, accounts, newsletter consent, orders, catalog, shows and Agent Brain remain authoritative and are referenced by Campaigns.
7. Verify Admin → Campaigns, a draft visual workflow, public campaign landing page, free-download claim, campaign discount quote and Agent campaign routing.
8. Fan Community remains OFF unless separately enabled in Admin Settings.

Application: **1.3.17**  
Database schema: **1.3.14**
