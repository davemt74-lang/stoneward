# Upgrade to Stonefellow v1.3.12 Section 14

Upload/extract the update over the existing Stonefellow installation, then run:

```
/upgrade.php
```

Section 14 advances the database schema from **1.3.11** to **1.3.12**.

Migration:

- `2026-10-08-013` — user My Library Collections and mixed saved-item organization.

New tables:

- `user_collections`
- `user_collection_items`

Existing favorites, playlists, user-library purchases, saved builds, listening history, Continue Listening, orders, search analytics, notification data, Agent data, and catalog/release content are preserved.

### Behavioral note

The new Purchases tab only treats an order item as owned Library content when its canonical order/payment state is paid or complete. Payment-pending orders remain visible in My Account/order history until payment completes.

### Section numbering

Section 13 Smart Radio was intentionally passed over. No Section 13 migration is required; migration numbering continues normally with migration 013 for this v1.3.12 Section 14 release.
