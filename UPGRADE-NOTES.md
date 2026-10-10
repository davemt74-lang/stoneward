# Stonefellow v1.3.26 Upgrade Notes

1. Upload/extract the v1.3.26 deploy over the existing Stonefellow installation.
2. Preserve production-owned `data/`, `storage/`, site-specific configuration and generated catalog/config JS.
3. No database migration is required for Section 27.
4. Database schema target remains **1.3.22**.
5. Open Admin → **Performance Intelligence**.
6. Verify the selected 7/30/90/365-day window changes recorded order revenue, fan acquisition, campaign participation, ticket activity and automation results together.
7. Confirm external ticket offers are displayed as demand/access signals rather than recorded revenue.
8. Confirm existing Listening + Conversion, search, notification, library, track and per-user analytics remain available below the new business layer.

Application: **1.3.26**  
Database schema: **1.3.22**
