# Stonefellow v1.3.6 — Section 7: Queue / Up Next

Stonefellow v1.3 Section 7 adds a persistent, user-controlled Up Next queue that is separate from playlists and Agent listening sessions.

## Included
- Persistent per-user Up Next queue stored server-side.
- Add to Queue and Play Next controls on track, catalog, related-track, and release surfaces.
- Queue drawer with play, remove, move up/down, and clear controls.
- Queue count in the persistent footer player.
- Signed-in menu access even when the player is hidden.
- Manual Next consumes Up Next after active Agent-session and playlist/release contexts.
- Automatic track completion falls through to Up Next.
- Queue Previous uses recent local playback history without mutating the server queue backward.
- Queue restores after reload and survives across authenticated devices.
- Agent commands can open, add, play next, remove, and clear the same queue.
- Dedicated `queue` and `queue_history` playback telemetry sources.

## Playback priority
1. Active Agent listening session
2. Active playlist/release playback
3. Up Next queue
4. Normal catalog stepping

## Database
Section 7 advances the database schema target to **1.3.6**.

New table:
- `user_play_queue`

Run `/upgrade.php` after deploying v1.3.6.
