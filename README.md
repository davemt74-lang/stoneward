# Stonefellow v1.3.13 — Section 15: Music Archive, Song Stories & Deep Catalog

Stonefellow remains a **single-artist platform**. Section 15 deepens that artist's catalog rather than introducing multi-artist profiles or following.

## Music Archive

A new public **Music Archive** view organizes the existing Stonefellow catalog by chronology, era, recording session, version family, and release history.

Archive metadata remains attached to the canonical track/release records. There is no parallel music database.

Track archive fields support:

- Era
- Canonical work ID
- Version type and display label
- Version-of relationship
- Alternate track IDs
- Recording date and session
- Structured personnel
- Source/archive notes
- Attached archival media

This allows studio masters, demos, live takes, rehearsals, remasters, alternate mixes, and other versions to be connected as recordings of the same underlying song.

## Rich track + release context

Track pages now expose recording context, personnel, connected versions, and archival media when available.

Release records advance to `stonefellow.release.v2` and support liner notes, credits, edition/original-release context, reissue relationships, and archival media.

## Search + Agent

Section 12 search now indexes archive metadata in addition to titles, lyrics, stories, credits, moods and themes.

The Stonefellow Agent can open the Music Archive and answer grounded questions about recording sessions, personnel, chronology, and alternate versions using canonical catalog context.

## Admin

Admin catalog editing now includes Archive + Version History controls. Release management includes liner notes, credits, edition/reissue context, and attached archive media.

Production `data/` remains deployment-owned content and is not overwritten by application deploys.

## Database

No database migration is required. Database schema target remains **1.3.12**.

Application: **1.3.13**  
Database schema target: **1.3.12**
