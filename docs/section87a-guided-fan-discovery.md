# Stonefellow 87A — Guided Fan Experience

87A1 and 87A2 reuse the existing catalog, personalization, favorites, listening feedback, playback queue, Agent Chat and CRM. No parallel customer data store.

## 87A1 — Public discovery and consent
Five paths: Start Here, After Dark, Open Road, Slow Down and Deep Cuts. Suggestions are scored from canonical public tracks using public mood/theme/energy metadata and existing saved preferences. The existing SEO public-track gate suppresses private, archived, draft or unpublished-release tracks. Anonymous and privacy-opted-out visitors receive generic results, with no fan history queries. Authenticated opt-in users receive explainable personal results; explicitly disliked tracks are removed.

## 87A2 — Guided listening on the Music canvas
The new section sits above the existing catalog search and uses its original persistent player, protected Up Next queue and song-details navigation. Guests can listen without signing in, but cannot save preferences or use the cross-device queue. For signed-in users with personalization enabled, Not for me writes a dislike through the existing user_track_feedback table using a CSRF-authenticated, rate-limited endpoint; it makes no newsletter or marketing mutation. Save/request actions never contact an LLM.

The whole path starts one song and queues the remainder in order. Queue status is only confirmed on successful server receipts. Text and reasons are escaped in the renderer.

## Next gate
87A3 will integrate matching discovery paths with the existing customer Agent Brain and governed fan listening sessions. CRM must be read-only for unsolicited outreach; marketing requires separately recorded opt-in and existing approved campaign rules.

## Acceptance
87A1 and 87A2 each require focused unit/behavior checks, legacy search/recommendation/privacy regressions, MySQL/full website CI, exact-revision PR green merge and merged-main verification. No HomeServer modifications.
