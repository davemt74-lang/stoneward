# Stonefellow v1.3.14 — Section 16: Shows, Tours & Live Archive

- Added dedicated public Shows & Live navigation.
- Added upcoming and historical show browsing.
- Added first-class show detail pages.
- Added venue/location, tour/era and lifecycle status.
- Added ticket links for scheduled shows.
- Added ordered setlists backed by canonical track IDs.
- Added playable live-recording links backed by canonical catalog tracks.
- Added posters, show notes and live-archive media.
- Added show entries to the Section 15 Music Archive timeline.
- Added grounded Agent show/tour/setlist context and routing.
- Added Admin Shows + Live workspace with governed CRUD.
- Added server-side validation for setlist/live-recording track references.
- Added canonical `stonefellow.show.v1` records in `data/shows.json`.
- Kept production `data/` deploy-owned and excluded from overlays.
- No database migration is required; schema target remains 1.3.12.
- Application advances to 1.3.14.
