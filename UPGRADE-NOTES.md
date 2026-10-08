# Upgrade to Stonefellow v1.3.5 Section 6

Upload/extract the update over the existing installation, then run `/upgrade.php`.

Section 6 advances the database schema target from **1.3.1** to **1.3.5** with migration:

- `2026-10-08-009` — persistent Agent listening sessions and track preference feedback.

The upgrader will create:
- `user_track_feedback`
- `agent_listening_sessions`
- `agent_listening_session_tracks`

Existing users, purchases, favorites, playlists, listening history, recommendations, releases, and custom builds are preserved.
