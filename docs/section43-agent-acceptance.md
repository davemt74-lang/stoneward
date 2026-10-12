# Stonefellow Section 43 — Agent Intelligence & Fan Engagement

**Application:** 1.3.42  
**Schema:** 1.3.33 (no schema migration)  
**Product boundary:** Stonefellow is a single-artist platform. Agent recommendations and content actions use existing authoritative public content and logged-in fan APIs. The Agent does not independently spend money, publish campaigns, send marketing emails, or alter account privacy.

## Existing tools and providers retained

- OpenAI and Anthropic/Claude language-model provider routing; JEV decision/routing and response evaluation.
- ElevenLabs text-to-speech; browser speech recognition (availability depends on browser permissions/support).
- Deterministic local action policy with media player, queue, playlist, library, search, release, show, store, EPK, album-building, support, and account navigation.
- Agent Brain route/response/usage logging and Active Tokens accounting.
- Media Library, Public Search, Songbook, Video Hub, Photo Gallery, CRM, campaigns, membership, and fan permissions.
- A5 Cloud/HomeServer functionality is a separate product integration; Section 43 does not claim Stonefellow executes Cloud/HomeServer specialist missions.

## Section-by-section acceptance

| Section | Delivered behavior | Acceptance |
|---|---|---|
| 43A | Public Chat/Voice provider errors sanitized; existing provider, action, and Agent Brain integrations audited | `tests/section43a-agent-runtime.php` |
| 43B | CRM and membership enrichment omitted from recommendation prompts when fan personalization is disabled | `tests/section43b-agent-privacy.php` |
| 43C | Agent track discovery, named play and recommendations use canonical published/public SEO track resolver | `tests/section43c-public-discovery.php` |
| 43D | Conversation-controlled favorite add/remove uses existing authenticated Personalization API with explicit desired state | `tests/section43d-favorite-actions.php` |
| 43E | Admin-only aggregate CRM/campaign status answer; fan callers receive no administrative metrics or write access | `tests/section43e-admin-campaign-brief.php` |
| 43F | ElevenLabs MP3 validation and size limit before delivery and Active Token debit | `tests/section43f-voice-validation.php` |
| 43G | Proactive fan suggestions respect auto-engagement consent, personalization privacy, listening-history controls, daily cooldown, and recent triggers | `tests/section43g-proactive-consent.php` |
| 43H | Agent Brain details redact nested secrets/provider errors while preserving routing and token usage metrics | `tests/section43h-brain-privacy.php` |
| 43I | Client-supplied routing state is allowlisted and bounded; server injects actual admin authority and avoids premature saved-action claims | `tests/section43i-agent-governance.php` |
| 43J | Integrated release gate includes all Section 43 checks, public/Admin/PWA build synchronization and certified deployment artifact | `tests/section43j-release.php` |

## Security and privacy controls

- Account/profile privacy decisions remain in Section 41, not ad-hoc Agent client state.
- Administrative campaign data is aggregate-only and available solely through authenticated admin role.
- Public music must pass `sf_seo_public_track`; draft/private tracks are not passed to Agent recommendations.
- User-requested favorites are written by the existing authenticated `personalization.php` route, not by LLM tool text alone.
- Proactive prompts require explicit opt-in, respect personalization/listening choices, and retain server-side deduplication.
- Voice binary failures are rejected before tokens are debited.
- Agent Brain logs retain audit metrics while stripping credentials, provider errors, and excessive nested diagnostic strings.
- Consequential purchases, campaign publishing/sending, account closure, and permissions remain in their existing explicit owner/Admin workflows.

## Validation and release requirements

1. PHP syntax and JavaScript syntax pass across the full repository.
2. All v1.3 regression suites including Sections 41 and 42 pass.
3. All Section 43A–43J acceptance scripts pass at the same current commit.
4. `version.php`, public app, Admin app, Apache build header and PWA cache agree on v1.3.42.
5. No schema upgrade required beyond 1.3.33.
6. Merge only after the exact PR head passes.
7. Post-merge main release gate passes; certified ZIP and SHA-256 are produced from that exact commit.

## Honest functional boundaries

This release **does not** implement automatic CRM editing or marketing publication, a new speech-recognition engine, a completely new proactive recommender, or multi-agent specialists. It integrates and hardens the existing Agent functionality. Production browser and provider-key smoke testing still requires a deployed environment; CI cannot prove external ElevenLabs/OpenAI/Claude availability.
