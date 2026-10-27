# Section 91B2B — Admin Agent show-calendar reads (v1.3.163)

Stonefellow Admin Agent can now answer questions about saved live performances, using the real `sf_admin_shows()` source already used by Shows + Live. The existing Admin-only Agent Chat auth, tool/skill registry and conversation controls remain authoritative.

Supported examples:

- “Show upcoming concerts.”
- “Which shows are postponed?”
- “List cancelled shows.”

The read tool `admin_show_inventory` returns bounded performance IDs, show titles, date, venue, city, status and visibility. Upcoming means `scheduled` with an ISO show date on or after the explicit UTC date used for the query. Dates sort chronologically, nonconforming dates are skipped, and the response reports its match total and truncation. Queries for cancelled or postponed shows do not silently treat those records as upcoming.

The projection excludes ticket URLs, archive notes, production media, private CRM data and arbitrary nested fields. It never calls an external provider or mutates the event calendar. Current-source failures return a clear unavailable message. Public/fan Agent sessions cannot discover the Admin read skill. Existing “open shows calendar” navigation is preserved. No cross-system changes or migration.

## Automated acceptance (10/10 for this bounded section)

1. Upcoming filters exclude past, cancelled and postponed shows.
2. Chronological order uses saved ISO dates.
3. Postponed/cancelled queries return the proper status.
4. Results report limits and truncation honestly.
5. Private archive notes and ticket URLs are excluded.
6. Invalid source dates are ignored safely.
7. Natural voice/text requests select the new read intent.
8. Ordinary navigation remains unchanged.
9. Admin-only read contract grants no writes and no public exposure.
10. Full release gate including prior 91A, 91B1 and 91B2A regressions passes.

Real HostGator show data and local time-zone interpretation still need human verification. Further Stonefellow data domains remain outside this narrow read increment.
