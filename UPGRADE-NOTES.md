# Upgrade to Stonefellow v1.3.9 Section 10

Upload/extract the application update over the existing installation.

**No new database migration is required for Section 10.** The database schema target remains **1.3.6**.

Section 10 reads the existing listening, favorites, playlist, saved-build, user, and order data. Skip events use the existing `listening_events.event_type` column.

Running `/upgrade.php` remains safe and should report no new Section 10 migration on an already-current v1.3.6 database.
