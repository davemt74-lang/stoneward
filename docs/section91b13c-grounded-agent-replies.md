# 91B13C — Grounded Agent replies and exact song catalog

Build: **1.3.197**

## Intent

The customer-facing Stonefellow Agent must answer the current turn rather than
volunteer corrections to its own older messages. It must never claim that a
browser action succeeded solely because the server selected an action route.

## Implementation

1. **Real action receipts** — policy-generated browser actions are initially
   saved to conversation history as *pending*, with a specific message ID and
   action type. The browser executes the request through the existing player
   and capture adapters. Only when that adapter returns displayable result text
   does it POST to `api/agent-action-receipt.php`. The endpoint requires a
   signed-in user and atomically updates only the matching still-pending
   assistant message belonging to that user and conversation. Missing or
   failed receipts leave the action **unconfirmed**, never falsely successful.
2. **Full catalog listing** — clear requests to list all songs or show the
   complete catalog use a deterministic, server-owned route that formats
   every publicly discoverable published track from `sf_agent_public_catalog()`.
   It bypasses LLM list generation and output-token truncation, keeps the
   actual catalog order, and does not invent titles from chat memory.
3. **Response grounding** — the Agent's system policy explicitly forbids
   unsolicited retrospective self-corrections and treating an earlier
   intended action as proof of execution.
4. **Playback wording** — a matched song request is *requested* from the
   browser and is not described as loaded until the local action result
   confirms it.

## Regression coverage

`tests/section91b13c-grounded-agent-replies.php` checks catalog intent
routing, ten-title enumeration and ordering, nonexistent song behavior,
pending action receipt wiring, receipt authentication, and prompt policy.
The release gate runs this test alongside existing player, lyric,
and canvas suites.

## Deployment

Updating the GitHub code alone does not update a self-hosted Stonefellow
installation. Upload and extract the *new certified full deployment ZIP*
after merge, reload the site, confirm `version.php` reports
`1.3.197`, and clear any old page tab/service-worker state. Test in a
signed-in browser: ask for the complete catalog, request a non-catalog
song (no playback), request a published title (confirmed playback),
and start/stop listening. Retrospective corrections should not appear
without an explicit request to review prior replies.
