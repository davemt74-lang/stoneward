# Stonefellow v1.3.3 — Section 4: Personalized Recommendations

- Added listener-specific recommendation profiles.
- Favorites, listening history, and playlist membership now influence ranking.
- Added explainable “because of your listening” recommendation reasons.
- Added Recommended for You to the signed-in home experience and account page.
- Recommendation playback is tagged separately in listening analytics.
- Stonefellow Agent recommendations now use the same personalized ranking.
- Agent-curated playlists now combine the listener profile with the current request.
- Added deterministic cold-start behavior for users without personalization signals.
- Fixed multi-row recommendation/resume event binding uncovered during Section 4 audit.
- No database migration required; schema target remains 1.3.1.
