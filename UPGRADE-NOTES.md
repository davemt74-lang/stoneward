# Upgrade to Stonefellow v1.3.1 Section 2

1. Upload/extract the update over the existing site.
2. Visit `/upgrade.php`.
3. Authenticate as Administrator.
4. Run the pending `1.3.1` playlist migration.
5. Confirm **Database is current**.

The migration is additive and creates `user_playlists` and `user_playlist_tracks`. Existing favorites, listening history, orders, accounts, catalog, and analytics are preserved.
