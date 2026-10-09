# Stonefellow v1.3.14 — Section 16: Shows, Tours & Live Archive

Stonefellow remains a **single-artist platform**. Section 16 connects that artist's performances to the music catalog and Section 15 archive.

## Shows & Live

The public site now has a dedicated **Shows & Live** workspace with:

- Upcoming public performances
- Historical/past shows
- Venue, city, region and country
- Tour and era context
- Show status
- Ticket links for scheduled shows
- Posters and archive media
- Show notes
- Setlists
- Playable live recordings

Each show has a first-class detail route. Setlists and live recordings reference the existing canonical Stonefellow catalog by track ID.

## Live archive integration

Shows are also added to the Section 15 Music Archive timeline. A performance can therefore be explored alongside recording sessions, versions and releases without creating a parallel music catalog.

Live recording track IDs point to normal Stonefellow catalog records, so their credits, version metadata, favorites, queue behavior and playback remain canonical.

## Agent

The Agent receives grounded show context including date, venue, location, tour/era, setlist titles and archive notes.

It distinguishes between:
- browsing shows/tour dates/live archive; and
- factual questions about a show, venue, setlist, tour or live performance.

## Admin

Admin now includes **Shows + Live** with governed show CRUD.

A show record supports:
- date/time and lifecycle status
- venue/location
- tour and era
- public description and archive notes
- ticket URL
- poster
- public/featured flags
- ordered setlist track IDs
- playable live-recording track IDs
- archive media

Unknown catalog track references are rejected server-side.

## Storage and database

Shows are stored in canonical `data/shows.json`, alongside the existing file-backed catalog/release content model. Production `data/` remains deployment-owned and must not be overwritten by application deploys.

No database migration is required.

Application: **1.3.14**  
Database schema target: **1.3.12**
