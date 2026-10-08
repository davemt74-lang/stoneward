# Upgrade to Stonefellow v1.3.7 Section 8

Upload/extract the application update over the existing installation.

**No new database migration is required for Section 8.** The database schema target remains **1.3.6** because saved drafts reuse the existing `user_saved_builds` schema.

Running `/upgrade.php` remains safe; it should report no new Section 8 migration after an already-current v1.3.6 database.

Existing users, saved builds, purchases, favorites, playlists, history, recommendations, Agent listening sessions, queues, releases, and custom-media orders are preserved.
