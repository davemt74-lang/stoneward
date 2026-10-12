# Stonefellow v1.3.38 — Section 39 Unified Public Search & Discovery Audit

Section 39 extends Stonefellow's existing catalog search into one governed public discovery surface for a single artist. The final audit reviewed public/private indexing boundaries, relevance, stale-result behavior, telemetry isolation, Agent routing, theme integration, SEO behavior and compatibility with Sections 12 and 34–38.

## 39A — Governed Public Search Corpus — 10/10
- Public result types are fixed to Song, Release, Show, Story, Video, Photo Gallery, Store Product and currently-live Offer.
- Tracks and releases reuse the canonical Section 32 public eligibility resolvers.
- Shows use the public live-show resolver.
- Stories, videos and galleries use their published/readiness-approved public resolvers.
- Products use active public commerce records.
- Campaign results require a currently-live campaign plus public landing headline/body copy.
- EPK/press records, professional inquiries, Admin records and other private datasets are intentionally excluded.
- Public track search uses a dedicated field projection: title, release, public story, mood, themes, public credits and lyrics only when a Songbook is published.
- Generic/internal track metadata and archive fields are not indexed by site-wide public Search.
- Public product search does not index internal merch tags.
- Offer suggestions use public landing headlines only, never internal campaign names.

## 39B — Search API, Limits & Privacy — 10/10
- Query length is capped at 300 characters.
- Result type is allowlisted.
- Result count is capped at 60.
- Anonymous initial GET search is read-only.
- Search/click telemetry is bounded through the existing search telemetry table and rate policy.
- Guest telemetry identity uses server-observed IP hash rather than caller session keys.
- Signed-in telemetry identity is bound to authenticated user only, preventing caller session-key rotation from bypassing throttles.
- Signed-in write actions retain CSRF protection.
- Site-search history is isolated from Section 12 catalog-search history.
- Clearing site-search history deletes only site_search/site_click events.

## 39C — Public Search Experience — 10/10
- Canonical public route: /search.
- Browser Search state uses /search?q=…&type=… rather than duplicate ?view=search URLs.
- Search itself is noindex,follow,noarchive and is deliberately omitted from the public sitemap.
- Results render escaped title, subtitle, snippet, reason and governed public imagery.
- Category filter covers all public result types.
- Recent signed-in queries, suggestions, zero-result guidance and loading/error states are supported.
- Slash and Ctrl/Cmd-K shortcuts open/focus Search without hijacking ordinary text editing.
- Search adds no Admin sidebar item.
- Search styling is responsive and inherits the published Section 34 theme variables rather than hard-coded typography/colors.

## 39D — Result Integrity & Navigation — 10/10
- Result destinations use existing canonical track/release/show/story/video/photo/product/campaign routes.
- A result click is revalidated against current public availability before telemetry is recorded.
- If content becomes private/unavailable after the search response, the client refuses navigation, informs the visitor and refreshes results.
- Result selection telemetry occurs before successful navigation.
- Search route state is restored after reload through the server/SPA pretty-route handoff.

## 39E — Telemetry & Intelligence — 10/10
- Reuses catalog_search_events instead of creating a duplicate analytics table or migration.
- New event names site_search/site_click remain isolated from legacy search/click/clear events.
- Measures search count, selected-result count, bounded CTR, zero-result rate, category usage, top queries and most-selected public results.
- Per-user analytics are available to Admin where applicable.
- Admin dashboard/recent catalog-search feeds remain scoped to their original Section 12 event set.
- Performance Intelligence displays a separate Site-wide Search panel.

## 39F — Agent Integration — 10/10
- Public Agent has a deterministic site_search route.
- Agent queries the same canonical public search core rather than a private/LLM-created index.
- Agent can emit open_site_search with the resolved query.
- Client Agent state sends the active site-wide Search query with catalog Search as fallback.
- Pretty-route/SPA navigation reports Search as the current view instead of incorrectly falling back to Home.
- Public Agent has no EPK, inquiry or draft-content search path through Section 39.

## 39G — Backward Compatibility & Shared Governance — 10/10
- Section 12 catalog Search remains available and retains its own facets/history/analytics.
- Section 12 Agent-query regression now explicitly supports site-wide Search plus catalog fallback.
- Section 34 theme contract remains enforced; Section 39 Search uses --display-font, --surface, --muted and other theme variables.
- Sections 33–38 public eligibility/readiness resolvers remain authoritative.
- Section 38 forward-version regression was repaired without weakening its minimum compatibility guarantee.
- No database schema change is required; schema remains 1.3.32 and migration 012 remains the canonical search telemetry schema.

## 39H — Release Certification — 10/10
- PHP syntax: PASS
- Public JavaScript syntax: PASS
- Admin JavaScript syntax: PASS
- Sections 1–38 inherited regression suites: PASS
- Section 39 Unified Public Search certification: PASS
- Explicit PASS lines on final hardened implementation candidate: 2239
- PHP warnings: 0
- Failures: 0

FINAL SECTION 39 SCORE: **10/10**
