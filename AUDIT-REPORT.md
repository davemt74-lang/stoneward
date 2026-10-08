# Stonefellow v1.3.1 — Section 2 Playlists Audit

## 1. Playlist persistence — 10/10
- Dedicated `user_playlists` and `user_playlist_tracks` tables.
- Ordered membership is stored independently from catalog records.
- Playlist deletion cascades to membership rows.
- Track IDs are validated against the current Stonefellow catalog.
- Playlist names/descriptions are bounded and sanitized.

## 2. Ownership and privacy — 10/10
- Every write path requires an authenticated customer and CSRF protection.
- Edit/delete/add/remove/reorder operations verify playlist ownership.
- Visibility is constrained to `private` or `public`.
- Public reads expose only playlists explicitly marked public.
- Private playlists cannot be fetched through the public route.

## 3. Playlist editing — 10/10
- Create / rename / describe / delete.
- Add and remove tracks.
- Reorder tracks with exact-current-membership validation to reject stale reorder payloads.
- Duplicate track occurrences are permitted as independent playlist items.
- Updated timestamps are refreshed on edits.

## 4. Playback — 10/10
- Play All enters a playlist-aware persistent-player context.
- Player Next/Previous uses playlist order while a playlist is active.
- End-of-track automatically advances through the playlist.
- End of playlist exits playlist playback cleanly.
- Selecting ordinary catalog playback exits the playlist context instead of leaving a stale queue behind.

## 5. Public sharing — 10/10
- Public playlists have a stable share URL using the normal Stonefellow route.
- Logged-out visitors can load public playlists.
- Private playlists return not found through the public endpoint.
- Public playlist pages remain playable without granting edit controls.

## 6. Agent playlist orchestration — 10/10
- Local and JEV route vocabularies include playlist open/create/save-session intents.
- Agent playlist creation uses catalog-scored deterministic track IDs.
- The browser executes only the structured allowlisted `create_playlist` / `save_agent_session` actions.
- Agent-started playback is tagged `source=agent` in listening telemetry.
- Recent agent-curated listening can be persisted as a playlist.

## 7. Account / activity integration — 10/10
- My Stonefellow includes playlist cards, counts, duration, visibility and source.
- Playlist lifecycle events are written to user activity/history.
- Create Playlist and Save Agent Session controls are available from the account.
- Playlist routes coexist with Favorites, Continue Listening and Listening History.

## 8. Upgrade lifecycle — 10/10
- Schema target advances to `1.3.1`.
- Migration `2026-10-08-008` creates playlist tables.
- Final integrity verification requires both playlist tables.
- The change is additive and preserves existing accounts, favorites, telemetry, orders and billing.

## Validation
- 67 runtime PHP files lint clean.
- Public JavaScript syntax: PASS.
- Admin JavaScript syntax: PASS.
- 3 current v1.3 regression suites: PASS.
- 68 explicit PASS assertions.
- Pure-function playlist behavior checks: PASS.

## Environment limitation
This build environment has PDO core but no SQLite/MySQL PDO driver. Schema execution itself must be accepted on the installed Stonefellow server through `/upgrade.php`.
