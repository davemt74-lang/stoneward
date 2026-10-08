# Stonefellow v1.3.5 — Section 6: Agent Listening Sessions

Stonefellow v1.3 Section 6 turns one-off recommendations into persistent Agent-guided listening.

## Included
- “Play me something” starts a real multi-track Agent listening session.
- Mood/theme sessions use the same personalized recommendation profile from favorites, history, playlists, and track feedback.
- Named release listening sessions play a release continuously from beginning to end.
- Guided release sessions add concise song context between tracks.
- Session queues have persistent server-side identity and ordered track state.
- Like/Dislike feedback is durable per user/track and immediately affects future recommendation ranking.
- Active listening panel exposes current session, position, feedback, Save Playlist, and End controls.
- Completed sessions remain saveable as exact playlists before dismissal.
- Session playback uses the existing persistent player and `agent_session` telemetry source.
- Skip/complete progression is synchronized back to the server.

## Database
Section 6 advances the database schema target to **1.3.5**.

New tables:
- `user_track_feedback`
- `agent_listening_sessions`
- `agent_listening_session_tracks`

Run `/upgrade.php` after deploying v1.3.5.
