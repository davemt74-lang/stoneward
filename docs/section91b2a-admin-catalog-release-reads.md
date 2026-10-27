# Section 91B2A — Genuine private Admin catalog and release reads (v1.3.162)

Stonefellow Admin Agent now answers specific catalog and release status questions using the existing authenticated site source functions, rather than only suggesting which Admin module to open.

## Supported questions

- “Show me unpublished songs.”
- “Which tracks are missing ISRC or writer information?”
- “Show pending releases.”
- “Which releases are scheduled?”

The Admin Brain's intent router chooses one of two new read-only skill/tool contracts (`admin_catalog_inventory` and `admin_release_inventory`). The existing `sf_catalog()` and `sf_admin_releases()` functions provide current data. No second catalog, database query layer or permission engine was introduced. Ordinary requests such as “Open catalog” and “Show my releases” still navigate to the installed Admin views.

## Data boundaries

Only an authenticated Admin can reach the Admin Agent endpoint. Results contain bounded IDs, titles, status/visibility flags, a few missing-metadata labels, release dates and track counts. The projection deliberately excludes private audio URLs, media paths, lyrics, rights records, customer information, notes and credentials. **No private inventory is sent to an external LLM**; the spoken/text result is assembled deterministically on the server.

Missing catalog metadata means specifically: absent ISRC, or both writer and composer fields absent. Catalog visibility follows the existing private/draft/archived and `public_visible` rules. “Pending releases” excludes already-public and archived records; “scheduled releases” matches the saved `scheduled` state. Results are capped at 10 per request with honest match totals and truncation status. Source failures return an unavailable message; an empty source does not become an invented track.

Both tools remain `read`, `admin`, `server` in the existing registries and do not gain direct execution or reviewed writes. The 91B1 capability audit automatically enumerates these additions. VP3 and Gelato remain untouched.

## Automated acceptance and score

1. Catalog source projection from provided fixture rows.
2. State/visibility decision parity for unpublished tracks.
3. Honest ISRC and writer/composer missing-field labels.
4. No private lyrics or audio source paths in replies.
5. Bounded, honestly truncated results.
6. Common spoken catalog wording mapped to read intents.
7. Release state/pending/scheduled filtering.
8. No internal release notes exposed.
9. Clear read-only response text.
10. Admin-only tool scope, existing module navigation and no direct execution preserved.

Rating **10/10 is conditional on all PHP contract tests, existing voice/navigation regressions and full release gate passing**. Live HostGator records and microphone hardware still require user validation. Further 91B2 domains remain future work, not silently marked implemented.
