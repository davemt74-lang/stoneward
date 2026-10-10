# Stonefellow v1.3.22 Upgrade Notes

1. Upload/extract the v1.3.22 deployment over the existing installation.
2. Preserve production `data/`, `storage/`, site-specific configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.18** to **1.3.19**.
5. Migration **2026-10-10-020**:
   - adds membership configuration columns to `subscription_packages`
   - creates `membership_content`
   - synchronizes existing subscription state into CRM membership stage
6. Existing subscription/package IDs, Stripe Price IDs and billing state remain unchanged.
7. Configure membership benefits from Admin → Monthly Packages.
8. Create gated exclusives in Admin → Membership + VIP.
9. Verify My Membership, My Account membership badge, member cart savings and direct member-media authorization.

Application: **1.3.22**  
Database schema: **1.3.19**
