# Stonefellow v1.3.20 Upgrade Notes

1. Upload/extract the v1.3.20 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, site-specific configuration and generated catalog/config JavaScript.
3. Run `/upgrade.php`.
4. Database target advances from **1.3.16** to **1.3.17**.
5. Migration **2026-10-10-018** creates:
   - `store_products`
   - `store_variants`
   - `store_inventory_events`
   - `store_order_items`
6. The existing configured custom-vinyl and custom-cassette products remain intact; the new database product catalog is additive.
7. Open Admin → Merch + Products and create products/variants as needed.
8. Attach product imagery using the universal Media Library.
9. Activate a product only after pricing, variants and inventory are correct. Activation requires explicit Admin confirmation.
10. For finite inventory, verify starting quantities before taking live orders.
11. Existing campaign discounts continue to work. A Campaign Builder discount node can optionally list product IDs to scope the discount to selected merchandise.
12. Production payment processing still depends on the payment provider already configured for Stonefellow; this release does not invent or bypass a payment adapter.

Application: **1.3.20**  
Database schema: **1.3.17**
