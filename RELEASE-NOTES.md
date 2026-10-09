# Stonefellow v1.3.13 — Section 15: Music Archive, Song Stories & Deep Catalog

- Added a public Music Archive view for Stonefellow's single-artist catalog.
- Added chronological recording/release timeline and era browsing.
- Added canonical song/version relationships for demos, studio masters, live takes, remasters, alternate mixes and other recordings.
- Added structured recording-session, personnel and archival-media metadata.
- Expanded rich track pages with archive context, connected versions and personnel.
- Expanded releases with liner notes, credits, edition/original-release context, reissue relationships and archive media.
- Advanced release records to `stonefellow.release.v2` while retaining the same file-backed release store.
- Extended catalog search to index archive/version metadata.
- Added Agent routing for archive/chronology requests and richer grounded context for version/personnel questions.
- Added Admin editors and server validation for archive relationships.
- Preserved production `data/` as deployment-owned content.
- No database migration is required; schema target remains 1.3.12.
- Application advances to 1.3.13.
