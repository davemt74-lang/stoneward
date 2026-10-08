# Stonefellow v1.3.3 — Section 4: Personalized Recommendations

Stonefellow v1.3 Section 4 makes recommendations reflect the individual listener instead of using a catalog-only ranking.

## Included
- Recommendation profile built from track/release favorites, listening history, and playlist membership.
- Deterministic ranking across mood, themes, release affinity, preferred energy, familiarity, and discovery.
- Explainable recommendation reasons shown to the listener.
- Signed-in home recommendations and a Recommended for You section in My Stonefellow.
- Recommendation playback uses the existing persistent player and is tagged with the `recommendation` telemetry source.
- Agent “recommend something” requests use the same listener profile.
- Agent-curated playlists use the same personalized ranking plus the user’s current prompt.
- Cold-start users receive a deterministic catalog fallback until personal signals exist.

## Database
No new schema is required for Section 4. The database target remains **1.3.1** and the existing favorites, listening, and playlist tables are reused.
