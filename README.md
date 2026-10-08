# Stonefellow v1.3.1 — Section 2: Playlists

Stonefellow v1.3 Section 2 adds persistent user playlists on top of the Section 1 favorites/library/listening foundation.

## Included
- Create, rename, describe, and delete playlists.
- Public/private visibility.
- Add/remove/reorder tracks.
- Play an entire playlist through the persistent player; Next/Previous and natural end-of-track progression stay inside the playlist.
- Public playlist URLs for playlists marked public.
- Stonefellow agent can curate and save a playlist.
- Recent agent-curated listening sessions can be saved as playlists.
- Playlist actions appear in user Activity/History.

## Upgrade
Upload/extract the code update, then visit `/upgrade.php`. Schema target `1.3.1` adds `user_playlists` and `user_playlist_tracks`.
