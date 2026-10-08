# Stonefellow v1.3.8 — Section 9: Personalized Home

Stonefellow v1.3 Section 9 turns the signed-in home screen into the central listening and creation workspace.

## Included
- **Continue Listening** with exact resume positions and progress.
- **Recently Played** with repeated events collapsed to the latest occurrence per track.
- **Favorites** combining saved tracks and releases.
- **Recommended for You** using the existing favorites/listening/playlists/feedback profile.
- **Recent Releases** ordered newest first and filtered to published, public releases.
- **Saved Builds** linking directly back into the exact custom vinyl/cassette draft.
- **Agent Suggestion** for the day, selected from resume opportunities, recommendations, drafts, favorites, and recent releases.
- **Up Next** count and shortcut on the home header.
- Empty states that lead somewhere useful when a section has no data.
- Agent routing for “what do you suggest today?” and “what should I listen to today?”
- The Agent receives the currently displayed home suggestion in its client context.
- Existing guest home remains simple and Agent-first.

## Architecture
Section 9 does not create a second personalization model. The home API aggregates the canonical systems already built in Sections 1–8:

- personalization / favorites / listening history
- recommendations and feedback
- saved custom-media builds
- release metadata
- Up Next

## Database
No new database migration is required.

Application: **1.3.8**  
Database schema target: **1.3.6**
