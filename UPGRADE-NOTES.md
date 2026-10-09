# Stonefellow v1.3.15 Upgrade Notes

1. Upload/extract the v1.3.15 application files over the installed site while preserving production configuration, `data/`, and `storage/`.
2. Run `/upgrade.php`.
3. The database target advances from **1.3.12** to **1.3.13**.
4. Migration **2026-10-09-014** creates:
   - `fan_contacts`
   - `fan_crm_events`
   - `fan_agent_engagements`
   - `community_posts`
5. Existing account/listening/order/library/Agent data remains authoritative; the CRM references and aggregates those systems.
6. Existing users are linked into CRM lazily as they interact. Newsletter contacts merge into later accounts by email/user identity.
7. Verify the public newsletter form, Fan Community, chat-footer + menu, Admin Fans + CRM, and Agent Brain fan-engagement ledger.
8. Newsletter marketing consent is never inferred from an account or purchase.

Application: **1.3.15**  
Database schema: **1.3.13**
