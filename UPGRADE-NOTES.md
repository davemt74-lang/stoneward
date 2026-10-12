# Stonefellow v1.3.40 Upgrade Notes

1. Upload/extract the v1.3.40 deploy over the existing installation.
2. Preserve production-owned data/, storage/, database/secrets/store configuration and generated catalog/config JS.
3. Run /upgrade.php.
4. Database target advances from **1.3.32** to **1.3.33**.
5. Migration **2026-10-12-034** creates:
   - user_profiles
   - user_privacy_events
6. Existing fan accounts default to listening-history and personalization enabled until the fan changes those settings.
7. My Stonefellow now includes **Privacy & data**. The existing public Privacy policy link remains unchanged.
8. The service-worker cache advances to **stonefellow-static-v1.3.40**; installed PWAs should refresh to the new shell automatically.
9. Before testing account closure, resolve active paid subscriptions, unfinished physical orders and open support cases. Administrator accounts cannot self-close.
10. Account closure intentionally retains disclosed historical order/invoice/refund/support records while removing or anonymizing fan-profile/behavior identity.

Application: **1.3.40**
Database schema target: **1.3.33**
