# Stonefellow v1.3.8 — Section 9: Personalized Home

- Rebuilt the signed-in home screen as a personalized listening workspace.
- Added Continue Listening with exact resume position.
- Added deduplicated Recently Played.
- Added Favorites, personalized recommendations, recent releases, and saved custom-media drafts.
- Added daily Agent suggestion with deterministic per-day selection from actionable user context.
- Added Up Next count/shortcut.
- Added robust empty states for new or low-activity accounts.
- Added Agent routing for today’s suggestion and listen-today phrasing.
- Added current home suggestion fields to Agent/JEV client state.
- Added malformed-source guards so daily suggestions never point at missing track/release/build IDs.
- Guest home remains unchanged.
- No database migration; schema target remains 1.3.6.
