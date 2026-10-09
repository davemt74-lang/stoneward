# Stonefellow v1.3.14 Upgrade Notes

1. Upload and extract the v1.3.14 application deploy over the existing installation.
2. Preserve production `data/`, `storage/`, and site-specific configuration.
3. No new database migration is required. Database schema target remains **1.3.12**.
4. `data/shows.json` is created by the Admin show editor when the first show is saved; application deploys must not overwrite production `data/`.
5. Existing catalog tracks can be referenced in ordered show setlists and playable live-recording lists.
6. Verify **Shows & Live** and the Music Archive timeline after entering show data.

Running `/upgrade.php` is safe but should report the database as current when migrations through schema 1.3.12 are already installed.
