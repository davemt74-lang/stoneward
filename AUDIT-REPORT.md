# Stonefellow v1.3.6 — Section 7 Queue / Up Next Audit

## 1. Persistence and ordering — 10/10
- Queue is persisted per authenticated user.
- Each track appears at most once per user.
- Ordered positions are normalized after remove/take/pop operations.
- Reorder requires exact current queue membership.

## 2. Queue operations — 10/10
- Add to end and Play Next are distinct operations.
- Exact queue items can be played and removed.
- Queue head can be atomically popped for automatic progression.
- Clear is authenticated and CSRF protected.

## 3. Playback integration — 10/10
- Active Agent sessions retain highest playback priority.
- Playlist/release playback remains intact until its current context ends.
- Up Next then becomes the next playback source.
- Queue Previous uses local playback history and does not reinsert/mutate server order.

## 4. Customer experience — 10/10
- Footer exposes Queue and live count while player is visible.
- Signed-in menu exposes Up Next when no player is visible.
- Drawer supports play, remove, reorder, and clear.
- Track pages expose Play Next and Add to Queue.
- Catalog, related tracks, and release tracklists expose queue actions.
- Queue restores after reload.

## 5. Agent integration — 10/10
- Agent can open Up Next.
- Agent can add a track, put a track next, remove a track, or clear the queue.
- Agent receives queue count in client state.
- Agent actions use the same queue API as direct user controls.

## 6. Data lifecycle — 10/10
- Migration 010 creates `user_play_queue`.
- User deletion cascades queue cleanup.
- Integrity checks require the queue table.
- Database target advances to 1.3.6.

## 7. Compatibility — 10/10
- Agent listening sessions, playlists, release playback, history, resume, recommendations, cart, builder, and rich media pages remain intact.
- Release gate now runs Sections 1–7.

The final measured gate is recorded in `TEST-RESULTS.txt`.
