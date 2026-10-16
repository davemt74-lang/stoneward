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

## 87A3 — Customer Agent Brain listening paths

Explicit user phrases such as “play an After Dark listening session” are now resolved through the same published discovery ranking used by the Music page. The current Customer Agent's play-one-song route and the existing saved Agent Listening Sessions both accept this path selection. They do not create a parallel model, queue, profile, or private listening table.

The Music panel's **Guide me with Agent Brain** control hands the requested path to the existing chat composer. The existing Agent listening session still owns playback progression, positive/negative feedback, playlist saves, privacy boundaries and audit events. Explicit dislikes remain excluded when personalization is enabled; account Privacy Settings still govern any use of listening history. The Agent never enrolls fans in the newsletter or authorizes campaign sends because they requested a playlist.

Tests: pure path recognition, exactly aligned fan/Agent ranking, unpublished exclusion, dislike suppression, privacy opt-out, session-plan compatibility, historical Agent session regression and full release/MySQL certification.
