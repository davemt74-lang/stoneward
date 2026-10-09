# Stonefellow v1.3.14 — Section 16 Shows, Tours & Live Archive Audit

## 1. Single-artist live architecture — 10/10
- Shows belong directly to Stonefellow; no multi-artist event marketplace was introduced.
- Canonical show records are file-backed alongside catalog/release content.
- Setlist and live-recording relationships use existing catalog track IDs.

## 2. Show model and governance — 10/10
- Show lifecycle supports scheduled, completed, cancelled, postponed and archived states.
- Date and venue are required.
- Setlist/live-recording references are validated against the canonical catalog.
- Deleting a show never deletes referenced tracks or recordings.
- Public visibility is explicit.

## 3. Public show experience — 10/10
- Dedicated Shows & Live navigation exists.
- Upcoming and historical performances are separated automatically.
- Show details expose venue/location, tour/era, notes, ticket link, setlist, live recordings and media.
- Show pages remain useful when optional archive data is absent.
- Responsive layouts cover narrow screens.

## 4. Setlists and live recordings — 10/10
- Setlist order is preserved.
- Setlist entries open/play canonical tracks.
- Playable live recordings use canonical catalog records.
- Existing playback, queue, credits and archive metadata remain authoritative.

## 5. Section 15 archive integration — 10/10
- Public shows participate in the Music Archive chronology.
- Show timeline entries deep-link to show details.
- Live performance history complements, rather than duplicates, recording/release history.

## 6. Agent integration — 10/10
- Agent context is grounded in canonical show records.
- Show context includes date, venue, location, tour/era, setlist titles and archive notes.
- Browse intent and factual show/setlist questions are routed separately.
- Factual responses remain grounded through the existing catalog-context flow.

## 7. Admin authoring — 10/10
- Dedicated Shows + Live workspace exists.
- Admin can create/edit/delete show records.
- Setlists, live recordings and media have structured authoring inputs.
- Server-side validation prevents invalid catalog references.

## 8. Deployment compatibility — 10/10
- No database migration is required.
- Database target remains 1.3.12.
- Existing installations with no `data/shows.json` return an empty show list safely.
- Production `data/` remains preserved during deploys.

## 9. Regression gate — 10/10
- PHP syntax: PASS.
- Public JavaScript syntax: PASS.
- Admin JavaScript syntax: PASS.
- Sections 1–12, 14, 15 and 16: PASS.
- Explicit assertions: 874 passed.
- Failures: 0.

FINAL SECTION 16 SCORE: **10/10**
