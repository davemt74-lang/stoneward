# Upgrade to Stonefellow v1.3.8 Section 9

Upload/extract the application update over the existing installation.

**No new database migration is required for Section 9.** The database schema target remains **1.3.6**.

Running `/upgrade.php` remains safe; an already-current v1.3.6 database should report no new Section 9 migration.

Existing users, favorites, listening history, playlists, recommendations, feedback, Agent sessions, Up Next queues, saved builds, releases, purchases, library records, and custom-media orders are preserved.
