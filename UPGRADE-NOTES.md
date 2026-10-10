# Stonefellow v1.3.23 Upgrade Notes

1. Upload/extract the v1.3.23 deploy over the existing Stonefellow installation.
2. Preserve production `data/`, `storage/`, site configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.19** to **1.3.20**.
5. Migration **2026-10-10-021** creates:
   - `ticket_offers`
   - `ticket_reservations`
   - `ticket_events`
6. Existing show metadata remains in the canonical `data/shows.json`; transactional ticket/guest state is stored in the database by show ID.
7. Existing Membership + VIP, CRM, Campaigns and lifecycle automation remain authoritative.
8. Verify Admin → Tickets + VIP, create a draft RSVP, publish it with confirmation, reserve from a test account, cancel/re-reserve, and verify guest check-in.
9. Paid ticket offers should use External ticket provider fulfillment and a valid external URL.

Application: **1.3.23**  
Database schema: **1.3.20**
