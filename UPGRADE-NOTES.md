# Stonefellow v1.3.24 Upgrade Notes

1. Upload/extract the v1.3.24 deploy over the existing installation.
2. Preserve production `data/`, `storage/`, site-specific configuration and generated catalog/config JS.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.20** to **1.3.21**.
5. Migration **2026-10-10-022** creates:
   - `order_shipments`
   - `order_refunds`
   - `support_cases`
   - `support_messages`
   - `order_ops_events`
6. Existing order JSON remains canonical for the base order; Section 25 adds queryable post-purchase ledgers around it.
7. Open Admin → Fulfillment + Care and verify shipment/refund/case queues.
8. Open a signed-in fan order and verify shipment history plus Get help with this order.

Application: **1.3.24**  
Database schema: **1.3.21**
