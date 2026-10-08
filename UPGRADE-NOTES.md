# Upgrade to Stonefellow v1.3.6 Section 7

Upload/extract the update over the existing installation, then run `/upgrade.php`.

Section 7 advances the database schema target from **1.3.5** to **1.3.6** with:

- `2026-10-08-010` — Persistent user Up Next playback queue.

The upgrader creates:
- `user_play_queue`

Existing users, purchases, favorites, playlists, listening history, recommendations, Agent listening sessions, releases, and custom builds are preserved.
