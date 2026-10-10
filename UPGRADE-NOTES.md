# Stonefellow v1.3.25 Upgrade Notes

1. Upload/extract the v1.3.25 deploy over the existing installation.
2. Preserve production `data/`, `storage/`, site configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.21** to **1.3.22**.
5. Migration **2026-10-10-023** creates:
   - `operating_plans`
   - `operating_milestones`
6. No release, show, campaign, ticket or member-content date is migrated or copied. The new calendar reads those native dates directly.
7. Open Admin → Operating Calendar and create a draft plan from a template.
8. Confirm native release/show/campaign/ticket dates appear separately from plan milestones.

Application: **1.3.25**  
Database schema: **1.3.22**
